# Getting started

Render a GitHub-style Markdown document, inspect its title, and count its links
without parsing the source twice.

## Create a document

Start from a Composer application with `alto/markdown` installed. Save this
as `render.php` beside `vendor/` and run `php render.php`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Markdown\Markdown;

$source = <<<'MD'
# Project

Read the [guide](https://example.com/guide).
MD;

$document = Markdown::github()->fromString($source);

echo $document->title()?->text()."\n";
echo $document->links()->count()."\n";
echo $document->toHtml();
```

The complete output is:

```text
Project
1
<h1>Project</h1>
<p>Read the <a href="https://example.com/guide">guide</a>.</p>
```

`github()` enables CommonMark, GitHub Flavored Markdown, GitHub alerts, and
front matter. The returned document retains the parsed structure and original
source bytes, so queries and edits share one workspace.

Use [HTML](conversion/html.md) when rendering is the only operation. Continue
with [Queries](documents/queries.md) when the application needs structural
answers.
