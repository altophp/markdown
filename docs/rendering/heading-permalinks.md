# Heading permalinks

`HeadingPermalinkExtension` gives headings stable slugs and adds configurable
permalink anchors. Duplicate headings receive deterministic numeric suffixes.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::commonmark()->with(new HeadingPermalinkExtension());
```

## Markdown

```markdown
# Hello, **world!**
```

## HTML

```html
<h1><a id="content-hello-world" href="#content-hello-world" class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>Hello, <strong>world!</strong></h1>
```

## Options

Pass a `HeadingPermalinkPolicy` to control the generated anchor:

```php
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPosition;

$extension = new HeadingPermalinkExtension(new HeadingPermalinkPolicy(
    minLevel: 2,
    maxLevel: 4,
    position: HeadingPermalinkPosition::After,
    idPrefix: 'heading',
    applyIdToHeading: true,
    headingClass: 'anchored',
    fragmentPrefix: 'heading',
    htmlClass: 'permalink',
    title: 'Link to this section',
    symbol: '#',
    ariaHidden: false,
));
```

| Option | Default |
| --- | --- |
| `minLevel`, `maxLevel` | `1`, `6` |
| `position` | `HeadingPermalinkPosition::Before` |
| `idPrefix`, `fragmentPrefix` | `content` |
| `applyIdToHeading` | `false` |
| `headingClass` | Empty |
| `htmlClass` | `heading-permalink` |
| `title` | `Permalink` |
| `symbol` | `¶` |
| `ariaHidden` | `true` |

Use `HeadingPermalinkPosition::None` to apply the configured heading ID and
class without rendering an anchor.

Both levels must be between 1 and 6, and `minLevel` cannot exceed `maxLevel`.

## Security

Configured classes, titles, symbols, IDs, and fragments are escaped through
the active HTML output context. Generated links remain subject to the active
HTML policy.

## Behavior

Slug allocation covers the complete document, including filtered headings,
so partial node and section rendering keeps the same IDs. Renaming a heading
invalidates the slug catalog. Rendering permalinks does not modify the source
Markdown.
