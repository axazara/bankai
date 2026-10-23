<?php

declare(strict_types=1);

namespace AxaZara\Bankai;

use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * Resolves the settings of a deployment environment.
 *
 * Every key is read from a BANKAI_{ENV}_{KEY} environment variable first (for
 * example BANKAI_PRODUCTION_PATH or BANKAI_STAGING_OCTANE_RELOAD), then from
 * config/bankai.php, then from the package default. The SSH host and user are
 * read from the environment only: config/bankai.php is committed, and server
 * access does not belong in version control.
 */
final class EnvironmentSettings
{
    /**
     * Keys that can only be provided through environment variables.
     */
    public const ENVIRONMENT_ONLY = ['ssh_host', 'ssh_user'];

    /**
     * Package defaults, keyed by dotted configuration key. Null marks a required key.
     */
    public const DEFAULTS = [
        'strategy'          => DeploymentConfig::STRATEGY_CLONE,
        'ssh_host'          => null,
        'ssh_user'          => null,
        'url'               => null,
        'branch'            => 'main',
        'path'              => null,
        'php'               => 'php',
        'composer'          => 'composer',
        'composer_options'  => '',
        'migration'         => false,
        'seeder'            => false,
        'maintenance'       => false,
        'octane.install'    => false,
        'octane.reload'     => false,
        'octane.server'     => 'swoole',
        'horizon.terminate' => false,
        'queue.restart'     => false,
    ];

    private const BOOLEAN_KEYS = [
        'migration',
        'seeder',
        'maintenance',
        'octane.install',
        'octane.reload',
        'horizon.terminate',
        'queue.restart',
    ];

    /**
     * The environment variable holding a key of the given environment.
     */
    public static function variable(string $env, string $key): string
    {
        $environment = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $env), '_'));

        return 'BANKAI_' . $environment . '_' . strtoupper(str_replace('.', '_', $key));
    }

    /**
     * Whether the environment is declared in the configuration or through its SSH host variable.
     */
    public static function exists(string $env): bool
    {
        return is_array(config("bankai.environments.{$env}"))
            || self::fromEnvironment(self::variable($env, 'ssh_host')) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(string $env): array
    {
        $configured = (array) config("bankai.environments.{$env}", []);
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            $variable = self::variable($env, $key);
            $fromEnvironment = self::fromEnvironment($variable);
            $fromConfig = Arr::get($configured, $key);

            if (in_array($key, self::ENVIRONMENT_ONLY, true)) {
                self::rejectCommittedValue("bankai.environments.{$env}.{$key}", $fromConfig, $fromEnvironment, $variable);
                $value = $fromEnvironment;
            } else {
                $value = $fromEnvironment ?? $fromConfig ?? $default;
            }

            Arr::set($settings, $key, in_array($key, self::BOOLEAN_KEYS, true) ? self::toBoolean($value) : $value);
        }

        return $settings;
    }

    /**
     * Read a secret from its environment variable only, refusing a value committed in the configuration.
     */
    public static function secret(string $variable, string $configKey): ?string
    {
        $value = self::fromEnvironment($variable);

        self::rejectCommittedValue($configKey, config($configKey), $value, $variable);

        return $value;
    }

    /**
     * A value that only differs from its environment variable was written in config/bankai.php itself.
     * One that matches came from an env() call, which is fine.
     */
    private static function rejectCommittedValue(string $configKey, mixed $configured, ?string $fromEnvironment, string $variable): void
    {
        if (is_scalar($configured) && (string) $configured !== '' && (string) $configured !== $fromEnvironment) {
            throw new RuntimeException(
                "{$configKey} must not be set in config/bankai.php, which is committed. " .
                "Remove it and provide {$variable} as an environment variable (a CI secret, or the deployer's local .env)."
            );
        }
    }

    private static function fromEnvironment(string $variable): ?string
    {
        $value = Env::get($variable);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function toBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        // Unrecognised values are kept as is so validation reports them.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
    }
}
