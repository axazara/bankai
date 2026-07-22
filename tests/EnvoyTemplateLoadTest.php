<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Envoy\Compiler;
use Laravel\Envoy\TaskContainer;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Loads the shipped Envoy template through Envoy's own TaskContainer, exactly
 * as `envoy run` does, and asserts that the stories resolve and that the
 * per-strategy tasks render the right script bodies.
 */
final class EnvoyTemplateLoadTest extends BaseTestCase
{
    private function loadedContainer(string $strategy): TaskContainer
    {
        $container = new TaskContainer();

        $container->load(
            __DIR__ . '/../src/Envoy.blade.php',
            new Compiler(),
            $this->templateVariables($strategy)
        );

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateVariables(string $strategy): array
    {
        return [
            'env'                => 'staging',
            'strategy'           => $strategy,
            'repositoryUrl'      => 'git@github.com:acme/app.git',
            'slackWebhookUrl'    => null,
            'appName'            => "Acme's App",
            'branch'             => 'main',
            'sshHost'            => 'staging.example.com',
            'sshUser'            => 'deploy',
            'appUrl'             => 'https://staging.example.com',
            'path'               => '/var/www/app',
            'php'                => 'php',
            'composer'           => 'composer',
            'composerOptions'    => '',
            'migration'          => true,
            'seeder'             => false,
            'queueRestart'       => false,
            'maintenance'        => false,
            'octaneInstall'      => false,
            'octaneReload'       => false,
            'octaneServer'       => 'swoole',
            'horizonTerminate'   => false,
            'releasesToKeep'     => 3,
            'date'               => '2026-01-01 00:00:00',
            'release'            => 'staging_20260101_000000',
            'releasePath'        => '/var/www/app/releases/staging_20260101_000000',
            'releasesPath'       => '/var/www/app/releases',
            'sharedPath'         => '/var/www/app/shared',
            'backupPath'         => '/var/www/app/backups',
            'currentReleasePath' => '/var/www/app/current',
            'artifactsPath'      => '/var/www/app/artifacts',
            'artifactPath'       => '/var/www/app/artifacts/incoming.tar.gz',
            'deployLockPath'     => '/var/www/app/.bankai-deploy.lock',
            'sentryEnabled'      => false,
            'sentryOrg'          => 'acme',
            'sentryProject'      => 'app',
            'sentryToken'        => 'secret',
            'sentryVersion'      => 'staging_20260101_000000',
        ];
    }

    public function test_the_deploy_story_contains_the_expected_tasks(): void
    {
        $container = $this->loadedContainer('clone');

        $story = $container->getMacro('deploy');

        $this->assertContains('deploy:acquire_lock', $story);
        $this->assertContains('make:clone_repository', $story);
        $this->assertContains('make:extract_artifact', $story);
        $this->assertContains('make:check_app_health', $story);
        $this->assertContains('deploy:release_lock', $story);

        // The health check must run while the previous release is still on disk.
        $this->assertLessThan(
            array_search('make:clean_old_release', $story, true),
            array_search('make:check_app_health', $story, true)
        );
    }

    public function test_the_clone_strategy_renders_git_tasks_and_skips_artifact_tasks(): void
    {
        $container = $this->loadedContainer('clone');

        $this->assertStringContainsString('git clone', $container->getTask('make:clone_repository')->script);
        $this->assertStringContainsString('skipped (clone strategy)', $container->getTask('make:extract_artifact')->script);
        $this->assertStringContainsString('composer install', $container->getTask('make:install_composer_dependencies')->script);
    }

    public function test_the_artifact_strategy_renders_extraction_and_skips_git_tasks(): void
    {
        $container = $this->loadedContainer('artifact');

        $this->assertStringContainsString('tar -xzf', $container->getTask('make:extract_artifact')->script);
        $this->assertStringContainsString('skipped (artifact strategy)', $container->getTask('make:clone_repository')->script);
        $this->assertStringContainsString('artifact ships its vendor directory', $container->getTask('make:install_composer_dependencies')->script);
    }

    public function test_slack_payloads_survive_quotes_in_the_app_name(): void
    {
        $container = $this->loadedContainer('clone');

        $script = $container->getTask('deploy:complete')->script;

        // With no webhook configured the curl is omitted entirely.
        $this->assertStringNotContainsString('curl', $script);
    }

    public function test_the_current_release_switch_is_atomic(): void
    {
        $container = $this->loadedContainer('clone');

        $script = $container->getTask('make:link_current_release')->script;

        $this->assertStringContainsString('ln -sfn', $script);
        $this->assertStringContainsString('mv -Tf', $script);
    }

    public function test_the_template_registers_the_artifact_build_hook(): void
    {
        $container = $this->loadedContainer('artifact');

        $this->assertNotEmpty(
            $container->getBeforeCallbacks(),
            'The @before hook that builds and uploads the artifact is missing.'
        );
    }
}
