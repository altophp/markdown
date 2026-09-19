# Installation

Install ALTO Markdown in a PHP 8.4 or newer application, then run one smoke
test to confirm that Composer can load the package.

## Requirements

The core has no runtime Composer dependencies. The DOM extension is optional
and is required only by `HtmlPolicy::curated()`.

## Install the package

```bash
composer require alto/markdown
```

## Verify the installation

Run this file through the same Composer autoloader as the application:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Markdown\Markdown;

echo Markdown::commonmark()->toHtml("# Ready\n");
```

The command must print:

```html
<h1>Ready</h1>
```

If Composer cannot resolve the package, verify the PHP version reported by
`php -v` and the platform requirements reported by `composer check-platform-reqs`.

Continue with [Getting started](getting-started.md) to choose a profile and
reuse a parsed document.
