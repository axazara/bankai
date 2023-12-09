<?php

return [
    'settings' => [
        'repository'         => 'your-repository',
        'slack_webhook'      => 'https://hooks.slack.com/services/your-webhook',
        'slack_channel'      => '#deployment',
    ],

    'sentry' => [
        'enabled'            => false,
        'organization'       => 'your-organization-slug',
        'project'            => 'your-project-slug',
        'token'              => 'your-token',
        'version'            => null, // If null, the release will be the current release name, otherwise it will be the value of this key
    ],

    'environments' => [
        'staging' => [
            'ssh_host'               => 'your-host',
            'ssh_user'               => 'your-user',
            'url'                    => 'your-app-url-here',
            'branch'                 => 'main',
            'path'                   => '',
            'php'                    => 'php',
            'migration'              => false,
            'seeder'                 => false,
            'maintenance'            => false,
            'composer'               => 'composer',
            'composer_options'       => '',
            'octane'                 => [
                'install' => false,
                'reload'  => false,
                'server'  => 'swoole',
            ],
            'horizon' => [
                'terminate' => true,
            ],
            'queue'                  => [
                'restart'  => false,
            ],
        ],
        'production' => [
            'ssh_host'               => 'your-host',
            'ssh_user'               => 'your-user',
            'url'                    => 'your-app-url-here',
            'branch'                 => 'main',
            'path'                   => '',
            'php'                    => 'php',
            'migration'              => true,
            'seeder'                 => false,
            'maintenance'            => false,
            'jwt_key_generate'       => true,
            'composer'               => 'composer',
            'composer_options'       => '--no-dev --verbose --prefer-dist --optimize-autoloader --no-progress --no-interaction',
            'octane'                 => [
                'install' => false,
                'reload'  => false,
                'server'  => 'swoole',
            ],
            'horizon' => [
                'terminate' => true,
            ],
            'queue'                  => [
                'restart'  => false,
            ],
        ],
    ],
];
