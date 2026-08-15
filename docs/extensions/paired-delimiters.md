# Paired delimiters

`PairedDelimiterExtension` turns one configured delimiter pair into a safe
inline HTML element. Use it for small, application-specific spans such as
insertions, keyboard input, or abbreviations.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\PairedDelimiter\PairedDelimiterExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new PairedDelimiterExtension(
    name: 'inserted',
    opening: '++',
    closing: '++',
    element: 'ins',
));
```

## Markdown

```markdown
Keep ++this & **literal**++ text.
```

## HTML

```html
<p>Keep <ins>this &amp; **literal**</ins> text.</p>
```

## Options

| Option | Description |
| --- | --- |
| `name` | Unique name starting with a lowercase letter, followed by lowercase letters, digits, or hyphens. |
| `opening` | Opening delimiter, from 1 to 16 bytes. |
| `closing` | Closing delimiter, from 1 to 16 bytes. |
| `element` | Safe inline element. Defaults to `span`. |

Supported elements include `abbr`, `code`, `del`, `ins`, `kbd`, `mark`,
`span`, `sub`, and `sup`. Invalid names, whitespace in delimiters, control
bytes, and unsupported elements are rejected when the extension is created.
The active profile also reserves some triggers. For example, CommonMark rejects
`**` as a custom opening delimiter when the extension is added with `with()`.

## Security

Only a fixed allowlist of safe inline elements is accepted. Delimited content
is escaped as text, so it cannot inject HTML through the extension output.

## Behavior

The delimited content is a plain-text leaf. Markdown inside it is escaped
rather than parsed. Empty, unclosed, escaped, or multiline pairs remain
literal Markdown. The original delimiters are preserved when the document is
rendered back to Markdown.
