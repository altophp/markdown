# Extensions

Alto compiles extensions once when a factory is created. Custom blocks then use
the same integer dispatch tables as built-in syntax, without exposing those
internal IDs to extension code. Package authors should also read
[Extension compatibility](extension-compatibility.md) for the stable V1
contract, deprecation rules, and migration matrix.

| Capability | Interface |
| --- | --- |
| Custom block syntax and rendering | `BlockExtensionInterface` |
| Custom leaf inline syntax and rendering | `InlineExtensionInterface` |
| Native and semantic-link HTML decoration | `HtmlDecoratorExtensionInterface` |
| Render-only document projection | `DocumentTransformExtensionInterface` |
| Lint rules and safe fixes | `LintExtensionInterface` |
| Formatter passes | `FormatterExtensionInterface` |
| Document metrics | `StatsExtensionInterface` |
| External resource reads | Inject a `ResourceResolver` into the extension |

## Define a custom block

An extension only needs a stable name. Optional capability interfaces declare
what it adds. A block extension returns one or more `BlockDefinition` values:

```php
use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\RenderOptions;

final readonly class CalloutExtension implements BlockExtensionInterface
{
    public function name(): string
    {
        return 'example';
    }

    public function blocks(): iterable
    {
        $output = new CalloutOutput();

        yield new BlockDefinition(
            kind: 'callout',
            parser: new CalloutParser(),
            html: $output,
            markdown: $output,
        );
    }
}

final readonly class CalloutParser implements BlockParser
{
    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || $context->indentColumns() > 3) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 !== preg_match('/^(:{3,})([A-Za-z][A-Za-z0-9-]*)$/D', $line, $match)) {
            return null;
        }

        return new BlockStartResult(
            $first,
            $context->lineContentEndOffset(),
            container: true,
            state: new BlockState([
                'fence' => strlen($match[1]),
                'label' => strtolower($match[2]),
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 === preg_match('/^:+$/D', $line)
            && strlen($line) >= $context->state()->int('fence')
        ) {
            return BlockContinueResult::closed($context->lineContentEndOffset());
        }

        return BlockContinueResult::matched();
    }
}

final readonly class CalloutOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        $label = $context->escapeAttribute($context->state()->string('label'));

        return "<aside class=\"callout callout-{$label}\">\n{$children}</aside>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        $fence = str_repeat(':', $context->state()->int('fence'));
        $label = $context->state()->string('label');

        return "{$fence}{$label}\n{$children}\n{$fence}";
    }
}

$factory = Markdown::commonmark()->with(new CalloutExtension());
$source = ":::note\n## Read this\n\nBody text.\n:::\n";

$html = $factory->toHtml($source);
$document = $factory->fromString($source);
$callouts = $document->query()->kind('example:callout')->get();
$original = $document->toMarkdown();
$normalized = $document->toMarkdown(new RenderOptions());
```

The qualified node kind is `example:callout`: the extension name plus the
local block kind. Alto allocates its compact integer ID while compiling the
profile.

The parser can inspect only the current line. It returns original byte offsets
and immutable scalar `BlockState`. Alto owns cursor movement, tape writes, tree
links, and range validation.

### Define inline syntax

Inline extensions follow the same complete parser, node, and renderer slice.
This example adds `^^marked text^^`:

```php
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParseResult;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;
use Alto\Markdown\Extension\InlineExtensionInterface;
use Alto\Markdown\Markdown;

final readonly class MarkExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'example';
    }

    public function inlines(): iterable
    {
        $output = new MarkOutput();

        yield new InlineDefinition(
            kind: 'mark',
            parser: new MarkParser(),
            html: $output,
            markdown: $output,
        );
    }
}

final readonly class MarkParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '^';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $remaining = $context->remaining();

        if (!str_starts_with($remaining, '^^')) {
            return null;
        }

        $close = strpos($remaining, '^^', 2);

        if (false === $close || 2 === $close) {
            return null;
        }

        $text = substr($remaining, 2, $close - 2);

        return new InlineParseResult(
            $context->offset() + $close + 2,
            new InlineNode($text),
        );
    }
}

final readonly class MarkOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return '<mark>'.$context->escapeText($context->node->text).'</mark>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return '^^'.$context->node->text.'^^';
    }
}

$factory = Markdown::commonmark()->with(new MarkExtension());
$html = $factory->toHtml("Read ^^this^^.\n");
```

The qualified kind is `example:mark`. `triggerByte()` returns exactly one
byte, so Alto compiles the parser directly into that byte's dispatch list.
The byte cannot be a parser-owned delimiter such as a line break, `*`, `_`,
`[`, `]`, or `!`. A profile can reserve more bytes. For example, `~` is
unavailable when strikethrough is active. Alto rejects these definitions when
the profile is compiled instead of installing a parser that can never run.
`InlineParseResult` must advance the joined inline-content cursor and remain
within its bounds. `InlineNode` carries semantic plain text plus optional
boolean, integer, string, or null attributes. Renderers receive its current
source range and bytes.

`$context->source()` is the exact joined byte sequence accepted by the parser.
For a match spanning lines inside a block quote or list, it contains the joined
line break but excludes container markers. `$context->range` contains original
document byte offsets when the inline input comes directly from a block.
Normalized GFM table cells are detached inline inputs, so their output context
has a null range instead of exposing local offsets as source offsets. Their
`source()` value remains the exact normalized cell bytes accepted by the
parser.

Public inline nodes are leaves. They can participate inside emphasis, links,
and image labels, but cannot own parsed inline children. Returning `null`
declines a match without moving the cursor. Alto validates successful results
before writing the inline tape.

Set `link` to an `InlineLinkSemantics` value when the custom node renders an
anchor:

```php
use Alto\Markdown\Extension\Inline\InlineLinkSemantics;

$link = new InlineLinkSemantics(
    destinationAttribute: 'url',
    titleAttribute: 'title',
);
```

Pass `$link` to the `InlineDefinition` constructor's `link` argument. The
parser must store those attributes on its `InlineNode`. Alto exposes them as
the standard `destination` and optional `title` values to link decorators. It
also emits the node's semantic text instead of a second anchor when the node
occurs inside a Markdown link. The same text contributes to image alt text.

Core inline parsers have priority over public extensions. Extensions sharing a
trigger are then consulted in registration order. The first successful parser
wins; a parser returning `null` lets the next parser try the same byte. A
declining parser is consulted only at occurrences of its compiled trigger.

Inline renderers are trusted application code. Their returned HTML is not
escaped as one opaque string by the default safe policy. Render dynamic values
through `escapeText()`, `escapeAttribute()`, and `escapeUrl()`. The curated
policy additionally sanitizes the complete rendered fragment, including
extension output. See [Security](security.md).

The same compiled inline definitions and native inline decorators run through
`MarkdownFactory::toInlineHtml()`. Output contexts in that first-class lane
keep ranges into the original fragment. Block extensions do not run because
the lane intentionally has no block parse.

### Resolve external resources safely

A custom extension that reads a file receives a `ResourceResolver` as an
ordinary constructor dependency. The resolver is a service, not an extension:
registering one adds no syntax, parser branch, rendering branch, or I/O.

```php
use Alto\Markdown\Resource\FilesystemResourceResolver;
use Alto\Markdown\Resource\ResourceRequest;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['md', 'php'],
    maxBytes: 1_000_000,
);

$resource = $resolver->resolve(new ResourceRequest(
    reference: 'examples/hello.php',
    purpose: 'source',
));

$bytes = $resource->bytes;
```

The filesystem implementation accepts relative references only. It rejects
null bytes, Unix absolute paths, UNC paths, Windows drive paths, parent
traversal, disallowed extensions, directories, non-regular files, and every
symbolic-link component. It reads at most `maxBytes + 1`; an exact limit and an
empty file are valid. `md` and `markdown` are the default extensions.

Resolved IDs are stable opaque values. Pass a previous ID as `originId` to
resolve a child reference beside that resource. An ID is context for relative
resolution, not a capability or authorization token. Applications must not
parse it or treat possession of one as permission.

Use a callback for a database, package registry, authenticated store, or
stronger operating-system boundary:

```php
use Alto\Markdown\Resource\CallbackResourceResolver;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;

$resolver = new CallbackResourceResolver(
    static function (ResourceRequest $request): ResolvedResource {
        return new ResolvedResource(
            id: 'memory:'.$request->reference,
            bytes: "# Loaded\n",
        );
    },
);
```

The callback owns authorization, size limits, recursion policy, network
behavior, and failures. Core never fetches a resource implicitly and keeps no
global resource cache.

### Import code from a resource

`ImportExtension` turns an explicit column-zero directive into an escaped code
block. It reads through an injected resolver while parsing, stores only the
selected bytes and language, and performs no further I/O when the document is
rendered:

```php
use Alto\Markdown\Extension\Import\ImportExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['md', 'php'],
    maxBytes: 100_000,
);

$factory = Markdown::github()->with(new ImportExtension($resolver));

$html = $factory->toHtml(
    '@import "examples/hello.php" {lines: 2-8, lang: php, indent: 2}',
);
```

Line numbers are one-based and inclusive. `lang` adds a `language-*` class,
and `indent` accepts 0 to 32 spaces. A directive with invalid syntax, unknown
or duplicate options, a descending range, or an option outside its fixed bound
remains literal Markdown and performs no resource lookup.

Imported bytes always become `<pre><code>` text escaped under the active HTML
policy. They are never raw HTML and are never parsed as Markdown.

Resource lookup happens during `toHtml()` or `fromString()`, not during a
later render. A typed `ResourceResolutionException` is not converted into a
placeholder and propagates to the caller. Configure and authorize the resolver
before enabling this extension on Markdown input.

### Include Markdown resources

`IncludeExtension` replaces a root-level directive with recursively resolved
Markdown:

```php
use Alto\Markdown\Extension\Include\IncludeExtension;
use Alto\Markdown\Extension\Include\IncludePolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['md', 'markdown'],
    maxBytes: 250_000,
);

$factory = Markdown::github()->with(new IncludeExtension(
    resolver: $resolver,
    policy: new IncludePolicy(
        maxDepth: 6,
        maxResources: 32,
        maxExpandedBytes: 500_000,
    ),
));

$html = $factory->toHtml('@include "chapters/install.md"');
```

The directive must be exactly `@include "relative/path.md"` at column zero
and directly below the document root. It does not interrupt a paragraph.
Directives inside block quotes, lists, tabs, fenced code, raw HTML, or another
container remain literal and perform no resource lookup. Invalid directives
also remain literal. Inside an included resource, separate a directive from
adjacent prose or other extension directives with a blank line so the
recursive discovery pass sees the same CommonMark root block.

Each nested request carries the resolved parent ID as its opaque `originId`,
so the resolver can locate `parts/setup.md` beside the file that includes it.
The extension rejects active cycles by resolved resource ID. Reusing the same
resource after its earlier branch has completed is valid.

`IncludePolicy` bounds depth, resource count, aggregate resolved bytes, and
the block, inline, reference, and nesting work for every include tree.
Resolver limits still apply to each individual resource. Resolution and
recursive expansion happen during `toHtml()` or `fromString()`. A parsed
document stores the expanded Markdown and performs no more I/O when rendered.
`toMarkdown()` preserves the original directive.

Included Markdown renders under the factory's compiled profile and active
HTML policy, but it is an isolated fragment. The outer document exposes one
`include:block`; its query, lint, format, edit, table-of-contents, and
heading-permalink operations do not absorb included blocks. Front matter
inside a resource is ordinary fragment content, not outer document metadata.
Render projections inside separate fragments also have separate ID scopes, so
applications that include ID-producing content more than once must configure
a namespace that keeps those IDs distinct.

### Embed rich external content

`EmbedExtension` recognizes an HTTP or HTTPS URL alone at the document root.
It asks an application-owned resolver for the corresponding HTML fragment:

```php
use Alto\Markdown\Extension\Embed\EmbedExtension;
use Alto\Markdown\Extension\Embed\EmbedPolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\CallbackResourceResolver;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;

$resolver = new CallbackResourceResolver(
    static function (ResourceRequest $request): ResolvedResource {
        // Read a bounded application cache or call a hardened oEmbed client.
        return new ResolvedResource(
            id: 'embed:'.hash('sha256', $request->reference),
            bytes: '<figure class="video">Resolved embed</figure>',
        );
    },
);

$factory = Markdown::commonmark()->with(new EmbedExtension(
    resolver: $resolver,
    policy: new EmbedPolicy(
        allowedHosts: ['video.example'],
        includeSubdomains: true,
    ),
));
```

`allowedHosts` is mandatory. Matching uses exact DNS labels, so allowing
`video.example` never allows `video.example.evil` or `notvideo.example`.
Subdomains require `includeSubdomains: true`. HTTPS is the default. HTTP
requires `allowHttp: true`, and non-default ports are never resolved.

The URL must start at column zero, occupy its own root block, contain no
userinfo or control bytes, and stay within `maxUrlBytes`. URLs inside prose,
lists, quotes, code, raw HTML, tabs, or another container remain ordinary
Markdown and perform no lookup. Disallowed hosts become links by default or
disappear with `fallback: EmbedFallback::Remove`; neither fallback calls the
resolver.

Resolved bytes are limited again by `maxHtmlBytes` and stored during parsing,
so repeated document rendering performs no I/O. Under the default safe HTML
policy, the output remains a link. Resolved HTML is emitted only when the
active policy accepts raw HTML, then any final-fragment sanitizer still runs
over the complete result. Use a dedicated sanitizer and a restrictive Content
Security Policy for active media such as iframes. `toMarkdown()` preserves the
original URL.

### Display a source excerpt

`SourceExtension` adds visible path metadata, optional titles, original line
numbers, and highlighted lines around an escaped source resource:

```php
use Alto\Markdown\Extension\Source\SourceExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(__DIR__.'/content', ['php']);
$factory = Markdown::github()->with(new SourceExtension($resolver));

$html = $factory->toHtml(
    '@source "src/Handler.php" {lines: 9-18, title: "Request handler", '
    .'numbers: true, highlight: "10, 14-16"}',
);
```

The directive must begin at column zero. `lines` is a one-based inclusive
range. `lang` overrides automatic detection from common filenames and
suffixes. `title` must be double quoted and accepts only `\"` and `\\`
escapes. `numbers` is exactly `true` or `false`. `highlight` accepts up to 64
one-based lines or inclusive ranges, with a quoted value when it contains
commas. Highlights keep their original resource numbers after a `lines`
selection.

The HTML wrapper has class `source-block`, with `source-title` when provided,
`source-path`, and escaped `<pre><code>` content. Numbered or highlighted
content wraps every source line in `.line`; highlighted lines also receive
`.highlighted`. Visible numbers use an `aria-hidden` `.line-number` span.
Applications should exclude those numbers from copied code:

```css
.source-block .line-number {
    user-select: none;
}
```

The complete resource is read once during parsing, but line selection performs
one bounded byte scan and retains only the requested excerpt. Highlight ranges
are validated, sorted, and merged once, then consumed monotonically while
rendering. Source bytes are never interpreted as HTML or reparsed as Markdown.
Malformed or over-limit directives stay literal and perform no I/O. Resolver
failures propagate as typed exceptions.

### Configure a leaf delimiter pair

`PairedDelimiterExtension` turns one opening and closing sequence into a safe
inline element:

```php
use Alto\Markdown\Extension\PairedDelimiter\PairedDelimiterExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new PairedDelimiterExtension(
    name: 'inserted',
    opening: '++',
    closing: '++',
    element: 'ins',
));

$html = $factory->toHtml("Keep ++this text++.\n");
```

The result contains `<ins>this text</ins>`. The extension name creates the
qualified kind `inserted:span`. Opening and closing delimiters may differ.
Each contains 1 through 16 non-whitespace bytes, and the opening byte must be
available in the active profile. Alto rejects parser-owned bytes such as `*`,
`_`, and `[`.

This helper intentionally creates a leaf. Its content is escaped text, not
nested Markdown. Empty, multiline, and unclosed pairs stay literal. Parsing
stops at the first closing sequence. `toMarkdown()` preserves the exact
source, and direct, document, table-cell, and inline-only rendering use the
same compiled definition. Factories without the extension do not add its
opening byte to the inline scanner.

The generated element must be one of the supported safe inline elements,
including `span`, `ins`, `del`, `mark`, `sub`, `sup`, `kbd`, and semantic text
elements. Use a custom `InlineExtensionInterface` implementation when output
needs attributes or application-specific rendering.

### Use smart punctuation

`SmartPunctuationExtension` replaces straight punctuation in visible text:

```php
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new SmartPunctuationExtension());
$html = $factory->toHtml(
    "\"Alto\" turns three dots... and dash runs -- into typography.\n",
);
```

Three consecutive dots and the spaced form `. . .` become one ellipsis.
Two hyphens become an en dash and three become an em dash. Longer hyphen runs
use the same deterministic grouping. Straight single and double quotes use
their surrounding Unicode whitespace and punctuation to select an opener or
closer. Apostrophes inside words become closing single quotes.

Quote marks are configurable without changing the ellipsis and dash rules:

```php
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationExtension;
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationPolicy;

$extension = new SmartPunctuationExtension(
    new SmartPunctuationPolicy(
        doubleQuoteOpener: '«',
        doubleQuoteCloser: '»',
        singleQuoteOpener: '‹',
        singleQuoteCloser: '›',
    ),
);
```

Configured marks must be non-empty valid UTF-8 and are escaped as text during
HTML rendering. Code spans, raw HTML tags, and backslash-escaped punctuation
stay literal. `toMarkdown()` preserves the authored punctuation. The
typographic characters are the semantic text seen by plain-text extraction.
Factories without the extension add no punctuation triggers to the inline
scanner.

### Add highlights

`HighlightExtension` recognizes a closed, single-line `==text==` span:

```php
use Alto\Markdown\Extension\Highlight\HighlightExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new HighlightExtension());
$html = $factory->toHtml("Read the ==important part== first.\n");
```

The result is `<mark>important part</mark>`. The content is escaped text, not a
nested Markdown container, so `==**important**==` keeps the asterisks inside
the `<mark>` element. Empty spans, whitespace next to a delimiter, triple
equals, multiline spans, and unclosed spans stay literal.

The parser is compiled only into factories that enable the extension. It is
called only at an equals byte and performs no renderer lookup when no highlight
is accepted.

### Add description lists

`DescriptionListExtension` adds Markdown Extra style terms and definitions:

```php
use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new DescriptionListExtension());
$html = $factory->toHtml(
    "Alto\n"
    .": A Markdown document engine.\n",
);
```

The result is one `<dl>` containing a `<dt>` and `<dd>`. Consecutive term lines
become separate terms. A term can have multiple definitions, and consecutive
term groups remain in the same list. Terms support inline Markdown. Definitions
support nested blocks when continuation lines reach the content column opened
by the `: ` marker.

A colon without preceding term content, without following whitespace, or
indented by more than three columns stays ordinary Markdown. The extension
adds its three block constructs only to factories that enable it.

### Add footnotes

`FootnoteExtension` adds references and block definitions without changing the
stored Markdown:

```php
use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new FootnoteExtension());
$html = $factory->toHtml(
    "See the compatibility notes[^compat].\n\n"
    ."[^compat]: Alto keeps the original source.\n",
);
```

References become numbered links in first-use order. The matching definitions
move into one footnote list after the rendered document. Repeated references
share a number and receive separate backlinks. An unused definition is hidden,
and a reference without a rendered definition stays literal.

Labels contain 1 through 128 bytes and exclude whitespace, `^`, and `]`. A
definition starts at up to three columns of indentation with
`[^label]: `. Indent continuation blocks by at least four columns to include
lists, quotes, or code. Definition content uses the active Markdown profile.

Whole-document direct and document rendering produce the same HTML.
`toMarkdown()` preserves the original references and definitions. Inline-only
rendering keeps references literal because it has no document definition
catalog. Rendering a node or section also keeps a reference literal when its
definition is outside that fragment, so Alto does not emit a broken link.

### Add nested tabs

`TabsExtension` groups fully parsed Markdown panels behind deterministic
same-document links:

```php
use Alto\Markdown\Extension\Tabs\TabsExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new TabsExtension());
$html = $factory->toHtml(
    "@tabs\n"
    ."@tab PHP\n"
    ."Run **Composer**.\n"
    ."@tab JavaScript\n"
    ."Run `npm install`.\n"
    ."@endtabs\n",
);
```

Each `@tab` starts a panel. Its title is plain escaped text, while its body uses
the active Markdown profile. The opening `@tabs`, each `@tab`, and the closing
`@endtabs` accept up to three columns of indentation. An unclosed group ends at
the end of its container. Content before the first valid `@tab` is omitted from
HTML but remains in the source. An empty group emits no HTML.

Add one `@` at each nested level so a closing marker is unambiguous:

```markdown
@tabs
@tab Framework
@@tabs
@@tab Symfony
Nested **Markdown**.
@@tab Laravel
Another panel.
@@endtabs
@tab Runtime
PHP 8.4+
@endtabs
```

The generated IDs depend only on document order. Alto emits no script and does
not hide panels. Without CSS or JavaScript, every panel stays readable and the
tab labels link to it. Applications can progressively enhance
`markdown-tabs`, `markdown-tabs-list`, `markdown-tabs-tab`,
`markdown-tabs-panels`, and `markdown-tabs-panel`. `toMarkdown()` preserves the
original directives and bytes.

### Configure mentions

`MentionExtension` turns configured prefixes and identifiers into safe links:

```php
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(new MentionExtension(
    MentionDefinition::links(
        type: 'user',
        prefix: '@',
        pattern: '[A-Z0-9](?:[A-Z0-9-]{0,38})(?![A-Z0-9-])',
        urlTemplate: 'https://github.com/%s',
    ),
    MentionDefinition::links(
        type: 'issue',
        prefix: '#',
        pattern: '\d+(?!\d)',
        urlTemplate: 'https://github.com/acme/project/issues/%s',
    ),
));

$html = $factory->toHtml("Ask @octocat about #42.\n");
```

Patterns are case-insensitive PCRE fragments anchored immediately after the
prefix. They run only when the compiled trigger byte occurs. Set a bounded
`maxIdentifierBytes` when the default 128-byte limit is too broad.

Use a `MentionResolver` when a URL template is not enough. It receives a typed
`Mention` and returns a `MentionTarget` with a URL and optional label or title.
Returning `null` declines the candidate and lets the next definition sharing
that trigger try. Template resolvers percent-encode identifiers.

A mention cannot start immediately after a word character. It preserves its
original Markdown, contributes its label to plain text and image alt text, and
never creates a nested anchor inside a Markdown link. Its URL always passes
through the active `HtmlPolicy`.

### Control rendered links

`ExternalLinkExtension` applies one host policy to Markdown links, autolinks,
mentions, and custom inline links that declare `InlineLinkSemantics`:

```php
use Alto\Markdown\Extension\ExternalLink\ExternalLinkExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkPolicy;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkScope;
use Alto\Markdown\Markdown;

$policy = new ExternalLinkPolicy(
    internalHosts: ['example.com'],
    includeSubdomains: true,
    openInNewWindow: true,
    htmlClass: 'external',
    nofollow: ExternalLinkScope::External,
);

$factory = Markdown::github()->with(new ExternalLinkExtension($policy));
$html = $factory->toHtml(
    '[Guide](/guide) [Account](https://example.com/account) '
    .'[Source](https://code.example.net/project)',
);
```

By default, external links receive `rel="noopener noreferrer"`. Relative
destinations, fragments, email links, and other destinations without a host
are not classified. `internalHosts` uses exact, case-insensitive host matches.
Set `includeSubdomains` explicitly to include their subdomains. IP addresses
remain exact even when that option is enabled.

`ExternalLinkScope::None`, `All`, `Internal`, and `External` control each
`nofollow`, `noopener`, and `noreferrer` token independently. The class and
`target="_blank"` apply only to external links.

### Adjust rendered heading levels

`HeadingLevelExtension` changes heading levels in HTML without rewriting the
Markdown document:

```php
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelExtension;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelPolicy;
use Alto\Markdown\Markdown;

$factory = Markdown::commonmark()->with(
    new HeadingLevelExtension(HeadingLevelPolicy::shift(1)),
);

$html = $factory->toHtml("# Page title\n"); // <h2>Page title</h2>
```

Choose one explicit strategy:

```php
$selected = HeadingLevelPolicy::map([1 => 2, 2 => 4]);
$shifted = HeadingLevelPolicy::shift(-1);
$dynamic = HeadingLevelPolicy::using(
    static fn (int $level): ?int => 2 === $level ? null : min(6, $level + 1),
);
```

`map()` changes only listed levels. `shift()` accepts offsets from `-5` to
`5`. It throws when a heading encountered during rendering would fall outside
levels 1 through 6 instead of silently clamping it. `using()` receives the
effective level produced by earlier transforms. Return `null` to leave that
heading unchanged, or an integer from 1 through 6.

The projection covers ATX and Setext headings at every nesting depth. It
applies to direct, document, node, and section HTML rendering. It does not run
for inline-only rendering and never changes source bytes, offsets, edits, or
diffs. Heading levels are projected before `TableOfContentsExtension`
catalogs them, regardless of extension registration order. Heading permalink
level filters also see the projected value.

Earlier Alto versions accepted one array containing `map`, `down`, or
`callback`. Use `map()`, `shift()`, or `using()` instead. The typed policy
removes ambiguous precedence and rejects invalid `h0` or `h7` output.

### Group heading sections

`ContentSlicerExtension` wraps root heading sections in semantic `<section>`
elements. It has no custom syntax and never changes the Markdown:

```php
use Alto\Markdown\Extension\ContentSlicer\ContentSlicerExtension;
use Alto\Markdown\Markdown;

$markdown = <<<'MARKDOWN'
# Guide

Introduction.

## Install

Installation details.

### Requirements

PHP 8.4 or newer.
MARKDOWN;

$html = Markdown::commonmark()
    ->with(new ContentSlicerExtension())
    ->toHtml($markdown);
```

The default `minLevel: 2` leaves h1 content at the document root, opens one
section at each h2, and nests deeper heading sections inside it. Use
`new ContentSlicerExtension(minLevel: 1)` to wrap h1 headings too, or a value
through `6` to select a deeper starting level. Skipped heading levels do not
create empty wrappers.

Only root headings define slices. A heading in a quote, list, tab, or another
container keeps its normal local meaning. Content before the first selected
heading stays at the root. A heading of the same or a shallower effective level
closes the active sections before opening its own section.

The slicer reads levels after `HeadingLevelExtension`, regardless of
registration order. Full direct and document rendering receive balanced
section wrappers. Isolated node and section rendering intentionally returns
only the requested fragment, without document-level shells. Included Markdown
is a separate render fragment and therefore receives its own section tree.

`HtmlPolicy::safe()` preserves the generated sections.
`HtmlPolicy::curated()` currently unwraps `<section>` while preserving its
heading and content. Use an audited application sanitizer when that semantic
wrapper must survive final sanitization.

### Add heading permalinks

`HeadingPermalinkExtension` adds stable, unique anchors to ATX and Setext
headings:

```php
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPosition;
use Alto\Markdown\Markdown;

$policy = new HeadingPermalinkPolicy(
    minLevel: 2,
    maxLevel: 4,
    position: HeadingPermalinkPosition::After,
    applyIdToHeading: true,
    headingClass: 'section-heading',
    fragmentPrefix: 'section',
    htmlClass: 'permalink',
    title: 'Link to this section',
    symbol: '#',
    ariaHidden: false,
);

$factory = Markdown::github()->with(new HeadingPermalinkExtension($policy));
$html = $factory->toHtml("## Install\n");
```

Slugs use the same GitHub-style normalization and duplicate suffixes as
Alto's anchor linting. For example, two `## Install` headings receive
`install` and `install-1`. The index covers the complete document, so rendering
one heading or section produces the same slug as rendering the full document.
Headings outside `minLevel` and `maxLevel` still reserve their slug in that
index. This keeps permalink IDs aligned with anchor linting and generated
tables of contents even when only selected heading levels receive links.

By default, the anchor appears before the heading content with an ID and
fragment prefix of `content`, class `heading-permalink`, title `Permalink`,
symbol `¶`, and `aria-hidden="true"`. Set `applyIdToHeading` to put the ID on
the heading instead. `HeadingPermalinkPosition::None` can apply only the
heading ID and class without inserting a link.

### Add a table of contents

`TableOfContentsExtension` renders each valid `@toc` marker from the headings
in the complete document:

```php
use Alto\Markdown\Extension\TableOfContents\TableOfContentsExtension;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsPolicy;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsStyle;
use Alto\Markdown\Markdown;

$policy = new TableOfContentsPolicy(
    minLevel: 2,
    maxLevel: 4,
    style: TableOfContentsStyle::Ordered,
    htmlClass: 'page-contents',
    id: 'contents',
    title: 'On this page',
);

$markdown = <<<'MARKDOWN'
# Guide

@toc {max: 3, ordered: false}

## Install

### Requirements
MARKDOWN;

$html = Markdown::commonmark()
    ->with(new TableOfContentsExtension($policy))
    ->toHtml($markdown);
```

The marker must occupy column zero and contain only optional trailing spaces
or one strict option object. `min` and `max` accept levels 1 through 6.
`ordered` accepts only `true` or `false`. Per-marker options override the
policy for that marker. Unknown, duplicate, quoted, malformed, or contradictory
options leave the line as literal Markdown.

Only root-level headings enter the list. Rich heading content becomes plain
link text, while its normal HTML remains unchanged. Duplicate headings receive
the same stable suffixes as Alto's GitHub-style slug index. Multiple markers
reuse one document catalog and receive wrapper IDs such as `contents` and
`contents-1`. Rendering a node or section still resolves headings against the
complete document. Markdown output, source ranges, edits, and diffs are never
changed by the generated list.

Enabling the extension assigns IDs to all ATX and Setext heading elements,
even when the document has no `@toc` marker. This matches the historical Alto
extension contract and ensures every generated link has a target. When
`HeadingPermalinkExtension` already assigns the same heading ID, it is reused.
When its configured ID differs, Alto emits a separate target span instead of
placing two `id` attributes on one heading.

Historical Alto emitted a `<div>` wrapper. This port intentionally emits the
semantic `<nav>` element with a valid nested `<ul>` or `<ol>`. Applications
that styled `.table-of-contents` keep the default class, but selectors tied to
the old wrapper tag must be updated.

The default `HtmlPolicy::safe()` preserves this generated navigation.
`HtmlPolicy::curated()` keeps its list content but removes the `<nav>` wrapper
and every target `id`, so its fragment links are not interactive. Use the spec
policy only for trusted input, or provide an audited application sanitizer
that explicitly preserves the required elements and attributes.

### Add constrained source attributes

`AttributesExtension` lets Markdown authors attach an allowed set of
attributes to generated elements:

```php
use Alto\Markdown\Extension\Attributes\AttributesExtension;
use Alto\Markdown\Extension\Attributes\AttributesPolicy;
use Alto\Markdown\Markdown;

$attributes = new AttributesExtension(new AttributesPolicy([
    'id',
    'class',
    'title',
    'lang',
]));

$markdown = <<<'MARKDOWN'
{#install .lead title="Install Alto"}
# Installation

Read **the guide**{.important}.
MARKDOWN;

$html = Markdown::commonmark()
    ->with($attributes)
    ->toHtml($markdown);
```

The default policy allows only `id` and `class`. A policy can add other names,
but event attributes such as `onclick` can never be enabled. One list accepts
at most 32 attributes and 1024 bytes by default, with values bounded to 512
bytes. Applications can lower those limits in `AttributesPolicy`.

Use `{#id .class key=value}` immediately before a block to target the next
block. Put `{: .class}` immediately after a block to target the previous one:

```markdown
{#intro .lead}
## Introduction

Important paragraph.
{: .notice}
```

Consecutive attribute lines merge in source order. Inline lists attach only
to the previous rendered inline element, so `**important**{.accent}` works but
`plain{.accent}` remains literal Markdown. Existing attributes generated from
Markdown win, while classes merge without duplicates.

Attribute lists are rendering metadata. Exact and normalized Markdown output
keep their source syntax. Raw HTML, front matter, and link reference
definitions cannot be targets. Generated block wrappers from other compiled
extensions can be targets. `href` and `src` pass through the active URL policy.
Other values are escaped for their attribute context, and
`HtmlPolicy::curated()` can remove names or values outside its final allowlist.

### Add default HTML attributes

`DefaultAttributesExtension` adds static defaults to HTML elements generated
by native syntax:

```php
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Markdown;

$attributes = new DefaultAttributesExtension([
    'paragraph' => [
        'class' => ['prose', 'content'],
        'data-controller' => 'copy',
    ],
    'link' => [
        'class' => 'content-link',
        'rel' => 'author',
    ],
    'fenced-code' => [
        'class' => ['code', 'copyable'],
        'data-copy' => true,
    ],
]);

$html = Markdown::commonmark()
    ->with($attributes)
    ->toHtml("Read [Alto](/docs).\n\n```php\ncode();\n```\n");
```

Configuration keys are Alto native kinds, not parser classes. Supported kinds
are `paragraph`, both heading kinds, both code-block kinds, `block-quote`,
`list`, `list-item`, `thematic-break`, `hard-break`, `code-span`, `emphasis`,
`strong`, `link`, `image`, `autolink`, and the profile-specific
`strikethrough`, `gfm:table`, and `github:alert`.

Values are strings or booleans. Only `class` also accepts a list of strings.
Class names are deduplicated in stable order and are placed before classes
already generated by Alto. Existing native attributes win, so a configured
`href`, image `src`, ordered-list `start`, or code language cannot replace the
value derived from Markdown. `true` emits a boolean attribute and `false`
omits it. Raw HTML, plain text, soft breaks, and reference definitions do not
own a generated element and cannot receive defaults.

Configured `href` and `src` values pass through the active URL allowlist. All
other names and values are escaped as attributes. Configuration is trusted PHP
code, so `style` and event attributes are allowed under `safe()` and `spec()`.
Use `HtmlPolicy::curated()` when the completed output must remove those
attributes. Other URL-bearing attribute names are treated as trusted values,
not URL-policy inputs.

### Add code-block titles

`CodeBlockTitleExtension` turns a fenced code title into a semantic figure:

````php
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitleExtension;
use Alto\Markdown\Markdown;

$markdown = <<<'MARKDOWN'
```php title="src/App.php"
echo 'Hello';
```
MARKDOWN;

$html = Markdown::commonmark()
    ->with(new CodeBlockTitleExtension())
    ->toHtml($markdown);
````

The first info-string word remains the CommonMark language. Put `title` or
`filename` after it. Values may use double quotes, single quotes, or no quotes
when they contain no spaces:

````markdown
```php filename='src/App.php'
echo 'Hello';
```
````

`title` wins over `filename`, independent of their order. A later duplicate of
the same name wins. Malformed metadata, an over-limit info string, or a missing
title leaves the normal `<pre><code>` output unchanged. The scanner is linear,
reads at most 4096 info bytes and 64 attributes, and accepts titles up to 512
bytes by default. `CodeBlockTitlePolicy` configures those limits, the figure
and caption classes, and the optional `data-title` attribute.

Titles pass through CommonMark info-string escape and entity decoding, then
are escaped separately as text and as an attribute. Code remains opaque: the
extension never parses or rewrites its contents. It works inside lists and
block quotes, preserves Markdown bytes, and composes with default attributes
on the inner `<code>` element.

### Rewrite link destinations

Build one typed pipeline, then choose whether it changes HTML output or
Markdown source:

```php
use Alto\Markdown\Extension\LinkRewrite\LinkRewriter;
use Alto\Markdown\Extension\LinkRewrite\LinkRewriterExtension;
use Alto\Markdown\Markdown;

$rewriter = LinkRewriter::compose(
    LinkRewriter::baseUri('https://docs.example.com'),
    LinkRewriter::map([
        'https://docs.example.com/old' => 'https://docs.example.com/new',
    ]),
);

$markdown = '[Guide](/old)';

// Render-only: the Markdown source is unchanged.
$html = Markdown::commonmark()
    ->with(new LinkRewriterExtension($rewriter))
    ->toHtml($markdown);

// Source mutation: explicit, inspectable, and independent of the extension.
$document = Markdown::commonmark()->fromString($markdown);
$result = $rewriter->rewriteDocument($document);
$changedMarkdown = $document->toMarkdown();
```

`baseUri()` is a prefix operation, not document-relative RFC 3986 resolution.
It maps both `/guide` and `guide` to `{base}/guide`. It leaves empty,
fragment-only, query-only, scheme-relative, and absolute destinations
unchanged. `map()` matches complete destinations. `pattern()` validates its
PCRE expression when constructed. `callback()` receives an immutable
`LinkDestinationContext` with the node kind, source, range when available, and
the current destination.

Context destinations follow the public `Link::destination()` contract: they
are already percent-encoded. A rewritten result is encoded once before output.
The active HTML URL policy runs after rewriting. External-link policy and
default-attribute decorators therefore see the final destination.

The render-only extension covers links, images, autolinks, and custom inline
nodes that declare link semantics. `rewriteDocument()` deliberately mutates
only inline Markdown links and images. It plans every edit before applying
one, refuses documents with pending edits, and preserves unrelated bytes.
Reference-style destinations are skipped because changing a shared definition
is a separate operation. Nested link and image ranges are also skipped because
their minimal source patches overlap. `LinkRewriteResult` reports rewritten,
unchanged, skipped-reference, and skipped-overlap counts.

Treat render-only conversion and source mutation as alternatives for a given
workflow. Running a non-idempotent rule in both places rewrites the destination
twice.

### Project a document for HTML rendering

A document transform changes the HTML view of a complete block tree without
editing its Markdown. This example caps rendered heading levels at three:

```php
use Alto\Markdown\Extension\Document\DocumentTransform;
use Alto\Markdown\Extension\Document\DocumentTransformContext;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Markdown;

final readonly class HeadingBandExtension implements DocumentTransformExtensionInterface
{
    public function name(): string
    {
        return 'heading-band';
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            name: 'cap-level',
            factory: static fn (): HeadingBandTransform => new HeadingBandTransform(),
        );
    }
}

final readonly class HeadingBandTransform implements DocumentTransform
{
    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            if ($heading->level > 3) {
                $context->overrideHeadingLevel($heading, 3);
            }
        }
    }
}

$factory = Markdown::commonmark()->with(new HeadingBandExtension());
$document = $factory->fromString("##### Detail\n");

$html = $document->toHtml();       // <h3>Detail</h3>
$markdown = $document->toMarkdown(); // ##### Detail
```

`headings()` returns lazy, memoized views with the native kind, original
source range, nesting depth, and original level. `blocks()` provides the same
semantic view for every block without building the heading view. These values
do not expose a mutable document, parser tape, node ordinal, or internal kind
ID.

Transform planning runs at the start of each block-tree HTML render entrypoint.
Local projections can cover direct conversion, document rendering, and
isolated node or section rendering. A document-wide layout can intentionally
apply wrappers only to complete document output so partial renders remain
balanced. `toInlineHtml()` has no document tree and does not run transforms.
The source, offsets, edit journal, diff, and `toMarkdown()` result remain
unchanged.

Definitions run by ascending `order`. Equal values keep extension registration
order and then definition order. A fresh transformer is created for each
render, and a later heading-level override wins. Levels must be from 1 to 6.
Exceptions propagate before HTML is returned.

Use a document transform for a semantic decision that depends on the complete
block tree. Use an HTML decorator for local attributes or wrappers. Use an
explicit document operation when the Markdown source itself must change.
`$context->rendersCompleteDocument()` lets a transform skip document-wide work
when the caller renders one node or one section.
Profiles without document transforms keep the normal render path; an extension
that yields no definitions compiles to no runtime transform state.

### Decorate native HTML

Use a decorator when syntax stays standard Markdown but selected native HTML
nodes need application-specific output. This example adds an attribute to
rendered links without replacing link parsing or exposing Alto's internal
syntax tape:

```php
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;
use Alto\Markdown\Markdown;

final readonly class HttpsRelExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'external-links';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('link', new HttpsRelDecorator());
    }
}

final readonly class HttpsRelDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        if (!str_starts_with($context->string('destination'), 'https://')) {
            return $html;
        }

        return str_replace('<a ', '<a rel="noreferrer" ', $html);
    }
}

$html = Markdown::commonmark()
    ->with(new HttpsRelExtension())
    ->toHtml('[Alto](https://example.com)');
```

Definitions target a native kind such as `link`, `image`, `atx-heading`,
`setext-heading`, `fenced-code`, `list`, or `gfm:table`. A profile must provide
the selected kind. Unknown and custom kinds are rejected when the profile is
compiled. Custom syntax already owns its registered renderer. Use
`HtmlDecoratorDefinition::linkLike()` for one policy shared by native links,
autolinks, and custom inline links with declared link semantics. Use
`HtmlDecoratorDefinition::heading()` for one document-wide heading policy
shared by ATX and Setext headings.

Lower priorities run first. Equal priorities keep factory registration order.
The decorator receives the current HTML, the native kind, its source span, and
typed semantic values such as link `destination`, heading `level`, or code
`language` and `info`. Its return value becomes the input of the next
decorator.

Decorator output is trusted application HTML, like custom renderer output.
Escape dynamic values through the context helpers. `escapeUrl()` also applies
the active URL policy. A curated policy sanitizes the completed fragment after
all decorators have run.

### Add a lint rule

An installed extension can also contribute opt-in lint rules. Rule names are
local; Alto qualifies this one as `callout-policy:lowercase-label`.

```php
use Alto\Markdown\Extension\LintExtensionInterface;
use Alto\Markdown\Extension\Lint\LintContext;
use Alto\Markdown\Extension\Lint\LintDiagnostic;
use Alto\Markdown\Extension\Lint\LintFix;
use Alto\Markdown\Extension\Lint\LintRule;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Source\SourceRange;

final readonly class CalloutLintExtension implements LintExtensionInterface
{
    public function name(): string
    {
        return 'callout-policy';
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition(
            name: 'lowercase-label',
            summary: 'Require lowercase callout labels.',
            factory: static fn (): LowercaseCalloutLabelRule => new LowercaseCalloutLabelRule(),
            fixable: true,
        );
    }
}

final readonly class LowercaseCalloutLabelRule implements LintRule
{
    public function check(LintContext $context): iterable
    {
        if (1 !== preg_match(
            '/^:{3,}([A-Z][A-Z0-9-]*)/m',
            $context->source(),
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return;
        }

        [$label, $offset] = $matches[1];
        $range = new SourceRange($offset, $offset + strlen($label));

        yield new LintDiagnostic(
            'Callout label must be lowercase.',
            $range,
            LintFix::replace($range, strtolower($label), 'lowercase callout label'),
        );
    }
}

$config = (new LintConfig())->withRule('callout-policy:lowercase-label');
$document = Markdown::commonmark()
    ->with(new CalloutLintExtension())
    ->fromString(":::NOTE\nBody\n:::\n");

$report = $document->lint($config);
$document->fix($config);
```

Rules receive a read-only source and immutable block or inline snapshots. They
return diagnostics and optional byte replacements. They never receive the
mutable document model, edit journal, node IDs, or arbitrary operations. Alto
validates every range and applies declared safe fixes through its normal
conflict-checked patch plan.

### Add formatting and stats

An extension can contribute formatter passes and scalar statistics. These
interfaces can live on `CalloutExtension`; the separate class below keeps the
example focused and is installed beside it. It works with the
`example:callout` block defined above:

```php
use Alto\Markdown\Extension\Formatter\FormatterContext;
use Alto\Markdown\Extension\Formatter\FormatterEdit;
use Alto\Markdown\Extension\Formatter\FormatterPass;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\FormatterExtensionInterface;
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsMetric;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Source\SourceRange;

final readonly class CalloutToolsExtension implements
    FormatterExtensionInterface,
    StatsExtensionInterface
{
    public function name(): string
    {
        return 'callout-tools';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition(
            name: 'lowercase-label',
            summary: 'Normalize callout labels to lowercase.',
            factory: static fn (): LowercaseCalloutLabelPass => new LowercaseCalloutLabelPass(),
        );
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition(
            name: 'callouts',
            summary: 'Count callout blocks.',
            factory: static fn (): CalloutCount => new CalloutCount(),
        );
    }
}

final readonly class LowercaseCalloutLabelPass implements FormatterPass
{
    public function format(FormatterContext $context): iterable
    {
        foreach ($context->blocks() as $block) {
            if ('example:callout' !== $block->kind) {
                continue;
            }

            if (1 !== preg_match(
                '/^(:{3,})([A-Za-z][A-Za-z0-9-]*)/',
                $context->slice($block->range),
                $matches,
                PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            [$label, $relativeOffset] = $matches[2];
            $replacement = strtolower($label);

            if ($label === $replacement) {
                continue;
            }

            $start = $block->range->startOffset + $relativeOffset;

            yield FormatterEdit::replace(
                new SourceRange($start, $start + strlen($label)),
                $replacement,
                'lowercase callout label',
            );
        }
    }
}

final readonly class CalloutCount implements StatsMetric
{
    public function measure(StatsContext $context): int
    {
        $count = 0;

        foreach ($context->blocks() as $block) {
            $count += (int) ('example:callout' === $block->kind);
        }

        return $count;
    }
}
```

Formatter passes receive one immutable source snapshot plus block snapshots.
Set `includeInlines: true` on a definition only when its pass needs inline
snapshots. Passes are evaluated after built-in passes, then by ascending
`order` and qualified ID. Every pass proposes edits against the same original
source, so passes must not depend on another pass rewriting their input. Alto
validates their byte ranges and sends all edits through the normal overlap and
journal checks.

Stats metrics share the traversal already required by core statistics. Metric
IDs are qualified and sorted, so this example appears as
`$document->stats()->extensionStats['callout-tools:callouts']`. Values are
limited to finite scalar values or `null`. Set `includeInlines: true` only when
the metric needs inline snapshots. Formatter and metric factories create a
fresh service instance for each operation.

## Install and use it

`with()` derives a new immutable factory. The original factory is unchanged,
and the configured factory can parse several documents safely.

Extensions are executable PHP code and must be installed as trusted
application dependencies. Markdown input cannot discover, load, or enable an
extension.

The direct and document HTML lanes use the same registered renderer.
`toMarkdown()` preserves unchanged source bytes. Passing `RenderOptions`
explicitly asks the registered Markdown printer for normalized output.

HTML renderers must escape extension-owned text, attributes, and URLs through
the context helpers. The selected final `HtmlPolicy`, including its optional
full-fragment sanitizer, also applies to extension output. Nested block
Markdown arrives as the already rendered `$children` string.

## Supported boundary

The current public extension API supports complete custom block and leaf
inline slices:

- Ordered factory composition.
- Container and leaf block parsing.
- Leaf inline parsing through one-byte compiled triggers.
- Original byte ranges for source-backed input, with explicit null ranges for
  detached normalized inline input.
- Scalar custom inline attributes and semantic plain text.
- Compiled, ordered native and semantic-link HTML decorators with typed
  read-only context.
- Qualified string block queries and inline traversal snapshots.
- Direct and document-backed HTML rendering.
- Source-preserving and normalized Markdown output.
- Read-only custom lint rules with qualified IDs.
- Validated replace, insert, and delete fixes.
- Source-preserving formatter passes with deterministic qualified ordering.
- Scalar stats metrics collected during the existing stats traversal.

Inline extensions cannot introduce inline containers or participate in core
emphasis-style delimiter processing. `PairedDelimiterExtension` provides
bounded closed leaf slices without changing that parser boundary. Custom
feature labels are not public extension points yet.
Built-in profiles still provide CommonMark, GFM, GitHub alerts, and front
matter. Decorators change HTML only. They do not change Markdown serialization,
source ranges, query results, or saved Markdown.

The compiler keeps extension iteration outside parser and renderer hot paths.
Core-only inline parsing never performs a custom renderer lookup. Profiles
without decorators perform no decorator dispatch. A semantic link policy also
performs no dispatch until a link is rendered. Formatting and stats perform no
contribution lookup per node when no matching capability is installed. Custom
APIs never expose `ParserState`, `ParseTape`, profile-local IDs, mutable
document internals, or a third-party AST.
