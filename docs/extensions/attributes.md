# Attributes

`AttributesExtension` adds constrained HTML attributes to Markdown elements.
Attribute names are explicitly allowed by `AttributesPolicy` before source
content can use them.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Allow the attributes required by the application:

```php
use Alto\Markdown\Extension\Attributes\AttributesExtension;
use Alto\Markdown\Extension\Attributes\AttributesPolicy;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(
    new AttributesExtension(new AttributesPolicy([
        'id',
        'class',
        'title',
    ])),
);
```

## Markdown

```markdown
{#intro .lead title="Welcome home"}
# Hello
```

## HTML

```html
<h1 class="lead" id="intro" title="Welcome home">Hello</h1>
```

## Options

Without an explicit policy, only `id` and `class` are allowed.

| Option | Default | Purpose |
| --- | ---: | --- |
| `allowed` | `['id', 'class']` | HTML attribute names accepted from Markdown |
| `maxAttributes` | `32` | Maximum attributes in one list |
| `maxListBytes` | `1024` | Maximum byte length of one attribute list |
| `maxValueBytes` | `512` | Maximum byte length of one value |

Event handler names such as `onclick` cannot be allowed. Attribute names are
matched case-insensitively.

## Security

The policy is an allowlist for attributes authored in Markdown. Event handler
names are always rejected, and configured length limits bound each parsed
attribute list.

URL attributes remain subject to the active HTML policy. Allow only attributes
the application intends to expose to content authors.

## Behavior

A block attribute list before a block decorates the next sibling. Prefix a
list with `:` to decorate the previous sibling instead:

```markdown
> Quoted text
> {: .quoted}
```

```html
<blockquote>
<p class="quoted">Quoted text</p>
</blockquote>
```

An inline list decorates only the rendered element immediately before it:

```markdown
Read the *important*{.accent} note.
```

```html
<p>Read the <em class="accent">important</em> note.</p>
```

Attributes native to the Markdown element take precedence. Classes from the
element and the attribute list are merged without duplicates.
