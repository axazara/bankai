<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\DeploymentConfig;
use AxaZara\Bankai\EnvironmentSettings;
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

    public function test_a_validation_failure_names_the_config_block_at_fault(): void
    {
        config([
            'bankai.sentry.enabled' => true,
            'bankai.sentry.token'   => '',
        ]);

        // On its own, {"token":["The token field is required."]} gives no clue
        // which of the three validated blocks failed, nor which key to set.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration for [bankai.sentry]');

        new DeploymentConfig('staging');
    }

    public function test_the_named_block_points_at_the_environment_that_failed(): void
    {
        config(['bankai.environments.staging.url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration for [bankai.environments.staging]');

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

    public function test_the_slack_webhook_url_can_be_provided_from_the_environment(): void
    {
        $this->withEnvironmentVariable('BANKAI_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/from/ci/secret', function (): void {
            $variables = (new DeploymentConfig('staging'))->extractVariables();

            $this->assertSame('https://hooks.slack.com/services/from/ci/secret', $variables['slackWebhookUrl']);
        });
    }

    public function test_a_slack_webhook_url_committed_in_the_config_is_refused(): void
    {
        config(['bankai.settings.slack_webhook_url' => 'https://hooks.slack.com/services/committed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bankai.settings.slack_webhook_url must not be set in config/bankai.php');

        new DeploymentConfig('staging');
    }

    public function test_a_secret_read_through_env_in_the_config_is_accepted(): void
    {
        // An app still carrying 'slack_webhook_url' => env('BANKAI_SLACK_WEBHOOK_URL') keeps working.
        $this->withEnvironmentVariable('BANKAI_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/from/ci/secret', function (): void {
            config(['bankai.settings.slack_webhook_url' => 'https://hooks.slack.com/services/from/ci/secret']);

            $variables = (new DeploymentConfig('staging'))->extractVariables();

            $this->assertSame('https://hooks.slack.com/services/from/ci/secret', $variables['slackWebhookUrl']);
        });
    }

    public function test_the_slack_webhook_url_is_null_when_nothing_is_configured(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertNull($variables['slackWebhookUrl']);
    }

    public function test_the_sentry_token_can_be_provided_from_the_environment(): void
    {
        config([
            'bankai.sentry.enabled' => true,
            'bankai.sentry.token'   => null,
        ]);

        $this->withEnvironmentVariable('BANKAI_SENTRY_TOKEN', 'sntrys_from_ci', function (): void {
            $variables = (new DeploymentConfig('staging'))->extractVariables();

            $this->assertTrue($variables['sentryEnabled']);
            $this->assertSame('sntrys_from_ci', $variables['sentryToken']);
        });
    }

    public function test_a_sentry_token_committed_in_the_config_is_refused(): void
    {
        config([
            'bankai.sentry.enabled' => true,
            'bankai.sentry.token'   => 'sntrys_committed',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bankai.sentry.token must not be set in config/bankai.php');

        new DeploymentConfig('staging');
    }

    public function test_it_throws_when_sentry_is_enabled_without_a_token(): void
    {
        config([
            'bankai.sentry.enabled' => true,
            'bankai.sentry.token'   => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration for [bankai.sentry]');

        new DeploymentConfig('staging');
    }

    public function test_it_throws_when_the_slack_webhook_url_from_the_environment_is_invalid(): void
    {
        $this->withEnvironmentVariable('BANKAI_SLACK_WEBHOOK_URL', 'not-a-url', function (): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid configuration for [bankai.settings]');

            new DeploymentConfig('staging');
        });
    }

    public function test_the_ssh_host_and_user_come_from_the_environment(): void
    {
        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('staging.example.com', $variables['sshHost']);
        $this->assertSame('deploy', $variables['sshUser']);
    }

    public function test_it_names_the_variable_when_the_ssh_host_is_missing(): void
    {
        $this->unsetEnvironmentVariable('BANKAI_STAGING_SSH_HOST');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set BANKAI_STAGING_SSH_HOST');

        new DeploymentConfig('staging');
    }

    public function test_an_ssh_user_committed_in_the_config_is_refused(): void
    {
        config(['bankai.environments.staging.ssh_user' => 'forge']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bankai.environments.staging.ssh_user must not be set in config/bankai.php');

        new DeploymentConfig('staging');
    }

    public function test_an_environment_variable_overrides_the_configured_setting(): void
    {
        $this->setEnvironmentVariable('BANKAI_STAGING_PATH', '/home/forge/app');
        $this->setEnvironmentVariable('BANKAI_STAGING_MIGRATION', 'true');
        $this->setEnvironmentVariable('BANKAI_STAGING_OCTANE_SERVER', 'frankenphp');

        $variables = (new DeploymentConfig('staging'))->extractVariables();

        $this->assertSame('/home/forge/app', $variables['path']);
        $this->assertTrue($variables['migration']);
        $this->assertSame('frankenphp', $variables['octaneServer']);
    }

    public function test_an_environment_can_be_defined_through_environment_variables_only(): void
    {
        $this->setEnvironmentVariable('BANKAI_PRE_PROD_SSH_HOST', 'preprod.example.com');
        $this->setEnvironmentVariable('BANKAI_PRE_PROD_SSH_USER', 'forge');
        $this->setEnvironmentVariable('BANKAI_PRE_PROD_URL', 'https://preprod.example.com');
        $this->setEnvironmentVariable('BANKAI_PRE_PROD_PATH', '/home/forge/preprod');
        $this->setEnvironmentVariable('BANKAI_PRE_PROD_STRATEGY', 'artifact');

        $variables = (new DeploymentConfig('pre-prod'))->extractVariables();

        $this->assertSame('preprod.example.com', $variables['sshHost']);
        $this->assertSame('main', $variables['branch']);
        $this->assertSame('php', $variables['php']);
        $this->assertFalse($variables['migration']);
        $this->assertFalse($variables['horizonTerminate']);
    }

    public function test_an_invalid_boolean_from_the_environment_is_refused(): void
    {
        $this->setEnvironmentVariable('BANKAI_STAGING_MIGRATION', 'sometimes');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BANKAI_STAGING_URL');

        new DeploymentConfig('staging');
    }

    public function test_the_published_configuration_deploys_from_environment_variables_alone(): void
    {
        $this->setEnvironmentVariable('BANKAI_PRODUCTION_SSH_HOST', 'app.example.com');
        $this->setEnvironmentVariable('BANKAI_PRODUCTION_SSH_USER', 'forge');
        $this->setEnvironmentVariable('BANKAI_PRODUCTION_URL', 'https://app.example.com/up');
        $this->setEnvironmentVariable('BANKAI_PRODUCTION_PATH', '/home/forge/app.example.com');
        $this->setEnvironmentVariable('BANKAI_PRODUCTION_OCTANE_SERVER', 'frankenphp');
        $this->setEnvironmentVariable('BANKAI_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/from/ci/secret');

        config(['bankai' => require __DIR__ . '/../config/bankai.php']);

        $variables = (new DeploymentConfig('production'))->extractVariables();

        $this->assertSame('artifact', $variables['strategy']);
        $this->assertSame('forge', $variables['sshUser']);
        $this->assertSame('/home/forge/app.example.com', $variables['path']);
        $this->assertSame('frankenphp', $variables['octaneServer']);
        $this->assertTrue($variables['migration']);
        $this->assertTrue($variables['horizonTerminate']);
        $this->assertSame('https://hooks.slack.com/services/from/ci/secret', $variables['slackWebhookUrl']);
        $this->assertFalse($variables['sentryEnabled']);
    }

    public function test_environment_variable_names_are_normalised(): void
    {
        $this->assertSame('BANKAI_PRE_PROD_OCTANE_RELOAD', EnvironmentSettings::variable('pre-prod', 'octane.reload'));
        $this->assertSame('BANKAI_PRODUCTION_SSH_HOST', EnvironmentSettings::variable('production', 'ssh_host'));
    }

    /**
     * Run the callback with an environment variable set, then remove it.
     */
    private function withEnvironmentVariable(string $name, string $value, callable $callback): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        try {
            $callback();
        } finally {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
