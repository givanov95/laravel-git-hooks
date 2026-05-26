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

1. **php-cs-fixer** — formats staged `*.php` files (needs `vendor/bin/php-cs-fixer` + a
   `.php-cs-fixer.php` / `.php-cs-fixer.dist.php` config), then re-stages them.
2. **Debug-statement guard** — blocks the commit if staged `*.php/*.vue/*.js/*.ts` files
   contain `console.log(`, `dd(` or `dump(`.
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

## Requirements

- PHP `^8.3`
- Composer `^2.0`
- Git, and (per step) `bash`, `npm`, your project's `php artisan` / `phpunit`

## License

MIT © Georgi Ivanov
