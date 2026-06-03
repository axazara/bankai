# Bankai

Bankai offers a streamlined solution for achieving zero-downtime deployments in Laravel applications using [Envoy](https://laravel.com/docs/envoy).
This guide covers installation, configuration, and deployment, complete with examples and detailed explanations.

## Requirements

- PHP 8.1 or higher
- Laravel 10.x, 11.x, 12.x or 13.x

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

- Publish `config/bankai.php` in your config directory.
- Add `Envoy.blade.php` to your project's root.

Customize `config/bankai.php` according to your project's needs.

### Example configuration (`config/bankai.php`)

```php
return [
    // General deployment settings
    'settings' => [
        // Git repository to deploy (HTTPS or SSH URL)
        'repository_url'    => 'git@github.com:your-org/your-repository.git',
        // Slack Incoming Webhook URL; leave null to disable notifications
        'slack_webhook_url' => null,
    ],

    // Define your environments, e.g. staging and production
    'environments' => [
        'staging' => [
            'ssh_host'         => 'your-host',       // SSH host of the server
            'ssh_user'         => 'your-user',       // SSH user used for deployment
            'url'              => 'https://staging.your-app.com', // Application URL
            'branch'           => 'main',            // Branch to deploy
            'path'             => '/var/www/your-app', // Deployment directory on the server
            'php'              => 'php',              // PHP binary
            'composer'         => 'composer',         // Composer binary
            'composer_options' => '',                 // Extra options passed to `composer install`
            'migration'        => false,              // Run migrations
            'seeder'           => false,              // Run seeders
            'maintenance'      => false,              // Toggle maintenance mode during deploy
            'octane'           => [
                'install' => false,                   // Install Laravel Octane
                'reload'  => false,                   // Reload Octane after deploy
                'server'  => 'swoole',                // roadrunner, swoole, frankenphp or openswoole
            ],
            'horizon' => [
                'terminate' => true,                  // Terminate Horizon after deploy
            ],
            'queue' => [
                'restart' => false,                   // Restart queue workers after deploy
            ],
        ],
    ],
];
```

### Example `Envoy.blade.php`

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

@task("run:before_deploy")
    # Runs before the deployment starts (the new release is not cloned yet).
    true
@endtask

@task("run:after_deploy")
    cd "{{ $releasePath }}"
    # Your post-build commands here, e.g. php artisan jwt:secret --force
@endtask

@task("run:after_rollback")
    cd "{{ $currentReleasePath }}"
@endtask
```

## Deployment

The first deployment follows five steps. Once set up, day-to-day deployments are just Step 4.

### Step 1 — Prepare the deployment directories

Set up your deployment environment:

```shell
vendor/bin/envoy run setup --env={your-environment}
```

This creates the directory structure on the server:

- **releases/**: Houses every deployment.
- **shared/**: Resources shared across releases, such as the `.env` file and `auth.json`.
- **backups/**: Reserved for release backups.
- **current**: Symlink pointing to the live release.

Your application key is generated and stored in `shared/.env`.

> This is a one-time setup, generally run from your local machine.

### Step 2 — Configure your environment file

Edit the shared environment file created during setup. Every release symlinks to it:

```shell
{path}/shared/.env
```

### Step 3 — Configure Composer authentication

Required only if your application pulls **private** Composer packages or registries. Add an `auth.json` file to the shared directory:

```shell
{path}/shared/auth.json
```

Bankai symlinks `shared/auth.json` into each release before running `composer install` (during both `setup` and `deploy`), so Composer can authenticate. Because it lives in `shared/`, it is created once and reused by every release.

If your **first** `setup` already needs private packages, create the directory and the file beforehand so they exist when setup runs `composer install`:

```shell
mkdir -p {path}/shared && editor {path}/shared/auth.json
```

See the [Composer authentication documentation](https://getcomposer.org/doc/articles/authentication-for-private-packages.md) for the expected file format.

### Step 4 — Deploy

```shell
vendor/bin/envoy run deploy --env={your-environment}
```

> This can be run from your local machine or from your CI/CD pipeline.
> At [Axa Zara](https://axazara.com), we deploy automatically after each merge to the `staging` or `release` branch.

### Step 5 — Configure your web server

Point your web server at the `current/public` directory. For example, with [Laravel Forge](https://forge.laravel.com/) you should set your site's web directory to `current/public`. The `current` symlink always points to the latest release.

## Lifecycle hooks

Bankai exposes three hooks you can define in your `Envoy.blade.php`:

- `run:before_deploy` runs before the new release is cloned.
- `run:after_deploy` runs after the release is built, before it goes live.
- `run:after_rollback` runs after a rollback completes.

Example:

```blade
@task("run:after_deploy")
    cd {{ $releasePath }}
    php artisan jwt:secret --force
@endtask
```

The following variables are available in your tasks:

- `$releasePath` — path to the release being deployed.
- `$currentReleasePath` — path to the `current` symlink (the live release).
- `$sharedPath` — path to the shared directory.
- `$releasesPath` — path to the releases directory.
- `$php` — path to the PHP binary.
- `$composer` — path to the Composer binary.

## Rollback

Quickly revert to the previous release:

```shell
vendor/bin/envoy run deploy:rollback --env={your-environment}
```

## Additional commands

- List releases: `vendor/bin/envoy run releases --env={your-environment}`
- List backups: `vendor/bin/envoy run backups --env={your-environment}`

## Zero-downtime deployment mechanics

1. **New release preparation**: Bankai creates a new release in the `releases/` directory.
2. **Symlink switching**: The `current` symlink is switched atomically to the new release.
3. **Shared resources**: Consistency across deployments is maintained via the shared directory and files.
4. **Rollbacks**: Revert to a previous release at any time.
5. **Cleanup**: Old releases are pruned, keeping the three most recent.

## Sentry integration

Bankai can record a release in Sentry after each deployment. To enable it, add a
`sentry` block to `config/bankai.php`:

```php
'sentry' => [
    'enabled'      => false,
    'organization' => 'your-organization',
    'project'      => 'your-project',
    'token'        => 'your-token',
    'version'      => null, // Defaults to the current release name when null
],
```

- `sentry.enabled`: Set to `true` to enable Sentry integration.
- `sentry.organization`: Your Sentry organization.
- `sentry.project`: Your Sentry project.
- `sentry.token`: Your Sentry auth token. Learn more [here](https://docs.sentry.io/product/accounts/auth-tokens).
- `sentry.version`: The Sentry release version. Defaults to the current release name.

## Contributing

Contributions are welcome.

## Security vulnerabilities

If you discover a security vulnerability within this package, please email Axa Zara Security at [security@axazara.com](mailto:security@axazara.com). All security vulnerabilities will be promptly addressed.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE.md).
