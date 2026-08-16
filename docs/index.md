# Alto Markdown

Alto Markdown converts Markdown to safe HTML and keeps a source-backed
document model when an application needs to inspect, lint, or edit content.
Use direct conversion for one result; open a document when later work depends
on its structure.

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toHtml("# Hello\n\nWelcome.\n");
```

## Start

- [Installation](installation.md): install the package and verify the runtime.
- [Getting started](getting-started.md): render and inspect one document.

## Conversion

- [HTML](conversion/html.md): render block or inline Markdown safely.
- [Markdown](conversion/markdown.md): preserve source or normalize output.

## Documents

- [Profiles](documents/profiles.md): choose CommonMark, GFM, or GitHub syntax.
- [Queries](documents/queries.md): find headings, sections, links, and code.
- [Statistics](documents/statistics.md): compute one immutable document summary.
- [Editing](documents/editing.md): make source-preserving changes and save files.

## Quality

- [Linting](quality/linting.md): report content and policy problems.
- [Fixing](quality/fixing.md): apply enabled safe corrections.
- [Formatting](quality/formatting.md): normalize selected Markdown style.

## Extension API

- [All extensions](extensions/index.md): choose bundled capabilities.
- [Compatibility](extensions/compatibility.md): understand extension versioning.
- [Extension points](extensions/extension-points.md): select the correct contract.
- [Custom extension](extensions/custom.md): register trusted application behavior.

## Syntax

- [Paired delimiters](extensions/paired-delimiters.md): define a safe inline syntax.
- [Smart punctuation](extensions/smart-punctuation.md): replace quotes, dashes, and ellipses.
- [Highlight](extensions/highlight.md): mark inline text with paired delimiters.
- [Description lists](extensions/description-lists.md): render terms and definitions.
- [Footnotes](extensions/footnotes.md): connect references to endnotes.
- [Tabs](extensions/tabs.md): group nested content into accessible panels.
- [Mentions](extensions/mentions.md): resolve application-specific references.
- [Attributes](extensions/attributes.md): attach constrained attributes to elements.

## Rendering

- [Heading levels](extensions/heading-levels.md): project headings to different HTML levels.
- [Content slicer](extensions/content-slicer.md): wrap heading sections in semantic elements.
- [Permalinks](extensions/heading-permalinks.md): add stable links to headings.
- [Contents](extensions/table-of-contents.md): build a document outline.
- [Default attributes](extensions/default-attributes.md): add controlled HTML attributes.
- [Code titles](extensions/code-block-titles.md): label fenced code blocks.
- [External links](extensions/external-links.md): classify and mark outbound links.
- [Link rewriting](extensions/link-rewriting.md): transform destinations at render time.

## Resources

- [Import](extensions/import.md): insert escaped source code.
- [Include](extensions/include.md): expand bounded Markdown resources.
- [Source](extensions/source.md): display annotated source excerpts.
- [Embeds](extensions/embeds.md): resolve allowlisted rich content.

## Engine

- [Security](engine/security.md): keep untrusted input within safe policies.
- [Performance](engine/performance.md): measure the correct processing lane.
- [Compliance](engine/compliance.md): review supported Markdown specifications.

## API

- [Overview](api/index.md): map the public surface by task.
- [Documents](api/documents.md): factories, documents, and files.
- [Nodes](api/nodes.md): inspect and mutate typed handles.
- [Queries](api/queries.md): collections, selectors, and statistics.
- [Editing](api/editing.md): builders, patches, diffs, and saves.
- [Extensions](api/extensions.md): profile and extension contracts.
- [Exceptions](api/exceptions.md): catch recoverable failures at boundaries.

HTML escapes authored raw HTML and rejects unsafe URL schemes by default.
Read [Security](engine/security.md) before changing those policies.
