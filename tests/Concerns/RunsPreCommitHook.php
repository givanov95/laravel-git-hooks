<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests\Concerns;

/**
 * Runs hooks/pre-commit against a scratch git repository, the way git does: from inside a
 * project that has its own vendor/bin tooling.
 *
 * php-cs-fixer is a stub that logs its arguments (one per line) and "fixes" a file by appending
 * a marker line, so a test can tell which files the hook handed to it and which it left alone.
 * Like a config-wide finder, the stub fixes every PHP file in the project when it is handed none.
 */
trait RunsPreCommitHook
{
    private string $project;

    protected function createProject(): void
    {
        $this->project = sys_get_temp_dir() . '/lgh-hook-' . uniqid('', true);
        mkdir($this->project . '/vendor/bin', 0o755, true);

        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'hook@example.test');
        $this->git('config', 'user.name', 'Hook Test');
        $this->git('config', 'commit.gpgsign', 'false');

        $this->write('.gitignore', "vendor/\nfixer.log\n");
        $this->write('.php-cs-fixer.dist.php', "<?php\n\nreturn null;\n");
        $this->write('vendor/bin/php-cs-fixer', $this->fixerStub());
        chmod($this->project . '/vendor/bin/php-cs-fixer', 0o755);
    }

    protected function removeProject(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($this->project);
    }

    protected function write(string $path, string $contents): void
    {
        $file = $this->project . '/' . $path;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        file_put_contents($file, $contents);
    }

    protected function read(string $path): string
    {
        return (string) file_get_contents($this->project . '/' . $path);
    }

    /**
     * Runs git in the project and returns its output; a failing command fails the test.
     */
    protected function git(string ...$arguments): string
    {
        [$code, $output] = $this->runProcess(['git', ...$arguments]);

        if ($code !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $arguments) . " failed:\n" . $output);
        }

        return $output;
    }

    /**
     * Runs the hook with the build and the test suite switched off, so a test only sees the step
     * it is about.
     *
     * @param  array<string, string>  $env
     * @return array{0: int, 1: string} exit code and combined output
     */
    protected function runHook(array $env = []): array
    {
        return $this->runProcess(
            ['bash', dirname(__DIR__, 2) . '/hooks/pre-commit'],
            $env + ['SKIP_BUILD' => '1', 'SKIP_TESTS' => '1'],
        );
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array{0: int, 1: string}
     */
    protected function runProcess(array $command, array $env = []): array
    {
        // A developer's own SKIP_* switches and any GIT_* variables (set when the tests run from a
        // git hook) must not leak into the scratch repository.
        $inherited = array_filter(
            getenv(),
            static fn (string $name): bool => ! str_starts_with($name, 'GIT_') && ! str_starts_with($name, 'SKIP_'),
            ARRAY_FILTER_USE_KEY,
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->project,
            $env + ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'] + $inherited,
        );

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * The paths the hook handed to php-cs-fixer (everything after `--`), across all calls.
     *
     * @return array<int, string>
     */
    protected function fixerPaths(): array
    {
        $log = $this->project . '/fixer.log';

        if (! is_file($log)) {
            return [];
        }

        $arguments = explode("\n", rtrim((string) file_get_contents($log), "\n"));
        $separator = array_search('--', $arguments, true);

        return $separator === false ? [] : array_slice($arguments, $separator + 1);
    }

    protected function fixerWasRun(): bool
    {
        return is_file($this->project . '/fixer.log');
    }

    private function fixerStub(): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            printf '%s\n' "$@" >> "$(cd "$(dirname "$0")/../.." && pwd)/fixer.log"

            files=(); past_separator=0
            for arg in "$@"; do
                if [ "$past_separator" = 1 ]; then files+=("$arg"); elif [ "$arg" = "--" ]; then past_separator=1; fi
            done

            if [ "${#files[@]}" -eq 0 ]; then
                while IFS= read -r -d '' file; do files+=("$file"); done < <(find . -name '*.php' -not -path './vendor/*' -print0)
            fi

            for file in "${files[@]}"; do printf '// fixed\n' >> "$file"; done
            BASH . "\n";
    }
}
