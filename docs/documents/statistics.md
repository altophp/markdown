# Statistics

`stats()` computes one immutable summary from the current document and active
extension metrics.

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Report\n\nA short paragraph with [docs](https://example.com).\n",
);

$stats = $document->stats();

$words = $stats->wordCount;
$minutes = $stats->readingTimeMinutes;
$outline = $stats->outline;
$hosts = $stats->linksByHost;
```

This source has 6 words, a 1-minute reading time, an outline containing
`# Report`, and one link for `example.com`.

## Available metrics

The summary contains heading counts and depth, an ordered outline, per-section
word counts, links by type and host, image and code-block counts, code
languages, words, characters, reading time, GFM table count, and namespaced
extension metrics.

Code spans and link labels count as visible text. Code-block payloads, HTML
blocks, images, and Markdown punctuation do not. Duplicate section titles stay
distinct because section metrics preserve source order.

Statistics describe the current in-memory document. Run `stats()` again after
an edit when the application needs updated values. See [Queries](queries.md)
for individual nodes and [Extension points](../extensions/extension-points.md)
for custom metrics.
