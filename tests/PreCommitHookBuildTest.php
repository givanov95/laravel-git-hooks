<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests;

use Givanov95\LaravelGitHooks\Tests\Concerns\RunsPreCommitHook;
use PHPUnit\Framework\TestCase;

/**
 * Step 3 of hooks/pre-commit: the Vite build runs when frontend files are staged. `npm` is a stub
 * that records that it was called.
 */
final class PreCommitHookBuildTest extends TestCase
{
    use RunsPreCommitHook;

    protected function setUp(): void
    {
        $this->createProject();

        $this->write('package.json', "{\"scripts\": {\"build\": \"vite build\"}}\n");
        $this->write('bin/npm', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$(dirname \"\$0\")/../npm.log\"\n");
        chmod($this->project . '/bin/npm', 0o755);
        $this->write('.gitignore', "vendor/\nfixer.log\nnpm.log\n");
        $this->write('resources/js/Moved.vue', "<template>\n<p>one</p>\n<p>two</p>\n<p>three</p>\n<p>four</p>\n</template>\n");
        $this->write('README.md', "# project\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function test_a_staged_frontend_file_triggers_the_build(): void
    {
        $this->write('resources/js/New.vue', "<template>\n<p>new</p>\n</template>\n");
        $this->git('add', 'resources/js/New.vue');

        [$code, $output] = $this->build();

        $this->assertSame(0, $code, $output);
        $this->assertFileExists($this->project . '/npm.log');
    }

    public function test_a_commit_without_frontend_files_does_not_build(): void
    {
        $this->write('README.md', "# changed\n");
        $this->git('add', 'README.md');

        [$code, $output] = $this->build();

        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($this->project . '/npm.log');
    }

    public function test_a_renamed_and_edited_frontend_file_triggers_the_build(): void
    {
        $this->git('mv', 'resources/js/Moved.vue', 'resources/js/Renamed.vue');
        $this->write('resources/js/Renamed.vue', "<template>\n<p>one</p>\n<p>two</p>\n<p>three</p>\n<p>four, edited</p>\n</template>\n");
        $this->git('add', 'resources/js/Renamed.vue');
        $this->assertStringContainsString('R', $this->git('status', '--short'));

        [$code, $output] = $this->build();

        $this->assertSame(0, $code, $output);
        $this->assertFileExists($this->project . '/npm.log');
    }

    /**
     * The build is switched on (the harness's default is off), the php-cs-fixer step is out of the
     * picture, and the stub `npm` comes first in the PATH.
     *
     * @return array{0: int, 1: string}
     */
    private function build(): array
    {
        return $this->runHook([
            'SKIP_BUILD' => '0',
            'SKIP_CSFIXER' => '1',
            'PATH' => $this->project . '/bin:' . getenv('PATH'),
        ]);
    }
}
