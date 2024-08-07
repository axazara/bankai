# Bankai 

Bankai offers a streamlined solution for achieving zero-downtime deployments in Laravel applications using [Envoy](https://laravel.com/docs/10.x/envoy). 
This comprehensive guide covers installation, configuration, and deployment processes, complete with examples and detailed explanations.

## Requirements
- PHP 8.1 or higher
- Laravel 9.x, 10.x, 11.x

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
        'slack_webhook' => 'https://hooks.slack.com/services/your-webhook', // Slack webhook URL, leave empty to disable
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

PS: Currently, Bankai does not work with FrankenPHP for Laravel octane.

### Example Configuration (`Envoy.blade.php`)

```blade
@include('vendor/autoload.php')

@setup
    define('LARAVEL_START', microtime(true));
    $app = require_once __DIR__.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    try {
        $config = new AxaZara\Bankai\DeploymentConfig($env);
    } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
    }

    extract($config->extractVariables());

@endsetup

@import('vendor/axazara/bankai/src/Envoy.blade.php');

@task("run:after_deploy")
    cd "{{ $releasePath }}"
@endtask

@task("run:after_rollback")
    cd "{{ $currentRelease }}"
@endtask
```

## Deployment Steps

### Step 1: Prepare Deployment Directories

Setup your deployment environment:

```shell
vendor/bin/envoy run setup --env={Your Environment}
```

Creates three key directories:
- **Releases**: Houses all deployments.
- **Shared**: For shared resources like `.env` files.
- **Backup**: Stores backups of releases.
- **Current**: Symlink pointing to the current release.
- Your application secret key is generated and stored in `shared/.env`

PS : This is a one-time setup, and generally, do from your local machine.

### Step 2: Execute Deployment

Deploy with:

```shell
vendor/bin/envoy run deploy --env={Your Environment}
```

PS : This can be run from your local machine, or from your CI/CD pipeline.
At [Axa Zara](https://axazara.coom), we automatically deploy our applications after each merge to the `staging` or `release` branch via Gitlab CI/CD.

### Step 3: Post-Deployment Tasks

Add tasks in `Envoy.blade.php` for post-deployment actions:
These tasks are executed after the deployment is complete.

Example:
```blade
@task("run:after_deploy")
    cd {{ $releasePath }}
    php artisan jwt:secret --force
@endtask
```

Use the following variables in your tasks:
- `$releasePath` is the path to the ongoing release directory.
- `$php` is the path to the PHP binary.
- `$composer` is the path to the Composer binary.

### Step 4: Configure your Web Server
You can configure your web server to serve the application from the `current/public` directory.
For exemple is your use [Laravel Forge](https://forge.laravel.com/), your should set your website directory to `current/public`.
`current/public` will always point to the latest release.

## Rollback
You can quickly revert to previous releases if needed:

```shell
vendor/bin/envoy run deploy:rollback --env={Your Environment}
```
### Post-Rollback Tasks
After rollback, you can run tasks in `Envoy.blade.php`:

```blade
@task("run:after_rollback")
    cd {{ $currentRelease }}
    
@endtask
```

You can use the following variables in your tasks:
- `$currentRelease` is the path to the current release directory.
- `$php` is the path to the PHP binary.
- `$composer` is the path to the Composer binary.

## Additional Commands

- List releases: `vendor/bin/envoy run releases --env=foo`
- List backups: `vendor/bin/envoy run backups --env=foo`

## Zero-Downtime Deployment Mechanics

1. **New Release Preparation**: Bankai creates a new release in the "Releases" directory.
2. **Symlink Switching**: The symlink pointing to the current version is instantaneously switched to the new release.
3. **Shared Resources**: Consistency across deployments is maintained via shared directories and files.
4. **Rollbacks**: Quickly revert to previous releases if needed.
5. **Maintenance**: Post-deployment, old releases can be cleaned up.

## Sentry Integration
Bankai supports Sentry integration for release tracking. 
If enabled, Bankai will automatically create a new release in Sentry after each deployment.
To enable Sentry integration, add the following to bloc `bankai.php` (config file):

```php
 'sentry' => [
        'enabled'            => false,
        'organization'       => 'your-organization',
        'project'            => 'your-project',
        'token'              => 'your-token',
        'version'            => null // If null, the release will be the current release name, otherwise it will be the value of this key
    ],
```
- `sentry.enabled`: Set to `true` to enable Sentry integration.
- `sentry.organization`: Your Sentry organization.
- `sentry.project`: Your Sentry project.
- `sentry.token`: Your Sentry auth token. Learn more [here](https://docs.sentry.io/product/accounts/auth-tokens).
- `sentry.version`: Your Sentry release version. Defaults to the current release name.

## Contributing

Contributions are welcome.

## Security Vulnerabilities

If you discover a security vulnerability within this package,
please send an e-mail to Axa Zara Security via [hello@axazara.com](mailto:security@axazara.com).
All security vulnerabilities will be promptly addressed.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE.md).
