# Import

`ImportExtension` reads a bounded resource and renders its bytes as an escaped
code block without parsing them as Markdown.

## Install

`ImportExtension` is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\Import\ImportExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['php'],
    maxBytes: 100_000,
);

$markdown = Markdown::commonmark()->with(new ImportExtension($resolver));
```

Assume `content/snippet.php` contains:

```php
<?php
echo 'Hello';
```

## Markdown

```markdown
@import "snippet.php" {lang: php}
```

## HTML

```html
<pre><code class="language-php">&lt;?php
echo &apos;Hello&apos;;
</code></pre>
```

## Options

Options follow the quoted resource reference inside braces.

| Option | Default | Effect |
| --- | --- | --- |
| `lines` | All lines | Selects one 1-based line or an inclusive range such as `2-8` |
| `lang` | None | Adds the corresponding `language-*` class to the code element |
| `indent` | `0` | Adds up to 32 spaces to every imported line |

Line numbers cannot exceed 1,000,000. Language identifiers are limited to 64
bytes. An invalid directive remains literal and performs no resource read.

## Security

The directive must start at column zero outside an open paragraph. Imported
bytes are always escaped as code, including under permissive HTML policies.
Resolution happens once while parsing; later renders perform no I/O.

The resolver defines the available resources. `FilesystemResourceResolver`
accepts relative paths only, rejects parent traversal and symlink components,
and enforces both the extension allowlist and `maxBytes`.

## When a resource is rejected

Check that the requested path is relative to the configured resolver root,
uses an allowed extension, and fits the byte limit. Verify the file exists and
is readable. Keep the root bounded when correcting a rejected path; do not
broaden filesystem authority to accept untrusted references. See
[Security](../security.md) and [Errors](../errors.md).
