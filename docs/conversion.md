# Conversion

Alto has a direct conversion path and a document path. They use the same
factory profile and HTML policy, but they serve different jobs.

## Choose the right path

| If you need to | Use |
| --- | --- |
| Turn one string into HTML | `$factory->toHtml($source)` |
| Render a label or rich-text field without a wrapper | `$factory->toInlineHtml($source)` |
| Query, lint, edit, or render repeatedly | `$factory->fromString($source)` |
| Read and later save a file | `$factory->open($path)` |

Direct conversion avoids the query and edit workspace. A document keeps parsed
structure, source ranges, pending changes, and reusable inline work.

Do not create a document merely to render once. Do not use direct conversion
when a later step needs headings, links, lint, statistics, or source-preserving
edits.

## Render HTML

Direct conversion is one call:

```php
use Alto\Markdown\Markdown;

$factory = Markdown::github();
$html = $factory->toHtml("# Release notes\n\nNothing broke.\n");
```

A document is just as simple when you need its structure:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\nRead the [documentation](https://example.com).\n",
);

$linkCount = $document->links()->count();
$html = $document->toHtml();
```

For the same source, factory profile, and HTML policy, direct and document
rendering produce the same HTML. The difference is the retained workspace, not
the Markdown language.

Both paths use [safe HTML](security.md) by default. Pass `RenderOptions` during
HTML rendering only when the application needs another HTML policy.

### Render an inline fragment

`toInlineHtml()` treats the complete input as inline Markdown:

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toInlineHtml(
    'Read **carefully** in [the guide](/guide).',
);
```

It emits a fragment without a paragraph wrapper or added newline. Physical
line endings become Markdown soft or hard breaks.

Block syntax is deliberately inactive. A leading `#`, list marker, block quote
marker, or front matter delimiter stays text. Backticks still follow inline
code-span rules; they do not create a fenced block. Reference definitions are
not extracted, so reference links require a normal document conversion. The
selected profile still controls inline syntax, and installed inline
extensions, mentions, native inline decorators, URL filtering, raw HTML
handling, and final sanitization all apply.

`ParseOptions::maxSourceBytes` and `maxInlineCount` apply.
`maxBlockCount`, `maxReferenceCount`, and `maxNestingDepth` are not applicable
because this lane creates no blocks.

## Export Markdown

Without options, `toMarkdown()` returns the current source with pending edits.
Unchanged bytes remain unchanged:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\n## Install\n\nOld instructions.\n",
);
$document->section('Install')->replaceBody("Run Composer.\n");

$markdown = $document->toMarkdown();
```

The result is:

```markdown
# Guide

## Install

Run Composer.
```

Pass `RenderOptions` to serialize a normalized model instead. This is where a
target profile and `MarkdownStyle` apply:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Render\RenderOptions;

$document = Markdown::github()->fromString("* One\n* Two\n");
$options = new RenderOptions(style: new MarkdownStyle(bulletMarker: '-'));

$normalized = $document->toMarkdown($options);
```

The source-preserving result keeps `*`; the normalized result uses `-`. For
files, prefer `save()` or `saveAs()` so Alto can preserve line endings, BOM,
permissions, and conflict checks.
