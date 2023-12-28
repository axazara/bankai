<?php

namespace AxaZara\Bankai\Traits;

use Illuminate\Support\Facades\Validator;

trait ConfigValidationTrait
{
    protected function validateConfiguration($environment): void
    {
        $configurations = [
            "bankai.environments.$environment"=> $this->getEnvironmentRules(),
            'bankai.settings'                 => $this->getSettingsRules(),
            'bankai.sentry'                   => config('bankai.sentry.enabled')
                ? $this->getSentryRules()
                : [],
        ];

        foreach ($configurations as $configKey => $rules) {
            $config = $this->getConfig($configKey);
            $this->validate($config, $rules);
        }
    }

    private function getConfig(string $key): string|null|array
    {
        return config($key);
    }

    private function validate(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new \RuntimeException('Validation error: ' . $validator->errors());
        }
    }

    private function getEnvironmentRules(): array
    {
        return [
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
            'repository_url'    => 'required|url',
            'slack_webhook_url' => 'nullable|url',
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
