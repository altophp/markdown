# Security

Treat Markdown as untrusted input. Alto makes HTML safe by default, but the host
application still owns request limits, path authorization, and trusted
extension code.

Report a suspected library vulnerability privately through this repository's
**Report a vulnerability** button, or see the
[org-wide security policy](https://github.com/altophp/.github/blob/main/.github/SECURITY.md).
Do not include exploit details in a public issue.

## Start with the safe default

The default policy:

- escapes raw HTML;
- filters unsafe link and image schemes;
- allows `http`, `https`, `mailto`, `tel`, relative paths, and fragments;
- never fetches links or images.

```php
use Alto\Markdown\Markdown;

$markdown = "Click [here](javascript:alert(1)).\n\n<script>alert(1)</script>\n";
$html = Markdown::github()->toHtml($markdown);
```

The result keeps the text but removes the unsafe destination and escapes the
script:

```html
<p>Click <a href="">here</a>.</p>
&lt;script&gt;alert(1)&lt;/script&gt;
```

Generated text and attributes are escaped for their HTML context. No option is
needed for this behavior.

Installed extensions are trusted PHP code. A custom HTML renderer returns
markup, so `HtmlPolicy::safe()` does not escape that complete return value.
Extension renderers must pass every dynamic value through the output context's
`escapeText()`, `escapeAttribute()`, or `escapeUrl()` helper. If extension
output also needs an independent boundary, use `HtmlPolicy::curated()`: its
final fragment sanitizer covers both core and extension HTML. The spec policy
passes extension HTML through unchanged.

The built-in default-attributes extension follows the same trust boundary.
It escapes values and filters configured `href` and `src` URLs, but permits
application-defined `style`, event, and other URL-bearing attributes.
`HtmlPolicy::curated()` removes unsupported and event attributes from the
completed fragment.

Author-controlled attributes use a narrower boundary.
`AttributesExtension` accepts only names listed in `AttributesPolicy`, whose
default is `id` and `class`. Event attributes can never be enabled. Source
`href` and `src` values use the active URL policy, all values are escaped, and
parser limits bound list size, value size, and attribute count. The curated
policy remains the final authority and may remove an otherwise allowed source
attribute.

## Preserve HTML deliberately

| Policy | Authored HTML | URL filtering | Use |
| --- | --- | --- | --- |
| `HtmlPolicy::safe()` | Escaped | Yes | Default for untrusted input |
| `HtmlPolicy::curated()` | Sanitized allowlist | Yes | Preserve common document HTML |
| `HtmlPolicy::spec()` | Passed through | No | Trusted input and conformance only |

The curated policy removes scripts, event handlers, SVG, MathML, unsafe URLs,
and unsupported attributes after the complete Markdown fragment is rendered:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;

$markdown = '<h1 onclick="alert(1)">Title</h1><script>alert(1)</script>';
$options = new RenderOptions(htmlPolicy: HtmlPolicy::curated());

$html = Markdown::github()->toHtml($markdown, renderOptions: $options);
```

The result is `<h1>Title</h1>`. Curated rendering requires PHP's DOM extension
and never changes the Markdown source.

For a stricter policy, keep safe URL filtering and strip raw HTML:

```php
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RawHtmlPolicy;

$policy = HtmlPolicy::safe()
    ->withAllowedSchemes('https')
    ->withRawHtml(RawHtmlPolicy::Strip);
```

Applications may provide an audited full-fragment `HtmlSanitizer`.
`HtmlPolicy::spec()` is not a sanitizer and must not be used for untrusted
input.

Generated tables of contents follow the same boundary. The safe default keeps
their `<nav>` wrapper and heading IDs. The curated policy keeps the list
content but removes the wrapper and target IDs, so those fragment links are
not interactive. Preserve them only with an audited application sanitizer, or
with the spec policy when all input and extensions are trusted.

Generated footnotes use numeric same-document fragment links. The curated
policy keeps only their specific IDs, roles, and `footnotes` class. Footnote
definitions still use the active Markdown and raw HTML policy, so enabling the
extension does not grant authored HTML any additional permission.

Generated tabs follow the same rule. Titles are always escaped text, panel
bodies use the active Markdown and raw HTML policy, and no script is emitted.
The curated policy preserves only Alto's deterministic tab IDs, fragment links,
and CSS classes. All panels remain present and readable without client-side
code.

Front matter decoders are explicit trusted application code. Parsing,
rendering, querying, and saving never invoke them automatically. `decode()`
passes opaque content bytes and the fence to the supplied decoder, propagates
its result or exception, and does not change the document. Apply the selected
YAML or TOML library's own limits and safe-loading options inside that decoder.

## Bound parsing and file access

Parsing limits are explicit:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SaveOptions;
use Alto\Markdown\Operation\SymlinkPolicy;
use Alto\Markdown\Parser\ParseOptions;

$options = new ParseOptions(
    maxNestingDepth: 64,
    maxSourceBytes: 1_000_000,
    maxBlockCount: 10_000,
    maxInlineCount: 50_000,
    maxReferenceCount: 1_000,
);

$document = Markdown::github()->fromString("# Bounded\n", $options);

$file = Markdown::github()->open('content/security-policy.md');
$file->section('Security')->append("\nSecurity review completed.\n");
$file->save(new SaveOptions(
    compareBeforeWrite: true,
    symlinks: SymlinkPolicy::Reject,
));
```

Nesting defaults to 256 simultaneously open blocks. The other limits are
unbounded until configured. All limit failures extend `ParseLimitException`;
the precise subclasses report source, block, inline, or reference exhaustion.

### Validate the correct boundary

Alto has no generic `validate()` method. Markdown is a permissive language, and
one boolean result would combine unrelated questions:

- use `ParseOptions` and catch `ParseLimitException` for resource acceptance;
- use `HtmlPolicy` for output safety;
- use `lint()` for project content rules;
- construct factories during startup to validate trusted extension contracts;
- use save options and typed file exceptions for persistence conflicts.

Each operation already performs the checks required by its boundary. A separate
validator would parse or render the same input again without making the later
operation safe. Validate application-specific authorization before calling
Alto, then handle the typed failure from the operation that consumes the input.

The application must still:

- reject oversized requests before PHP work begins;
- set appropriate memory and execution limits;
- authorize paths before `open()` and `saveAs()`;
- validate canonical parent directories, including intermediate symlinks;
- use `SaveOptions(compareBeforeWrite: true)` when another writer may change a
  file.

Custom extensions that read source or include files should receive a
`FilesystemResourceResolver` instead of joining paths themselves. It confines
relative references to one canonical root, applies an extension allowlist and
byte limit, rejects every symbolic-link component, and verifies the opened
file's type, device, and inode before returning bytes. Opaque resource IDs may
anchor later relative requests, but they are not capabilities and do not
authorize access.

Filesystem validation is best effort under PHP. A path can still change
between checks and `fopen()`, and the resolver does not promise immutable
contents while a file is being read. Use an application resolver backed by an
OS sandbox or a stronger file-opening primitive when another hostile process
can mutate the resource tree.

`ImportExtension`, `SourceExtension`, `IncludeExtension`, and `EmbedExtension`
perform their explicit resource reads while parsing. Enable them only with an
authorized resolver and expected input, because `toHtml()` and `fromString()`
may then perform I/O. Imports and source displays render returned bytes as
escaped code. Includes intentionally parse returned bytes as Markdown under
the current profile and HTML policy.

`IncludePolicy` adds per-tree depth, resource-count, aggregate-byte, block,
inline, reference, and nesting limits. Cycle detection uses stable resolved
resource IDs, not author-controlled references. Root-only syntax prevents a
directive hidden inside another container from opening a new resolution
context. Resolver failures and include limit failures remain typed exceptions;
Alto does not hide them behind successful placeholder output.

`EmbedPolicy` requires an explicit host allowlist, matches at DNS label
boundaries, defaults to HTTPS, refuses userinfo and non-default ports, and
bounds both URL and resolved HTML bytes. A disallowed URL never reaches the
resolver. The default safe HTML policy renders a fallback link rather than
resolver-provided HTML.

The resolver still owns the network boundary. It must restrict redirects,
private and link-local addresses, DNS rebinding, response sizes, content types,
timeouts, and credentials. Resolved embed HTML is trusted application data.
Emit it only behind a final sanitizer designed for the allowed providers and a
restrictive Content Security Policy. Alto performs no network request itself.

The final path component rejects symlinks by default. Explicit
`SymlinkPolicy::Follow` resolves the target before conflict checking and
writing, but cannot remove filesystem time-of-check/time-of-use races. Atomic
save and compare-before-write are not a cross-process lock. File reads and
writes reject direct directories, FIFOs, sockets, and devices. Relative paths
are anchored when opened or adopted by `saveAs()`, so a later
working-directory change cannot redirect a save.

On POSIX, atomic replacement preserves permission and special mode bits or
fails before replacement. It does not preserve ACLs, ownership, extended
attributes, or other platform metadata. New files use `0666 & ~umask`.
Windows replacement and mode semantics are platform-limited and may fail when
the destination exists or is open. See [Errors](errors.md) for recovery and
[Editing](documents/editing.md) for the complete save flow.
