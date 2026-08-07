# Alto Markdown

Alto Markdown parses Markdown into a document model you can lint, format,
edit, and convert to HTML, preserving everything you don't touch.

[![CI](https://github.com/altophp/markdown/actions/workflows/CI.yml/badge.svg)](https://github.com/altophp/markdown/actions/workflows/CI.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.4-777bb4.svg)](composer.json)

The core has no runtime Composer dependencies. It supports CommonMark, GitHub
Flavored Markdown, and GitHub-oriented documents.
Trusted extensions can add custom blocks, leaf inlines, native HTML
decorators, render-only document projections, lint rules, formatter passes,
and document metrics. Alto includes configurable leaf delimiter pairs, smart
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

```bash
composer require alto/markdown
```

Requires PHP 8.4 or newer. `ext-dom` is optional, used only by the curated HTML
sanitizer.

## Convert to HTML

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

See [Installation](docs/installation.md), [Conversion](docs/conversion.md),
[Profiles](docs/profiles.md), and [Security](docs/security.md) to choose the
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
bytes, line endings, and a UTF-8 BOM. Alto rejects an edit when it cannot apply
it safely under the documented V1 contract.

The documentation covers this in more depth: [Queries and
stats](docs/queries-and-stats.md) to inspect headings, sections, links, code,
and document metrics; [Manipulation](docs/manipulation.md) to edit sections
and rearrange top-level blocks with minimal diffs; and [Lint, fix, and
format](docs/lint-fix-and-format.md) to enforce content and style policies.

## Extend

Trusted extensions add custom blocks, leaf inlines, native and link-aware HTML
decoration, document render projections, lint, formatting, metrics,
heading-level projection, permalinks, and generated tables of contents through
compiled contracts. Read [Extensions](docs/extensions.md) and [Extension
compatibility](docs/extension-compatibility.md) for the extension contracts
and migration notes from historical Alto CommonMark extensions.

## Documentation

- [Documentation index](docs/index.md): browse the complete guide set.
- [API reference](docs/api-reference.md) and [Errors](docs/errors.md): the
  public surface and recovery contracts.

## Development

```bash
composer qa        # phpstan (max), php-cs-fixer, phpunit
composer tests     # phpunit only
composer coverage  # phpunit with a 99% line-coverage floor
```

## License

Alto Markdown is available under the [MIT License](LICENSE).
