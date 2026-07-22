@setup
    $deploymentSuccess = "
        *Deployment completed on $appName*
        - App name: $appName
        - Environment: $env
        - Strategy: $strategy
        - URL: $appUrl
        - Deployment path: $path
        - Current release: $release
        - Branch: $branch
        - SSH host: $sshHost
        - Date: $date
    ";

    $rollbackSuccessMessage = "*$appName* has been rolled back to the previous release.";

    // Slack payloads are JSON-encoded and shell-quoted up front so app names or
    // messages containing quotes can never break the curl command or the JSON body.
    $deploymentSuccessPayload = escapeshellarg(json_encode(['text' => $deploymentSuccess]));
    $rollbackSuccessPayload = escapeshellarg(json_encode(['text' => $rollbackSuccessMessage]));
@endsetup

@servers([$env => "{$sshUser}@{$sshHost}"])

@before
    // Artifact strategy: build the release on this machine and upload it right
    // before the server extracts it, so `envoy run deploy` is self-contained.
    if ($task === 'make:extract_artifact' && $strategy === 'artifact') {
        try {
            AxaZara\Bankai\ArtifactBuilder::buildAndUpload(getcwd(), $sshUser, $sshHost, $artifactPath);
        } catch (Throwable $e) {
            echo 'Artifact build failed: ' . $e->getMessage() . "\n";
            exit(1);
        }
    }
@endbefore

@story('setup' , ['on' => $env])
    setup:directories
    make:clone_repository
    make:extract_artifact
    make:link_composer_auth
    make:install_composer_dependencies
    setup:common_files
    make:symlinks
    setup:generate_app_key
    make:link_current_release
    setup:finish
@endstory

@story('deploy' , ['on' => $env])
    display_info
    check_if_release_exists
    deploy:acquire_lock
    run:before_deploy
    make:clone_repository
    make:extract_artifact
    make:link_composer_auth
    make:install_composer_dependencies
    make:build_assets
    make:symlinks
    make:app_down
    make:migration
    make:db_seed
    make:install_octane
    make:cache
    run:after_deploy
    make:link_current_release
    make:app_up
    make:restart_queue
    make:horizon:terminate
    make:reload_octane
    make:check_app_health
    make:clean_old_release
    deploy:release_lock
    sentry:release
    deploy:complete
@endstory

@story('deploy:rollback', ['on' => $env])
    make:rollback
    run:after_rollback
    rollback:complete
@endstory

@story('deploy:unlock', ['on' => $env])
    deploy:force_unlock
@endstory

@story('backups')
    backups:list
@endstory

@story('releases')
    releases:list
@endstory

@task('display_info')
    echo "Deployment information:";
    echo "- Strategy: {{ $strategy }}";
    echo "- Deployment path: {{ $releasePath }}";
    echo "- Current release: {{ $release }}";
    echo "- Releases path: {{ $releasesPath }}";
    echo "- Shared path: {{ $sharedPath }}";
    @if ($strategy === 'clone')
        echo "- Repository: {{ $repositoryUrl }}";
        echo "- Branch: {{ $branch }}";
    @else
        echo "- Artifact: {{ $artifactPath }}";
    @endif
    echo "- URL: {{ $appUrl }}";
    echo "- Environment: {{ $env }}";
    echo "- Releases kept: {{ $releasesToKeep }}";
@endtask

@task('setup:directories')
   set -euo pipefail

   # Refuse to run twice: an existing release means the server is already set up.
   if [ -d "{{ $releasesPath }}" ] && [ -n "$(ls -A "{{ $releasesPath }}" 2>/dev/null)" ]; then
       echo "Releases already exist on this server. Run 'envoy run deploy' to deploy your application."
       echo "If you think this is an error, clean up the deployment folder manually and try again."
       exit 1
   fi

   echo "Creating deployment directories"

   mkdir -p "{{ $releasesPath }}"
   mkdir -p "{{ $sharedPath }}"
   mkdir -p "{{ $backupPath }}"
   mkdir -p "{{ $artifactsPath }}"

   echo "Deployment directories created"
@endtask

@task('deploy:acquire_lock')
   mkdir -p "{{ $artifactsPath }}"

   # mkdir is atomic: it either creates the lock or fails because one exists.
   if mkdir "{{ $deployLockPath }}" 2>/dev/null; then
       date '+%Y-%m-%d %H:%M:%S' > "{{ $deployLockPath }}/started_at"
       echo "Deployment lock acquired"
   else
       echo "Another deployment appears to be in progress since $(cat "{{ $deployLockPath }}/started_at" 2>/dev/null || echo 'unknown')."
       echo "If that deployment crashed, release the lock with: envoy run deploy:unlock --env={{ $env }}"
       exit 1
   fi
@endtask

@task('deploy:release_lock')
   rm -rf "{{ $deployLockPath }}"
   echo "Deployment lock released"
@endtask

@task('deploy:force_unlock')
   if [ -d "{{ $deployLockPath }}" ]; then
       rm -rf "{{ $deployLockPath }}"
       echo "Deployment lock forcibly released"
   else
       echo "No deployment lock found, nothing to do"
   fi
@endtask

@task('make:clone_repository')
   @if ($strategy === 'clone')
       set -euo pipefail

       echo "Cloning repository ({{ $branch }})"

       cd "{{ $releasesPath }}"
       git clone "{{ $repositoryUrl }}" --branch="{{ $branch }}" --depth=1 --single-branch -q "{{ $release }}"

       echo "Repository cloned"
   @else
       echo "Repository clone skipped (artifact strategy)"
   @endif
@endtask

@task('make:extract_artifact')
   @if ($strategy === 'artifact')
       set -euo pipefail

       if [ ! -s "{{ $artifactPath }}" ]; then
           echo "No artifact found at {{ $artifactPath }}; the build-and-upload step did not run or failed."
           exit 1
       fi

       echo "Extracting artifact into the new release"

       mkdir -p "{{ $releasePath }}"
       tar -xzf "{{ $artifactPath }}" -C "{{ $releasePath }}"
       rm -f "{{ $artifactPath }}"

       echo "Artifact extracted and consumed"
   @else
       echo "Artifact extraction skipped (clone strategy)"
   @endif
@endtask

@task('make:link_composer_auth')
   @if ($strategy === 'clone')
       # Symlink a shared auth.json (if present) so Composer can authenticate against
       # private registries and repositories during installation.
       if [ -f "{{ $sharedPath }}/auth.json" ]; then
           ln -sf "{{ $sharedPath }}/auth.json" "{{ $releasePath }}/auth.json"
           echo "Composer auth.json linked from the shared directory"
       else
           echo "No shared auth.json found, skipping Composer authentication setup"
       fi
   @else
       echo "Composer auth skipped (artifact ships its vendor directory)"
   @endif
@endtask

@task('make:install_composer_dependencies')
   @if ($strategy === 'clone')
       set -euo pipefail

       echo "Installing Composer dependencies"

       cd "{{ $releasePath }}"
       {{ $composer }} install {{ $composerOptions }} --no-progress

       echo "Composer dependencies installed"
   @else
       echo "Composer install skipped (artifact ships its vendor directory)"
   @endif
@endtask

@task('make:build_assets')
   @if ($strategy === 'clone')
       set -euo pipefail

       cd "{{ $releasePath }}"

       if [ ! -f package.json ]; then
           echo "Asset build skipped, no package.json found"
       else
           echo "Installing front-end dependencies"

           if [ -f yarn.lock ]; then
               yarn install --immutable
           elif [ -f package-lock.json ]; then
               npm ci
           else
               npm install
           fi

           if grep -q '"build"' package.json; then
               echo "Building assets"

               if [ -f yarn.lock ]; then
                   yarn build
               else
                   npm run build
               fi

               echo "Assets built"
           else
               echo "No build script defined, skipping asset build"
           fi
       fi
   @else
       echo "Asset build skipped (artifact ships built assets)"
   @endif
@endtask

@task('setup:common_files')
   set -euo pipefail

   echo "Copying common files"

   if [ -d "{{ $sharedPath }}/storage" ]; then
       echo "Shared storage already exists, left untouched"
       rm -rf "{{ $releasePath }}/storage"
   elif [ -d "{{ $releasePath }}/storage" ]; then
       mv "{{ $releasePath }}/storage" "{{ $sharedPath }}/storage"
       echo "Storage moved to the shared directory"
   else
       mkdir -p "{{ $sharedPath }}/storage"
       echo "Shared storage directory created"
   fi

   if [ -f "{{ $sharedPath }}/.env" ]; then
       echo "Shared .env already exists, left untouched"
   elif [ -f "{{ $releasePath }}/.env.example" ]; then
       cp "{{ $releasePath }}/.env.example" "{{ $sharedPath }}/.env"
       echo "Shared .env created from .env.example"
   else
       touch "{{ $sharedPath }}/.env"
       echo "Empty shared .env created; fill it in before deploying"
   fi
@endtask

@task('make:symlinks')
   set -euo pipefail

   echo "Creating symlinks"

   # Remove the release storage directory and .env files before linking the shared ones.
   rm -rf "{{ $releasePath }}/storage"
   rm -f "{{ $releasePath }}/.env" "{{ $releasePath }}/.env.example"

   ln -s "{{ $sharedPath }}/storage" "{{ $releasePath }}/storage"
   ln -s "{{ $sharedPath }}/.env" "{{ $releasePath }}/.env"

   cd "{{ $releasePath }}"
   {{ $php }} artisan storage:link

   echo "Release '.env' and 'storage' have been symlinked"
@endtask

@task('setup:generate_app_key')
   set -euo pipefail

   if grep -qE '^APP_KEY=.+' "{{ $sharedPath }}/.env"; then
       echo "APP_KEY already set, left untouched"
   else
       echo "Generating app key"

       cd "{{ $releasePath }}"
       {{ $php }} artisan key:generate
   fi
@endtask

@task('make:link_current_release')
   set -euo pipefail

   echo "Switching the current release"

   if [ -e "{{ $currentReleasePath }}" ] && [ ! -L "{{ $currentReleasePath }}" ]; then
       echo "{{ $currentReleasePath }} exists and is not a symlink; refusing to replace it."
       exit 1
   fi

   # Atomic switch: build the symlink aside, then rename over the live one.
   ln -sfn "{{ $releasePath }}" "{{ $currentReleasePath }}.tmp"
   mv -Tf "{{ $currentReleasePath }}.tmp" "{{ $currentReleasePath }}"

   if [ "$(readlink "{{ $currentReleasePath }}")" != "{{ $releasePath }}" ]; then
       echo "Current symlink could not be switched"
       exit 1
   fi

   echo "Current release switched to {{ $release }}"
@endtask

@task('setup:finish')
   @if($octaneReload === true)
       echo "You have enabled Octane: make sure the Octane package is installed and configured properly.";
   @endif

   echo "Deployment path initialized. Edit your .env file in {{ $sharedPath }}/.env and run 'envoy run deploy' to deploy your application.";
@endtask

@task('run:before_deploy')
   true
@endtask

@task('run:after_deploy')
   true
@endtask

@task('run:after_rollback')
   true
@endtask

@task('check_if_release_exists')
   if [ ! -d "{{ $releasesPath }}" ]; then
       echo "Deploy directory does not exist on the server. Run 'envoy run setup' to set up your deployment directory."
       exit 1
   fi
@endtask

@task('make:app_down')
   @if ($maintenance === true)
       {{ $php }} "{{ $currentReleasePath }}/artisan" down || true
       echo "App is in maintenance mode"
   @else
       echo "Application is not in maintenance mode"
   @endif
@endtask

@task('make:migration', ['on' => $env])
   @if ($migration === true)
       set -euo pipefail

       echo "Running migrations"

       cd "{{ $releasePath }}"
       {{ $php }} artisan migrate --force

       echo "Migrations complete"
   @else
       echo "Database migrations skipped"
   @endif
@endtask

@task('make:db_seed', ['on' => $env])
   @if ($seeder === true)
       set -euo pipefail

       echo "Running seeders"

       cd "{{ $releasePath }}"
       {{ $php }} artisan db:seed --force

       echo "Database seeding complete"
   @else
       echo "Database seeding skipped"
   @endif
@endtask

@task('make:cache', ['on' => $env])
   set -euo pipefail

   # Only the new release is touched. The live release keeps its caches until the
   # symlink switch, and the shared application cache store is never cleared here.
   cd "{{ $releasePath }}"
   {{ $php }} artisan optimize

   echo "New release caches warmed (config, events, routes, views)"
@endtask

@task('make:install_octane', ['on' => $env])
   @if ($octaneInstall === true)
       set -euo pipefail

       cd "{{ $releasePath }}"
       {{ $php }} artisan octane:install --server={{ $octaneServer }} --no-interaction

       echo "Octane installed"
   @else
       echo "Octane install skipped"
   @endif
@endtask

@task('make:app_up', ['on' => $env])
   {{ $php }} "{{ $currentReleasePath }}/artisan" up
   echo "App is up"
@endtask

@task('make:restart_queue', ['on' => $env])
   @if ($queueRestart === true)
       cd "{{ $currentReleasePath }}"
       {{ $php }} artisan queue:restart
       echo "Queue restarted"
   @else
       echo "Queue restart skipped"
   @endif
@endtask

@task('make:horizon:terminate', ['on' => $env])
   @if ($horizonTerminate === true)
       cd "{{ $currentReleasePath }}"
       {{ $php }} artisan horizon:terminate
       echo "Horizon terminated, it should restart automatically"
   @else
       echo "Horizon restart skipped"
   @endif
@endtask

@task('make:reload_octane', ['on' => $env])
   cd "{{ $currentReleasePath }}"

   @if ($octaneReload === true)
       if [ $( {{ $php }} artisan octane:status --no-interaction 2>&1 | grep -c 'server is running' ) -gt 0 ]; then
           echo "Octane is running. Stopping it in the old release; supervisor will restart it in the new release."
           {{ $php }} artisan octane:stop --no-interaction
           echo "Octane stopped in the old release"
       else
           echo "Octane is not running, skipping restart."
       fi
   @else
       echo "Octane restart skipped";
   @endif
@endtask

@task('make:clean_old_release', ['on' => $env])
   set -euo pipefail

   cd "{{ $releasesPath }}"

   CURRENT_TARGET=$(basename "$(readlink "{{ $currentReleasePath }}")")

   # Keep the live release plus the most recent others, prune the rest by age.
   (ls -1t | grep -vFx "$CURRENT_TARGET" || true) | tail -n +{{ $releasesToKeep }} | while read -r OLD_RELEASE; do
       echo "Deleting old release $OLD_RELEASE"
       rm -rf "./$OLD_RELEASE"
   done

   echo "Old releases pruned; keeping the current release and the {{ $releasesToKeep - 1 }} most recent others"
@endtask

@task('make:check_app_health', ['on' => $env])
   ATTEMPTS=5

   for i in $(seq 1 $ATTEMPTS); do
       STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "{{ $appUrl }}" || echo "000")

       if [ "$STATUS" = "200" ]; then
           echo "Health check passed (HTTP $STATUS)"
           exit 0
       fi

       echo "Health check attempt $i/$ATTEMPTS returned HTTP $STATUS, retrying in 3s"
       sleep 3
   done

   echo "App is unhealthy after $ATTEMPTS attempts. The previous release is still on disk:"
   echo "  envoy run deploy:rollback --env={{ $env }}"
   exit 1
@endtask

@task('sentry:release', ['on' => $env])
   @if ((bool) $sentryEnabled === true)
       # Best effort: the release is already live, a Sentry hiccup must not fail the deploy.
       SENTRY_CLI="{{ $sharedPath }}/bin/sentry-cli"

       if [ ! -x "$SENTRY_CLI" ]; then
           echo "Installing sentry-cli"
           mkdir -p "{{ $sharedPath }}/bin"
           curl -sL https://github.com/getsentry/sentry-cli/releases/download/2.23.0/sentry-cli-Linux-x86_64 -o "$SENTRY_CLI" && chmod +x "$SENTRY_CLI" || echo "sentry-cli installation failed (non-fatal)"
       fi

       if [ -x "$SENTRY_CLI" ]; then
           export SENTRY_AUTH_TOKEN="{{ $sentryToken }}"
           export SENTRY_ORG="{{ $sentryOrg }}"
           export SENTRY_PROJECT="{{ $sentryProject }}"

           "$SENTRY_CLI" releases new "{{ $sentryVersion }}" && \
           "$SENTRY_CLI" releases deploys "{{ $sentryVersion }}" new -e "{{ $env }}" && \
           "$SENTRY_CLI" releases finalize "{{ $sentryVersion }}" && \
           echo "Sentry release recorded" || echo "Sentry release failed (non-fatal)"
       fi

       true
   @else
       echo "Sentry release skipped"
   @endif
@endtask

@task('deploy:complete')
   @if (! empty($slackWebhookUrl))
       curl -sf -X POST -H 'Content-type: application/json' --data {{ $deploymentSuccessPayload }} "{{ $slackWebhookUrl }}" > /dev/null 2>&1 || true
   @endif

   echo "Deployment complete, live at {{ $appUrl }}"
@endtask

@task('make:rollback', ['on' => $env])
   set -euo pipefail

   echo "Starting rollback process on the {{ $env }} environment"

   if [ ! -L "{{ $currentReleasePath }}" ]; then
       echo "No current release symlink found, nothing to roll back from"
       exit 1
   fi

   CURRENT_TARGET=$(basename "$(readlink "{{ $currentReleasePath }}")")

   cd "{{ $releasesPath }}"
   PREV_RELEASE=$( (ls -1t | grep -vFx "$CURRENT_TARGET" || true) | head -1 )

   if [ -z "$PREV_RELEASE" ]; then
       echo "No previous release found to roll back to"
       exit 1
   fi

   echo "Rolling back from $CURRENT_TARGET to $PREV_RELEASE"

   # Atomic switch, no maintenance window needed.
   ln -sfn "{{ $releasesPath }}/$PREV_RELEASE" "{{ $currentReleasePath }}.tmp"
   mv -Tf "{{ $currentReleasePath }}.tmp" "{{ $currentReleasePath }}"

   echo "Rolled back to previous release: $PREV_RELEASE"

   @if($queueRestart === true)
       cd "{{ $currentReleasePath }}"
       {{ $php }} artisan queue:restart
       echo "Queue worker restarted"
   @endif

   @if($horizonTerminate === true)
       cd "{{ $currentReleasePath }}"
       {{ $php }} artisan horizon:terminate
       echo "Laravel Horizon terminated"
   @endif

   @if($octaneReload === true)
       cd "{{ $currentReleasePath }}"
       {{ $php }} artisan octane:reload || true
       echo "Laravel Octane reloaded"
   @endif
@endtask

@task('rollback:complete')
   @if (! empty($slackWebhookUrl))
       curl -sf -X POST -H 'Content-type: application/json' --data {{ $rollbackSuccessPayload }} "{{ $slackWebhookUrl }}" > /dev/null 2>&1 || true
   @endif

   echo "Rollback complete, live at {{ $appUrl }}"
@endtask

@task('backups:list')
   for dir in "{{ $backupPath }}"/*; do
       [ -e "$dir" ] || continue
       echo "$(basename "$dir") | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)"
   done
@endtask

@task('releases:list')
   CURRENT_TARGET=$(basename "$(readlink "{{ $currentReleasePath }}" 2>/dev/null || echo '')")

   for dir in "{{ $releasesPath }}"/*; do
       [ -e "$dir" ] || continue

       NAME=$(basename "$dir")
       MARKER=""

       if [ "$NAME" = "$CURRENT_TARGET" ]; then
           MARKER=" (current)"
       fi

       echo "$NAME | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)$MARKER"
   done
@endtask

@success
   echo "Envoy task has been completed";
@endsuccess

@error
   // Task bodies are captured at parse time, so per-story "set message" tasks can
   // never work: the failure message must be built here, where $task is known.
   $failureMessage = "*Task '$task' failed on $appName*
       - Environment: $env
       - Strategy: $strategy
       - Deployment path: $path
       - SSH host: $sshHost
       - Date: $date
       If the deployment lock was left behind, release it with 'envoy run deploy:unlock --env=$env'.";

   @slack($slackWebhookUrl, '', $failureMessage)

   echo "Envoy task has failed";
@enderror
