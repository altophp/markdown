# Formatting

Formatting normalizes Markdown syntax style without rewrapping prose or
rewriting code, raw HTML, inline code, or front matter.

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;

$document = Markdown::github()->fromString("* One\n* Two");
$style = new MarkdownStyle(
    bulletMarker: '-',
    orderedListDelimiter: '.',
    fenceMarker: '`',
    finalNewline: true,
);

$document->format($style);
$markdown = $document->toMarkdown();
```

The result is:

```markdown
- One
- Two
```

The formatter can normalize trailing spaces, ATX heading spacing, fence and
list markers, GFM table delimiters, reference-definition spacing, structural
blank lines, and the final newline.

Ambiguous changes are skipped. This includes list boundaries that could merge,
nested table delimiters without a safe source prefix, and multiline reference
definitions. Extension passes run after built-in passes and use the same patch
validation.

Formatting is deterministic and idempotent: a second pass over the result adds
no changes. Use `diff()` before `save()` when formatting a file. See
[Markdown](../conversion/markdown.md) when normalized rendering is needed
without mutating the document workspace.
