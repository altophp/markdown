# Queries

Typed accessors answer common document questions. Generic queries remain
available for extension node kinds and compound filters.

## Use typed accessors

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Project\n\nRead the [guide](https://example.com).\n\n"
    ."## Install\n\nRun Composer.\n",
);

$title = $document->title()?->text();
$hasInstall = $document->section('install')->exists();
$linkCount = $document->links()->count();
$firstH2 = $document->headings(level: 2)->first()?->text();
```

The values are `Project`, `true`, `1`, and `Install`.

Available typed entry points include `headings()`, `section()`, `sections()`,
`links()`, `images()`, `codeBlocks()`, and `frontMatter()`. A missing section
returns a handle whose `exists()` method is false; check it before mutation.

## Work with collections

Collections are lazy, repeatable, iterable, and countable:

- `first()` stops after the first match;
- `count()` consumes matches without building an array;
- `all()` returns every handle;
- `filter()` derives another lazy collection.

## Select node kinds

Use the generic query for core or qualified extension kinds:

```php
$blocks = $document->query()
    ->kind('paragraph')
    ->kind('gfm:table')
    ->get();
```

Kind filters are combined with OR. `where()` predicates are combined with AND,
and results remain in source order. Unknown kinds produce an empty collection.

Handles belong to one document generation. Query again after a save or
structural reparse. Continue with [Statistics](statistics.md) for aggregate
metrics or [Editing](editing.md) to change selected content.
