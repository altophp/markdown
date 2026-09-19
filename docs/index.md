# ALTO Markdown

ALTO Markdown converts Markdown to safe HTML and keeps a source-backed
document model when an application needs to inspect, lint, or edit content.
Use direct conversion for one result; open a document when later work depends
on its structure.

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toHtml("# Hello\n\nWelcome.\n");
```

The conversion returns `<h1>Hello</h1>` followed by a paragraph containing
`Welcome.`. Raw HTML is escaped and unsafe URL schemes are rejected by default.

## Documentation

- [Installation](installation.md): install the package and verify the runtime.
- [Getting started](getting-started.md): render and inspect one document.
- [Conversion](conversion.md): produce HTML or Markdown output.
- [Documents](documents.md): inspect, query, measure, and edit parsed documents.
- [Quality](quality.md): lint, fix, and format Markdown.
- [Extensions](extensions.md): choose or create trusted capabilities.
- [Syntax](syntax.md): add authoring syntax.
- [Rendering](rendering.md): control document and HTML output.
- [Resources](resources.md): resolve imported, included, and embedded content.
- [Security](security.md): define input, HTML, and resource boundaries.
- [Errors](errors.md): catch recoverable failures.
- [Performance](performance.md): select and measure the correct processing path.
- [Compliance](compliance.md): review supported Markdown specifications.

The core has no runtime Composer dependencies. It supports CommonMark, GitHub
Flavored Markdown, and GitHub-oriented documents. Extensions run as trusted
application code and receive resource access only through an injected resolver.
