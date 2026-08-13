# Performance

Performance depends on the operation. Alto measures direct conversion, document
creation, existing-document rendering, analysis, formatting, and memory
separately.

## Choose the right path

| Need | API |
| --- | --- |
| Convert once | `Markdown::github()->toHtml($source)` |
| Convert an inline-only field | `Markdown::github()->toInlineHtml($source)` |
| Query, lint, or edit | `Markdown::github()->fromString($source)` |
| Render an existing document | `$document->toHtml()` |
| Preserve edited source | `$document->toMarkdown()` |

Direct conversion avoids the public document workspace. A document costs more
to create but can reuse its parsed representation and inline caches across
several operations.

Inline conversion also skips the block scanner and never creates paragraph,
heading, list, or reference-definition nodes. It is the narrow path for
already-scoped rich-text fields, not a faster substitute for document
conversion.

Avoid materializing query results unless needed. `first()` can stop early,
while `all()` and `count()` consume the complete lazy collection.

## Reference results

The release corpus contains 95 public Markdown files totaling about 1.2 MiB.
On the reference Apple M1 with PHP 8.5, CLI OPcache enabled, and JIT disabled,
one complete direct-conversion iteration falls in these observed ranges:

| Engine | Typical median time | Cold peak memory |
| --- | ---: | ---: |
| Alto | 110--120 ms | 4.6 MiB |
| League CommonMark | 270--280 ms | 17.8 MiB |
| Parsedown | 65--70 ms | 4.4 MiB |
| Michelf PHP Markdown | 140--150 ms | 3.1 MiB |
| cebe/markdown | 55--60 ms | 6.7 MiB |

These are reference measurements, not universal rankings. League uses its GFM
converter. Parsedown, Michelf, and cebe are one-shot throughput references and
do not provide Alto's complete document, conformance, query, lint, format, and
editing contract.

The generated Markdown, HTML, SVG, and JSON reports contain every corpus,
product lane, measured sample, protocol field, and input digest.

### Extension dispatch

Public inline parsers are compiled into dispatch lists keyed by their trigger
byte. When an installed extension's trigger is absent, instrumentation records
zero calls to its parser.

A focused PHPBench run on the same reference machine measured the following
modes with CLI OPcache enabled, 10 iterations, and 10 revolutions:

| Subject | PHPBench mode |
| --- | ---: |
| Baseline source, no extension | 2.154 ms |
| Same source, inline extension installed but not triggered | 2.103 ms |
| Source containing 48 trigger bytes, no extension | 2.076 ms |
| Same trigger source, installed parser declines all 48 matches | 2.118 ms |

The small differences overlap normal run variation. This benchmark guards the
dispatch boundary rather than claiming that an extension makes conversion
faster. A parser is called only for matching trigger bytes, and a declining
parser adds work at those matches.

## Measurement protocol

The comparative lane converts the complete official corpus with Alto, League
CommonMark, Parsedown, Michelf, and cebe. Product-only lanes measure Alto
direct conversion, document parsing, first and existing-document HTML
rendering, queries, stats, lint, formatting, typed edits, diffs, protected
atomic saves, and fresh CLI startup.

Time uses five measured iterations of one complete workload and no unmeasured
target warmup. Each library is initialized with a small neutral document before
measurement. Memory uses one fresh process per engine, corpus, temperature, or
product lane. Cold and warm peaks remain separate and are never added.

Do not compare results produced with Xdebug or PCOV enabled. Keep the corpus,
PHP version, OPcache state, warmup count, iterations, and HTML policy identical
between engines. Use real documents for product comparisons and synthetic
families only to locate a specific cost.
