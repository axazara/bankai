<?php

namespace AxaZara\Bankai;

use Illuminate\Support\Facades\Validator;

class DeploymentConfig
{
    private array $config;

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
    }

    public function extractVariables(): array
    {
        $environementSettings = config('bankai.environments.' . $this->env);

        return [
            'repositoryUrl'     => config('bankai.settings.repository_url'),
            'slackNotification' => config('bankai.settings.slack_notification'),
            'slackWebhookUrl'   => config('bankai.settings.slack_webhook_url'),
            'appName'           => config('app.name'),
            'branch'            => $environementSettings['branch'],
            'sshHost'           => $environementSettings['ssh_host'],
            'sshUser'           => $environementSettings['ssh_user'],
            'appUrl'            => $environementSettings['url'],
            'path'              => $environementSettings['path'],
            'php'               => $environementSettings['php'],
            'composer'          => $environementSettings['composer'],
            'composerOptions'   => $environementSettings['composer_options'],
            'migration'         => $environementSettings['migration'],
            'seeder'            => $environementSettings['seeder'],
            'queueRestart'      => $environementSettings['queue']['restart'],
            'maintenance'       => $environementSettings['maintenance'],
            'octaneInstall'     => $environementSettings['octane']['install'],
            'octaneReload'      => $environementSettings['octane']['reload'],
            'octaneServer'      => $environementSettings['octane']['server'],
            'horizonTerminate'  => $environementSettings['horizon']['terminate'],
            'trimmedPath'       => trim($environementSettings['path'], '/'),
            'releasesPath'      => trim($environementSettings['path'], '/') . '/releases',
            'currentPath'       => trim($environementSettings['path'], '/') . '/current',
            'sharedPath'        => trim($environementSettings['path'], '/') . '/shared',
            'backupPath'        => trim($environementSettings['path'], '/') . '/backup',
        ];
    }
}
