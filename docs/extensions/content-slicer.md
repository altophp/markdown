# Content slicer

`ContentSlicerExtension` groups root-level heading sections in semantic
`<section>` elements. It changes HTML structure without changing the Markdown
document.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Add the extension to any factory profile:

```php
use Alto\Markdown\Extension\ContentSlicer\ContentSlicerExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new ContentSlicerExtension());
```

## Markdown

```markdown
# Main

Intro.

## Install

Run Composer.
```

## HTML

```html
<h1>Main</h1>
<p>Intro.</p>
<section>
<h2>Install</h2>
<p>Run Composer.</p>
</section>
```

## Options

The constructor accepts the first heading level that opens a section:

```php
$markdown = Markdown::github()->with(
    new ContentSlicerExtension(minLevel: 3),
);
```

`minLevel` defaults to `2` and must be between `1` and `6`. Deeper matching
headings create nested sections.

## Security

The extension introduces no source syntax, resource access, or raw HTML. It
wraps existing rendered blocks in generated `<section>` elements. The active
HTML policy still controls the final fragment.

## Behavior

Only headings in the document root participate in the outline. Headings
inside block quotes and lists keep their normal HTML without opening sections.

The transformation is render-only. `toMarkdown()` preserves the original
source. The curated HTML policy preserves the content but unwraps `<section>`
elements that are outside its allowed HTML subset.
