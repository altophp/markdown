# Heading levels

`HeadingLevelExtension` projects heading levels at render time. It is useful
when a Markdown fragment must fit inside an existing document outline without
rewriting its source.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelExtension;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelPolicy;
use Alto\Markdown\Markdown;

$markdown = Markdown::commonmark()->with(
    new HeadingLevelExtension(HeadingLevelPolicy::shift(1)),
);
```

## Markdown

```markdown
# Title

## Details
```

## HTML

```html
<h2>Title</h2>
<h3>Details</h3>
```

## Options

Choose one policy strategy:

```php
$mapped = HeadingLevelPolicy::map([
    1 => 2,
    2 => 4,
]);

$shifted = HeadingLevelPolicy::shift(1);

$custom = HeadingLevelPolicy::using(
    static fn (int $level): ?int => 2 === $level ? null : $level + 1,
);
```

`map()` changes only listed levels. `shift()` accepts offsets from `-5` to
`5`. A callback passed to `using()` may return `null` to keep the effective
level unchanged. Every rendered level must remain between 1 and 6; a document
containing a heading projected outside that range fails when rendered.

## Security

The extension changes only the numeric level of existing heading elements. It
does not emit user-provided HTML or access external resources.

## Behavior

The projection applies to ATX and Setext headings, including nested headings.
It does not change the document, its Markdown output, or its edit diff. Other
document transforms, including heading permalinks and tables of contents, use
the projected level.
