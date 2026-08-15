# External links

`ExternalLinkExtension` classifies destinations that contain a host and
decorates external ones with controlled classes, relations, and target
behavior.

## Install

This extension is bundled with `alto/markdown`:

```bash
composer require alto/markdown
```

## Configure

List the hosts that belong to the application:

```php
use Alto\Markdown\Extension\ExternalLink\ExternalLinkExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkPolicy;
use Alto\Markdown\Markdown;

$markdown = Markdown::github()->with(
    new ExternalLinkExtension(new ExternalLinkPolicy(
        internalHosts: ['internal.test'],
    )),
);
```

## Markdown

```markdown
See [outside](https://outside.test/docs) and [inside](https://internal.test/docs).
```

## HTML

```html
<p>See <a rel="noopener noreferrer" href="https://outside.test/docs">outside</a> and <a href="https://internal.test/docs">inside</a>.</p>
```

## Options

`ExternalLinkPolicy` controls classification and every added attribute.

| Option | Default | Purpose |
| --- | --- | --- |
| `internalHosts` | `[]` | Hosts classified as internal |
| `includeSubdomains` | `false` | Classify subdomains of internal DNS hosts as internal |
| `openInNewWindow` | `false` | Add `target="_blank"` to external links |
| `htmlClass` | `''` | Class added to external links |
| `nofollow` | `ExternalLinkScope::None` | Scope receiving `nofollow` |
| `noopener` | `ExternalLinkScope::External` | Scope receiving `noopener` |
| `noreferrer` | `ExternalLinkScope::External` | Scope receiving `noreferrer` |

Each relation accepts `ExternalLinkScope::None`, `All`, `Internal`, or
`External`.

## Security

The extension classifies links and adds attributes; it does not make a URL
safe. Every destination remains subject to the active HTML policy, which
filters unsafe schemes and escapes emitted attributes.

`noopener` and `noreferrer` apply to external links by default. Keep these
relations enabled when opening external links in a new window.

## Behavior

Host comparison is case-insensitive and ignores a trailing dot. Subdomain
matching is disabled unless `includeSubdomains` is enabled. A sibling domain
such as `notexample.com` never matches `example.com`.

Absolute and scheme-relative destinations can contain a host. Relative links,
fragments, and email links do not.
