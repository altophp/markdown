# Table of contents

`TableOfContentsExtension` replaces an explicit marker with a document outline
and assigns stable IDs to root headings. Each marker may select its own heading
range and list style.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\TableOfContents\TableOfContentsExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::commonmark()->with(new TableOfContentsExtension());
```

## Markdown

```markdown
# Guide

@toc {min: 2}

## Intro *now*

### Use [`it`](https://example.com)
```

## HTML

```html
<h1 id="guide">Guide</h1>
<nav class="table-of-contents" id="toc">
<ul>
<li><a href="#intro-now">Intro now</a>
<ul>
<li><a href="#use-it">Use it</a></li>
</ul>
</li>
</ul>
</nav>
<h2 id="intro-now">Intro <em>now</em></h2>
<h3 id="use-it">Use <a href="https://example.com"><code>it</code></a></h3>
```

## Options

Configure document-wide defaults with `TableOfContentsPolicy`:

```php
use Alto\Markdown\Extension\TableOfContents\TableOfContentsPolicy;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsStyle;

$extension = new TableOfContentsExtension(new TableOfContentsPolicy(
    minLevel: 2,
    maxLevel: 4,
    style: TableOfContentsStyle::Ordered,
    htmlClass: 'contents',
    id: 'contents',
    title: 'On this page',
    marker: '[[toc]]',
));
```

| Option | Default |
| --- | --- |
| `minLevel`, `maxLevel` | `1`, `6` |
| `style` | `TableOfContentsStyle::Bullet` |
| `htmlClass` | `table-of-contents` |
| `id` | `toc` |
| `title` | `null` |
| `marker` | `@toc` |

A marker may override three values locally:

```markdown
@toc {min: 2, max: 3, ordered: true}
```

Levels must be between 1 and 6. `ordered` accepts only `true` or `false`.
`htmlClass` is limited to 256 bytes without control characters. `id` is
limited to 128 bytes without whitespace or control characters. A title is
limited to 512 bytes without unsupported control characters. Custom markers
contain 1 to 64 visible ASCII bytes and cannot contain braces.

## Security

Titles, classes, IDs, heading text, and link fragments are escaped through the
active HTML output context. Malformed directives remain literal Markdown and
do not partially apply options.

## Behavior

Only root headings appear in the outline. Headings inside block quotes, lists,
and extension containers still receive stable IDs but are excluded from the
catalog. Multiple markers share one catalog and receive unique navigation IDs.
If no heading matches a marker, the marker renders no HTML. The source marker
is preserved when rendering back to Markdown.
