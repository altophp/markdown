# ALTO Markdown

ALTO Markdown parses Markdown into a document model you can lint, format,
edit, and convert to HTML, preserving everything you don't touch.

&nbsp; ![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-00B7FF?logoColor=00B7FF&labelColor=050608)
&nbsp; ![CI](https://img.shields.io/github/actions/workflow/status/altophp/markdown/CI.yml?branch=main&label=Tests&labelColor=050608&color=00B7FF)
&nbsp; [![Packagist](https://img.shields.io/packagist/v/alto/markdown?label=Packagist&labelColor=050608&color=00B7FF)](https://packagist.org/packages/alto/markdown)
&nbsp; ![License](https://img.shields.io/github/license/altophp/markdown?label=License&labelColor=050608&color=00B7FF)
&nbsp; [![GitHub Sponsors](https://img.shields.io/github/sponsors/smnandre?logo=githubsponsors&logoColor=00B7FF&label=%20Sponsor&labelColor=050608&color=00B7FF)](https://github.com/sponsors/smnandre)

The core has no runtime Composer dependencies. It supports CommonMark, GitHub
Flavored Markdown, and GitHub-oriented documents.
Trusted extensions can add custom blocks, leaf inlines, native HTML
decorators, render-only document projections, lint rules, formatter passes,
and document metrics. ALTO includes configurable leaf delimiter pairs, smart
punctuation, highlights, description lists, footnotes, configurable mentions,
nested Markdown tabs, constrained source attributes, escaped code imports,
recursive Markdown includes, semantic heading sections, source excerpt
displays, and a bounded resource resolver for trusted custom extensions and
rich embeds. Front matter stays opaque until the application explicitly passes
a decoder.

The full guide set lives under [`docs/`](docs/index.md).

| Need | Start with |
| --- | --- |
| Convert a string to HTML | `Markdown::github()->toHtml($source)` |
| Convert inline Markdown without a wrapper | `Markdown::github()->toInlineHtml($source)` |
| Inspect or reuse a document | `Markdown::github()->fromString($source)` |
| Edit and save a file | `Markdown::github()->open($path)` |
| Generate Markdown | `Markdown::github()->builder()` |
| Add trusted syntax or analysis | `Markdown::github()->with($extension)` |

## Installation

Install ALTO Markdown with Composer:

```bash
composer require alto/markdown
```

ALTO Markdown requires PHP 8.4 or later. `ext-dom` is optional and used only by the curated HTML
sanitizer.

## Quick Start

```php
use Alto\Markdown\Markdown;

$markdown = "# Installation\n\nRun `composer install`.\n";
$html = Markdown::github()->toHtml($markdown);
```

The result is:

```html
<h1>Installation</h1>
<p>Run <code>composer install</code>.</p>
```

Direct conversion is the shortest and fastest path when HTML is the only
result you need. Output is safe by default: raw HTML is escaped and unsafe URL
schemes are filtered.

Use `toInlineHtml()` for a title, label, comment, or other fragment where block
syntax and a paragraph wrapper are unwanted:

```php
$label = Markdown::github()->toInlineHtml('Install **Alto**');
```

See [Installation](docs/installation.md), [HTML](docs/conversion/html.md),
[Profiles](docs/documents/profiles.md), and [Security](docs/security.md) to choose the
right language and HTML policy.

## Open a document when you need more

A document keeps the parsed structure and the original source bytes. Query and
edit it without rewriting unrelated content:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\nRead the [documentation](https://example.com).\n\n"
    ."## Install\n\nOld instructions.\n",
);

$title = $document->title()?->text();
$linkCount = $document->links()->count();

$document->section('Install')->replaceBody("Run Composer.\n");

$markdown = $document->toMarkdown();
$html = $document->toHtml();
```

Use `open()` for a file. It adds atomic saving, unified diffs, formatting, and
safe lint fixes:

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SaveOptions;

$file = Markdown::github()->open('README.md');
$config = LintConfig::recommended();

$report = $file->lint($config);
$file->fix($config);
$file->format();

if ($file->hasChanges()) {
    echo $file->diff()->toUnifiedString();
    $file->save(new SaveOptions(compareBeforeWrite: true));
}
```

Source ranges use original byte offsets. `toMarkdown()` preserves unchanged
bytes, line endings, and a UTF-8 BOM. ALTO rejects an edit when it cannot apply
it safely under the documented V1 contract.

The documentation covers this in more depth: [Queries](docs/documents/queries.md)
and [Statistics](docs/documents/statistics.md) to inspect structure and metrics;
[Editing](docs/documents/editing.md) to change sections and rearrange top-level
blocks with minimal diffs; and [Linting](docs/quality/linting.md),
[Fixing](docs/quality/fixing.md), and [Formatting](docs/quality/formatting.md)
to enforce content and style policies.

## Extend

Trusted extensions add custom blocks, leaf inlines, native and link-aware HTML
decoration, document render projections, lint, formatting, metrics,
heading-level projection, permalinks, and generated tables of contents through
compiled contracts. Read [Extensions](docs/extensions.md) and
[Compatibility](docs/extensions/compatibility.md) for the extension contracts
and migration notes from historical ALTO CommonMark extensions.

## Documentation

- [Installation](docs/installation.md): install the package and verify the runtime.
- [Getting started](docs/getting-started.md): render and inspect a document.
- [Conversion](docs/conversion.md): produce HTML or Markdown output.
- [Documents](docs/documents.md): query, measure, and edit source-backed content.
- [Quality](docs/quality.md): lint, fix, and format Markdown.
- [Extensions](docs/extensions.md): choose bundled capabilities or create one.
- [Syntax](docs/syntax.md): add authoring syntax.
- [Rendering](docs/rendering.md): control document and HTML output.
- [Resources](docs/resources.md): resolve imported, included, and embedded content.
- [Security](docs/security.md): define HTML, input, and resource boundaries.
- [Errors](docs/errors.md): recover from parse, edit, resource, and file failures.
- [Performance](docs/performance.md): select and measure the processing path.
- [Compliance](docs/compliance.md): review supported Markdown specifications.
- [Documentation index](docs/index.md): browse every guide.

## Contributing

Contributions of all kinds are welcome. Visit the
[project on GitHub](https://github.com/altophp/markdown) to
[report a bug](https://github.com/altophp/markdown/issues/new),
[suggest a feature](https://github.com/altophp/markdown/issues/new), or
[open a pull request](https://github.com/altophp/markdown/pulls).

Before submitting code, run:

```bash
# Runs PHP CS Fixer, PHPStan, and PHPUnit
composer qa
```

Changes to public behavior should include tests and documentation. Run
`composer coverage` separately to enforce the 99% line-coverage floor.

## Support

ALTO Markdown is open source. You can support its continued development through
[GitHub Sponsors](https://github.com/sponsors/smnandre).

Sharing this package with others or
[starring it on GitHub](https://github.com/altophp/markdown) is also much
appreciated.

## License

ALTO Markdown is released by [ALTO PHP](https://altophp.com) under the
[MIT License](LICENSE).
