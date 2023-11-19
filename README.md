# Bankai 

Bankai offers a streamlined solution for achieving zero-downtime deployments in Laravel applications using [Envoy](https://laravel.com/docs/10.x/envoy). 
This comprehensive guide covers installation, configuration, and deployment processes, complete with examples and detailed explanations.

## Installation

Begin by integrating Bankai with your Laravel project via Composer:

```shell
composer require axazara/bankai --dev
```

## Configuration

After installation, initialize Bankai with the following command:

```shell
php artisan bankai:install
```

This will:
- Publish `bankai.php` in your config directory.
- Add `Envoy.blade.php` to your project's root.

Customize `bankai.php` according to your project's needs.

### Example Configuration (`bankai.php`)

```php
return [
    // General settings for the deployment
    'settings' => [
        'repository' => 'your-repository', // Specify the Git repository URL
        'slack_notification' => false, // Set true to enable Slack notifications
        'slack_webhook' => 'https://hooks.slack.com/services/your-webhook', // Slack webhook URL
        'slack_channel' => '#deployment', // Slack channel for notifications
    ],

    // Define environments such as staging, production
    'environments' => [
        'staging' => [
            'ssh_host' => 'your-host', // SSH host for the server
            'ssh_user' => 'your-user', // SSH user for deployment
            'url' => 'your-app-url-here', // Application URL
            'branch' => 'main', // Branch to deploy
            'path' => '', // Path to the deployment directory
            'php' => 'php', // PHP binary to use
            'migration' => false, // Set true to run migrations
            'seeder' => false, // Set true to run seeders
            'maintenance' => false, // Set true to enable maintenance mode
            'composer' => 'composer', // Composer binary to use
            'composer_options' => '', // Additional options for Composer
            'octane' => [
                'install' => false, // Set true if using Laravel Octane
                'reload' => false, // Set true to reload Octane servers
                'server' => 'swoole', // Octane server type (e.g., Swoole)
            ],
            'horizon' => [
                'terminate' => true, // Set true to terminate Horizon after deployment
            ],
            'queue' => [
                'restart' => false, // Set true to restart queue workers
            ],
        ],
    ],
];
```

## Deployment Steps

### Step 1: Prepare Deployment Directories

Setup your deployment environment:

```shell
vendor/bin/envoy run deploy --env={Your Environment}
```

Creates three key directories:
- **Releases**: Houses all deployments.
- **Shared**: For shared resources like `.env` files.
- **Backup**: Stores backups of releases.

### Step 2: Execute Deployment

Deploy with:

```shell
php artisan bankai:deploy --env={Your Environment}
```

### Step 3: Post-Deployment Tasks

Add tasks in `Envoy.blade.php` for post-deployment actions:
These tasks are executed after the deployment is complete.

```blade
@task("run:after_deploy")
    cd {{ $release }}
    php artisan key:generate
    {{ $php }} artisan db:seed --class=RolesAndPermissionsSeeder
@endtask
```

Use `$release` and `$php` for dynamic task configuration.
- `$release` is the path to the current release.
- `$php` is the path to the PHP binary.
- `$composer` is the path to the Composer binary.
- `$npm` is the path to the NPM binary.

## Additional Commands

- List releases: `vendor/bin/envoy run releases --env=foo`
- Rollback: `vendor/bin/envoy run rollback --env=foo`
- List backups: `vendor/bin/envoy run backups --env=foo`

## Zero-Downtime Deployment Mechanics

1. **New Release Preparation**: Bankai creates a new release in the "Releases" directory.
2. **Symlink Switching**: The symlink pointing to the current version is instantaneously switched to the new release.
3. **Shared Resources**: Consistency across deployments is maintained via shared directories and files.
4. **Rollbacks**: Quickly revert to previous releases if needed.
5. **Maintenance**: Post-deployment, old releases can be cleaned up.

## Contributing

Contributions are welcome.

## Security Vulnerabilities

If you discover a security vulnerability within this package,
please send an e-mail to MailZeet Security via [hello@axazara.com](mailto:security@axazara.com).
All security vulnerabilities will be promptly addressed.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE.md).
