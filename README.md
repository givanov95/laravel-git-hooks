# laravel-git-hooks

A tiny **Composer plugin** that wires a `pre-commit` git hook into your Laravel + Vite
projects. On every commit it runs your quality gate; if any step fails, the commit is
aborted.

It is intentionally small and self-contained — one bash hook plus a few lines of PHP to
install it. The hook is the authoritative source of truth, versioned in this package, so
every project that requires it stays in sync.

> **Heads-up:** a local git hook is a *fast-feedback* gate, not a security boundary — it is
> trivially bypassed (`--no-verify`) and only runs for people who have it installed. Pair it
> with **CI (GitHub Actions)** for the real, enforced gate.

## What the hook runs

Each step is skipped gracefully when its tooling isn't present, so the same hook works across
projects with different setups:

1. **php-cs-fixer** — formats the staged `*.php` files (needs `vendor/bin/php-cs-fixer` + a
   `.php-cs-fixer.php` / `.php-cs-fixer.dist.php` config) and re-stages them. Only the staged
   files are passed to the fixer — unstaged and untracked files are never touched. A file that
   is only *partially* staged (`git add -p`) is skipped with a warning, so the fixer cannot
   rewrite hunks you left out of the commit or pull them in when re-staging.
2. **Debug-statement guard** — blocks the commit if the staged `*.php/*.vue/*.js/*.ts` files
   call `console.log()`, `dd()` or `dump()` (see [Debug-statement guard](#debug-statement-guard)).
3. **Vite build** — runs `npm run build` **only when frontend files are staged** and a
   `build` script exists in `package.json`.
4. **Tests** — runs `php artisan test` (or `vendor/bin/phpunit`).

## Installation

```bash
composer require --dev givanov95/laravel-git-hooks
```

That's it. The plugin installs the hook into `.git/hooks/pre-commit` after install/update.
Because it's a plugin, Composer will ask you to trust it the first time (or add it to
`allow-plugins` in your project's `composer.json`):

```json
{
    "config": {
        "allow-plugins": {
            "givanov95/laravel-git-hooks": true
        }
    }
}
```

If a hand-written `pre-commit` already exists, it is backed up to `pre-commit.local.bak`.

### Manual install (plugins disabled)

```bash
vendor/bin/laravel-git-hooks
```

## Bypassing & skipping

| Goal | How |
| --- | --- |
| Skip everything for one commit | `git commit --no-verify` |
| Skip every step (keep the hook) | `SKIP_HOOK=1 git commit ...` |
| Skip just the build | `SKIP_BUILD=1 git commit ...` |
| Skip just the tests | `SKIP_TESTS=1 git commit ...` |
| Skip php-cs-fixer | `SKIP_CSFIXER=1 git commit ...` |
| Skip the debug-statement guard | `SKIP_DEBUG_GUARD=1 git commit ...` |

## Debug-statement guard

The guard looks at what is **staged** (not the working tree) and only flags *calls*:

- `dd(...)`, `dump(...)` and `\dump(...)` as functions, and `console.log(...)`;
- not methods or other things that share the name: `$collection->dump()`, `->dd()`,
  `Vite::dump()`, `$dump()`, `yaml.dump()`, `Foo\dump()`, `function dump()`;
- not lines that start with a comment marker (`//`, `#`, `*`, `/*`, `<!--`). A comment *after*
  code on the same line is not recognised, so `dd($x); // later` is still flagged, and so is a
  `dump(` inside a comment that follows code.

To exempt paths — tests, docs, fixtures — add a `.debug-guard-ignore` file to the project root.
Each line is a [git pathspec](https://git-scm.com/docs/gitglossary#def_pathspec); blank lines
and lines starting with `#` are ignored:

```
# tests may use dump() on purpose
tests/
docs/**
*.stub.php
```

For a single commit use `SKIP_DEBUG_GUARD=1`. By default no path is exempt.

## Requirements

- PHP `^8.2`
- Composer `^2.0`
- Git, and (per step) `bash`, `npm`, your project's `php artisan` / `phpunit`

## License

MIT © Georgi Ivanov
