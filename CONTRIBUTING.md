# Contributing to Alto Markdown

Contributions should preserve the public contracts, source bytes outside the
requested change, and safe defaults.

## Prepare a checkout

```bash
composer install
composer qa
```

`composer qa` runs PHPStan, the PHP CS Fixer check, and PHPUnit. Run coverage
separately when a change affects executable code:

```bash
composer coverage
```

The coverage command enforces the repository's 99 percent line floor.

## Propose a change

Add or update tests for observable behavior. Update `docs/` and `CHANGELOG.md`
when the public contract changes. Keep parser limits, HTML policies, resource
authority, and file-conflict behavior explicit.

Open a pull request against `main` only after the complete quality gate passes.
Describe the user-visible result, compatibility impact, and any security or
performance tradeoff.
