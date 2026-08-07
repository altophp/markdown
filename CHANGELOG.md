# CHANGELOG

## [0.9.0] - 2026-08-07

First public release. The API is complete but not frozen: it may still change
before 1.0.

### Added
- `Markdown` entry point with the `commonmark()`, `gfm()`, and `github()`
  profiles.
- Direct conversion: `toHtml()` and `toInlineHtml()`.
- Document model: `fromString()` and `open()`, keeping the parsed structure and
  the original source bytes side by side.
- Queries and stats over headings, sections, links, code, and document metrics.
- Source-preserving manipulation: section edits, top-level block moves, and
  minimal diffs through `toMarkdown()`.
- File operations: unified diffs, formatting, safe lint fixes, and atomic saves
  with `SaveOptions(compareBeforeWrite: true)`.
- Lint and fix engine with `LintConfig::recommended()`.
- Builder API for generating Markdown.
- Extension contracts for custom blocks, leaf inlines, HTML decorators, render
  projections, lint rules, formatter passes, and document metrics.
- Bundled extensions: leaf delimiter pairs, smart punctuation, highlights,
  description lists, footnotes, mentions, nested tabs, constrained source
  attributes, escaped code imports, recursive includes, heading sections,
  source excerpts, and a bounded resource resolver.
- `HtmlPolicy` output policies, safe by default: raw HTML escaped, unsafe URL
  schemes filtered.

### Notes
- Requires PHP 8.4 or newer. The core has no runtime Composer dependencies.
- `ext-dom` is optional and used only by `HtmlPolicy::curated()`.
- Front matter stays opaque until the application passes a decoder, such as
  [`alto/frontmatter`](https://github.com/altophp/frontmatter).
- The command-line application ships separately as `alto/markdown-cli`.

[0.9.0]: https://github.com/altophp/markdown/releases/tag/v0.9.0
