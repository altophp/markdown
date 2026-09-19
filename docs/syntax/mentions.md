# Mentions

`MentionExtension` recognizes application-defined identifiers and resolves
them to safe links.

## Install

`MentionExtension` is bundled with `alto/markdown`.

```bash
composer require alto/markdown
```

## Configure

```php
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Markdown;

$markdown = Markdown::commonmark()->with(new MentionExtension(
    MentionDefinition::links(
        type: 'user',
        prefix: '@',
        pattern: '[A-Z0-9](?:[A-Z0-9-]{0,38})(?![A-Z0-9-])',
        urlTemplate: 'https://github.com/%s',
    ),
));
```

## Markdown

```markdown
Ask @Ada-Lovelace.
```

## HTML

```html
<p>Ask <a href="https://github.com/Ada-Lovelace">@Ada-Lovelace</a>.</p>
```

## Options

Each `MentionDefinition` configures one mention type.

| Option | Default | Effect |
| --- | --- | --- |
| `type` | Required | Stable lowercase name used in the qualified node kind |
| `prefix` | Required | Trigger such as `@`, `@@`, or `#` |
| `pattern` | Required | PCRE fragment matched after the prefix |
| `resolver` | Required | Resolves an identifier to a URL, label, and optional title |
| `maxIdentifierBytes` | `128` | Rejects longer candidates before resolution |

`MentionDefinition::links()` creates a URL-template resolver. Its template
must contain exactly one `%s`; the identifier is URL-encoded before insertion.
Pass a custom `MentionResolver` when resolution depends on application data or
when the visible label and title must differ from the source.

## Security

Resolved URLs pass through the active URL policy. Labels and titles are
escaped before rendering. A custom resolver is trusted application code and
must enforce any authorization required by its data source.

## Behavior

Definitions may share a prefix and are evaluated in registration order. A
resolver may return `null` to let the next definition try the same candidate.
Mentions do not start inside words and never create nested links. Generated
destinations pass through the active URL policy.
