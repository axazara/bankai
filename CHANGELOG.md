# Changelog

All notable changes to `axazara/bankai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.3](https://github.com/axazara/bankai/compare/v2.0.2...v2.0.3) (2026-08-02)


### Bug Fixes

* **config:** name the config block a validation failure came from ([#30](https://github.com/axazara/bankai/issues/30)) ([74227f4](https://github.com/axazara/bankai/commit/74227f422af9044d8b50a63a6a799371e8bbb847))
* **octane:** stop the master on rollback and fix the deploy ordering ([#29](https://github.com/axazara/bankai/issues/29)) ([05ca5aa](https://github.com/axazara/bankai/commit/05ca5aaa932df9af95843c550c5ce1bc42f2fd49))

## [2.0.2](https://github.com/axazara/bankai/compare/v2.0.1...v2.0.2) (2026-07-26)


### Bug Fixes

* **artifact:** honour the environment's composer_options during the build ([#27](https://github.com/axazara/bankai/issues/27)) ([3fd8d8a](https://github.com/axazara/bankai/commit/3fd8d8af17d6b65efe87e666c60f8f2fffe5fae2))

## [2.0.1](https://github.com/axazara/bankai/compare/v2.0.0...v2.0.1) (2026-07-24)


### Bug Fixes

* **artifact:** copy auth.json into the build directory so composer can authenticate, exclude it from the tarball ([#25](https://github.com/axazara/bankai/issues/25)) ([bd1f10e](https://github.com/axazara/bankai/commit/bd1f10e0656f02538cb083a1761afcfede51659d))

## [2.0.0](https://github.com/axazara/bankai/compare/v1.0.1...v2.0.0) (2026-07-22)


### ⚠ BREAKING CHANGES

* **deploy:** repository_url with embedded credentials is now rejected; make:npm_install was replaced by make:build_assets (which also runs the build script); make:cache no longer clears caches in the live release.

### Features

* **deploy:** add artifact deployment strategy, locking, and harden the Envoy pipeline ([#23](https://github.com/axazara/bankai/issues/23)) ([e269c6e](https://github.com/axazara/bankai/commit/e269c6e47c1bf96bf01b7d13eebb4eeef6b98def))

## [1.0.1](https://github.com/axazara/bankai/compare/v1.0.0...v1.0.1) (2026-06-07)


### Bug Fixes

* **envoy:** write [@error](https://github.com/error) handler body as raw PHP to avoid nested tag ([#16](https://github.com/axazara/bankai/issues/16)) ([684826f](https://github.com/axazara/bankai/commit/684826f08f9175e44c5dbee15920d6d7417585ba))

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
