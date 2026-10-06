# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Fixed

- php-cs-fixer now runs only on the staged PHP files (`--path-mode=intersection`) instead of the
  whole project, so unstaged files are no longer rewritten by a commit. Partially staged files
  (`git add -p`) are skipped with a warning and never re-staged as a whole.

## [0.1.0] - 2026-05-26

### Added

- Composer plugin that installs a `pre-commit` hook into the consuming project on
  `composer install` / `composer update`.
- Pre-commit hook running, in order: php-cs-fixer (staged PHP), a `console.log/dd/dump`
  guard, the Vite build (only on staged frontend changes) and the test suite
  (`php artisan test` or `vendor/bin/phpunit`).
- Per-step skip switches (`SKIP_HOOK`, `SKIP_BUILD`, `SKIP_TESTS`, `SKIP_CSFIXER`) and
  native `--no-verify` bypass.
- CLI fallback installer `bin/laravel-git-hooks` for projects with Composer plugins disabled.
- Backup of a pre-existing hand-written hook to `pre-commit.local.bak`.
