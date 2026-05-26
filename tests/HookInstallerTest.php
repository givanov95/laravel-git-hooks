<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks\Tests;

use Givanov95\LaravelGitHooks\HookInstaller;
use PHPUnit\Framework\TestCase;

final class HookInstallerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/lgh-' . uniqid('', true);
        mkdir($this->project . '/.git/hooks', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->project);
    }

    public function test_it_installs_the_pre_commit_hook(): void
    {
        $result = HookInstaller::install($this->project);

        $hook = $this->project . '/.git/hooks/pre-commit';

        $this->assertTrue($result);
        $this->assertTrue(is_link($hook) || is_file($hook));
        $this->assertStringContainsString('laravel-git-hooks', (string) file_get_contents($hook));
    }

    public function test_it_returns_false_when_not_a_git_repository(): void
    {
        $nonRepo = sys_get_temp_dir() . '/lgh-norepo-' . uniqid('', true);
        mkdir($nonRepo, 0o755, true);

        $this->assertFalse(HookInstaller::install($nonRepo));

        $this->deleteTree($nonRepo);
    }

    public function test_it_is_idempotent(): void
    {
        $this->assertTrue(HookInstaller::install($this->project));
        $this->assertTrue(HookInstaller::install($this->project));

        $hook = $this->project . '/.git/hooks/pre-commit';
        $this->assertTrue(is_link($hook) || is_file($hook));
    }

    public function test_it_backs_up_a_pre_existing_handwritten_hook(): void
    {
        $hook = $this->project . '/.git/hooks/pre-commit';
        file_put_contents($hook, "#!/bin/sh\necho legacy\n");

        HookInstaller::install($this->project);

        $this->assertFileExists($hook . '.local.bak');
        $this->assertStringContainsString('legacy', (string) file_get_contents($hook . '.local.bak'));
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
