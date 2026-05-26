<?php

declare(strict_types=1);

namespace Givanov95\LaravelGitHooks;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin: wires the pre-commit hook into the consuming project on
 * every `composer install` / `composer update`. No per-project scripts needed.
 */
final class HookInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'installHook',
            ScriptEvents::POST_UPDATE_CMD => 'installHook',
        ];
    }

    public function installHook(Event $event): void
    {
        // Never install into our own repository while developing the package.
        if ($event->getComposer()->getPackage()->getName() === 'givanov95/laravel-git-hooks') {
            return;
        }

        $io = $event->getIO();

        HookInstaller::install(getcwd(), static function (string $message) use ($io): void {
            $io->write('  <info>' . $message . '</info>');
        });
    }
}
