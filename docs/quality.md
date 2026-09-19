# Quality

Quality tools report content problems, apply explicit safe fixes, and normalize
selected style without silently rewriting unrelated Markdown.

```php
$report = $document->lint($config);
$document->fix($config);
$document->format();
```

## Tools

- [Linting](quality/linting.md): report content and policy problems.
- [Fixing](quality/fixing.md): apply fixes attached to enabled rules.
- [Formatting](quality/formatting.md): normalize selected Markdown style.

Preview pending changes with `diff()` before saving a file.
