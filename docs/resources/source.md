# Source

`SourceExtension` renders a bounded resource with its path and optional source
metadata such as a title, line numbers, and highlighted lines.

## Install

`SourceExtension` is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\Source\SourceExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['php'],
    maxBytes: 100_000,
);

$markdown = Markdown::commonmark()->with(new SourceExtension($resolver));
```

Assume `content/src/App.php` contains:

```text
one
<two>
```

## Markdown

```markdown
@source "src/App.php" {title: "Application", numbers: true, highlight: 2}
```

## HTML

```html
<div class="source-block">
<div class="source-title">Application</div>
<div class="source-path">src/App.php</div>
<pre><code class="language-php"><span class="line"><span class="line-number" data-line="1" aria-hidden="true">1</span>one</span>
<span class="line highlighted"><span class="line-number" data-line="2" aria-hidden="true">2</span>&lt;two&gt;</span>
</code></pre>
</div>
```

## Options

Options follow the quoted resource reference inside braces.

| Option | Default | Effect |
| --- | --- | --- |
| `lines` | All lines | Selects one 1-based line or an inclusive range such as `9-12` |
| `lang` | Detected from path | Overrides the code language |
| `title` | None | Adds an escaped visible title; the value must be double-quoted |
| `numbers` | `false` | Adds the original line number to every rendered line |
| `highlight` | None | Highlights line numbers or ranges such as `2, 5-7` |

Line and highlight numbers refer to the original resource, including when
`lines` selects a smaller range. At most 64 highlight ranges are accepted.
Line numbers cannot exceed 1,000,000; language identifiers are limited to 64
bytes and titles to 256 bytes.

## Security

The directive must start at column zero outside an open paragraph. Resource
bytes, paths, and titles are escaped before rendering. Invalid directives stay
literal and perform no I/O.

The resolver defines the available resources. `FilesystemResourceResolver`
accepts relative paths only, rejects parent traversal and symlink components,
and enforces both the extension allowlist and `maxBytes`.

## When a resource is rejected

Check that the requested path is relative to the configured resolver root,
uses an allowed extension, and fits the byte limit. Verify the file exists and
is readable. Keep the root bounded when correcting a rejected path; do not
broaden filesystem authority to accept untrusted references. See
[Security](../security.md) and [Errors](../errors.md).
