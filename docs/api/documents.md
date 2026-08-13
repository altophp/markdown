# Documents API

Factories create one of two document contracts: an in-memory
`MarkdownDocument` or a path-backed `MarkdownFile`.

## MarkdownFactory

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

## MarkdownDocument

The document exposes its `profile()` and `model()`, typed accessors, `query()`,
`ensure()`, `lint()`, `fix()`, `format()`, and `stats()`. Output methods are
`toMarkdown()` and `toHtml()`. `hasChanges()` and `diff()` inspect pending
source edits.

```php
$document = $factory->fromString($source);

if ($document->hasChanges()) {
    echo $document->diff()->toUnifiedString();
}
```

## MarkdownFile

`MarkdownFile` adds `path()`, `save()`, and `saveAs()`. Saving may reject a
changed, missing, symbolic-link, or unauthorized target. See
[Editing](editing.md) for persistence contracts and [Exceptions](exceptions.md)
for recoverable failures.
