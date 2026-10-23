<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\Providers\BankaiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * SSH access only comes from the environment, so every test starts with it set.
     *
     * @var array<string, string>
     */
    protected const ENVIRONMENT = [
        'BANKAI_STAGING_SSH_HOST' => 'staging.example.com',
        'BANKAI_STAGING_SSH_USER' => 'deploy',
    ];

    protected function setUp(): void
    {
        foreach (static::ENVIRONMENT as $name => $value) {
            $this->setEnvironmentVariable($name, $value);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (array_keys($_ENV) as $name) {
            if (str_starts_with((string) $name, 'BANKAI_')) {
                $this->unsetEnvironmentVariable($name);
            }
        }

        parent::tearDown();
    }

    protected function setEnvironmentVariable(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function unsetEnvironmentVariable(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            BankaiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Bankai Test');
        $app['config']->set('bankai', $this->validConfig());
    }

    /**
     * A complete, valid Bankai configuration used as the baseline for tests.
     *
     * @return array<string, mixed>
     */
    protected function validConfig(): array
    {
        return [
            'settings' => [
                'repository_url'    => 'git@github.com:acme/app.git',
                'slack_webhook_url' => null,
            ],
            'sentry' => [
                'enabled'      => false,
                'organization' => 'acme',
                'project'      => 'app',
                'version'      => null,
            ],
            'environments' => [
                'staging' => [
                    'url'              => 'https://staging.example.com',
                    'branch'           => 'main',
                    'path'             => '/var/www/app/',
                    'php'              => 'php',
                    'composer'         => 'composer',
                    'composer_options' => '',
                    'migration'        => false,
                    'seeder'           => false,
                    'maintenance'      => false,
                    'octane'           => [
                        'install' => false,
                        'reload'  => false,
                        'server'  => 'swoole',
                    ],
                    'horizon' => [
                        'terminate' => true,
                    ],
                    'queue' => [
                        'restart' => false,
                    ],
                ],
            ],
        ];
    }
}
