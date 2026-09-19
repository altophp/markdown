# HTML

Use direct conversion when HTML is the only result. Use a document when the
same parse must also support queries, linting, statistics, or edits.

## Render a document

```php
use Alto\Markdown\Markdown;

$html = Markdown::github()->toHtml(
    "# Release notes\n\nNothing broke.\n",
);
```

The same profile produces the same HTML through a retained document:

```php
$document = Markdown::github()->fromString(
    "# Release notes\n\nNothing broke.\n",
);

$html = $document->toHtml();
```

Direct conversion avoids creating the query and edit workspace. It is the
recommended path for a single render.

## Render an inline fragment

Use `toInlineHtml()` for labels, comments, and other fragments that must not
receive a paragraph wrapper:

```php
$html = Markdown::github()->toInlineHtml(
    'Read **carefully** in [the guide](/guide).',
);
```

Block syntax stays inactive in this lane. Reference definitions are not
extracted, while inline extensions and HTML security policies still apply.

## Choose an HTML policy

The default policy escapes raw HTML and filters unsafe URL schemes. Pass a
`RenderOptions` value only when the application has an explicit reason to use
another policy.

Read [Security](../security.md) before enabling authored HTML. Continue
with [Markdown](markdown.md) when output must remain Markdown.
