# Include

`IncludeExtension` expands bounded Markdown resources into the current
document and parses them with the same compiled profile.

## Install

`IncludeExtension` is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\Include\IncludeExtension;
use Alto\Markdown\Extension\Include\IncludePolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Resource\FilesystemResourceResolver;

$resolver = new FilesystemResourceResolver(
    root: __DIR__.'/content',
    allowedExtensions: ['md'],
    maxBytes: 256_000,
);

$markdown = Markdown::commonmark()->with(new IncludeExtension(
    $resolver,
    new IncludePolicy(maxDepth: 4, maxResources: 16),
));
```

Assume `content/parts/setup.md` contains:

```markdown
## Install

Run **Composer**.
```

## Markdown

```markdown
Before.

@include "parts/setup.md"

After.
```

## HTML

```html
<p>Before.</p>
<h2>Install</h2>
<p>Run <strong>Composer</strong>.</p>
<p>After.</p>
```

## Options

The directive has no inline options. `IncludePolicy` bounds the complete
expansion and the parser used for included content.

| Option | Default | Effect |
| --- | --- | --- |
| `maxDepth` | `8` | Maximum recursive include depth |
| `maxResources` | `64` | Maximum resources resolved per parse |
| `maxExpandedBytes` | `1,048,576` | Maximum total bytes across included resources |
| `maxNestingDepth` | `128` | Maximum Markdown block nesting in included content |
| `maxBlockCount` | `50,000` | Maximum parsed blocks in included content |
| `maxInlineCount` | `200,000` | Maximum parsed inline nodes in included content |
| `maxReferenceCount` | `10,000` | Maximum link-reference definitions in included content |

## Security

The resolver owns access control and per-resource size limits. The include
policy adds aggregate byte, recursion, and parser limits. With
`FilesystemResourceResolver`, only relative allowlisted files below the
configured root are available; traversal and symlink components are rejected.

## Behavior

Nested references resolve relative to their parent resource. Repeated
non-cyclic resources are allowed and count toward the resource limit. Cycles
are rejected by resolved resource ID. Include directives inside fenced code,
raw HTML, or custom containers remain literal.

The original `@include` directive is preserved when the document is rendered
back to Markdown. Included HTML follows the active HTML policy.
