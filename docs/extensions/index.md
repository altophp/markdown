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

- [Paired delimiters](paired-delimiters.md): configurable leaf delimiter pairs.
- [Smart punctuation](smart-punctuation.md): quotes, dashes, and ellipses.
- [Highlight](highlight.md): highlighted inline text.
- [Description lists](description-lists.md): terms and definitions.
- [Footnotes](footnotes.md): definitions and references.
- [Tabs](tabs.md): nested tab groups.
- [Mentions](mentions.md): application-resolved references.
- [Attributes](attributes.md): constrained source attributes.

## Documents and HTML

- [Heading levels](heading-levels.md): project heading levels at render time.
- [Content slicer](content-slicer.md): group heading sections.
- [Heading permalinks](heading-permalinks.md): add stable heading links.
- [Table of contents](table-of-contents.md): generate a document outline.
- [Default attributes](default-attributes.md): add controlled HTML attributes.
- [Code block titles](code-block-titles.md): render code-block titles.
- [External links](external-links.md): mark external links.
- [Link rewriting](link-rewriting.md): rewrite link destinations.

## External resources

- [Import](import.md): insert escaped source code.
- [Include](include.md): expand bounded Markdown resources.
- [Source](source.md): display source excerpts.
- [Embeds](embeds.md): resolve allowlisted rich content.

Resource-backed extensions require an injected `ResourceResolver`; installing
an extension never grants filesystem or network authority by itself. Read
[Security](../engine/security.md) before enabling them on untrusted input.

Use [Extension points](extension-points.md) to choose a public contract,
[Custom extension](custom.md) for a minimal implementation, and
[Compatibility](compatibility.md) before publishing an extension package.
