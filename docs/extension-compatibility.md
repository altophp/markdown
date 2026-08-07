# Extension compatibility

Alto extensions depend only on Alto's public contracts. Extensions written for
League CommonMark, Parsedown, or the historical `alto/commonmark` packages use
different parser and renderer APIs. They cannot be passed directly to
`MarkdownFactory::with()`.

## Support contract

For Alto 1.x, the extension contract includes:

- public extension interfaces, definitions, contexts, value objects, and
  exceptions that are not marked `@internal`;
- public constructor and method signatures, including parameter names used by
  named arguments;
- extension names, definition names, and qualified node kinds;
- original-input byte offsets and the documented ordering of extension passes;
- documented Markdown syntax and generated HTML for built-in extensions.

Compact node IDs, parser tapes, caches, instrumentation, and every type marked
`@internal` are implementation details. An extension must not inspect them or
depend on their ordering.

An external package targeting the stable V1 contract should require:

```json
{
    "require": {
        "alto/markdown": "^1.0",
        "php": ">=8.4"
    }
}
```

Until a stable `1.0.0` release exists, a package may test a pinned development
revision, but it should not describe that revision as a stable compatibility
range.

## Changes and deprecations

Alto applies these compatibility rules to extension contracts:

| Release | Allowed extension-contract change |
| --- | --- |
| Patch | Correct a bug, security issue, specification mismatch, or unintended performance regression without changing the intended public contract |
| Minor | Add a new optional capability interface, definition, context method on a concrete class, or built-in extension |
| Major | Remove or rename public API, add a method to an interface implemented by packages, change a type, or change stable syntax, node names, or HTML structure |

Adding a method to an implementable interface is a major change even when Alto
can provide a default internally. Adding an optional capability as a separate
interface is minor because existing extensions do not need to implement it.
Enum cases and constructor parameter names are treated as public API.

A public deprecation:

1. names the replacement in source and documentation;
2. is recorded in the changelog for the release that introduces it;
3. remains usable for the rest of the current major line;
4. is removed only in the next major release.

Alto does not emit deprecation warnings from parser or renderer hot paths.
Security fixes may narrow unsafe behavior in a patch release when preserving it
would keep users exposed. Such changes require an explicit security notice.

Package authors should test the lowest and newest Alto versions allowed by
their Composer constraint. Invalid definitions and callback results raise
`InvalidExtensionException`; arbitrary exceptions thrown by application
callbacks remain unchanged.

## External package matrix

This snapshot was verified on 2026-07-28 against
[`alto/commonmark`](https://github.com/PhpAlto/commonmark) main,
League CommonMark 2.8, and Parsedown 1.8.

| Package family | Co-installable | Directly accepted by `with()` | Support |
| --- | --- | --- | --- |
| Native package implementing Alto interfaces | Yes | Yes | Supported when its Composer range includes the installed Alto version |
| [`alto/commonmark`](https://github.com/PhpAlto/commonmark) and its standalone packages | Yes | No | Historical League CommonMark implementation; use the native replacements below |
| [`league/commonmark`](https://github.com/thephpleague/commonmark) extensions | Yes | No | Port behavior to Alto definitions and contexts |
| [Parsedown](https://github.com/erusev/parsedown) and ParsedownExtra subclasses or plugins | Yes | No | Port syntax behavior; their array protocol and inheritance hooks are not Alto contracts |

Co-installable means Composer can install both package families. It does not
mean their extension objects, configuration, output, or security model are
interchangeable.

When porting another engine's extension, keep only its user-visible behavior.
Map new block syntax to `BlockExtensionInterface`, leaf inline syntax to
`InlineExtensionInterface`, local HTML changes to
`HtmlDecoratorExtensionInterface`, and document-wide output decisions to
`DocumentTransformExtensionInterface`. Do not adapt or expose the foreign AST.

Every historical Alto CommonMark feature has a native replacement:

| Historical package | Native Alto replacement | Migration note |
| --- | --- | --- |
| `alto/commonmark-code-block-title` | [`CodeBlockTitleExtension`](extensions.md#add-code-block-titles) | Use the typed title policy |
| `alto/commonmark-content-slicer` | [`ContentSlicerExtension`](extensions.md#group-heading-sections) | `minLevel` is the first heading level that opens a section |
| `alto/commonmark-heading-level` | [`HeadingLevelExtension`](extensions.md#adjust-rendered-heading-levels) | Use `HeadingLevelPolicy::map()`, `shift()`, or `using()` |
| `alto/commonmark-import` | [`ImportExtension`](extensions.md#import-code-from-a-resource) | Inject a bounded `ResourceResolver` |
| `alto/commonmark-include` | [`IncludeExtension`](extensions.md#include-markdown-resources) | Inject a resolver and explicit recursion policy |
| `alto/commonmark-link-rewriter` | [`LinkRewriterExtension`](extensions.md#rewrite-link-destinations) | Build rules with `LinkRewriter` |
| `alto/commonmark-source` | [`SourceExtension`](extensions.md#display-a-source-excerpt) | Inject a bounded resolver |
| `alto/commonmark-table-of-contents` | [`TableOfContentsExtension`](extensions.md#add-a-table-of-contents) | Use the typed TOC policy |
| `alto/commonmark-tabs` | [`TabsExtension`](extensions.md#add-nested-tabs) | Review generated HTML and progressive behavior |

These are feature migrations, not drop-in class aliases. Re-run output,
security, and source-preservation tests when moving an application.
