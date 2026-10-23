<?php

return [
    'settings' => [
        'repository_url'   => env('BANKAI_REPOSITORY_URL'),
        'releases_to_keep' => (int) env('BANKAI_RELEASES_TO_KEEP', 3),
    ],

    'sentry' => [
        'enabled'      => (bool) env('BANKAI_SENTRY_ENABLED', false),
        'organization' => env('BANKAI_SENTRY_ORGANIZATION'),
        'project'      => env('BANKAI_SENTRY_PROJECT'),
        'version'      => env('BANKAI_SENTRY_VERSION'),
    ],

    'environments' => [
        'staging' => [
            'strategy'         => env('BANKAI_STAGING_STRATEGY', 'artifact'),
            'url'              => env('BANKAI_STAGING_URL'),
            'branch'           => env('BANKAI_STAGING_BRANCH', 'main'),
            'path'             => env('BANKAI_STAGING_PATH'),
            'php'              => env('BANKAI_STAGING_PHP', 'php'),
            'composer'         => env('BANKAI_STAGING_COMPOSER', 'composer'),
            'composer_options' => env('BANKAI_STAGING_COMPOSER_OPTIONS', ''),
            'migration'        => env('BANKAI_STAGING_MIGRATION', false),
            'seeder'           => env('BANKAI_STAGING_SEEDER', false),
            'maintenance'      => env('BANKAI_STAGING_MAINTENANCE', false),
            'octane'           => [
                'install' => env('BANKAI_STAGING_OCTANE_INSTALL', false),
                'reload'  => env('BANKAI_STAGING_OCTANE_RELOAD', false),
                'server'  => env('BANKAI_STAGING_OCTANE_SERVER', 'swoole'),
            ],
            'horizon' => [
                'terminate' => env('BANKAI_STAGING_HORIZON_TERMINATE', true),
            ],
            'queue' => [
                'restart' => env('BANKAI_STAGING_QUEUE_RESTART', false),
            ],
        ],

        'production' => [
            'strategy'         => env('BANKAI_PRODUCTION_STRATEGY', 'artifact'),
            'url'              => env('BANKAI_PRODUCTION_URL'),
            'branch'           => env('BANKAI_PRODUCTION_BRANCH', 'main'),
            'path'             => env('BANKAI_PRODUCTION_PATH'),
            'php'              => env('BANKAI_PRODUCTION_PHP', 'php'),
            'composer'         => env('BANKAI_PRODUCTION_COMPOSER', 'composer'),
            'composer_options' => env('BANKAI_PRODUCTION_COMPOSER_OPTIONS', '--no-dev --prefer-dist --optimize-autoloader --no-progress --no-interaction'),
            'migration'        => env('BANKAI_PRODUCTION_MIGRATION', true),
            'seeder'           => env('BANKAI_PRODUCTION_SEEDER', false),
            'maintenance'      => env('BANKAI_PRODUCTION_MAINTENANCE', false),
            'octane'           => [
                'install' => env('BANKAI_PRODUCTION_OCTANE_INSTALL', false),
                'reload'  => env('BANKAI_PRODUCTION_OCTANE_RELOAD', false),
                'server'  => env('BANKAI_PRODUCTION_OCTANE_SERVER', 'swoole'),
            ],
            'horizon' => [
                'terminate' => env('BANKAI_PRODUCTION_HORIZON_TERMINATE', true),
            ],
            'queue' => [
                'restart' => env('BANKAI_PRODUCTION_QUEUE_RESTART', false),
            ],
        ],
    ],
];
