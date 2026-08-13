# Nodes API

Typed handles expose semantic values and the mutations Alto can lower safely
to source patches.

| Handle | Read | Change |
| --- | --- | --- |
| `Section` | `title()`, `exists()` | `rename()`, `append()`, `prepend()`, `replaceBody()`, `remove()` |
| `Heading` | `level()`, `text()` | `rename()` and top-level block operations |
| `CodeBlock` | `language()`, `code()` | `setLanguage()`, `replaceCode()` |
| `Link` | `text()`, `destination()`, `titleAttribute()` | `setDestination()`, `setTitleAttribute()` |
| `Image` | `altText()`, `destination()` | `setDestination()`, `setAltText()` |
| `FrontMatter` | `content()`, `fence()`, `decode()` | `replaceContent()` |
| `Block` | kind and source range | insert, move, clone, replace, wrap, remove |

```php
$section = $document->section('Install');

if ($section->exists()) {
    $section->replaceBody("Run Composer.\n");
}
```

A handle belongs to its document and generation. A replacement, wrap, save, or
structural reparse can make an earlier handle stale. Use returned handles or
query the current document again.

Generic block movement and insertion accept only direct children of the
document root. Typed mutations retain their own documented boundaries. See
[Editing](../documents/editing.md) for worked operations.
