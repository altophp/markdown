# Editing API

Editing APIs either build new Markdown or record validated patches against an
existing source.

## Builders

`MarkdownBuilder` provides `heading()`, `h1()`, `h2()`, `paragraph()`,
`codeBlock()`, `unorderedList()`, `orderedList()`, `blockquote()`, and
`thematicBreak()`. Finish with `document()` or `toMarkdown()`.

```php
$markdown = $factory->builder()
    ->h1('Guide')
    ->paragraph('Start here.')
    ->toMarkdown();
```

`MarkdownFragmentBuilder` creates content intended for insertion rather than a
standalone document.

## Pending patches

Typed handle mutations update the in-memory model immediately and add source
operations to the document journal. `toMarkdown()` lowers pending operations;
`diff()` returns a `Diff` whose `toUnifiedString()` method is suitable for a
preview.

## SaveOptions

`MarkdownFile::save()` replaces the opened file. `saveAs()` targets another
path. `SaveOptions` controls compare-before-write and related persistence
policies. Atomic replacement, permission preservation, line-ending retention,
and conflict checks remain available through the file contract.

Catch the file exceptions described in [Exceptions](exceptions.md) at the
application boundary. See [Editing](../documents/editing.md) for complete
source-preserving workflows.
