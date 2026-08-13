# Markdown

A document can preserve untouched source bytes or render a normalized Markdown
model. Choose the mode from the intended result, not from the input syntax.

## Preserve the source

Without render options, `toMarkdown()` applies pending edits and keeps
unrelated bytes, line endings, and a UTF-8 BOM unchanged:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\n## Install\n\nOld instructions.\n",
);
$document->section('Install')->replaceBody("Run Composer.\n");

$markdown = $document->toMarkdown();
```

## Normalize the model

Pass `RenderOptions` with a `MarkdownStyle` when consistent output matters more
than retaining the author's markers:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Render\RenderOptions;

$document = Markdown::github()->fromString("* One\n* Two\n");
$options = new RenderOptions(
    style: new MarkdownStyle(bulletMarker: '-'),
);

$normalized = $document->toMarkdown($options);
```

The source-preserving result keeps `*`; the normalized result uses `-`.
For files, use the save operations described in [Editing](../documents/editing.md)
so conflict checks, permissions, line endings, and atomic replacement remain
available.
