<?php

namespace AxaZara\Bankai\Traits;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Validator;

trait ConfigValidationTrait
{
    protected function validateConfiguration(string $environment): void
    {
        if (! is_array($this->getConfig("bankai.environments.{$environment}"))) {
            throw new \RuntimeException("Unknown deployment environment: '{$environment}'. Define it under 'environments' in config/bankai.php.");
        }

        // Secrets are validated as resolved, so a value injected through its
        // environment variable satisfies the rules just like a configured one.
        $configurations = [
            "bankai.environments.{$environment}" => [
                (array) $this->getConfig("bankai.environments.{$environment}"),
                $this->getEnvironmentRules(),
            ],
            'bankai.settings' => [
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
    }

    /**
     * Resolve a secret, preferring the given environment variable so CI can
     * inject it from its secret store instead of committing it to the
     * configuration file. Falls back to the configured value, and returns
     * null when neither is set.
     */
    private function resolveSecret(string $environmentVariable, string $configKey): ?string
    {
        $override = Env::get($environmentVariable);

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $configured = $this->getConfig($configKey);

        return is_string($configured) && $configured !== '' ? $configured : null;
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
            throw new \RuntimeException("Invalid configuration for [{$configKey}]: " . $validator->errors());
        }
    }

    private function getEnvironmentRules(): array
    {
        return [
            'strategy'          => 'sometimes|string|in:clone,artifact',
            'branch'            => 'required|string',
            'ssh_host'          => 'required|string',
            'ssh_user'          => 'required|string',
            'url'               => 'required|url',
            'path'              => 'required|string',
            'php'               => 'sometimes|string',
            'composer'          => 'sometimes|string',
            'composer_options'  => 'sometimes|string',
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
