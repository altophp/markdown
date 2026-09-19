# Documents

A document retains the parsed model and original source. Use it when an
application needs to inspect, reuse, measure, or change Markdown without
rewriting unrelated content.

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString("# Guide\n\nRead the docs.\n");
$title = $document->title()?->text();
```

`$title` is `Guide`.

## Guides

- [Profiles](documents/profiles.md): choose CommonMark, GFM, or GitHub syntax.
- [Queries](documents/queries.md): find headings, sections, links, and code.
- [Statistics](documents/statistics.md): compute an immutable document summary.
- [Editing](documents/editing.md): make source-preserving changes and save files.
- [Nodes](documents/nodes.md): inspect and mutate typed handles.

## Entry points

| Method | Return | Purpose |
| --- | --- | --- |
| `profile()` | `Profile` | Return the compiled language profile. |
| `with(ExtensionInterface ...$extensions)` | `self` | Return a factory with trusted extensions. |
| `fromString(string $source, ?ParseOptions $options = null)` | `MarkdownDocument` | Parse an in-memory document. |
| `open(string $path, ?ParseOptions $options = null)` | `MarkdownFile` | Read and parse a file. |
| `toHtml(...)` | `string` | Render block Markdown directly. |
| `toInlineHtml(...)` | `string` | Render an inline fragment directly. |
| `builder()` | `MarkdownBuilder` | Start a document builder. |
| `fragment()` | `MarkdownFragmentBuilder` | Start a fragment builder. |

`MarkdownDocument` exposes typed accessors, `query()`, `ensure()`, `lint()`,
`fix()`, `format()`, `stats()`, `toMarkdown()`, and `toHtml()`.
`MarkdownFile` adds `path()`, `save()`, and `saveAs()`. See
[Errors](errors.md) for recoverable parse, edit, resource, and file failures.
