# API reference

The public API is organized by application task. Types marked `@internal` in
source are not part of the package contract.

## Entry points

`Markdown::commonmark()`, `Markdown::gfm()`, and `Markdown::github()` return a
`MarkdownFactory` for the selected syntax profile. A factory can render a
string directly, create a document, open a file, start a builder, or install
trusted extensions.

```php
use Alto\Markdown\Markdown;

$factory = Markdown::github();
$document = $factory->fromString("# Guide\n");
```

## Reference domains

- [Documents](documents.md): `MarkdownFactory`, `MarkdownDocument`, and
  `MarkdownFile`.
- [Nodes](nodes.md): headings, sections, links, images, code, and front matter.
- [Queries](queries.md): collections, generic selectors, and statistics.
- [Editing](editing.md): builders, source patches, diffs, and persistence.
- [Extensions](extensions.md): profiles and public extension capabilities.
- [Exceptions](exceptions.md): argument, parse, render, resource, and file
  failures.

Use the guide pages for decisions and workflows. These reference pages define
callable contracts and observable boundaries.
