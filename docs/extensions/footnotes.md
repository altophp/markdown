# Footnotes

`FootnoteExtension` adds reference markers and block definitions. It collects
used definitions into an accessible footnote section after the document.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Add the extension to any factory profile:

```php
use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new FootnoteExtension());
```

## Markdown

```markdown
Text[^note].

[^note]: Footnote with **strong**.
```

## HTML

```html
<p>Text<sup id="fnref-1"><a href="#fn-1" role="doc-noteref">1</a></sup>.</p>
<div class="footnotes" role="doc-endnotes">
<hr />
<ol>
<li id="fn-1" role="doc-endnote">
<p>Footnote with <strong>strong</strong>. <a href="#fnref-1" role="doc-backlink">↩</a></p>
</li>
</ol>
</div>
```

## Options

This extension has no configuration options.

## Security

Footnote content uses the normal Markdown renderers and remains subject to the
active HTML policy. Generated IDs, links, and ARIA roles are owned by the
extension. It performs no resource access and does not enable raw HTML.

## Behavior

Reference order determines numbering, regardless of definition order.
Repeated references share one footnote and receive distinct return links.

Definitions can contain indented nested blocks. Unused definitions are not
rendered. Missing references, escaped markers, markers inside code spans, and
invalid labels remain literal text.
