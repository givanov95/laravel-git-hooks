<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks;

/**
 * Installs the package's pre-commit hook into a consuming project's git
 * hooks directory. Used both by the Composer plugin and the CLI fallback.
 */
final class HookInstaller
{
    public const HOOK_NAME = 'pre-commit';

    /**
     * Install the hook into the git repository rooted at $projectRoot.
     *
     * @param callable(string):void|null $log
     */
    public static function install(string $projectRoot, ?callable $log = null): bool
    {
        $log ??= static function (string $message): void {};

        $projectRoot = realpath($projectRoot) ?: rtrim($projectRoot, '/');

        $hooksDir = self::resolveHooksDir($projectRoot);
        if ($hooksDir === null) {
            $log('laravel-git-hooks: no git repository found, skipping hook install.');

            return false;
        }

        $source = realpath(dirname(__DIR__) . '/hooks/' . self::HOOK_NAME);
        if ($source === false || ! is_file($source)) {
            $log('laravel-git-hooks: hook source file is missing.');

            return false;
        }

        if (! is_dir($hooksDir) && ! @mkdir($hooksDir, 0o755, true) && ! is_dir($hooksDir)) {
            $log('laravel-git-hooks: could not create hooks directory ' . $hooksDir);

            return false;
        }

        @chmod($source, 0o755);
        $target = $hooksDir . '/' . self::HOOK_NAME;

        // Preserve a pre-existing, hand-written hook (only back up once).
        if (is_file($target) && ! is_link($target)) {
            $backup = $target . '.local.bak';
            if (! file_exists($backup) && @copy($target, $backup)) {
                $log('laravel-git-hooks: existing pre-commit backed up to ' . basename($backup));
            }
        }

        if (is_link($target) || file_exists($target)) {
            @unlink($target);
        }

        if (self::trySymlink($source, $target)) {
            $log('laravel-git-hooks: pre-commit hook linked into ' . self::shorten($projectRoot, $hooksDir));

            return true;
        }

        // Filesystems without symlink support (e.g. some Windows setups): copy.
        if (@copy($source, $target)) {
            @chmod($target, 0o755);
            $log('laravel-git-hooks: pre-commit hook copied into ' . self::shorten($projectRoot, $hooksDir));

            return true;
        }

        $log('laravel-git-hooks: failed to install the hook into ' . $hooksDir);

        return false;
    }

    /**
     * Resolve the git hooks directory, handling both regular repos and
     * worktrees/submodules where ".git" is a file pointing elsewhere.
     */
    private static function resolveHooksDir(string $projectRoot): ?string
    {
        $git = $projectRoot . '/.git';

        if (is_dir($git)) {
            return $git . '/hooks';
        }

        if (is_file($git)) {
            $contents = (string) @file_get_contents($git);
            if (preg_match('/^gitdir:\s*(.+)$/m', $contents, $matches) === 1) {
                $dir = trim($matches[1]);
                if (! self::isAbsolute($dir)) {
                    $dir = $projectRoot . '/' . $dir;
                }

                return rtrim($dir, '/') . '/hooks';
            }
        }

        return null;
    }

    private static function trySymlink(string $source, string $target): bool
    {
        $relative = self::relativePath(dirname($target), $source);

        return @symlink($relative, $target) === true && is_link($target);
    }

    /**
     * Build a relative path from directory $from to file $to (both absolute).
     */
    private static function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));

        $i = 0;
        while (isset($fromParts[$i], $toParts[$i]) && $fromParts[$i] === $toParts[$i]) {
            $i++;
        }

        $up = array_fill(0, max(0, count($fromParts) - $i), '..');

        return implode('/', array_merge($up, array_slice($toParts, $i)));
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private static function shorten(string $projectRoot, string $path): string
    {
        return str_starts_with($path, $projectRoot)
            ? '.' . substr($path, strlen($projectRoot))
            : $path;
    }
}
