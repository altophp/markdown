# Description lists

`DescriptionListExtension` adds terms and descriptions using a compact block
syntax. It renders them as semantic `<dl>`, `<dt>`, and `<dd>` elements.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Add the extension to any factory profile:

```php
use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new DescriptionListExtension());
```

## Markdown

```markdown
Term
: Definition
```

## HTML

```html
<dl>
<dt>Term</dt>
<dd>Definition</dd>
</dl>
```

## Options

This extension has no configuration options.

## Security

Terms and descriptions use the normal Markdown renderers. Text, links, and
other inline content remain subject to the active HTML policy. The extension
does not enable raw HTML or access external resources.

## Behavior

Adjacent terms and descriptions share one description list. A list can have
multiple terms, multiple descriptions, and inline Markdown in either part.

Indent nested blocks under a description:

```markdown
Term
: First paragraph

  - one
  - two
```

```html
<dl>
<dt>Term</dt>
<dd>
<p>First paragraph</p>
<ul>
<li>one</li>
<li>two</li>
</ul>
</dd>
</dl>
```

A description marker requires a preceding term and a space after `:`.
Invalid markers remain ordinary Markdown.
