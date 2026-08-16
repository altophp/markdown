# Default attributes

`DefaultAttributesExtension` adds application-defined HTML attributes to
native Markdown elements. It does not add Markdown syntax.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Map node kinds to the attributes they should receive:

```php
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(
    new DefaultAttributesExtension([
        'paragraph' => [
            'class' => ['prose', 'content'],
            'data-kind' => 'body',
        ],
        'link' => [
            'class' => 'link',
            'target' => '_self',
        ],
    ]),
);
```

## Markdown

```markdown
Read [Alto](https://altophp.com).
```

## HTML

```html
<p class="prose content" data-kind="body">Read <a href="https://altophp.com" class="link" target="_self">Alto</a>.</p>
```

## Options

Each attribute value is a string or boolean. The `class` attribute also
accepts a list of strings. Duplicate classes are removed, `true` emits a
valueless attribute, and `false` omits it.

Supported core kinds are `paragraph`, `atx-heading`, `setext-heading`,
`indented-code`, `fenced-code`, `block-quote`, `list`, `list-item`,
`thematic-break`, `hard-break`, `code-span`, `emphasis`, `strong`, `link`,
`image`, and `autolink`. The `strikethrough` and `gfm:table` kinds require the
GFM or GitHub profile. The `github:alert` kind requires the GitHub profile.

## Security

Configuration is trusted application code, not an allowlist for untrusted
Markdown. It may add attributes such as `style` or event handlers. URL
attributes still follow the active HTML policy.

Use the curated HTML policy when the final fragment must remove attributes
outside its allowlist.

## Behavior

Attributes produced by the Markdown element take precedence over configured
defaults. Default classes are added first and merged with native or authored
classes.
