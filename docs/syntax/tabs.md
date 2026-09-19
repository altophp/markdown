# Tabs

`TabsExtension` groups Markdown into named panels while keeping the content
available to the normal parser and renderer.

## Install

`TabsExtension` is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\Tabs\TabsExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(new TabsExtension());
```

## Markdown

```markdown
@tabs
@tab PHP
Use Composer.
@tab CLI
Run the command.
@endtabs
```

## HTML

```html
<div class="markdown-tabs" id="markdown-tabs-1">
<div class="markdown-tabs-list">
<a class="markdown-tabs-tab is-active" id="markdown-tabs-1-tab-1" href="#markdown-tabs-1-panel-1" aria-controls="markdown-tabs-1-panel-1">PHP</a>
<a class="markdown-tabs-tab" id="markdown-tabs-1-tab-2" href="#markdown-tabs-1-panel-2" aria-controls="markdown-tabs-1-panel-2">CLI</a>
</div>
<div class="markdown-tabs-panels">
<div class="markdown-tabs-panel is-active" id="markdown-tabs-1-panel-1" aria-labelledby="markdown-tabs-1-tab-1">
<p>Use Composer.</p>
</div>
<div class="markdown-tabs-panel" id="markdown-tabs-1-panel-2" aria-labelledby="markdown-tabs-1-tab-2">
<p>Run the command.</p>
</div>
</div>
</div>
```

## Options

`TabsExtension` has no application-level configuration options.

## Security

Tab titles are escaped as text, and panel content follows the active HTML
policy. The extension performs no resource or network access.

## Behavior

The first tab and panel receive the `is-active` class. The extension generates
stable links and associations between tabs and panels; the application owns
their styling and interactive behavior.

Nested groups use one additional `@` per level: `@@tabs`, `@@tab`, and
`@@endtabs`. A title may be quoted, cannot be empty, and is limited to 256
bytes. Nesting is limited to 32 levels.
