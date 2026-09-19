# Smart punctuation

`SmartPunctuationExtension` replaces straight quotes, apostrophes, dash runs,
and three-dot ellipses while rendering. It leaves the source Markdown
unchanged.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new SmartPunctuationExtension());
```

## Markdown

```markdown
"Hello," she said... It's ready -- really --- now.
```

## HTML

```html
<p>“Hello,” she said… It’s ready – really — now.</p>
```

## Options

Pass a `SmartPunctuationPolicy` to replace the four quote characters:

```php
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationPolicy;

$markdown = Markdown::commonmark()->with(new SmartPunctuationExtension(
    new SmartPunctuationPolicy(
        doubleQuoteOpener: '« ',
        doubleQuoteCloser: ' »',
        singleQuoteOpener: '‹ ',
        singleQuoteCloser: ' ›',
    ),
));
```

Each replacement must be a non-empty valid UTF-8 string. Ellipses and dash
replacement rules are fixed: `...` becomes `…`, `--` becomes an en dash, and
`---` becomes an em dash. Longer runs are decomposed deterministically into em
and en dashes.

## Security

Every replacement is validated as non-empty UTF-8 and emitted as text. The
extension does not enable raw HTML or access external resources.

## Behavior

Quote direction is selected from the surrounding characters. Apostrophes
inside words use the configured single-quote closer. Code spans and escaped
punctuation stay literal. Punctuation inside raw HTML is not replaced; the
active HTML policy still decides whether that HTML is escaped, removed, or
emitted. Replacements also apply inside rich inline content and GFM table
cells.
