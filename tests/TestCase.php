<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\Providers\BankaiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
                'token'        => 'secret',
                'version'      => null,
            ],
            'environments' => [
                'staging' => [
                    'ssh_host'         => 'staging.example.com',
                    'ssh_user'         => 'deploy',
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
