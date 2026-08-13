# Getting started

Render a GitHub-style Markdown document, inspect its title, and count its links
without parsing the source twice.

## Create a document

Start from a Composer application with `alto/markdown` installed:

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

The first two lines are `Project` and `1`. The remaining output is the rendered
heading and paragraph.

`github()` enables CommonMark, GitHub Flavored Markdown, GitHub alerts, and
front matter. The returned document retains the parsed structure and original
source bytes, so queries and edits share one workspace.

Use [HTML](conversion/html.md) when rendering is the only operation. Continue
with [Queries](documents/queries.md) when the application needs structural
answers.
