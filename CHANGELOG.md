# Changelog

All notable changes to this project are documented here.

## [1.2.0] - 2026-10-06

### Added

- `SKIP_DEBUG_GUARD=1` skips the debug-statement guard on its own.
- `.debug-guard-ignore`: git pathspecs, one per line, that the debug-statement guard does not scan.

### Changed

- The debug-statement guard only flags function calls (`dd(`, `dump(`, `console.log(`), no longer
  methods such as `->dump(` / `Vite::dump(` or `function dump(`, and it skips whole-line comments.
  It reads the staged content instead of the working tree and reports `path:line`.

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
