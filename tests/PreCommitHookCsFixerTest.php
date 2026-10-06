<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests;

use Givanov95\LaravelGitHooks\HookInstaller;
use Givanov95\LaravelGitHooks\Tests\Concerns\RunsPreCommitHook;
use PHPUnit\Framework\TestCase;

/**
 * Step 1 of hooks/pre-commit: php-cs-fixer must touch the staged PHP files and nothing else.
 */
final class PreCommitHookCsFixerTest extends TestCase
{
    use RunsPreCommitHook;

    protected function setUp(): void
    {
        $this->createProject();

        $this->write('app/A.php', "<?php\n// a\n");
        $this->write('app/B.php', "<?php\n// b\n");
        $this->write('app/P.php', "<?php\n// p\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function test_it_formats_only_the_staged_php_files(): void
    {
        $this->write('app/A.php', "<?php\n// a, edited\n");
        $this->write('app/B.php', "<?php\n// b, edited but not staged\n");
        $this->write('app/C.php', "<?php\n// c, never added\n");
        $this->git('add', 'app/A.php');

        [$code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertSame(['app/A.php'], $this->fixerPaths());
        $this->assertSame("<?php\n// a, edited\n// fixed\n", $this->read('app/A.php'));
        $this->assertSame("<?php\n// b, edited but not staged\n", $this->read('app/B.php'));
        $this->assertSame("<?php\n// c, never added\n", $this->read('app/C.php'));
    }

    public function test_the_formatted_file_is_staged_again(): void
    {
        $this->write('app/A.php', "<?php\n// a, edited\n");
        $this->git('add', 'app/A.php');

        $this->runHook();

        $this->assertSame("<?php\n// a, edited\n// fixed\n", $this->git('show', ':app/A.php'));
        $this->assertSame('', $this->git('diff', '--name-only'));
    }

    public function test_a_partially_staged_file_is_left_alone_and_said_so(): void
    {
        $this->write('app/P.php', "<?php\n// p, first change\n");
        $this->git('add', 'app/P.php');
        $this->write('app/P.php', "<?php\n// p, first change\n// p, second change, not staged\n");
        $this->write('app/A.php', "<?php\n// a, edited\n");
        $this->git('add', 'app/A.php');

        [$code, $output] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertSame(['app/A.php'], $this->fixerPaths());
        $this->assertSame("<?php\n// p, first change\n", $this->git('show', ':app/P.php'));
        $this->assertSame("<?php\n// p, first change\n// p, second change, not staged\n", $this->read('app/P.php'));
        $this->assertStringContainsString('partially staged', $output);
        $this->assertStringContainsString('app/P.php', $output);
    }

    public function test_the_fixer_does_not_run_when_the_only_php_file_is_partially_staged(): void
    {
        $this->write('app/P.php', "<?php\n// p, first change\n");
        $this->git('add', 'app/P.php');
        $this->write('app/P.php', "<?php\n// p, first change\n// p, second change, not staged\n");

        [$code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertFalse($this->fixerWasRun());
    }

    public function test_the_fixer_does_not_run_without_a_staged_php_file(): void
    {
        $this->write('README.md', "# readme\n");
        $this->write('app/B.php', "<?php\n// b, edited but not staged\n");
        $this->git('add', 'README.md');

        [$code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertFalse($this->fixerWasRun());
        $this->assertSame("<?php\n// b, edited but not staged\n", $this->read('app/B.php'));
    }

    public function test_it_handles_paths_with_spaces(): void
    {
        $this->write('app/My File.php', "<?php\n// spaced\n");
        $this->write('app/B.php', "<?php\n// b, edited but not staged\n");
        $this->git('add', 'app/My File.php');

        $this->runHook();

        $this->assertSame(['app/My File.php'], $this->fixerPaths());
        $this->assertSame("<?php\n// b, edited but not staged\n", $this->read('app/B.php'));
        $this->assertSame("<?php\n// spaced\n// fixed\n", $this->git('show', ':app/My File.php'));
    }

    public function test_a_renamed_and_edited_php_file_is_formatted(): void
    {
        $this->write('app/Moved.php', "<?php\n// one\n// two\n// three\n// four\n// five\n");
        $this->git('add', 'app/Moved.php');
        $this->git('commit', '-q', '-m', 'add moved');
        $this->git('mv', 'app/Moved.php', 'app/Renamed.php');
        $this->write('app/Renamed.php', "<?php\n// one\n// two\n// three\n// four\n// five, edited\n");
        $this->git('add', 'app/Renamed.php');
        $this->assertStringContainsString('R', $this->git('status', '--short'));

        [$code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertSame(['app/Renamed.php'], $this->fixerPaths());
        $this->assertStringEndsWith("// fixed\n", $this->git('show', ':app/Renamed.php'));
    }

    public function test_skip_csfixer_skips_the_step(): void
    {
        $this->write('app/A.php', "<?php\n// a, edited\n");
        $this->git('add', 'app/A.php');

        [$code] = $this->runHook(['SKIP_CSFIXER' => '1']);

        $this->assertSame(0, $code);
        $this->assertFalse($this->fixerWasRun());
    }

    public function test_the_installed_hook_commits_the_formatted_file_and_nothing_else(): void
    {
        $this->assertTrue(HookInstaller::install($this->project));
        $this->write('app/A.php', "<?php\n// a, edited\n");
        $this->write('app/B.php', "<?php\n// b, edited but not staged\n");
        $this->git('add', 'app/A.php');

        [$code, $output] = $this->runProcess(['git', 'commit', '-m', 'edit a'], ['SKIP_BUILD' => '1', 'SKIP_TESTS' => '1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame("<?php\n// a, edited\n// fixed\n", $this->git('show', 'HEAD:app/A.php'));
        $this->assertSame("<?php\n// b\n", $this->git('show', 'HEAD:app/B.php'));
        $this->assertSame("<?php\n// b, edited but not staged\n", $this->read('app/B.php'));
    }
}
