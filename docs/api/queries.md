# Queries API

Documents provide typed accessors for common questions and `MarkdownQuery` for
core or extension node kinds.

## Typed accessors

`title()`, `headings()`, `section()`, `sections()`, `links()`, `images()`,
`codeBlocks()`, and `frontMatter()` return typed handles or lazy collections.

## Collection

`Collection` implements `Countable`, `IteratorAggregate`, and lazy operations:

| Method | Result |
| --- | --- |
| `first()` | First item or `null`. |
| `count()` | Number of matching items. |
| `all()` | Materialized result array. |
| `filter()` | Derived lazy collection. |

## MarkdownQuery

`kind()` adds an OR node-kind selector. `where()` adds an AND predicate.
`get()` returns matching handles in source order.

```php
$tablesAndParagraphs = $document->query()
    ->kind('paragraph')
    ->kind('gfm:table')
    ->get();
```

`stats()` returns `DocumentStats`, including headings, sections, links, images,
code, words, reading time, tables, and extension metrics. See
[Queries](../documents/queries.md) and [Statistics](../documents/statistics.md)
for selection and counting semantics.
