# Conversion

Convert Markdown directly when the application only needs one output. Open a
document first when the same parsed structure will also be queried, edited,
linted, or rendered more than once.

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toHtml("# Guide\n\nRead **carefully**.\n");
```

## Output

- [HTML](conversion/html.md): render blocks or inline fragments with an explicit
  HTML policy.
- [Markdown](conversion/markdown.md): preserve original source or produce
  normalized Markdown.

Direct conversion avoids retaining a document object. Document output preserves
unchanged source bytes until an operation explicitly normalizes them.
