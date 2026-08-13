# Extension points

Choose the narrowest extension contract that owns the required behavior. Alto
compiles all registered definitions when the factory is created.

| Need | Contract |
| --- | --- |
| Block syntax and output | `BlockExtensionInterface` |
| Leaf inline syntax and output | `InlineExtensionInterface` |
| Native or semantic-link HTML decoration | `HtmlDecoratorExtensionInterface` |
| Render-only document projection | `DocumentTransformExtensionInterface` |
| Link destination rewriting | `LinkRewriterExtension` |
| Lint rules and safe fixes | `LintExtensionInterface` |
| Formatter passes | `FormatterExtensionInterface` |
| Document metrics | `StatsExtensionInterface` |
| External resource reads | injected `ResourceResolver` |

## Registration

`with()` returns a new factory and leaves the original unchanged:

```php
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Markdown;

$base = Markdown::github();
$extended = $base->with(new HeadingPermalinkExtension());
```

Extension names and local definition names form qualified node kinds such as
`acme:callout`. Definitions with invalid names, reserved parser triggers, or
conflicting contracts fail while the profile is compiled.

## Trust boundary

Extensions are PHP code and therefore trusted. HTML callbacks must escape
dynamic values through their output context. Source transforms must return
validated patches. Resource behavior must receive explicit authority from the
application.

See [Custom extension](custom.md) for a complete small metric and
[Compatibility](compatibility.md) for the public versioning boundary.
