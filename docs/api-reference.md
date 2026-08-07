# API reference

This page maps Alto's stable public surface. Start with
[Installation](installation.md) or [Conversion](conversion.md) for a guided
first use. Types marked `@internal` in source are implementation details and
are not part of this contract.

## Create and render

`Markdown` creates a factory for one language profile:

| Method | Result |
| --- | --- |
| `Markdown::commonmark()` | Portable CommonMark factory |
| `Markdown::gfm()` | CommonMark plus GFM factory |
| `Markdown::github()` | GFM plus GitHub alerts and front matter |

Each `MarkdownFactory` provides:

| Method | Purpose |
| --- | --- |
| `profile()` | Return the compiled `Profile` |
| `with(...$extensions)` | Derive a factory with trusted extensions |
| `toHtml($source, $parseOptions, $renderOptions)` | Convert once without a document workspace |
| `toInlineHtml($source, $parseOptions, $renderOptions)` | Render only inline syntax, without a wrapper |
| `fromString($source, $parseOptions)` | Create an editable in-memory document |
| `open($path, $parseOptions)` | Open an editable file |
| `builder()` | Build a complete document |
| `fragment()` | Build content for insertion |

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;

$factory = Markdown::github();
$parse = new ParseOptions(maxSourceBytes: 1_000_000);
$render = new RenderOptions(htmlPolicy: HtmlPolicy::safe());

$html = $factory->toHtml("# Guide\n", $parse, $render);
$document = $factory->fromString("# Guide\n", $parse);
```

`ParseOptions` controls parser resource limits. In `RenderOptions`,
`HtmlPolicy` applies to HTML output, while a target profile and `MarkdownStyle`
apply to normalized Markdown output. See [Profiles](profiles.md),
[Security](security.md), and [Performance](performance.md).

## Inspect and change documents

`MarkdownDocument` is the main workspace:

| Method | Purpose |
| --- | --- |
| `profile()` | Return the document profile |
| `frontMatter()` | Return leading front matter or `null` |
| `title()` | Return the first level-one heading or `null` |
| `headings($level)` | Lazily select headings |
| `section($title)` | Select one section, with `exists()` for absence |
| `sections($title)` | Lazily select all matching sections |
| `links()` and `images()` | Lazily select inline links or images |
| `codeBlocks($language)` | Lazily select fenced and indented code blocks |
| `query()` | Start a generic node-kind query |
| `ensure()` | Add required sections idempotently |
| `lint($config)` | Return a `LintReport` |
| `fix($config)` | Apply safe fixes to the journal |
| `format($style)` | Apply deterministic formatting |
| `stats()` | Return immutable `DocumentStats` |
| `toHtml($options)` | Render the current document |
| `toMarkdown($options)` | Export edited source or normalized Markdown |
| `hasChanges()` | Test whether the journal contains edits |
| `diff()` | Return the pending `Diff` |
| `model()` | Return the low-level `DocumentModel` view |

`MarkdownFile` adds:

| Method | Purpose |
| --- | --- |
| `path()` | Return the current target path |
| `save($options)` | Save to the current path |
| `saveAs($path, $options)` | Save to another path |

Collections are lazy, repeatable, iterable, and countable. They expose
`first()`, `all()`, and `filter()`. A `MarkdownQuery` combines one or more
`kind()` filters with `where()` predicates, then returns a collection through
`get()`.

All node handles expose `id()`, `kind()`, `range()`, and `exists()`. Source
ranges use original input byte offsets.

| Handle | Read methods | Mutations |
| --- | --- | --- |
| `Heading` | `level()`, `text()` | `rename()` |
| `Section` | `title()`, `exists()` | `rename()`, `remove()`, `append()`, `prepend()`, `replaceBody()` |
| `CodeBlock` | `language()`, `code()` | `setLanguage()`, `replaceCode()` |
| `Link` | `text()`, `destination()`, `titleAttribute()` | `setDestination()`, `setTitleAttribute()` |
| `Image` | `altText()`, `destination()`, `titleAttribute()` | `setDestination()`, `setAltText()` |
| `FrontMatter` | `text()`, `content()`, `fence()`, `decode()` | `replaceContent()` |
| `Block` | Common handle methods | `insertBefore()`, `insertAfter()`, `moveBefore()`, `moveAfter()`, `cloneBefore()`, `cloneAfter()`, `replaceWith()`, `wrap()` |

A top-level block is a direct child of the document root. General block
insertion, movement, cloning, replacement, and wrapping are limited to those
blocks in V1. Queries and typed accessors can still return nested blocks. A
heading handle represents only the heading block; a `Section` represents its
heading and following body.

Structural edits and saves can retire handles. Query the document again after a
save or rebase. See [Queries and stats](queries-and-stats.md),
[Manipulation](manipulation.md), and [Errors](errors.md).

## Build, analyze, and configure

`MarkdownBuilder` creates headings, paragraphs, code blocks, ordered and
unordered lists, blockquotes, and thematic breaks. Call `document()` for a
workspace or `toMarkdown()` for serialized output.

`MarkdownFragmentBuilder` creates paragraphs, code blocks, and explicitly raw
Markdown. Call `toFragment()` before inserting the result into a section or
around a block.

Lint uses these public values:

| Type | Purpose |
| --- | --- |
| `LintConfig` | Immutable rule selection, severity overrides, and typed per-rule options |
| `LintRuleCategory` | Accessibility, code, links, structure, or style |
| `LintRuleMetadata` | Discoverable category, defaults, fixability, and option schema |
| `RuleRegistry` | Built-in rule catalog and metadata lookup |
| `Linter` | Reuse one lint policy across documents |
| `LintReport` | Iterable, countable collection with `isClean()` and `hasErrors()` |
| `LintProblem` | Rule ID, message, severity, source range, and optional fix |

Rendering and persistence use:

| Type | Purpose |
| --- | --- |
| `MarkdownStyle` | List markers, fences, table and reference spacing, final newline, blank lines, and heading spacing |
| `BlockWrapper` | Safe block quote, bullet-item, or ordered-item wrapper |
| `RenderOptions` | Target profile, Markdown style, and HTML policy |
| `HtmlPolicy` | Safe, curated, spec, or custom full-fragment sanitization |
| `SaveOptions` | Atomic write, BOM, EOL, conflict detection, and final-component symlink policy |
| `SymlinkPolicy` | Reject final-component links by default or explicitly follow them |
| `Diff` | Empty state, unified text, and structured hunks |

External-resource services are separate from profiles and syntax:

| Type | Purpose |
| --- | --- |
| `ResourceResolver` | Resolve one explicit typed resource request |
| `ResourceRequest` | Reference, purpose, and optional opaque origin ID |
| `ResolvedResource` | Stable opaque ID and returned bytes |
| `FilesystemResourceResolver` | Confine bounded regular-file reads to one root and extension allowlist |
| `CallbackResourceResolver` | Adapt an application-owned resolver callback |

Front matter remains opaque unless one of these explicit application services
is passed to `FrontMatter::decode()`:

| Type | Purpose |
| --- | --- |
| `FrontMatterDecoder` | Decode current content bytes and their `---` or `+++` fence |
| `CallbackFrontMatterDecoder` | Adapt one typed application callback as a decoder |

Trusted extensions compose capabilities through `MarkdownFactory::with()`.
`BlockExtensionInterface` and `InlineExtensionInterface` add syntax,
`HtmlDecoratorExtensionInterface` changes selected native or semantic link
HTML output, `DocumentTransformExtensionInterface` projects a complete block
tree for HTML rendering, and the lint, formatter, and stats interfaces add
analysis. HTML decorators receive `HtmlNodeOutputContext`, not a mutable
syntax tree.

Render-only document projection uses:

| Type | Purpose |
| --- | --- |
| `DocumentTransformExtensionInterface` | Yield ordered transform definitions |
| `DocumentTransformDefinition` | Name and construct one fresh transform per render |
| `DocumentTransform` | Apply one render-only semantic transformation |
| `DocumentTransformContext` | Lazily read blocks or headings and override rendered heading levels |
| `DocumentTransformBlock` | Immutable kind, source range, and nesting depth |
| `DocumentTransformHeading` | Immutable heading view with its original level |

Alto also ships configurable output and syntax extensions:

| Type | Purpose |
| --- | --- |
| `PairedDelimiterExtension` | Map one configurable leaf delimiter pair to a safe inline HTML element |
| `SmartPunctuationExtension` | Replace straight quotes, ellipses, and hyphen runs in visible inline text |
| `SmartPunctuationPolicy` | Configure the four opening and closing quote strings |
| `HighlightExtension` | Render closed, single-line `==text==` spans as escaped `<mark>` elements |
| `DescriptionListExtension` | Parse Markdown Extra style terms and definitions into `<dl>`, `<dt>`, and `<dd>` |
| `FootnoteExtension` | Number `[^label]` references and append referenced definitions with backlinks |
| `TabsExtension` | Render nested, source-preserving Markdown tab groups with deterministic fragment links |
| `AttributesExtension` | Attach policy-constrained source attributes to generated block and inline elements |
| `AttributesPolicy` | Allow attribute names and bound list, value, and count limits |
| `MentionExtension` | Compile one or more mention definitions into a factory |
| `MentionDefinition` | Define a type, prefix, identifier pattern, resolver, and byte limit |
| `MentionResolver` | Resolve a typed `Mention` to a target or decline it |
| `MentionTarget` | Provide the URL and optional rendered label and title |
| `UrlTemplateMentionResolver` | Build percent-encoded targets from one `%s` URL template |
| `ImportExtension` | Resolve column-zero imports into escaped code blocks during parsing |
| `IncludeExtension` | Resolve bounded root-level Markdown includes recursively |
| `IncludePolicy` | Bound recursive depth, resources, bytes, and nested parser work |
| `EmbedExtension` | Resolve root-level rich-content URLs through an application service |
| `EmbedPolicy` | Allow hosts and bound URL and resolved HTML bytes |
| `EmbedFallback` | Keep an unresolved embed as a link or remove it |
| `SourceExtension` | Render bounded source excerpts with path, title, line numbers, and highlights |
| `InlineLinkSemantics` | Declare destination and optional title attributes for a custom inline link |
| `ExternalLinkExtension` | Apply one host policy to native and declared custom links |
| `ExternalLinkPolicy` | Configure internal hosts, subdomains, class, target, and relation tokens |
| `ExternalLinkScope` | Select no links, all links, internal links, or external links |
| `HeadingLevelExtension` | Project ATX and Setext heading levels during HTML rendering |
| `HeadingLevelPolicy` | Map, shift, or resolve effective heading levels within 1 through 6 |
| `ContentSlicerExtension` | Group root heading ranges into nested semantic HTML sections |
| `HeadingPermalinkExtension` | Add document-wide unique anchors to ATX and Setext headings |
| `HeadingPermalinkPolicy` | Configure levels, placement, IDs, classes, prefixes, title, and symbol |
| `HeadingPermalinkPosition` | Insert before content, after content, or add no anchor |
| `TableOfContentsExtension` | Render strict column-zero `@toc` markers from root headings |
| `TableOfContentsPolicy` | Configure levels, list style, wrapper attributes, title, and marker |
| `TableOfContentsStyle` | Select bullet or ordered lists |
| `DefaultAttributesExtension` | Add escaped static defaults to native generated HTML elements |
| `CodeBlockTitleExtension` | Wrap titled fenced code in a figure and caption |
| `CodeBlockTitlePolicy` | Configure classes, data attributes, and parser limits |
| `LinkRewriter` | Compose URI-prefix, exact-map, regex, and callback destination rules |
| `LinkRewriterExtension` | Apply a rewriter to rendered semantic destinations only |
| `LinkDestinationContext` | Immutable kind, encoded destination, range, and source callback context |
| `LinkRewriteResult` | Count explicit source rewrites and conservative skips |

`DocumentStats` is an immutable summary of headings, sections, links, images,
code blocks, tables, visible text, reading time, and extension metrics. Its
complete field list is documented in
[Queries and stats](queries-and-stats.md#read-document-statistics).

Alto exceptions share `MarkdownExceptionInterface`. Recoverable families are
`ParseLimitException`, `FileException`, `ResourceResolutionException`, and
`InvalidExtensionException`. File failures expose their original application
path. Catch a precise subtype only when the application can take a more precise
recovery action. See [Errors](errors.md).

Custom syntax and analysis capabilities are documented in
[Extensions](extensions.md). Alto-owned interfaces are the public boundary;
third-party ASTs and mutable parser internals are never exposed. Public
versioning and package migration rules are documented in
[Extension compatibility](extension-compatibility.md).

An HTML block renderer may call
`HtmlBlockOutputContext::renderMarkdown($source, $parseOptions)` to render a
fragment with explicit parser limits, the current compiled profile, and the
active HTML policy. The current custom block kind is disabled inside that
fragment to prevent accidental self-recursion. The fragment is isolated from
the outer document tree and its document-wide render projections.

`HtmlBlockOutputContext::allowsRawHtml()` lets a block renderer honor the
active raw HTML policy before returning trusted application HTML.
