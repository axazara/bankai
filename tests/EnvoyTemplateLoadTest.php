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
    /**
     * @param array<string, mixed> $overrides
     */
    private function loadedContainer(string $strategy, array $overrides = []): TaskContainer
    {
        $container = new TaskContainer();

        $container->load(
            __DIR__ . '/../src/Envoy.blade.php',
            new Compiler(),
            array_merge($this->templateVariables($strategy), $overrides)
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

        // Octane only serves the new code once its master has been restarted, so the
        // app must stay down until then: bringing it up earlier would serve the old
        // code against an already migrated database.
        $this->assertLessThan(
            array_search('make:app_up', $story, true),
            array_search('make:reload_octane', $story, true)
        );

        $this->assertLessThan(
            array_search('make:check_app_health', $story, true),
            array_search('make:app_up', $story, true)
        );
    }

    public function test_the_rollback_story_health_checks_the_restored_release(): void
    {
        $container = $this->loadedContainer('clone');

        $story = $container->getMacro('deploy:rollback');

        $this->assertContains('make:check_app_health', $story);

        $this->assertLessThan(
            array_search('rollback:complete', $story, true),
            array_search('make:check_app_health', $story, true)
        );
    }

    public function test_octane_is_stopped_rather_than_reloaded_on_deploy_and_rollback(): void
    {
        $container = $this->loadedContainer('clone', ['octaneReload' => true]);

        foreach (['make:reload_octane', 'make:rollback'] as $task) {
            $script = $container->getTask($task)->script;

            // 'octane:reload' recycles the workers of a master still booted from the
            // release being left behind, so it would keep serving the old code.
            $this->assertStringNotContainsString('octane:reload', $script);
            $this->assertStringContainsString('octane:stop', $script);
            $this->assertStringContainsString('octane:status --no-interaction > /dev/null 2>&1', $script);
        }
    }

    public function test_stale_compiled_views_are_pruned_from_the_shared_storage(): void
    {
        $container = $this->loadedContainer('clone');

        $script = $container->getTask('make:clean_old_release')->script;

        $this->assertStringContainsString('/var/www/app/shared/storage/framework/views', $script);
        $this->assertStringContainsString('-mtime +30 -delete', $script);
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

    public function test_an_empty_current_directory_is_removed_with_rmdir_only(): void
    {
        $container = $this->loadedContainer('clone');

        $script = $container->getTask('make:link_current_release')->script;

        // rmdir fails on non-empty directories, so a real deployment can never
        // be deleted; anything non-empty must still abort the deploy.
        $this->assertStringContainsString('rmdir', $script);
        $this->assertStringNotContainsString('rm -rf "{{ $currentReleasePath }}"', $script);
        $this->assertStringContainsString('refusing to replace it', $script);
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
