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
        // Git repository to deploy (clone strategy only). Use an SSH URL with a
        // read-only deploy key. HTTPS URLs with embedded credentials are rejected;
        // CI can inject an ephemeral token-bearing URL through the
        // BANKAI_REPOSITORY_URL environment variable instead.
        'repository_url'    => 'git@github.com:your-org/your-repository.git',
        // Slack Incoming Webhook URL; leave null to disable notifications
        'slack_webhook_url' => env('BANKAI_SLACK_WEBHOOK_URL'),
        // How many releases to keep on the server (the live one included)
        'releases_to_keep'  => 3,
    ],

    // Define your environments, e.g. staging and production
    'environments' => [
        'staging' => [
            'strategy'         => 'artifact',        // 'artifact' or 'clone', see below
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

`bankai:install` generates this file for you. The Laravel bootstrap is handled by
`AxaZara\Bankai\Bankai::bootstrap()`, so your Envoy script stays to the point:

```blade
@include('vendor/autoload.php')

@setup
    try {
        extract(AxaZara\Bankai\Bankai::bootstrap($env));
    } catch (Throwable $e) {
        echo $e->getMessage();
        exit(1);
    }
@endsetup

@import('vendor/axazara/bankai/src/Envoy.blade.php')

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

## Deployment strategies

Bankai supports two ways of getting a release onto the server, selected per
environment with the `strategy` key.

### `artifact` (recommended)

The machine running Envoy (CI or your laptop) builds the release and uploads a
tarball; the server only extracts it. The server needs **no Git access, no
Composer authentication and no GitHub or registry credentials**, and what was
tested in CI is exactly what ships.

Everything happens inside one command:

```shell
vendor/bin/envoy run deploy --env=production
```

Right before extraction, Bankai copies the project to a temporary build
directory (excluding `.git`, `.github`, `node_modules`, `tests`, `storage`,
`.env*`, `auth.json` and the local `vendor`), runs
`composer install --no-dev --optimize-autoloader`, builds front-end assets when
a `package.json` with a `build` script is present, packs the tarball, uploads
it to `{path}/artifacts/incoming.tar.gz` over SSH, and the server consumes
(deletes) it after extraction. Your working directory is never modified.

`php artisan bankai:artifact` is also available to package the working
directory as-is (same exclusions), for inspection or manual distribution.

### `clone` (default)

The historical flow: the server clones the repository and runs
`composer install` itself. Requires:

- an SSH `repository_url` with a **read-only deploy key** installed on the server;
- a `shared/auth.json` on the server when private Composer packages are used.

For a temporary token-based clone (for example a GitHub App installation token
minted in CI), set the `BANKAI_REPOSITORY_URL` environment variable on the
machine running Envoy; it takes precedence over the configured URL and is never
stored on the server.

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

**Clone strategy only.** With the `artifact` strategy the server never runs
Composer, so no credentials are needed there — skip this step entirely.

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

## Laravel Octane

Enabling `octane.reload` does **not** run `octane:reload`. Reloading only recycles
the workers of the running master, and that master is booted from the release
directory it was started in (`base_path()` is resolved from `__FILE__`, after the
symlink has been dereferenced). It would keep serving the old code forever.

Bankai stops the master instead and lets your process manager respawn it, so the
new process re-resolves `current` and picks up the new release. This requires a
correctly configured process manager, otherwise the application simply stays down
until the health check fails.

**Supervisor program (Octane, Horizon and queue workers alike):**

```ini
[program:app-octane]
command=php /var/www/your-app/current/artisan octane:start --server=swoole --host=127.0.0.1 --port=8000
directory=/var/www/your-app/current   ; never point this at a release directory
autostart=true
autorestart=true                      ; not 'unexpected': a clean octane:stop exits 0
user=your-user
```

With `autorestart=unexpected` and `exitcodes=0`, Supervisor treats the clean exit
of `octane:stop` as normal and never restarts the process.

Two more constraints:

- **`storage/` must stay shared.** Octane writes its server state file under
  `storage/logs`, which is how a command run from the new release can stop the
  master started by the old one. Un-sharing `storage/` breaks this silently.
- **`artisan down` does not cover the restart window.** Once the master is dead,
  Nginx gets a connection refused and returns a raw 502, not Laravel's maintenance
  page. If you want a clean page, serve it from the web server:

  ```nginx
  error_page 502 503 504 /maintenance.html;
  location = /maintenance.html {
      root /var/www/your-app/shared/public;
      internal;
  }
  ```

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

Quickly revert to the previous release (atomic symlink switch, no maintenance window):

```shell
vendor/bin/envoy run deploy:rollback --env={your-environment}
```

The rollback is also the recovery path for a deploy that died halfway through: it
restarts the queue workers, Horizon and Octane on the restored release, lifts the
maintenance mode a failed deploy may have left behind, and health checks the
result before reporting success.

## Deployment lock

At the start of every deploy, Bankai atomically creates a lock directory on the
server (`{path}/.bankai-deploy.lock`). If the lock already exists, the deploy
aborts immediately with the timestamp of the run holding it. This guarantees two
deployments can never interleave: without it, two concurrent runs would race on
`incoming.tar.gz`, migrations and the `current` symlink.

The lock is released at the end of a successful deploy. It is deliberately
**not** released on failure, because a half-finished deploy left the server in
a state that deserves a human look. Once you have checked (and rolled back if
needed), release it with:

```shell
vendor/bin/envoy run deploy:unlock --env={your-environment}
```

## Additional commands

- Build a release artifact: `php artisan bankai:artifact`
- List releases (the live one is marked): `vendor/bin/envoy run releases --env={your-environment}`
- List backups: `vendor/bin/envoy run backups --env={your-environment}`

## Zero-downtime deployment mechanics

1. **New release preparation**: Bankai creates a new release in the `releases/` directory (cloned or extracted from the artifact).
2. **Cache warm-up**: The new release is optimised before going live; the live release's caches and the shared application cache store are never touched.
3. **Symlink switching**: The `current` symlink is switched atomically to the new release (`ln + rename`).
4. **Health check**: The application URL is polled (5 attempts) while the previous release is still on disk, so a failing release can be rolled back instantly.
5. **Cleanup**: Old releases are pruned, keeping the live release and the most recent others (`releases_to_keep`, default 3).

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
