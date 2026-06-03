<?php

return [
    'settings' => [
        // Git repository to deploy. Supports HTTPS and SSH URLs.
        'repository_url'    => 'git@github.com:your-org/your-repository.git',

        // Slack Incoming Webhook URL used to post deployment notifications.
        // Leave null to disable Slack notifications.
        'slack_webhook_url' => null,
    ],

    'sentry' => [
        'enabled'            => false,
        'organization'       => 'your-organization',
        'project'            => 'your-project',
        'token'              => 'your-token',
        // If null, the Sentry release name defaults to the current release; otherwise this value is used.
        'version'            => null,
    ],

    'environments' => [
        'staging' => [
            'ssh_host'         => 'your-host',
            'ssh_user'         => 'your-user',
            'url'              => 'https://staging.your-app.com',
            'branch'           => 'main',
            'path'             => '/var/www/your-app',
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

        'production' => [
            'ssh_host'         => 'your-host',
            'ssh_user'         => 'your-user',
            'url'              => 'https://your-app.com',
            'branch'           => 'main',
            'path'             => '/var/www/your-app',
            'php'              => 'php',
            'composer'         => 'composer',
            'composer_options' => '--no-dev --prefer-dist --optimize-autoloader --no-progress --no-interaction',
            'migration'        => true,
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
