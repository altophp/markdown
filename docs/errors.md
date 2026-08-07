# Errors

Alto uses typed exceptions for recoverable library failures. All Alto-owned
domain exceptions implement `MarkdownExceptionInterface`, which provides one
catch point at an application boundary.

## Catch the right boundary

Catch a precise exception when the application has a recovery path. Catch
`MarkdownExceptionInterface` only where the request, job, or command must turn
any Alto failure into one application-level error.

```php
use Alto\Markdown\Exception\MarkdownExceptionInterface;
use Alto\Markdown\Markdown;

try {
    $html = Markdown::github()->toHtml("# Guide\n");
} catch (MarkdownExceptionInterface $error) {
    throw new \RuntimeException('Markdown processing failed.', previous: $error);
}
```

Programmer errors inside application callbacks and extensions are not
automatically converted into Alto exceptions. Do not catch `Throwable` around
the whole application merely to hide those failures.

## Recover by category

Catch these family types when several failures have the same application
response:

| Family | Includes | Recovery |
| --- | --- | --- |
| `ParseLimitException` | Source, block, inline, reference, and nesting limits | Reject the input or retry only with an explicitly reviewed budget |
| `FileException` | Read, write, and conflict failures | Report `$error->path`, preserve pending edits, then branch on the subtype only when recovery differs |
| `ResourceResolutionException` | Missing, denied, oversized, and unsupported resources | Reject the reference or use an application fallback |
| `InvalidExtensionException` | Duplicate extensions, invalid definitions, factories, parser results, lint results, formatter edits, and stats values | Fail factory construction or the current operation and fix the trusted extension |

`InvalidExtensionException` is also an
`InvalidMarkdownArgumentException`. Existing configuration-level catch points
therefore continue to work.

Use the precise subtype when its recovery is different:

| Exception | Typical cause | Recovery |
| --- | --- | --- |
| `FileReadException` | The source path is not a readable regular file | Check path authorization, type, existence, and permissions |
| `FileWriteException` | A save failed, permissions could not be preserved, or the final target was rejected | Keep the pending diff and report the filesystem failure |
| `FileConflictException` | The target changed after `open()` | Reopen, compare both versions, and reapply or abandon the edit |
| `StaleHandleException` | A handle predates a reparse or save | Query the current document again |
| `MissingSectionException` | A mutation targeted a section that does not exist | Check `exists()` or create it with `ensure()` |
| `InvalidMarkdownArgumentException` | A rule ID, style, or public configuration value is invalid | Fix application configuration |
| `InvalidExtensionException` | A trusted extension violates its declared contract | Fix or replace the extension, then rebuild the factory |
| `InvalidMarkdownOperationException` | The requested edit has no safe V1 contract | Choose a supported operation or edit a larger known-safe unit |
| `PatchConflictException` | Pending edits overlap without a safe lowering | Inspect the diff and split or reorder the operations |
| `RenderException` | Rendering requires unavailable support or receives invalid state | Correct the selected policy or runtime requirement |
| `ResourceNotFoundException` | A requested external resource does not exist | Report the missing reference or choose a fallback |
| `ResourceDeniedException` | A path, symlink, type, read, or include tree violates resource policy | Reject the reference without weakening the boundary |
| `ResourceTooLargeException` | A resource exceeds its byte budget | Reject it or use a separately reviewed resolver limit |
| `UnsupportedResourceException` | The extension or origin ID is unsupported | Select an allowed file type or the matching resolver |

Parser limit subclasses expose the configured and attempted values. File
exceptions expose the original application path through `path`. Resource limits
and their exact counting rules are documented in
[Security](security.md#bound-parsing-and-file-access).

Unknown generic node kinds also raise an Alto domain exception. Construct
factories with custom extensions during application startup rather than after
accepting a request.

## Protect edits and files

Use `exists()` before mutating an optional section:

```php
use Alto\Markdown\Markdown;

$document = Markdown::github()->fromString("# Guide\n");
$section = $document->section('Installation');

if (!$section->exists()) {
    $document->ensure()->section('Installation', 2)->apply();
}
```

Use compare-before-write when another process or editor may change the file:

```php
use Alto\Markdown\Exception\FileConflictException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SaveOptions;

$file = Markdown::github()->open('docs/installation.md');
$file->section('Run the first conversion')->append("\nUpdated.\n");

try {
    $file->save(new SaveOptions(compareBeforeWrite: true));
} catch (FileConflictException) {
    echo $file->diff()->toUnifiedString();
}
```

A failed protected save leaves the target and pending journal unchanged.
`diff()` remains available for reconciliation.

After a successful save, requery nodes instead of reusing an earlier handle:

```php
use Alto\Markdown\Markdown;

$file = Markdown::github()->open('docs/installation.md');
$file->format();
$file->save();

$currentTitle = $file->title()?->text();
```

The file API does not authorize paths for the host application. Validate the
allowed directory before `open()` or `saveAs()`, and define the application's
own symlink policy. Existing non-regular targets are rejected. A relative path
is anchored to its canonical parent when opened or adopted by `saveAs()`, but
that does not replace application authorization. Permission inspection or
restoration failures abort atomic replacement and preserve the pending diff.
See [Manipulation](manipulation.md#preview-and-save) for the complete
persistence contract.
