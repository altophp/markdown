# Specifications and compliance

Alto separates language compliance from safe application rendering. The parser
profiles define accepted syntax, while `HtmlPolicy` defines output safety.

## Supported specifications

| Profile | Language |
| --- | --- |
| `commonmark` | CommonMark |
| `gfm` | GitHub Flavored Markdown |
| `github` | GFM plus GitHub alerts and front matter |

The CommonMark and GFM profiles are checked against pinned specification
examples. GitHub-specific fixtures cover alerts, front matter, safe HTML, and
direct versus document rendering parity.

`HtmlPolicy::spec()` is used by conformance tests because raw HTML passthrough
belongs to the specifications. Normal application rendering remains safe by
default.

## Run the suites

The published package carries the conformance fixtures and runs them with the
rest of the suite:

```bash
composer tests
```

The full quality gate also runs static analysis and style checks on top of the
unit, integration, source-preservation, round-trip, lint, formatter, and
file-save tests:

```bash
composer qa
```

## Interpret guarantees

Alto verifies three different properties:

- Conformance: HTML matches the selected specification examples.
- Semantic round-trip: normalized Markdown reparses to an equivalent model.
- Source preservation: unchanged or unrelated input bytes are not rewritten.

These guarantees apply only to the selected profile. A GitHub extension such as
an alert is ordinary blockquote content under narrower profiles. Opt-in
syntax such as description lists, footnotes, tabs, constrained attributes, and
recursive includes and embeds is outside the CommonMark and GFM conformance
score. Render-only output such as heading sections is also outside those
specification scores. Each extension has separate direct-render,
document-render, and source-preservation tests.
