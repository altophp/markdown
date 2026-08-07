# Lint, fix, and format

Lint reports policy problems. Fix applies only corrections that do not require
guessing author intent. Format applies one conservative, deterministic style.
All changes use the same source-patch journal.

## Find policy problems

Create an immutable configuration and pass it to the document:

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintSeverity;
use Alto\Markdown\Markdown;

$config = new LintConfig(
    enabledRules: ['require-title', 'no-dead-anchor', 'no-bare-urls', 'final-newline'],
    severityByRule: ['no-bare-urls' => LintSeverity::Warning],
);

$document = Markdown::github()->fromString(
    "# Guide\n\nVisit https://example.com",
);
$report = $document->lint($config);

foreach ($report as $problem) {
    echo $problem->ruleId.': '.$problem->message."\n";
}
```

The report includes:

```text
no-bare-urls: Bare URL should be wrapped in angle brackets.
final-newline: Document must end with a newline.
```

`LintReport` is iterable and countable. `isClean()` means it contains no
problem; `hasErrors()` means at least one problem has error severity. Each
problem includes its rule ID, severity, message, original byte range, and
optional safe fix.

Create one `Linter` with a `LintConfig` when several documents share a policy.
Extension rules use qualified IDs and remain opt-in. Unknown IDs fail before
traversal with `InvalidMarkdownArgumentException`.

`LintConfig::recommended()` selects Alto's stable default policy. Its immutable
`withRule()`, `withoutRule()`, `withSeverity()`, and `withOptions()` methods are
convenient when deriving a nearby policy.

### Configure a rule

Built-in rules with options accept a dedicated type, not a nested array:

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\Linter;
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Markdown;

$config = new LintConfig(
    enabledRules: ['require-code-block-language'],
    optionsByRule: [
        'require-code-block-language' => new RequireCodeBlockLanguageOptions(
            defaultLanguage: 'plaintext',
        ),
    ],
);
$linter = new Linter($config);
$documentA = Markdown::github()->fromString("```\necho 'A';\n```\n");
$documentB = Markdown::github()->fromString("```php\necho 'B';\n```\n");

$reportA = $linter->lint($documentA);
$reportB = $linter->lint($documentB);
```

Set `defaultLanguage` to `null` to report missing languages without offering a
fix. `RuleRegistry::metadata()` lists each rule's category, default severity,
fixability, recommended status, and option class. A wrong option type fails
before document traversal.

## Apply safe fixes

`fix()` applies the fixes attached to the selected rules:

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;

$config = new LintConfig(
    enabledRules: ['no-bare-urls', 'final-newline'],
);

$document = Markdown::github()->fromString(
    "# Guide\n\nVisit https://example.com",
);
$document->fix($config);

$markdown = $document->toMarkdown();
```

The result is:

```markdown
# Guide

Visit <https://example.com>
```

Built-in rules:

| Rule | Category | Safe fix |
| --- | --- | --- |
| `single-h1` | Structure | No |
| `no-skipped-heading-levels` | Structure | No |
| `require-title` | Structure | No |
| `no-duplicate-headings` | Structure | No |
| `consistent-table-columns` | Structure | No |
| `no-dead-anchor` | Links | No |
| `require-image-alt` | Accessibility | No |
| `no-empty-links` | Links | No |
| `no-bare-urls` | Links | Yes |
| `no-dead-reference-definitions` | Links | No |
| `require-code-block-language` | Code | When configured |
| `code-fence-info-spacing` | Code | Yes |
| `prefer-fenced-code-blocks` | Code | When unambiguous |
| `require-closed-code-fence` | Code | No |
| `no-trailing-spaces` | Style | Yes |
| `final-newline` | Style | Yes |

Report-only rules identify real problems but leave decisions to the caller.
For example, Alto reports ragged GFM table rows and unclosed code fences but
does not guess whether to add cells, discard content, or place a closing marker.

Fixes are byte insertions, replacements, or deletions. Alto validates their
ranges and rejects overlaps before adding them to the document journal.

## Format predictable Markdown

Formatting changes syntax style, not prose or meaning:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;

$document = Markdown::github()->fromString("* One\n* Two");
$style = new MarkdownStyle(
    bulletMarker: '-',
    orderedListDelimiter: '.',
    fenceMarker: '`',
    finalNewline: true,
);

$document->format($style);
$markdown = $document->toMarkdown();
```

The result is:

```markdown
- One
- Two
```

Formatting passes run in this order:

| Pass | What it normalizes |
| --- | --- |
| `no-trailing-spaces` | Non-semantic trailing whitespace |
| `atx-heading-spacing` | Space after an ATX marker |
| `fence-marker` | Backtick or tilde fences |
| `fence-info-spacing` | Space before a fence info string |
| `bullet-marker` | Unordered-list markers |
| `ordered-list-delimiter` | `.` or `)` after ordered-list numbers |
| `table-delimiter` | Top-level GFM table delimiter rows |
| `reference-definition-spacing` | Space after `:` in single-line reference definitions |
| `blank-lines` | Required structural blank lines |
| `final-newline` | End-of-file newline |

`format()` changes the document immediately. Use `diff()` to preview pending
changes and `save()` to persist them. A second format pass is a no-op.

The formatter does not rewrap prose or rewrite code, raw HTML, inline code, or
front-matter payloads. It skips an ordered-list boundary that could merge two
lists, nested table delimiter rows whose physical source prefix is ambiguous,
and multiline reference definitions. Extension passes run after built-ins in
deterministic order and use the same validation and conflict checks.
