# Code block titles

`CodeBlockTitleExtension` reads a title from a fenced code block's info string.
It wraps the code block in a figure with a visible caption.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Add the extension to any factory profile:

```php
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitleExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new CodeBlockTitleExtension());
```

## Markdown

````markdown
```php title="src/App.php"
<?php echo 1;
```
````

## HTML

```html
<figure class="code-block has-title" data-title="src/App.php">
<figcaption class="code-title">src/App.php</figcaption>
<pre><code class="language-php">&lt;?php echo 1;
</code></pre>
</figure>
```

## Options

Pass a `CodeBlockTitlePolicy` to change the wrapper or parser limits:

```php
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitlePolicy;

$extension = new CodeBlockTitleExtension(new CodeBlockTitlePolicy(
    figureClass: 'code-block',
    captionClass: 'code-block-title',
    includeDataTitle: false,
));
```

| Option | Default | Purpose |
| --- | --- | --- |
| `figureClass` | `code-block has-title` | Classes added to the figure |
| `captionClass` | `code-title` | Class added to the caption |
| `includeDataTitle` | `true` | Add the title as `data-title` on the figure |
| `maxInfoBytes` | `4096` | Maximum fenced-code info string length |
| `maxTitleBytes` | `512` | Maximum decoded title length |

## Security

The title and code content are escaped before insertion into HTML. Parser
limits bound the info string and decoded title. The extension performs no I/O
and does not enable raw HTML.

## Behavior

Both quoted and unquoted values are accepted. `filename` is an alias when no
`title` is present. If both appear, `title` takes precedence.

A missing, malformed, or oversized title leaves the ordinary `<pre><code>`
output unchanged.
