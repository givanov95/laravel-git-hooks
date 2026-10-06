<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests;

use Givanov95\LaravelGitHooks\Tests\Concerns\RunsPreCommitHook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 2 of hooks/pre-commit: block a commit that adds console.log() / dd() / dump() calls, but
 * not methods that happen to share the name, comments, or paths the project opted out.
 */
final class PreCommitHookDebugGuardTest extends TestCase
{
    use RunsPreCommitHook;

    protected function setUp(): void
    {
        $this->createProject();

        $this->write('README.md', "# project\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    /**
     * @return array<string, array{0: string, 1: string}> path and contents of a file with a debug call
     */
    public static function debugCalls(): array
    {
        return [
            'dd in php' => ['app/A.php', "<?php\n\$x = 1;\ndd(\$x);\n"],
            'dump in php' => ['app/A.php', "<?php\n\$x = 1;\ndump(\$x);\n"],
            'dd after a statement' => ['app/A.php', "<?php\n\$x = 1; dd(\$x);\n"],
            'dd inside an expression' => ['app/A.php', "<?php\n\$x = foo(dd(\$y));\n"],
            'fully qualified dump' => ['app/A.php', "<?php\n\\dump(\$x);\n"],
            'dump with a space before the parenthesis' => ['app/A.php', "<?php\ndump (\$x);\n"],
            'console.log in js' => ['resources/js/app.js', "const x = 1;\nconsole.log(x);\n"],
            'console.log in ts' => ['resources/js/app.ts', "const x = 1;\nconsole.log(x);\n"],
            'console.log in vue' => ['resources/js/App.vue', "<script setup>\nconsole.log('x');\n</script>\n"],
            'window.console.log' => ['resources/js/app.js', "window.console.log(x);\n"],
            'dd in js' => ['resources/js/app.js', "dd(x);\n"],
            'trailing comment does not hide the call' => ['app/A.php', "<?php\ndd(\$x); // remove before commit\n"],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> path and contents of a file without one
     */
    public static function harmlessCode(): array
    {
        return [
            'dump method' => ['app/A.php', "<?php\n\$collection->dump();\n"],
            'dd method' => ['app/A.php', "<?php\n\$collection->dd();\n"],
            'nullsafe dump method' => ['app/A.php', "<?php\n\$collection?->dump();\n"],
            'static dump method' => ['app/A.php', "<?php\nVite::dump();\n"],
            'static dd method' => ['app/A.php', "<?php\nVite::dd(\$x);\n"],
            'namespaced dump function' => ['app/A.php', "<?php\nApp\\Support\\dump(\$x);\n"],
            'dump defined as a function' => ['app/A.php', "<?php\nfunction dump(\$x) {}\n"],
            'dump defined as a method' => ['app/A.php', "<?php\nclass A { public function dump(\$x) {} }\n"],
            'dump through a variable' => ['app/A.php', "<?php\n\$dump(\$x);\n"],
            'longer names' => ['app/A.php', "<?php\nadd(\$x);\nmydump(\$x);\ndumper(\$x);\n"],
            'dump method in js' => ['resources/js/app.js', "yaml.dump(x);\n"],
            'console.log as part of a longer name' => ['resources/js/app.js', "myconsole.log(x);\n"],
            'double slash comment' => ['app/A.php', "<?php\n// dump(\$x);\n"],
            'indented double slash comment' => ['app/A.php', "<?php\n    // dd(\$x);\n"],
            'hash comment' => ['app/A.php', "<?php\n# dd(\$x);\n"],
            'docblock' => ['app/A.php', "<?php\n/**\n * Calls dump(\$x) for you.\n */\n"],
            'single line block comment' => ['app/A.php', "<?php\n/* dd(\$x); */\n"],
            'console.log in a js comment' => ['resources/js/app.js', "// console.log(x);\n"],
            'console.log in an html comment' => ['resources/js/App.vue', "<template>\n<!-- console.log(x) -->\n</template>\n"],
        ];
    }

    #[DataProvider('debugCalls')]
    public function test_it_blocks_a_debug_call(string $path, string $contents): void
    {
        $this->write($path, $contents);
        $this->git('add', $path);

        [$code, $output] = $this->guard();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString($path, $output);
    }

    #[DataProvider('harmlessCode')]
    public function test_it_lets_code_that_only_looks_like_a_debug_call_through(string $path, string $contents): void
    {
        $this->write($path, $contents);
        $this->git('add', $path);

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);
    }

    public function test_it_names_the_line_of_each_offending_call(): void
    {
        $this->write('app/A.php', "<?php\n\$x = 1;\ndd(\$x);\n\$y = 2;\ndump(\$y);\n");
        $this->git('add', 'app/A.php');

        [, $output] = $this->guard();

        $this->assertStringContainsString('app/A.php:3', $output);
        $this->assertStringContainsString('app/A.php:5', $output);
    }

    public function test_it_judges_what_is_staged_not_the_working_tree(): void
    {
        $this->write('app/A.php', "<?php\n\$x = 1;\n");
        $this->git('add', 'app/A.php');
        $this->write('app/A.php', "<?php\n\$x = 1;\ndd(\$x); // unstaged, not part of this commit\n");

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);

        $this->write('app/B.php', "<?php\ndd(1);\n");
        $this->git('add', 'app/B.php');
        $this->write('app/B.php', "<?php\n");

        [$code, $output] = $this->guard();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('app/B.php', $output);
    }

    public function test_it_ignores_files_that_are_not_part_of_the_commit(): void
    {
        $this->write('app/Old.php', "<?php\ndd(1);\n");
        $this->git('add', 'app/Old.php');
        $this->git('commit', '-q', '-m', 'old debug call', '--no-verify');
        $this->write('README.md', "# changed\n");
        $this->git('add', 'README.md');

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);
    }

    public function test_it_scans_a_file_that_was_renamed_and_edited(): void
    {
        $this->write('app/Moved.php', "<?php\n// one\n// two\n// three\n// four\n// five\n");
        $this->git('add', 'app/Moved.php');
        $this->git('commit', '-q', '-m', 'add moved');
        $this->git('mv', 'app/Moved.php', 'app/Renamed.php');
        $this->write('app/Renamed.php', "<?php\n// one\n// two\n// three\n// four\ndd(1);\n");
        $this->git('add', 'app/Renamed.php');
        $this->assertStringContainsString('R', $this->git('status', '--short'));

        [$code, $output] = $this->guard();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('app/Renamed.php:6', $output);
    }

    public function test_a_rename_without_a_debug_call_passes(): void
    {
        $this->write('app/Moved.php', "<?php\n// one\n// two\n// three\n// four\n// five\n");
        $this->git('add', 'app/Moved.php');
        $this->git('commit', '-q', '-m', 'add moved');
        $this->git('mv', 'app/Moved.php', 'app/Renamed.php');

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);
    }

    public function test_skip_debug_guard_skips_the_step(): void
    {
        $this->write('app/A.php', "<?php\ndd(1);\n");
        $this->git('add', 'app/A.php');

        [$code, $output] = $this->guard(['SKIP_DEBUG_GUARD' => '1']);

        $this->assertSame(0, $code, $output);
    }

    public function test_it_skips_the_paths_listed_in_the_ignore_file(): void
    {
        $this->write('.debug-guard-ignore', "# fixtures and docs may mention debug calls\n\ntests/\n*.stub.php\ndocs/**\n");
        $this->write('tests/Unit/DumpTest.php', "<?php\ndump(1);\n");
        $this->write('app/Foo.stub.php', "<?php\ndd(1);\n");
        $this->write('docs/guide/debugging.php', "<?php\ndd(1);\n");
        $this->git('add', '.');

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);
    }

    public function test_the_ignore_file_does_not_exempt_other_paths(): void
    {
        $this->write('.debug-guard-ignore', "tests/\n");
        $this->write('tests/Unit/DumpTest.php', "<?php\ndump(1);\n");
        $this->write('app/A.php', "<?php\ndd(1);\n");
        $this->git('add', '.');

        [$code, $output] = $this->guard();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('app/A.php', $output);
        $this->assertStringNotContainsString('tests/Unit/DumpTest.php', $output);
    }

    public function test_the_last_line_of_the_ignore_file_counts_without_a_trailing_newline(): void
    {
        $this->write('.debug-guard-ignore', "docs/\r\ntests/");
        $this->write('docs/a.php', "<?php\ndd(1);\n");
        $this->write('tests/a.php', "<?php\ndd(1);\n");
        $this->git('add', '.');

        [$code, $output] = $this->guard();

        $this->assertSame(0, $code, $output);
    }

    /**
     * The php-cs-fixer step is out of the picture here.
     *
     * @param  array<string, string>  $env
     * @return array{0: int, 1: string}
     */
    private function guard(array $env = []): array
    {
        return $this->runHook($env + ['SKIP_CSFIXER' => '1']);
    }
}
