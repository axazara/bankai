# Changelog

All notable changes to `axazara/bankai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0](https://github.com/axazara/bankai/compare/v1.0.0...v1.1.0) (2026-06-03)


### Features

* add composer auth and before-deploy hook, fix config and Envoy template ([#12](https://github.com/axazara/bankai/issues/12)) ([ab20387](https://github.com/axazara/bankai/commit/ab203874ceafb7d7d061af56549249afef66e156))

## [Unreleased]

## [1.0.0] - 2026-06-03

First published release. Earlier history was never tagged.

### Added

- Support for Laravel 12 and 13, in addition to 10 and 11.
- A `run:before_deploy` lifecycle hook, executed before the new release is cloned.
- Composer authentication support: a shared `auth.json` is symlinked into each release before `composer install`, during both `setup` and `deploy`.
- `AxaZara\Bankai\Bankai::bootstrap()`, which reduces the project's `Envoy.blade.php` setup block to a single line instead of the full framework bootstrap boilerplate.
- A test suite and a `Tests` CI workflow running it across PHP 8.1-8.4 and Laravel 10-13.
- A `composer test` script.

### Changed

- Aligned the published configuration keys with the code: `settings.repository_url` and `settings.slack_webhook_url` (previously `repository` / `slack_webhook`). This fixes configuration validation failing on a fresh install.
- Rewrote the Envoy template for clarity: consistent use of the shared path variables, plain-text output (removed decorative emoji), and corrected typos (`Deloyment`, `rollack`).
- Consolidated the two Slack notification helpers into a single `Slack` class that no-ops on an empty webhook.
- Broadened the development dependencies (Testbench, Larastan, Collision, PHPUnit) so Composer resolves against current Laravel releases instead of EOL `laravel/framework` 9.x-dev.
- `bankai:install` now resolves the Envoy file via `base_path()` and ships it from a `.stub` template; the published Envoy script uses the new `Bankai::bootstrap()` helper.
- Quoted the repository URL and Sentry secrets in the shell tasks to avoid word-splitting.

### Fixed

- Configuration validation now raises a clear error for an unknown deployment environment instead of a type error.
- `DeploymentConfig` exposes a `date` variable used by the notification messages.
- Corrected undefined variables in the Envoy template (`$sshHost`, `$currentReleasePath`, `$date`).
- Removed the dead `octaneIsRunning()` helper, the duplicate `make:run_migrations` and unused `make:clear_cache` / `deploy:durations` tasks.
- Standardised the backups directory name to `backups`.
- Removed a duplicate `frankenphp` entry from the Octane server validation rule.

### Removed

- The duplicate `SlackNotification` class (superseded by `Slack`).
- The `insolita/unused-scanner` dev dependency and the `composer unused` script: it capped `symfony/finder` at 6.x, which blocked Laravel 12 and 13.
