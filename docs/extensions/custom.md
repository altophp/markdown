# Custom extension

This extension adds one document metric without changing syntax or rendering.
It demonstrates registration and namespacing with a complete small
implementation.

```php
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsMetric;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Markdown;

final readonly class AcmeMetrics implements StatsExtensionInterface
{
    public function name(): string
    {
        return 'acme';
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition(
            name: 'block-count',
            summary: 'Number of parsed blocks.',
            factory: static fn (): StatsMetric => new BlockCount(),
        );
    }
}

final readonly class BlockCount implements StatsMetric
{
    public function measure(StatsContext $context): int
    {
        return count($context->blocks());
    }
}

$document = Markdown::github()
    ->with(new AcmeMetrics())
    ->fromString("# Guide\n\nBody.\n");

$metrics = $document->stats()->extensionStats;
```

The value is stored under the extension and metric names, preventing unrelated
packages from claiming the same metric identity. A metric receives an immutable
context and returns only a finite scalar value or `null`.

Use another capability interface only when the extension owns that behavior.
Do not implement parser syntax merely to run analysis, and do not perform
resource I/O without an injected resolver. See [Extension points](extension-points.md)
for the available contracts.
