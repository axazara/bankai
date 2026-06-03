# CLAUDE.md

Guidance for Claude Code and other AI agents working in this repository.

## Project overview

Bankai is a Laravel Composer package (installed as a `--dev` dependency) that provides zero-downtime deployments for Laravel applications via [Laravel Envoy](https://laravel.com/docs/envoy). It ships an Artisan install command, a configuration file, and a reusable `Envoy.blade.php` task library that host projects include in their own Envoy scripts. The package supports deployment, rollback, Slack notifications, and optional Sentry release tracking.

## Tech stack

- PHP ^8.1
- Laravel Envoy ^2.0 (the only runtime dependency)
- Laravel 10.x / 11.x / 12.x / 13.x (host application; tested via Orchestra Testbench ^8 – ^11)
- PHPUnit ^10.5 / ^11.0 (testing)
- Larastan / PHPStan level 4 (static analysis)
- `axazara/php-cs` (^0.3) PHP CS Fixer rules for code style

## Getting started

```bash
composer install

# Copy and adjust phpstan config if needed
# No .env file is required for the package itself
```

To use Bankai inside a host Laravel application:

```bash
composer require axazara/bankai --dev
php artisan bankai:install   # publishes config/bankai.php and Envoy.blade.php
```

## Common commands

| Task | Command |
|---|---|
| Run tests | `composer test` |
| Static analysis | `composer analyse` |
| Check code style (dry-run) | `composer sniff` |
| Fix code style | `composer format` |

To test against a specific Laravel line, constrain Testbench, e.g.:
`composer update -W --with="orchestra/testbench:^11"` (Laravel 13).

## Architecture

```
src/
  Bankai.php                            # bootstrap($env): boots Laravel and returns the deploy variables
  DeploymentConfig.php                  # Builds + validates the deploy variables for a given env
  Traits/ConfigValidationTrait.php      # Shared config validation helpers
  Slack.php                             # Slack webhook notification helper
  Providers/BankaiServiceProvider.php   # Registers the command; publishes config
  Console/BankaiInstall.php             # `php artisan bankai:install` command
  Console/stubs/Envoy.blade.php.stub    # Stub copied to the host project's Envoy.blade.php
  Envoy.blade.php                       # Core Envoy tasks: setup, deploy, deploy:rollback, releases, backups
config/bankai.php                       # Default config published to the host app
tests/                                  # PHPUnit + Orchestra Testbench suite
```

The deployment flow is entirely Envoy-driven: `setup` provisions the releases/shared/backups/current directories on the remote server; `deploy` clones the repo into a new timestamped release, optionally links `shared/auth.json` for Composer auth, links the shared `.env`, runs Composer, optional migrations/seeders, switches the `current` symlink, then restarts Horizon/queues/Octane as configured; `deploy:rollback` reinstates the previous symlink. Host projects keep their `Envoy.blade.php` to a single bootstrap line via `AxaZara\Bankai\Bankai::bootstrap($env)`.

## Conventions

- Code style is enforced by `axazara/php-cs` (PHP CS Fixer rules); run `composer sniff` to check and `composer format` to auto-fix before committing.
- Static analysis runs at PHPStan level 4 with Larastan; run `composer analyse` and fix all errors before opening a PR.
- Tests live under `tests/` and use PHPUnit with Orchestra Testbench. The `Tests` workflow runs them across PHP 8.1–8.4 and Laravel 10–13.
- The package registers itself via Laravel's package auto-discovery (`extra.laravel.providers`); no manual registration is needed by consumers.
- Supports multiple named deployment environments defined in `config/bankai.php`; environment name is passed at runtime via `--env={name}`.
- All shell interpolations of config values in `Envoy.blade.php` should be quoted (e.g. `"{{ $repositoryUrl }}"`) to avoid word-splitting.

## Release flow

The repository follows a `develop` → `main` branching model with automated releases:

- **`develop`** — integration branch; feature/fix branches are merged here first.
- **`main`** — stable branch. Merging `develop` into `main` is what ships a release.
- **Releases are automated with [release-please](https://github.com/googleapis/release-please).** On every push to `main`, the `Release` workflow opens (or updates) a release PR that bumps the version from the Conventional Commit history (`feat` → minor, `fix` → patch, `feat!`/`BREAKING CHANGE` → major) and updates `CHANGELOG.md`. Merging that release PR creates the `v*` tag and the GitHub release.
- Version state lives in `.release-please-manifest.json`; configuration in `release-please-config.json`.
- Because versions are derived from commit history, **Conventional Commit messages are required** (already enforced by the `commit_message_pattern` ruleset).

## Git Conventions

### 1. Branch names

Enforced regex (`branch_name_pattern`):
```
^(feature|fix|hotfix|chore|docs|refactor|test|ci|perf|build|style)/[a-z0-9._-]+$
```

- Lowercase only, kebab-case after the prefix, **max 50 characters** total.
- Use the full word `feature/` — **never** `feat/` (the short `feat` form is only for commit message types).
- Include the ticket id when relevant: `feature/AXA-123-add-stripe` (the ticket id is lowercased to satisfy the pattern — e.g. `feature/axa-123-add-stripe`).
- **Never** use a `claude/` prefix or any prefix outside the allowed set.
- `main`, `release`, `staging` are permanent protected branches — never push to them directly.
- If a branch is misnamed, rename it before pushing: `git branch -m <old> <new>`.

### 2. Commit messages
Enforced regex (`commit_message_pattern`), applied to **every** commit:
```
^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([^)]+\))?!?: .+
```
- Lowercase type, optional scope in parens, optional `!` for breaking changes, subject after `: `.
- Subject starts with a lowercase letter and has no trailing period.
- Examples: `feat(checkout): add Apple Pay support`, `fix(api): handle expired tokens`, `chore(deps): bump axios from 1.7.2 to 1.15.2`, `refactor!: drop Node 18 support`.
- Do not rewrite Dependabot commits — `chore(deps): bump X from a to b` is already enforced via `.github/dependabot.yml`.

### 3. Files that are always rejected
Never stage or commit:
- `.env`, `.env.*` (only `.env.example` and `.env.sample` are allowed), `**/.env`, `**/.env.*`
- Private keys: `**/id_rsa{,.pub}`, `**/id_dsa`, `**/id_ecdsa`, `**/id_ed25519`, `**/.ssh/id_*`
- Credentials: `**/.aws/credentials`, `**/credentials.json`, `**/service-account.json`, `**/firebase-adminsdk-*.json`, `**/secrets.{yml,yaml}`
- Extensions: `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`, `*.ppk`, `*.asc`, `*.gpg`
- Any file larger than 100 MB (use git LFS)
If a secret is needed, use `.env.example` for env vars and an external secret manager for credentials.

### Pull requests targeting `main`, `release`, `staging`
All three are protected — a PR is required (direct push blocked):
- 1 approval, all conversations resolved, **squash or rebase merge only** (linear history enforced — no merge commits).
- Commits must be GPG- or SSH-signed. Signing is required for `main` (`required-signatures-main` ruleset).
- The PR **title** becomes the squash commit message and must match the commit-message regex above (enforced on all three branches).

**Required workflows run on PRs whose base is `main` only** (not `release`/`staging`): `Branch naming convention`, `PR title — Conventional Commits`, and `PR size labeler`.
If a check shows `Waiting for workflow to run` for over a minute, the third-party action is likely missing from the enterprise allowlist.

When the branch-naming or PR-title check fails, the baseline bot auto-posts rename/title suggestions, following the enforced regex patterns.
If the bot's suggestions are incorrect, edit the PR title or branch name to match the required format.

### Pre-push checklist
Before running `git push`:
1. Branch name matches the regex.
2. Every commit in `origin/main..HEAD` matches the commit pattern (`git log --format=%s origin/main..HEAD`).
3. No staged file is in the blocked paths/extensions list.
4. Commits are signed if the target is `main`.

If any check fails, fix it locally rather than letting the server reject the push.
