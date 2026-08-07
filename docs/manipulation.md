# Manipulation

Alto edits a source-backed document. Each supported mutation updates the current
model immediately and records the smallest safe source change for export or
save.

## Edit existing content

Sections are the simplest editing unit:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString(
    "# Guide\n\n## Install\n\nOld instructions.\n",
);

$document->section('Install')->replaceBody("Run Composer.\n");
$markdown = $document->toMarkdown();
```

The result changes only the section body:

```markdown
# Guide

## Install

Run Composer.
```

Available typed edits:

| Content | Read | Change |
| --- | --- | --- |
| `Section` | `title()`, `exists()` | `rename()`, `append()`, `prepend()`, `replaceBody()`, `remove()` |
| `Heading` | `level()`, `text()` | `rename()` |
| `CodeBlock` | `language()`, `code()` | `setLanguage()`, `replaceCode()` |
| `Link` | `text()`, `destination()`, `titleAttribute()` | `setDestination()`, `setTitleAttribute()` |
| `Image` | `altText()`, `destination()` | `setDestination()`, `setAltText()` |
| `FrontMatter` | `content()`, `fence()`, `decode()` | `replaceContent()` |
| Top-level `Block` | source range and kind | insert, move, clone, replace, or wrap |

Code language changes preserve the fence, remaining info string, payload, and
line endings. Indented code has no language token and rejects `setLanguage()`.

Changing one reference-style link localizes that use as inline syntax and
leaves the shared definition unchanged. Front matter remains opaque inside
Alto. `decode()` delegates explicitly to an application decoder, while
`replaceContent()` changes only the bytes between its existing fences.

Block insertion accepts a string or `MarkdownFragment`. V1 supports top-level
blocks only. It rejects nested insertion instead of guessing list or quote
prefixes, and never inserts content before existing front matter.

### Rearrange whole blocks

Whole-block operations stay on the handle:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Block;
use Alto\Markdown\Operation\BlockWrapper;

$document = Markdown::github()->fromString(
    "# Guide\n\nInstall instructions.\n\nUsage instructions.\n",
);

$paragraphs = $document->query()->kind('paragraph')->get()->all();
$install = $paragraphs[0] ?? null;
$usage = $paragraphs[1] ?? null;

if (!$install instanceof Block || !$usage instanceof Block) {
    throw new \LogicException('Expected two top-level paragraphs.');
}

$usage->moveBefore($install);
$copy = $install->cloneAfter($install);
$quoted = $copy->wrap(BlockWrapper::quote());
```

`moveBefore()` and `moveAfter()` keep the moved handle current and return it.
`cloneBefore()` and `cloneAfter()` return a new handle with the same public
block type. Moving or cloning requires a destination in the same document.

A top-level block is a direct child of the document root. Headings, paragraphs,
lists, tables, code blocks, and HTML blocks commonly occupy that level. A
paragraph inside a list item or block quote is nested and cannot use these
structural operations in V1. Queries can still inspect nested blocks.

A heading and a section are different editing units. Moving or wrapping an
`h2` handle changes only that heading block. It does not include the paragraphs
and subheadings that follow it. Use `Section` methods to edit a heading and its
body. Moving, cloning, or wrapping a complete section is not supported yet.

`replaceWith()` accepts a string or `MarkdownFragment` and returns the zero or
more replacement blocks. An empty string removes the block. `wrap()` replaces
the source handle and returns the new outer container:

```php
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\BlockWrapper;

$document = Markdown::github()->fromString("# Old title\n");
$title = $document->title();

if ($title !== null) {
    $replacements = $title->replaceWith("# New title\n\nNew instructions.\n");
}

$quote = BlockWrapper::quote();
$bullet = BlockWrapper::bullet();
$ordered = BlockWrapper::ordered(start: 3, delimiter: ')');
```

The original handle becomes stale after replace or wrap. Query or use the
returned handles for further work. Wrappers are deliberately closed to safe
CommonMark block quotes, bullet items, and ordered items. Alto does not accept
an arbitrary prefix template.

These operations preserve BOM and source line endings. A clean move lowers to
an original-range deletion plus an insertion, so the moved block and unrelated
bytes are not re-rendered. When pending edits target the same source position
or overlap the moved block, Alto widens through its normal safe patch fallback
rather than silently reordering work. Generic operations reject front matter;
use `replaceContent()` for that block. Alto also validates the complete future
top-level block order before an insertion, clone, move, or replacement. A
thematic break therefore cannot combine with a following block, including one
from an earlier pending edit, into accidental front matter.

Block manipulation changes Markdown structure. It does not attach arbitrary
metadata or HTML attributes to native headings, lists, or other blocks.

## Build and insert content

Use a builder when Alto owns the complete document:

```php
use Alto\Markdown\Markdown;

$markdown = Markdown::github()
    ->builder()
    ->h1('Release notes')
    ->paragraph('Changes for the next version.')
    ->unorderedList(['Parser updates', 'Formatter fixes'])
    ->toMarkdown();
```

Use `ensure()` when an existing document must contain named sections:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString("# Guide\n");

$document
    ->ensure()
    ->section('Installation', 2)
    ->section('Changelog', 2)
    ->apply();
```

Running the same ensure operation again does not duplicate those sections.

Fragments combine generated content and, when needed, explicitly raw Markdown:

```php
use Alto\Markdown\Markdown;

$factory = Markdown::github();
$document = $factory->fromString("# Guide\n");
$fragment = $factory->fragment()
    ->paragraph('Install with Composer.')
    ->codeBlock('bash', 'composer install')
    ->toFragment();

$document->section('Guide')->append($fragment);
```

The example uses only generated content. Call `raw()` only when the application
already trusts and validates those Markdown bytes.

## Preview and save

`diff()` previews all pending operations without changing the file:

```php
use Alto\Markdown\Exception\FileConflictException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SaveOptions;
use Alto\Markdown\Operation\SymlinkPolicy;

$file = Markdown::github()->open('docs/installation.md');
$file->section('Run the first conversion')->append("\nMore details.\n");

if ($file->hasChanges()) {
    echo $file->diff()->toUnifiedString();

    try {
        $file->save(new SaveOptions(
            compareBeforeWrite: true,
            symlinks: SymlinkPolicy::Reject,
        ));
    } catch (FileConflictException) {
        // The file changed after open(). The target and pending diff remain.
    }
}
```

`save()` uses same-directory atomic replacement by default. It preserves the
existing line-ending style and UTF-8 BOM. On POSIX, an existing regular file
keeps its permission and special mode bits (`07777`), while a new file uses
`0666 & ~umask`. Alto fails before replacement if it cannot read or apply the
existing mode. After a successful save, Alto reparses the written source and
clears the journal. Query new handles after that reparse.

Compare-before-write protects against silently replacing bytes changed by
another editor or process. It is optimistic conflict detection, not a
filesystem lock.

Protected `saveAs()` can create a new target but refuses to replace an existing
file that this document did not open. The default comparison option remains
`false`, so enable it explicitly when concurrent changes are possible.

The default `SymlinkPolicy::Reject` refuses a final path component that is a
symbolic link. `SymlinkPolicy::Follow` resolves that link before conflict
checking and writing. Atomic mode creates its temporary file beside the
resolved target and renames over that target, so the link itself remains.
Dangling links and links to non-regular files are rejected. Directories, FIFOs,
sockets, and devices are also rejected before either atomic or non-atomic
writes.

This policy only governs the final path component. Alto does not authorize the
path or reject symbolic links in intermediate directories. Validate an allowed
canonical directory in the application. Resolution and replacement also remain
subject to filesystem time-of-check/time-of-use races because portable PHP
does not expose an atomic open-and-replace operation relative to a directory
handle.

Relative paths are anchored to their canonical parent directory at `open()` or
after a successful `saveAs()`. A later working-directory change therefore
cannot redirect `save()`. `path()` still returns the path spelling supplied by
the caller. The final component is deliberately not resolved during anchoring,
so the selected symlink policy still applies.

Atomic replacement creates a new inode. On POSIX, a successful same-directory
`rename()` replaces the path atomically. Alto does not claim to preserve
ownership, ACLs, extended attributes, resource forks, or other platform
metadata. PHP and Windows filesystems do not provide the same portable
replacement guarantee: replacing an existing or open destination can fail, and
mode handling is platform-limited. Use `atomic: false` only when retaining the
existing inode and its metadata is more important than all-or-nothing
replacement. A non-atomic write can leave partial content after an I/O failure.

See [Errors](errors.md) for conflicts, stale handles, missing sections, and
unsupported edits.
