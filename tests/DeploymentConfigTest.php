<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\DeploymentConfig;
use RuntimeException;

class DeploymentConfigTest extends TestCase
{
    public function test_it_extracts_variables_for_a_valid_environment(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('git@github.com:acme/app.git', $variables['repositoryUrl']);
        $this->assertSame('main', $variables['branch']);
        $this->assertSame('staging.example.com', $variables['sshHost']);
        $this->assertSame('Bankai Test', $variables['appName']);
        $this->assertFalse($variables['sentryEnabled']);
    }

    public function test_it_normalises_paths_and_builds_release_paths(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        // The trailing slash from the configured path is trimmed.
        $this->assertSame('/var/www/app', $variables['path']);
        $this->assertSame('/var/www/app/releases', $variables['releasesPath']);
        $this->assertSame('/var/www/app/shared', $variables['sharedPath']);
        $this->assertSame('/var/www/app/backups', $variables['backupPath']);
        $this->assertSame('/var/www/app/current', $variables['currentReleasePath']);
        $this->assertStringStartsWith('staging_', $variables['release']);
        $this->assertSame("/var/www/app/releases/{$variables['release']}", $variables['releasePath']);
        $this->assertArrayHasKey('date', $variables);
    }

    public function test_the_sentry_version_defaults_to_the_release_name(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame($variables['release'], $variables['sentryVersion']);
    }

    public function test_it_throws_for_an_unknown_environment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown deployment environment: 'does-not-exist'");

        new DeploymentConfig('does-not-exist');
    }

    public function test_it_throws_when_a_required_setting_is_missing(): void
    {
        config(['bankai.settings.repository_url' => null]);

        $this->expectException(RuntimeException::class);

        new DeploymentConfig('staging');
    }

    public function test_it_throws_for_an_invalid_octane_server(): void
    {
        config(['bankai.environments.staging.octane.server' => 'not-a-server']);

        $this->expectException(RuntimeException::class);

        new DeploymentConfig('staging');
    }

    public function test_the_strategy_defaults_to_clone(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('clone', $variables['strategy']);
    }

    public function test_it_exposes_artifact_lock_and_retention_variables(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('/var/www/app/artifacts', $variables['artifactsPath']);
        $this->assertSame('/var/www/app/artifacts/incoming.tar.gz', $variables['artifactPath']);
        $this->assertSame('/var/www/app/.bankai-deploy.lock', $variables['deployLockPath']);
        $this->assertSame(3, $variables['releasesToKeep']);
    }

    public function test_releases_to_keep_is_configurable(): void
    {
        config(['bankai.settings.releases_to_keep' => 5]);

        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame(5, $variables['releasesToKeep']);
    }

    public function test_it_accepts_the_artifact_strategy(): void
    {
        config(['bankai.environments.staging.strategy' => 'artifact']);

        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('artifact', $variables['strategy']);
    }

    public function test_it_throws_for_an_unknown_strategy(): void
    {
        config(['bankai.environments.staging.strategy' => 'teleport']);

        $this->expectException(RuntimeException::class);

        new DeploymentConfig('staging');
    }

    public function test_the_artifact_strategy_does_not_require_a_repository_url(): void
    {
        config([
            'bankai.environments.staging.strategy' => 'artifact',
            'bankai.settings.repository_url'       => null,
        ]);

        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertNull($variables['repositoryUrl']);
    }

    public function test_it_rejects_a_repository_url_with_embedded_credentials(): void
    {
        config(['bankai.settings.repository_url' => 'https://user:token@github.com/acme/app.git']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not embed credentials');

        new DeploymentConfig('staging');
    }

    public function test_the_repository_url_can_be_overridden_from_the_environment(): void
    {
        $_ENV['BANKAI_REPOSITORY_URL'] = 'https://x-access-token:ephemeral@github.com/acme/app.git';
        $_SERVER['BANKAI_REPOSITORY_URL'] = $_ENV['BANKAI_REPOSITORY_URL'];

        try {
            $variables = (new DeploymentConfig('staging'))->extractVariables();

            $this->assertSame(
                'https://x-access-token:ephemeral@github.com/acme/app.git',
                $variables['repositoryUrl']
            );
        } finally {
            unset($_ENV['BANKAI_REPOSITORY_URL'], $_SERVER['BANKAI_REPOSITORY_URL']);
        }
    }
}
