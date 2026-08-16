# Highlight

`HighlightExtension` adds `==marked text==` as a small inline syntax. It renders
the marked content with the semantic HTML `<mark>` element.

## Install

`HighlightExtension` is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Add the extension to any factory profile:

```php
use Alto\Markdown\Extension\Highlight\HighlightExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new HighlightExtension());
```

## Markdown

```markdown
Review the ==important change== before merging.
```

## HTML

```html
<p>Review the <mark>important change</mark> before merging.</p>
```

## Options

This extension has no configuration options.

## Security

The extension grants no resource or raw HTML authority. Highlighted content is
stored as plain text and HTML-sensitive characters are escaped during output.

## Behavior

The opening and closing `==` delimiters must appear on the same line. The
content cannot start or end with whitespace, and an empty pair stays literal.

Highlighted content is plain text. Markdown syntax inside the delimiters is
not parsed, and HTML-sensitive characters are escaped:

```markdown
==**important** and <safe>==
```

```html
<p><mark>**important** and &lt;safe&gt;</mark></p>
```

Escaped delimiters and delimiters inside code spans remain literal.
