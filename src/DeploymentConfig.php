<?php

namespace AxaZara\Bankai;

use AxaZara\Bankai\Traits\ConfigValidationTrait;

class DeploymentConfig
{
    use ConfigValidationTrait;

    public function __construct(
        public readonly string $env
    ) {
        $this->validateConfiguration(environment: $env);
    }

    public function extractVariables(): array
    {
        $environmentSettings = $this->getConfig('bankai.environments.' . $this->env);
        $release = $this->env . '_' . date('Ymd') . '_' . date('His');
        $path = rtrim($environmentSettings['path'], '/');

        return array_merge(
            $this->getBasicVariables($environmentSettings, $path, $release),
            $this->getSentryVariables($release)
        );
    }

    private function getBasicVariables(
        array $environmentSettings,
        string $path,
        string $release
    ): array {
        return [
            'repositoryUrl'      => $this->getConfig('bankai.settings.repository_url'),
            'slackWebhookUrl'    => $this->getConfig('bankai.settings.slack_webhook_url'),
            'appName'            => config('app.name'),
            'branch'             => $environmentSettings['branch'],
            'sshHost'            => $environmentSettings['ssh_host'],
            'sshUser'            => $environmentSettings['ssh_user'],
            'appUrl'             => $environmentSettings['url'],
            'path'               => $path,
            'php'                => $environmentSettings['php'] ?? 'default_php_version',
            'composer'           => $environmentSettings['composer'] ?? 'default_composer_path',
            'composerOptions'    => $environmentSettings['composer_options'] ?? '',
            'migration'          => $environmentSettings['migration'],
            'seeder'             => $environmentSettings['seeder'],
            'queueRestart'       => $environmentSettings['queue']['restart'],
            'maintenance'        => $environmentSettings['maintenance'],
            'octaneInstall'      => $environmentSettings['octane']['install'],
            'octaneReload'       => $environmentSettings['octane']['reload'],
            'octaneServer'       => $environmentSettings['octane']['server'],
            'horizonTerminate'   => $environmentSettings['horizon']['terminate'],
            'release'            => $release,
            'releasePath'        => "{$path}/releases/{$release}",
            'releasesPath'       => "{$path}/releases",
            'sharedPath'         => "{$path}/shared",
            'backupPath'         => "{$path}/backups",
            'currentReleasePath' => "{$path}/current",
        ];
    }

    private function getSentryVariables(string $release): array
    {
        $version = is_null($this->getConfig('bankai.sentry.version'))
            ? $release
            : $this->getConfig('bankai.sentry.version');

        return [
            'sentryEnabled'      => $this->getConfig('bankai.sentry.enabled'),
            'sentryOrg'          => $this->getConfig('bankai.sentry.organization'),
            'sentryProject'      => $this->getConfig('bankai.sentry.project'),
            'sentryToken'        => $this->getConfig('bankai.sentry.token'),
            'sentryVersion'      => $version,
        ];
    }
}
