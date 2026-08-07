# Queries and stats

Open a document when you need answers about its structure. Typed accessors cover
common questions; generic queries handle extension kinds; stats produce one
immutable summary.

## Ask common questions

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Project\n\nRead the [guide](https://example.com).\n\n"
    ."## Install\n\nRun Composer.\n",
);

$title = $document->title()?->text();
$install = $document->section('install')->exists();
$linkCount = $document->links()->count();
$firstLevelTwo = $document->headings(level: 2)->first()?->text();
```

The values are `Project`, `true`, `1`, and `Install`.

Use these typed accessors first:

| Question | Accessor |
| --- | --- |
| What is the title? | `title()` |
| Which headings exist? | `headings($level)` |
| Does a named section exist? | `section($title)->exists()` |
| Are there duplicate named sections? | `sections($title)` |
| Which links or images exist? | `links()` and `images()` |
| Which code blocks use a language? | `codeBlocks($language)` |
| Is there leading front matter? | `frontMatter()` |

Section matching trims the requested title and compares it with Unicode-aware
case folding. `section()` returns a missing-section handle when no match exists,
so check `exists()` before a mutation.

## Use collections and generic queries

Collections are lazy, repeatable, iterable, and countable. Choose the operation
that matches the amount of work you need:

- `first()` stops at the first result;
- `count()` consumes the matching results without creating an array;
- `all()` returns every handle as an array;
- `filter()` derives another lazy collection.

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "```php\necho \"first\";\n```\n\n"
    ."```php\necho \"second\";\n```\n",
);

$phpBlocks = $document->codeBlocks('php');
$first = $phpBlocks->first();
$count = $phpBlocks->count();
```

Generic queries select core or qualified extension kinds:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "| Name | Value |\n| --- | --- |\n| Alto | Fast |\n",
);

$blocks = $document->query()
    ->kind('paragraph')
    ->kind('gfm:table')
    ->get();
```

Kind filters are ORed. `where()` predicates are ANDed. Results remain in source
order. An unknown kind returns an empty collection. Prefer a typed accessor when
it provides useful domain methods.

Queries can return nodes at any depth. Mutation support is narrower: general
block insertion, movement, cloning, replacement, and wrapping currently accept
only direct children of the document root. Typed mutations such as changing a
link destination keep their own documented boundaries.

A handle belongs to one document generation. Query again after a save or
structural reparse instead of retaining old handles.

## Read document statistics

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Report\n\nA short paragraph with [docs](https://example.com).\n",
);

$stats = $document->stats();

$words = $stats->wordCount;
$minutes = $stats->readingTimeMinutes;
$outline = $stats->outline;
$hosts = $stats->linksByHost;
```

This document has 6 words, 1 reading minute, outline `["# Report"]`, and one
link for `example.com`.

`DocumentStats` exposes:

| Field | Meaning |
| --- | --- |
| `headingCount` | Total ATX and setext headings |
| `headingsByLevel` | Counts keyed by heading level |
| `maxHeadingDepth` | Highest heading level present |
| `outline` | Heading lines rendered as `# Title` |
| `sectionWordCounts` | Ordered title, level, and word-count values |
| `linkCount` | Links, excluding images |
| `linksByType` | `internal` fragment links and `external` non-fragment links |
| `linksByHost` | Non-fragment links grouped by parsed host |
| `imageCount` | Images, counted separately from links |
| `codeBlockCount` | Fenced and indented code blocks |
| `codeBlocksByLanguage` | Counts by language, with `plain` for none |
| `wordCount` | Words in visible inline text |
| `characterCount` | Unicode scalars, or bytes for invalid UTF-8 |
| `readingTimeMinutes` | Zero when empty, otherwise `ceil(words / 200)` |
| `tableCount` | GFM tables |
| `extensionStats` | Qualified custom metrics |

Section counts stay ordered, so duplicate titles remain distinct. Code spans
and link labels count as visible text; code-block payloads, HTML blocks, and
Markdown punctuation do not.

Extension metrics reuse the stats traversal and return finite scalar values or
`null`. See [Extensions](extensions.md#add-formatting-and-stats).
