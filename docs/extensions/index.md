# Extensions

Extensions add trusted syntax, rendering, transformations, analysis, or
resource-backed behavior to an immutable factory profile. Install only the
capabilities required by the document format.

```php
use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Extension\Highlight\HighlightExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(
    new FootnoteExtension(),
    new HighlightExtension(),
);
```

## Authoring syntax

- `PairedDelimiterExtension`: configurable leaf delimiter pairs.
- `SmartPunctuationExtension`: quotes, dashes, and ellipses.
- `HighlightExtension`: highlighted inline text.
- `DescriptionListExtension`: description lists.
- `FootnoteExtension`: definitions and references.
- `TabsExtension`: nested tab groups.
- `MentionExtension`: application-resolved mentions.
- `AttributesExtension`: constrained source attributes.

## Documents and HTML

- `HeadingLevelExtension`: project heading levels at render time.
- `ContentSlicerExtension`: group heading sections.
- `HeadingPermalinkExtension`: add stable heading links.
- `TableOfContentsExtension`: generate a document outline.
- `DefaultAttributesExtension`: add controlled HTML attributes.
- `CodeBlockTitleExtension`: render code-block titles.
- `ExternalLinkExtension`: mark external links.
- `LinkRewriterExtension`: rewrite link destinations.

## External resources

- `ImportExtension`: insert escaped source code.
- `IncludeExtension`: expand bounded Markdown resources.
- `SourceExtension`: display source excerpts.
- `EmbedExtension`: resolve allowlisted rich embeds.

Resource-backed extensions require an injected `ResourceResolver`; installing
an extension never grants filesystem or network authority by itself. Read
[Security](../engine/security.md) before enabling them on untrusted input.

Use [Extension points](extension-points.md) to choose a public contract,
[Custom extension](custom.md) for a minimal implementation, and
[Compatibility](compatibility.md) before publishing an extension package.
