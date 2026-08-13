# Extensions API

An extension has a stable name and implements only the public capability
interfaces required by its behavior.

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

```php
$extended = $factory->with($firstExtension, $secondExtension);
```

The returned factory is immutable. Extension compilation rejects invalid
names, duplicate or incompatible definitions, and unavailable parser triggers
before processing a document.

Read [Extension points](../extensions/extension-points.md) to choose a contract,
[Custom extension](../extensions/custom.md) for an implementation, and
[Compatibility](../extensions/compatibility.md) before declaring a package
version range.
