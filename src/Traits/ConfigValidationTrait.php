<?php

namespace AxaZara\Bankai\Traits;

use Illuminate\Support\Facades\Validator;

trait ConfigValidationTrait
{
    protected function validateConfiguration(string $environment): void
    {
        if (! is_array($this->getConfig("bankai.environments.{$environment}"))) {
            throw new \RuntimeException("Unknown deployment environment: '{$environment}'. Define it under 'environments' in config/bankai.php.");
        }

        $configurations = [
            "bankai.environments.{$environment}" => $this->getEnvironmentRules(),
            'bankai.settings'                    => $this->getSettingsRules(),
            'bankai.sentry'                      => config('bankai.sentry.enabled')
                ? $this->getSentryRules()
                : [],
        ];

        foreach ($configurations as $configKey => $rules) {
            $this->validate((array) $this->getConfig($configKey), $rules, $configKey);
        }
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
