<?php

namespace AxaZara\Bankai;

use Illuminate\Support\Facades\Validator;

class DeploymentConfig
{
    private string $env;

    public function __construct(string $env)
    {
        $this->env = $env;
        $this->validateConfiguration();
    }

    private function validateConfiguration(): void
    {
        $environments = config('bankai.environments');

        if (! array_key_exists($this->env, $environments)) {
            throw new \RuntimeException(message: 'Environment not found.');
        }

        $environmentConfig = $environments[$this->env];

        $rules = [
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
            'octane.server'     => 'required|in:roadrunner,swoole',
            'horizon.terminate' => 'required|boolean',
        ];

        $validator = Validator::make($environmentConfig, $rules);

        if ($validator->fails()) {
            throw new \RuntimeException(message: 'Validation error: ' . $validator->errors());
        }

        $settings = config('bankai.settings');

        $settingsRules = [
            'repository_url'    => 'required|url',
            'slack_webhook_url' => 'nullable|url',
        ];

        $validator = Validator::make($settings, $settingsRules);

        if ($validator->fails()) {
            throw new \RuntimeException(message: 'Validation error: ' . $validator->errors());
        }

    }

    public function extractVariables(): array
    {
        $environmentSettings = config('bankai.environments.' . $this->env);
        $date = date('Y-m-d_H-i-s');
        $path = $environmentSettings['path'];
        $release = $this->env . '_' . date('YmdHis');
        $releasePath = "{$path}/releases/{$release}";
        $path = rtrim($path, '/');

        return [
            'repositoryUrl'      => config('bankai.settings.repository_url'),
            'slackWebhookUrl'    => config('bankai.settings.slack_webhook_url'),
            'appName'            => config('app.name'),
            'branch'             => $environmentSettings['branch'],
            'sshHost'            => $environmentSettings['ssh_host'],
            'sshUser'            => $environmentSettings['ssh_user'],
            'appUrl'             => $environmentSettings['url'],
            'path'               => $path,
            'php'                => $environmentSettings['php'],
            'composer'           => $environmentSettings['composer'],
            'composerOptions'    => $environmentSettings['composer_options'],
            'migration'          => $environmentSettings['migration'],
            'seeder'             => $environmentSettings['seeder'],
            'queueRestart'       => $environmentSettings['queue']['restart'],
            'maintenance'        => $environmentSettings['maintenance'],
            'octaneInstall'      => $environmentSettings['octane']['install'],
            'octaneReload'       => $environmentSettings['octane']['reload'],
            'octaneServer'       => $environmentSettings['octane']['server'],
            'horizonTerminate'   => $environmentSettings['horizon']['terminate'],
            'trimmedPath'        => trim($environmentSettings['path'], '/'),
            'releasesPath'       => trim($environmentSettings['path'], '/') . '/releases',
            'currentPath'        => trim($environmentSettings['path'], '/') . '/current',
            'sharedPath'         => trim($environmentSettings['path'], '/') . '/shared',
            'backupPath'         => trim($environmentSettings['path'], '/') . '/backup',
            'release'            => $release,
            'releasePath'        => $releasePath,
            'currentReleasePath' => "{$path}/current",
            'currentRelease'     => "{$path}/current",
            'date'               => $date,
        ];
    }
}
