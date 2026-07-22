<?php

namespace AxaZara\Bankai;

use AxaZara\Bankai\Traits\ConfigValidationTrait;

class DeploymentConfig
{
    use ConfigValidationTrait;

    public const STRATEGY_CLONE = 'clone';

    public const STRATEGY_ARTIFACT = 'artifact';

    public const DEFAULT_RELEASES_TO_KEEP = 3;

    private readonly string $strategy;

    private readonly ?string $repositoryUrl;

    public function __construct(
        public readonly string $env
    ) {
        $this->validateConfiguration(environment: $env);

        $this->strategy = $this->getConfig("bankai.environments.{$env}.strategy") ?? self::STRATEGY_CLONE;
        $this->repositoryUrl = $this->resolveRepositoryUrl();

        if ($this->strategy === self::STRATEGY_CLONE && ($this->repositoryUrl === null || $this->repositoryUrl === '')) {
            throw new \RuntimeException(
                "The '{$env}' environment uses the 'clone' strategy but no repository URL is configured. " .
                "Set 'bankai.settings.repository_url' or the BANKAI_REPOSITORY_URL environment variable."
            );
        }
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
            'strategy'           => $this->strategy,
            'repositoryUrl'      => $this->repositoryUrl,
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
            'releasesToKeep'     => $this->resolveReleasesToKeep(),
            'date'               => date('Y-m-d H:i:s'),
            'release'            => $release,
            'releasePath'        => "{$path}/releases/{$release}",
            'releasesPath'       => "{$path}/releases",
            'sharedPath'         => "{$path}/shared",
            'backupPath'         => "{$path}/backups",
            'currentReleasePath' => "{$path}/current",
            'artifactsPath'      => "{$path}/artifacts",
            'artifactPath'       => "{$path}/artifacts/incoming.tar.gz",
            'deployLockPath'     => "{$path}/.bankai-deploy.lock",
        ];
    }

    /**
     * Resolve the repository URL, preferring the BANKAI_REPOSITORY_URL
     * environment variable so CI can inject an ephemeral, token-bearing URL
     * (for example a GitHub App installation token) without persisting it.
     *
     * A credential embedded in the committed configuration is rejected: HTTP(S)
     * URLs with a userinfo segment leak secrets into version control and logs.
     */
    private function resolveRepositoryUrl(): ?string
    {
        $override = env('BANKAI_REPOSITORY_URL');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $configured = $this->getConfig('bankai.settings.repository_url');

        if (is_string($configured) && preg_match('#^https?://[^/@]+@#i', $configured) === 1) {
            throw new \RuntimeException(
                'bankai.settings.repository_url must not embed credentials. ' .
                'Use an SSH deploy key (git@github.com:org/repo.git) or inject an ephemeral ' .
                'token-bearing URL through the BANKAI_REPOSITORY_URL environment variable.'
            );
        }

        return $configured;
    }

    private function resolveReleasesToKeep(): int
    {
        $configured = $this->getConfig('bankai.settings.releases_to_keep');

        return is_numeric($configured) && (int) $configured >= 1
            ? (int) $configured
            : self::DEFAULT_RELEASES_TO_KEEP;
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
