# Extensions

Extensions add trusted syntax, rendering, transformations, analysis, or
resource-backed behavior to an immutable factory profile. Install only the
capabilities required by the document format.

```php
use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Extension\Highlight\HighlightExtension;
use Alto\Markdown\Markdown;

$factory = Markdown::github()->with(
    new FootnoteExtension(),
    new HighlightExtension(),
);
```

Choose bundled extensions by outcome:

- [Syntax](syntax.md) adds author-facing Markdown constructs.
- [Rendering](rendering.md) projects or decorates document output.
- [Resources](resources.md) resolves bounded external content.

## Development

- [Compatibility](extensions/compatibility.md): understand extension versioning.
- [Extension points](extensions/extension-points.md): select the correct contract.
- [Custom extension](extensions/custom.md): register trusted application behavior.

## Capabilities

An extension has a stable name and implements only the public capabilities its
behavior requires.

| Capability | Public contract |
| --- | --- |
| Syntax | `BlockExtensionInterface`, `InlineExtensionInterface` |
| HTML | `HtmlDecoratorExtensionInterface` |
| Projection | `DocumentTransformExtensionInterface` |
| Lint | `LintExtensionInterface` |
| Format | `FormatterExtensionInterface` |
| Statistics | `StatsExtensionInterface` |
| Resources | `ResourceResolver` |

Definitions, contexts, and value objects under `Alto\Markdown\Extension` are
public unless marked `@internal`. Parser tapes, compact node IDs, compiler
caches, and internal dispatch objects are not extension contracts.

The returned factory is immutable. Extension compilation rejects invalid
names, duplicate or incompatible definitions, and unavailable parser triggers
before processing a document. Extensions execute as trusted application code.
