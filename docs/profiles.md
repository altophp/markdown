# Profiles

A profile defines which Markdown syntax Alto recognizes. Select it when you
create a factory, then reuse that factory for conversion, documents, builders,
and fragments.

## Choose a profile

| Profile | Adds | Choose it for |
| --- | --- | --- |
| `commonmark` | CommonMark only | Portable Markdown |
| `gfm` | Tables, task lists, strikethrough, extended autolinks, tag filter | GFM documents |
| `github` | GFM plus GitHub alerts and front matter | Repository files for GitHub |

```php
use Alto\Markdown\Markdown;

$commonmark = Markdown::commonmark();
$gfm = Markdown::gfm();
$github = Markdown::github();
```

Prefer the narrowest profile that matches the destination. The document keeps
that profile while it is queried, edited, and rendered.

## See what the profile changes

The same table source is a paragraph under CommonMark and a table under GFM:

```php
use Alto\Markdown\Markdown;

$source = "| Name | Value |\n| --- | --- |\n| Alto | Fast |\n";

$portableHtml = Markdown::commonmark()->toHtml($source);
$gfmHtml = Markdown::gfm()->toHtml($source);
```

Ask a compiled profile whether it supports a feature when application behavior
depends on it:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Profile\Feature;

$profile = Markdown::github()->profile();

$tables = $profile->supports(Feature::Tables);
$alerts = $profile->supports(Feature::GitHubAlerts);
$frontMatter = $profile->supports(Feature::FrontMatter);
```

Feature queries describe the factory. The parser itself uses compiled dispatch
tables rather than checking extensions for every line.

## Work with GitHub front matter

The GitHub profile recognizes a `---` or `+++` block only at the start of the
document:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "---\ntitle: Guide\n---\n\n# Guide\n",
);

$frontMatter = $document->frontMatter();
$content = $frontMatter?->content();
```

Alto keeps front matter opaque until the application explicitly supplies a
decoder. This example uses JSON, which is also valid YAML:

```php
use Alto\Markdown\Extension\FrontMatter\CallbackFrontMatterDecoder;

$decoder = new CallbackFrontMatterDecoder(
    static function (string $content, string $fence): array {
        if ('---' !== $fence) {
            throw new \DomainException('Expected YAML-compatible front matter.');
        }

        $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    },
);

$document = Markdown::github()->fromString(
    "---\n{\"title\":\"Guide\"}\n---\n\n# Guide\n",
);
$metadata = $document->frontMatter()?->decode($decoder);
```

`decode()` passes the current content bytes and the exact `---` or `+++`
fence. It does not change the document. Implement `FrontMatterDecoder` to
adapt a YAML or TOML library already selected by the application. Decoder
exceptions propagate unchanged.

Under `commonmark` or `gfm`, the same bytes keep their normal Markdown meaning.
GitHub alerts follow the same rule: they become dedicated nodes only in the
`github` profile and remain blockquote content in narrower profiles.
