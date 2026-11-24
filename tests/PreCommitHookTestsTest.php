<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests;

use Givanov95\LaravelGitHooks\Tests\Concerns\RunsPreCommitHook;
use PHPUnit\Framework\TestCase;

/**
 * Step 4 of hooks/pre-commit: the test suite, one by one or, when the project opts in, in parallel.
 * `artisan` and `vendor/bin/paratest` are stubs that record how they were called.
 */
final class PreCommitHookTestsTest extends TestCase
{
    use RunsPreCommitHook;

    protected function setUp(): void
    {
        $this->createProject();

        $this->write('artisan', "<?php\nfile_put_contents(__DIR__ . '/tests.log', 'artisan ' . implode(' ', array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\nexit((int) getenv('TESTS_EXIT'));\n");
        $this->write('.gitignore', "vendor/\nfixer.log\nfixer.env\ntests.log\n");
        $this->write('README.md', "# project\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');

        $this->write('README.md', "# changed\n");
        $this->git('add', 'README.md');
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function test_without_the_setting_the_tests_run_one_by_one(): void
    {
        $this->installParatest();
        $this->write('composer.json', "{\"name\": \"acme/app\"}\n");

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['artisan test'], $this->testRuns());
    }

    public function test_the_setting_runs_the_tests_in_parallel(): void
    {
        $this->installParatest();
        $this->optIn();

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['artisan test --parallel'], $this->testRuns());
    }

    public function test_a_failing_parallel_run_aborts_the_commit(): void
    {
        $this->installParatest();
        $this->optIn();

        [$code, $output] = $this->tests(['TESTS_EXIT' => '1']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('tests failed', $output);
        $this->assertSame(['artisan test --parallel'], $this->testRuns());
    }

    public function test_without_paratest_the_setting_falls_back_to_one_by_one(): void
    {
        $this->optIn();

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('brianium/paratest is not installed', $output);
        $this->assertSame(['artisan test'], $this->testRuns());
    }

    public function test_skip_parallel_runs_the_tests_one_by_one(): void
    {
        $this->installParatest();
        $this->optIn();

        [$code, $output] = $this->tests(['SKIP_PARALLEL' => '1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['artisan test'], $this->testRuns());
    }

    public function test_without_artisan_the_parallel_run_uses_paratest(): void
    {
        unlink($this->project . '/artisan');
        $this->installParatest();
        $this->optIn();

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['paratest'], $this->testRuns());
    }

    public function test_a_setting_that_is_not_true_warns_and_runs_one_by_one(): void
    {
        $this->installParatest();
        $this->write('composer.json', "{\"extra\": {\"laravel-git-hooks\": {\"parallel\": \"true\"}}}\n");

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('must be true', $output);
        $this->assertSame(['artisan test'], $this->testRuns());
    }

    public function test_a_failing_serial_run_aborts_the_commit(): void
    {
        [$code, $output] = $this->tests(['TESTS_EXIT' => '1']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('tests failed', $output);
        $this->assertSame(['artisan test'], $this->testRuns());
    }

    public function test_without_artisan_and_the_setting_phpunit_runs_as_before(): void
    {
        unlink($this->project . '/artisan');
        $this->installParatest();
        $this->write('vendor/bin/phpunit', "#!/usr/bin/env bash\necho phpunit >> \"\$(dirname \"\$0\")/../../tests.log\"\n");
        chmod($this->project . '/vendor/bin/phpunit', 0o755);

        [$code, $output] = $this->tests();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Running tests (phpunit)...', $output);
        $this->assertSame(['phpunit'], $this->testRuns());
    }

    private function optIn(): void
    {
        $this->write('composer.json', "{\"extra\": {\"laravel-git-hooks\": {\"parallel\": true}}}\n");
    }

    private function installParatest(): void
    {
        $this->write('vendor/bin/paratest', "#!/usr/bin/env bash\necho \"paratest\$([ \$# -gt 0 ] && echo \" \$*\")\" >> \"\$(dirname \"\$0\")/../../tests.log\"\n");
        chmod($this->project . '/vendor/bin/paratest', 0o755);
    }

    /**
     * @return array<int, string>
     */
    private function testRuns(): array
    {
        $log = $this->project . '/tests.log';

        return is_file($log) ? explode("\n", rtrim((string) file_get_contents($log), "\n")) : [];
    }

    /**
     * Only the test step: the build and php-cs-fixer are out of the picture.
     *
     * @param  array<string, string>  $env
     * @return array{0: int, 1: string}
     */
    private function tests(array $env = []): array
    {
        return $this->runHook($env + ['SKIP_TESTS' => '0', 'SKIP_CSFIXER' => '1']);
    }
}
