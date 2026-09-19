# Rendering

Rendering extensions project or decorate a parsed document without changing its
stored Markdown. Use them to control generated structure, links, headings, and
attributes.

## Extensions

- [Heading levels](rendering/heading-levels.md): project headings to different HTML levels.
- [Content slicer](rendering/content-slicer.md): wrap heading sections in semantic elements.
- [Permalinks](rendering/heading-permalinks.md): add stable links to headings.
- [Contents](rendering/table-of-contents.md): build a document outline.
- [Default attributes](rendering/default-attributes.md): add controlled HTML attributes.
- [Code titles](rendering/code-block-titles.md): label fenced code blocks.
- [External links](rendering/external-links.md): classify and mark outbound links.
- [Link rewriting](rendering/link-rewriting.md): transform destinations at render time.

These projections affect rendered output. `toMarkdown()` continues to preserve
the source unless a separate edit or formatting operation changes it.
