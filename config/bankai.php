<?php

return [
    'settings' => [
        // Git repository to deploy (clone strategy only). Use an SSH URL with a
        // read-only deploy key; HTTPS URLs with embedded credentials are rejected.
        // CI can override this at run time via the BANKAI_REPOSITORY_URL
        // environment variable (for example with an ephemeral GitHub App token).
        'repository_url'    => 'git@github.com:your-org/your-repository.git',

        // Slack Incoming Webhook URL used to post deployment notifications.
        // Leave null to disable Slack notifications.
        'slack_webhook_url' => env('BANKAI_SLACK_WEBHOOK_URL'),

        // How many releases to keep on the server (the live one included).
        'releases_to_keep'  => 3,
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
            // How the release lands on the server:
            // - 'artifact': CI builds and uploads a tarball; the server needs no Git or Composer access.
            // - 'clone': the server clones the repository and installs dependencies itself.
            'strategy'         => 'artifact',
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
            'strategy'         => 'artifact',
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
