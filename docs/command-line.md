# Command line

The command-line application uses the same parser, linter, formatter, and stats
engine as the PHP API. It ships as a separate package, `alto/markdown-cli`.

## Install and run

```bash
composer require --dev alto/markdown-cli
vendor/bin/markdown-cli --version
```

The examples below use `markdown-cli` as the installed binary name. Prefix them
with `vendor/bin/` when the package is installed as a project dependency.

## Commands

```bash
markdown-cli lint docs/
markdown-cli lint README.md --fix --rules=no-bare-urls,no-trailing-spaces
markdown-cli format docs/ --check
markdown-cli format README.md --diff
markdown-cli stats README.md --json
markdown-cli outline README.md
```

Lint output supports text, JSON, and GitHub annotations. Format supports write,
check, and diff modes. Mutating lint and format commands refuse symlink targets.
They also compare the opened bytes immediately before replacement and fail
instead of overwriting a concurrently changed file.
Stats JSON exposes the complete `DocumentStats` shape documented in
[Queries and stats](queries-and-stats.md), including ordered section counts and
all grouped arrays.

## Exit codes and configuration

| Code | Meaning |
| --- | --- |
| `0` | Success, clean lint, or warnings only |
| `1` | Lint errors or formatting required by `--check` |
| `2` | Usage, configuration, or I/O error |

Configuration discovery walks upward and uses the nearest directory with a
supported config. A local `markdown-cli.php`, `.yaml`, or `.yml` file takes
precedence over its `.dist` counterpart. `--config` bypasses discovery, while
`--profile` and `--rules` override the corresponding configured values.

```yaml
profile: github
rules:
  single-h1: warning
  no-bare-urls: off
style:
  bullet: "-"
  fence: "`"
  ordered-list-delimiter: "."
  final-newline: true
  normalize-blank-lines: true
  normalize-heading-spacing: true
  normalize-table-delimiters: true
  normalize-reference-definition-spacing: true
```

`bullet`, `fence`, and `ordered-list-delimiter` accept the same marker values as
`MarkdownStyle`. The boolean fields control the corresponding PHP formatting
settings. The nearest config wins as a complete project policy; Alto does not
merge hidden style layers from parent directories.

CLI rule configuration selects rules and severities. Rule-specific typed options
remain a PHP API feature through `LintConfig`; the CLI uses each selected rule's
default options.
