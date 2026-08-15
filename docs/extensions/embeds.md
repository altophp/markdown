# Embeds

`EmbedExtension` replaces an allowlisted URL with HTML returned by an
application-provided resource resolver. The extension grants no network or
filesystem access by itself.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

Inject a resolver, an explicit host policy, and an HTML policy appropriate for
the resolver's output:

```php
use Alto\Markdown\Extension\Embed\EmbedExtension;
use Alto\Markdown\Extension\Embed\EmbedPolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Resource\CallbackResourceResolver;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;

$resolver = new CallbackResourceResolver(
    static fn (ResourceRequest $request): ResolvedResource => new ResolvedResource(
        'embed:'.hash('sha256', $request->reference),
        '<iframe src="https://video.example/embed/1"></iframe>',
    ),
);

$markdown = Markdown::github()->with(
    new EmbedExtension(
        $resolver,
        new EmbedPolicy(['video.example']),
    ),
);

$options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
$html = $markdown->toHtml(
    "https://video.example/watch?v=1\n",
    renderOptions: $options,
);
```

## Markdown

```markdown
https://video.example/watch?v=1
```

## HTML

```html
<iframe src="https://video.example/embed/1"></iframe>
```

## Options

`EmbedPolicy` requires at least one allowed host.

| Option | Default | Purpose |
| --- | --- | --- |
| `includeSubdomains` | `false` | Allow subdomains of configured DNS hosts |
| `allowHttp` | `false` | Allow plain HTTP in addition to HTTPS |
| `fallback` | `EmbedFallback::Link` | Render a link when the embed cannot be emitted |
| `maxUrlBytes` | `2048` | Maximum input URL length |
| `maxHtmlBytes` | `262144` | Maximum resolved HTML length |

Set `fallback` to `EmbedFallback::Remove` to omit a recognized embed when its
HTML cannot be emitted, for example when the host or active HTML policy rejects
it. Invalid Markdown remains literal, and resolver errors still propagate.

## Security

An embed is recognized only when one valid URL occupies a root-level line.
HTTPS and the scheme's default port are required unless the policy explicitly
allows HTTP. Host checks use exact DNS boundaries, and URLs with user
information are rejected.

The default safe HTML policy does not emit resolver HTML. With the default
link fallback, the example instead renders:

```html
<p><a href="https://video.example/watch?v=1">https://video.example/watch?v=1</a></p>
```

Use `HtmlPolicy::spec()` only when the complete resolver output is trusted.
For other rich output, attach an application sanitizer to the safe policy.
