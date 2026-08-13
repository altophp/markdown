# Linting

Linting reports structural, link, accessibility, code, and style problems
without changing the document.

## Run the recommended rules

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\nVisit https://example.com",
);
$report = $document->lint(LintConfig::recommended());

foreach ($report as $problem) {
    echo $problem->ruleId.': '.$problem->message."\n";
}
```

`LintReport` is iterable and countable. `isClean()` means no problem was
reported; `hasErrors()` means at least one problem has error severity. Each
problem carries its rule ID, severity, message, original byte range, and an
optional safe fix.

## Derive a policy

`LintConfig` is immutable:

```php
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintSeverity;

$config = LintConfig::recommended()
    ->withoutRule('no-bare-urls')
    ->withRule('require-code-block-language')
    ->withSeverity('require-code-block-language', LintSeverity::Warning);
```

Rules with options accept their dedicated `LintRuleOptions` type. Unknown
rules and invalid option types fail before traversal.

The built-in set covers title and heading structure, dead anchors and
references, image alternatives, empty and bare links, code fence language and
closure, GFM table columns, trailing spaces, and final newlines.

Continue with [Fixing](fixing.md) to apply attached safe fixes. Use
[Formatting](formatting.md) for syntax style rather than diagnostics.
