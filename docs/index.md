# Alto Markdown documentation

Alto Markdown is a PHP 8.4+ document engine. It converts Markdown to HTML, but
it can also open a document, query its structure, lint it, format it, and edit
selected content without rewriting unrelated bytes.

## Start here

- [Installation](installation.md): install the package and render your first
  document.
- [Conversion](conversion.md): choose between direct HTML conversion and a
  reusable document.
- [Profiles](profiles.md): select CommonMark, GFM, or GitHub behavior.
- [Security](security.md): render untrusted Markdown safely and report a
  suspected vulnerability privately.

The shortest path from a Markdown string to HTML is:

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toHtml("# Hello\n\nWelcome.\n");
```

## Work with documents

- [Queries and stats](queries-and-stats.md): inspect headings, sections, links,
  code blocks, and aggregate metrics.
- [Manipulation](manipulation.md): edit sections and rearrange top-level blocks
  with minimal diffs.
- [Lint, fix, and format](lint-fix-and-format.md): report policy problems and
  apply safe, deterministic corrections.
- [Command line](command-line.md): use the same engine in scripts and CI.

Use a document when more than conversion is required:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString("# Guide\n\n## Install\n\nRun Composer.\n");

$title = $document->title()?->text();
$install = $document->section('Install');
$html = $document->toHtml();
```

## Understand the boundaries

- [API reference](api-reference.md): find the stable entry points, document
  actions, handles, builders, and configuration values.
- [Errors](errors.md): catch recoverable failures and protect file edits.
- [Compliance](compliance.md): supported specifications and verification
  commands.
- [Performance](performance.md): benchmark lanes, reference results, and
  measurement rules.
- [Extensions](extensions.md): custom blocks and leaf inlines, lint rules,
  formatter passes, stats metrics, compiled dispatch, and extension boundaries.
- [Extension compatibility](extension-compatibility.md): versioning,
  deprecations, package support, and migration from other engines.

Public behavior described in these pages is covered by the repository test
suite.
