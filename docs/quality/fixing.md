# Fixing

`fix()` applies only the safe corrections attached to enabled lint rules. It
does not guess missing content or author intent.

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

The result wraps the bare URL and adds the final newline:

```markdown
# Guide

Visit <https://example.com>
```

Built-in fixes cover bare URLs, code-fence info spacing, trailing spaces,
final newlines, missing code languages when a default is configured, and
unambiguous conversion of indented code to fenced code.

Report-only rules leave decisions to the caller. For example, Alto does not
invent missing table cells or choose where an unclosed fence should end.

Fixes are byte insertions, replacements, or deletions. Alto validates their
ranges and rejects overlapping changes before updating the document journal.
Preview the result with `diff()` before saving a file. See [Editing](../documents/editing.md)
for conflict-safe persistence.
