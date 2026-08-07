# Installation

Alto Markdown requires PHP 8.4 or newer. The core has no runtime Composer
dependencies. PHP's DOM extension is optional and used only by the curated HTML
sanitizer.

## Install

```bash
composer require alto/markdown
```

The command-line application ships as a separate package:

```bash
composer require --dev alto/markdown-cli
vendor/bin/markdown-cli --version
```

Use the PHP library for application code. Use the CLI for repository linting,
format checks, statistics, and outlines.

## Run the first conversion

Create a PHP file that loads Composer and converts one string:

```php
use Alto\Markdown\Markdown;

$markdown = "# Installation\n\nRun `composer install`.\n";
$html = Markdown::github()->toHtml($markdown);
```

The generated HTML is:

```html
<h1>Installation</h1>
<p>Run <code>composer install</code>.</p>
```

`github()` selects GFM plus GitHub alerts and front matter. If the target only
needs portable CommonMark, start with `Markdown::commonmark()` instead.

HTML is safe by default. Read [Security](security.md) before preserving authored
HTML, and [Conversion](conversion.md) before creating a reusable document.

## Verify a checkout

From a clone of the repository, install the development dependencies and run
static analysis, style checks, and tests:

```bash
composer install
composer qa
```

Continue with [Profiles](profiles.md), or use the
[API reference](api-reference.md) when you already know the operation you need.
