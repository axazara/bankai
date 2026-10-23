<?php

namespace AxaZara\Bankai\Traits;

use AxaZara\Bankai\EnvironmentSettings;
use Illuminate\Support\Facades\Validator;

trait ConfigValidationTrait
{
    /**
     * Validate the deployment configuration and return the resolved settings of the environment.
     *
     * Values are validated as resolved, so one provided through its environment
     * variable satisfies the rules exactly like a configured one.
     *
     * @return array<string, mixed>
     */
    protected function validateConfiguration(string $environment): array
    {
        if (! EnvironmentSettings::exists($environment)) {
            throw new \RuntimeException(
                "Unknown deployment environment: '{$environment}'. Define it under 'environments' in config/bankai.php " .
                'or set ' . EnvironmentSettings::variable($environment, 'ssh_host') . '.'
            );
        }

        $settings = EnvironmentSettings::resolve($environment);

        foreach (EnvironmentSettings::ENVIRONMENT_ONLY as $key) {
            if ($settings[$key] === null) {
                throw new \RuntimeException(
                    "The '{$environment}' environment has no " . str_replace('_', ' ', $key) . '. ' .
                    'Set ' . EnvironmentSettings::variable($environment, $key) . ' in the environment running Envoy.'
                );
            }
        }

        $configurations = [
            "bankai.environments.{$environment}" => [$settings, $this->getEnvironmentRules()],
            'bankai.settings'                    => [
                [
                    ...(array) $this->getConfig('bankai.settings'),
                    'slack_webhook_url' => $this->resolveSecret('BANKAI_SLACK_WEBHOOK_URL', 'bankai.settings.slack_webhook_url'),
                ],
                $this->getSettingsRules(),
            ],
            'bankai.sentry' => [
                [
                    ...(array) $this->getConfig('bankai.sentry'),
                    'token' => $this->resolveSecret('BANKAI_SENTRY_TOKEN', 'bankai.sentry.token'),
                ],
                config('bankai.sentry.enabled') ? $this->getSentryRules() : [],
            ],
        ];

        foreach ($configurations as $configKey => [$data, $rules]) {
            $this->validate($data, $rules, $configKey);
        }

        return $settings;
    }

    /**
     * Secrets come from their environment variable only, so CI injects them
     * from its secret store; a value committed in config/bankai.php is refused.
     */
    private function resolveSecret(string $environmentVariable, string $configKey): ?string
    {
        return EnvironmentSettings::secret($environmentVariable, $configKey);
    }

    private function getConfig(string $key): mixed
    {
        return config($key);
    }

    private function validate(array $data, array $rules, string $configKey): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            // Name the config block: on its own, a message like
            // {"token":["The token field is required."]} gives no clue which of
            // the three validated blocks failed, let alone which key to set.
            throw new \RuntimeException("Invalid configuration for [{$configKey}]: " . $validator->errors() . $this->environmentHint($configKey));
        }
    }

    /**
     * Environment settings can come from BANKAI_{ENV}_{KEY} variables, so say which ones.
     */
    private function environmentHint(string $configKey): string
    {
        if (! str_starts_with($configKey, 'bankai.environments.')) {
            return '';
        }

        $environment = substr($configKey, strlen('bankai.environments.'));

        return ' Each key can be set through ' . EnvironmentSettings::variable($environment, 'KEY') .
            ' (for example ' . EnvironmentSettings::variable($environment, 'url') . ').';
    }

    private function getEnvironmentRules(): array
    {
        return [
            'strategy'          => 'required|string|in:clone,artifact',
            'branch'            => 'required|string',
            'ssh_host'          => 'required|string',
            'ssh_user'          => 'required|string',
            'url'               => 'required|url',
            'path'              => 'required|string',
            'php'               => 'required|string',
            'composer'          => 'required|string',
            'composer_options'  => 'present|string',
            'migration'         => 'required|boolean',
            'seeder'            => 'required|boolean',
            'queue.restart'     => 'required|boolean',
            'maintenance'       => 'required|boolean',
            'octane.install'    => 'required|boolean',
            'octane.reload'     => 'required|boolean',
            'octane.server'     => 'required|in:roadrunner,swoole,frankenphp,openswoole',
            'horizon.terminate' => 'required|boolean',
        ];
    }

    private function getSettingsRules(): array
    {
        return [
            'repository_url'    => 'nullable|string',
            'slack_webhook_url' => 'nullable|url',
            'releases_to_keep'  => 'sometimes|integer|min:1',
        ];
    }

    private function getSentryRules(): array
    {
        return [
            'organization'       => 'required|string',
            'project'            => 'required|string',
            'token'              => 'required|string',
            'version'            => 'nullable|string',
        ];
    }
}
