# Link rewriting

`LinkRewriterExtension` changes link, image, and autolink destinations while
rendering. Use it to move documentation under a base URI, replace known paths,
or apply an application-specific URL rule without editing the source.

## Install

This extension is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\LinkRewrite\LinkRewriter;
use Alto\Markdown\Extension\LinkRewrite\LinkRewriterExtension;
use Alto\Markdown\Extension\LinkRewrite\LinkDestinationContext;
use Alto\Markdown\Markdown;

$rewriter = LinkRewriter::map([
    '/guide' => '/v2/guide',
    '/logo.png' => 'https://cdn.example/logo.png',
]);

$markdown = Markdown::commonmark()->with(
    new LinkRewriterExtension($rewriter),
);
```

## Markdown

```markdown
[Guide](/guide) ![Logo](/logo.png)
```

## HTML

```html
<p><a href="/v2/guide">Guide</a> <img src="https://cdn.example/logo.png" alt="Logo" /></p>
```

## Options

Build a rewriter with one or more strategies:

```php
$base = LinkRewriter::baseUri('https://docs.example/base');
$mapped = LinkRewriter::map(['/old' => '/new']);
$pattern = LinkRewriter::pattern('~^/v1/~', '/v2/');
$callback = LinkRewriter::callback(
    static fn (LinkDestinationContext $context): string => $context->destination,
);

$rewriter = LinkRewriter::compose($base, $mapped, $pattern)
    ->then($callback);
```

`baseUri()` prefixes path-like destinations. It leaves empty, fragment-only,
query-only, scheme-relative, and absolute destinations unchanged. `map()`
replaces exact destinations. `pattern()` uses `preg_replace()`. `callback()`
receives a `LinkDestinationContext`. Composed strategies run in declaration
order.

## Security

Every strategy result is validated as a Markdown destination. The active HTML
policy still escapes the URL and filters unsafe schemes before output.

## Behavior

The extension rewrites destinations only for HTML rendering. The retained
document and its Markdown output stay unchanged. Call
`$rewriter->rewriteDocument($document)` when source links and images must be
edited explicitly. It requires Alto's parsed document model and must run before
any other pending edit. Reference-style destinations and overlapping nested
ranges remain untouched in that mutation lane.
