@setup
    $deploymentSuccess = "
        *Deployment completed on $appName*
        - App name: $appName
        - Environment: $env
        - URL: $appUrl
        - Deployment path: $path
        - Current release: $release
        - Repository: $repositoryUrl
        - Branch: $branch
        - SSH host: $sshHost
        - Date: $date
    ";

    $rollbackSuccessMessage = "*$appName* has been rolled back to the previous release.";

    $failureMessage = null;
@endsetup

@servers([$env => "{$sshUser}@{$sshHost}"])

@story('setup' , ['on' => $env])
    set:setup_failure_message
    setup:directories
    make:clone_repository
    make:link_composer_auth
    make:install_composer_dependencies
    setup:common_files
    make:symlinks
    setup:generate_app_key
    make:link_current_release
    setup:finish
@endstory

@story('deploy' , ['on' => $env])
    set:deploy_failure_message
    display_info
    check_if_release_exists
    run:before_deploy
    make:clone_repository
    make:link_composer_auth
    make:install_composer_dependencies
    make:npm_install
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
    make:clean_old_release
    make:check_app_health
    sentry:release
    deploy:complete
@endstory

@story('deploy:rollback', ['on' => $env])
    make:rollback
    run:after_rollback
    rollback:complete
@endstory

@story('backups')
    backups:list
@endstory

@story('releases')
    releases:list
@endstory

@task('display_info')
    echo "Deployment information:";
    echo "- Deployment path: {{ $releasePath }}";
    echo "- Current release: {{ $release }}";
    echo "- Releases path: {{ $releasesPath }}";
    echo "- Shared path: {{ $sharedPath }}";
    echo "- Repository: {{ $repositoryUrl }}";
    echo "- Branch: {{ $branch }}";
    echo "- URL: {{ $appUrl }}";
    echo "- Environment: {{ $env }}";
    echo "- Backup path: {{ $backupPath }}";
@endtask

@task('set:deploy_failure_message')
    @php
        $failureMessage = "
            *Deployment failed on $appName*
            - App name: $appName
            - Environment: $env
            - Deployment path: $path
            - Repository: $repositoryUrl
            - Branch: $branch
            - SSH host: $sshHost
            - Date: $date
        ";
    @endphp

    true
@endtask

@task('set:setup_failure_message')
    @php
        $failureMessage = "
            *Project setup failed on $appName*
            - App name: $appName
            - Environment: $env
            - Deployment path: $path
            - Repository: $repositoryUrl
            - Branch: $branch
            - SSH host: $sshHost
            - Date: $date
        ";
    @endphp

    true
@endtask

@task('setup:directories')
   # Abort if the release directory already exists to avoid overwriting an existing deployment.
   if [ -d {{ $releasesPath }} ]; then
       echo "Release directory already exists on the server. Run 'envoy run deploy' to deploy your application.";
       echo "If you think this is an error, clean up the deployment folder manually and try again.";
       exit 1;
   fi

   echo "Creating deployment directories";

   mkdir -p "{{ $releasesPath }}"
   mkdir -p "{{ $sharedPath }}"
   mkdir -p "{{ $backupPath }}"

   echo "Deployment directories created";
@endtask

@task('make:clone_repository')
   echo "Cloning repository";

   cd {{ $releasesPath }}
   git clone "{{ $repositoryUrl }}" --branch="{{ $branch }}" --depth=1 -q "{{ $release }}"
   echo "Repository cloned";
@endtask

@task('make:link_composer_auth')
   # Symlink a shared auth.json (if present) so Composer can authenticate against
   # private registries and repositories during installation.
   if [ -f {{ $sharedPath }}/auth.json ]; then
       ln -sf {{ $sharedPath }}/auth.json {{ $releasePath }}/auth.json
       echo "Composer auth.json linked from the shared directory";
   else
       echo "No shared auth.json found, skipping Composer authentication setup";
   fi
@endtask

@task('make:install_composer_dependencies')
   echo "Installing Composer dependencies";

   cd {{ $releasePath }}
   {{ $composer }} install {{ $composerOptions }} --no-progress

   echo "Composer dependencies installed";
@endtask

@task('make:npm_install')
   cd {{ $releasePath }}

   if [ -f "package.json" ]; then
       echo "Running npm install"

       if [ -f "yarn.lock" ]; then
           yarn install --immutable
       else
           npm install
       fi

       echo "Npm install complete"
   else
       echo "Npm install skipped, no package.json file found"
   fi
@endtask

@task('setup:common_files')
   echo "Copying common files";

   mv {{ $releasePath }}/storage {{ $sharedPath }}/storage
   echo "Storage moved to the shared directory";

   # Seed the shared .env from the repository's .env.example
   cp {{ $releasePath }}/.env.example {{ $sharedPath }}/.env
   echo "Shared .env created from .env.example";
@endtask

@task('make:symlinks')
   echo "Creating symlinks";

   # Remove the release storage directory and .env.example before linking the shared ones.
   if [ -d {{ $releasePath }}/storage ]; then
       rm -rf {{ $releasePath }}/storage
   fi

   if [ -f {{ $releasePath }}/.env.example ]; then
       rm -rf {{ $releasePath }}/.env.example
   fi

   # Link shared storage and .env into the release
   ln -s {{ $sharedPath }}/storage {{ $releasePath }}/storage
   ln -s {{ $sharedPath }}/.env {{ $releasePath }}/.env

   # Link the storage folder into public
   cd {{ $releasePath }}
   {{ $php }} artisan storage:link

   echo "Release '.env' and 'storage' have been symlinked";
@endtask

@task('setup:generate_app_key')
   echo "Generating app key";

   cd {{ $releasePath }}
   {{ $php }} artisan key:generate
@endtask

@task('make:link_current_release')
   echo "Creating current symlink";

   if [ -L {{ $currentReleasePath }} ]; then
       rm -rf {{ $currentReleasePath }}
   fi

   if [ -d {{ $releasePath }}/current ]; then
       rm -rf {{ $releasePath }}/current
   fi

   ln -nfs {{ $releasePath }} {{ $currentReleasePath }}

   if [ ! -L {{ $currentReleasePath }} ]; then
       echo "Current symlink could not be created";
       exit 1;
   fi

   echo "Current release symlink created";
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
   if [ ! -d {{ $releasesPath }} ]; then
       echo "Deploy directory does not exist on the server. Run 'envoy run setup' to set up your deployment directory.";
       exit 1;
   fi
@endtask

@task('make:app_down')
   @if ($maintenance === true)
       {{ $php }} {{ $currentReleasePath }}/artisan down
       echo "App is in maintenance mode";
   @else
       echo "Application is not in maintenance mode";
   @endif
@endtask

@task('make:migration', ['on' => $env])
   @if ($migration === true)
       echo "Running migrations"

       cd {{ $releasePath }}
       {{ $php }} artisan migrate --force

       echo "Migrations complete"
   @else
       echo "Database migrations skipped"
   @endif
@endtask

@task('make:db_seed', ['on' => $env])
   @if ($seeder === true)
       echo "Running seeders"

       cd {{ $releasePath }}
       {{ $php }} artisan db:seed --force

       echo "Database seeding complete"
   @else
       echo "Database seeding skipped"
   @endif
@endtask

@task('make:cache', ['on' => $env])
   # Clear the cache held by the currently live release
   cd {{ $currentReleasePath }}
   {{ $php }} artisan optimize:clear
   {{ $php }} artisan config:clear
   {{ $php }} artisan route:clear
   {{ $php }} artisan view:clear
   echo "Cache cleared in the current release";

   # Warm the cache for the new release
   cd {{ $releasePath }}
   {{ $php }} artisan route:cache
   {{ $php }} artisan view:cache
   {{ $php }} artisan config:cache
   echo "New release has been cached";
@endtask

@task('make:install_octane', ['on' => $env])
   @if ($octaneInstall === true)
       cd {{ $releasePath }}
       {{ $php }} artisan octane:install --server={{ $octaneServer }} --no-interaction

       echo "Octane installed";
   @else
       echo "Octane install skipped";
   @endif
@endtask

@task('make:app_up', ['on' => $env])
   {{ $php }} {{ $currentReleasePath }}/artisan up
   echo "App is up";
@endtask

@task('make:restart_queue', ['on' => $env])
   @if ($queueRestart === true)
       cd {{ $releasePath }}
       {{ $php }} artisan queue:restart
       echo "Queue restarted";
   @else
       echo "Queue restart skipped";
   @endif
@endtask

@task('make:horizon:terminate', ['on' => $env])
   @if ($horizonTerminate === true)
       cd {{ $currentReleasePath }}
       {{ $php }} artisan horizon:terminate
       echo "Horizon terminated, it should restart automatically";
   @else
       echo "Horizon restart skipped";
   @endif
@endtask

@task('make:reload_octane', ['on' => $env])
   cd {{ $currentReleasePath }}

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
   # Keep only the three most recent releases
   cd {{ $releasesPath }}

   for RELEASE in $(ls -1d * | head -n -3); do
       echo "Deleting old release $RELEASE"
       rm -rf "$RELEASE"
   done

   echo "Old releases cleaned up, only the latest 3 releases are kept";
@endtask

@task('make:check_app_health', ['on' => $env])
   # Verify the application responds with HTTP 200
   curl -s -o /dev/null -w "%{http_code}" {{ $appUrl }} | grep 200 > /dev/null 2>&1 || (echo "App is down" && exit 1) && echo "App is up and running";
@endtask

@task('sentry:release', ['on' => $env])
   @if ((bool) $sentryEnabled === true)
       echo "Recording release in Sentry";

       mkdir -p /tmp/sentry_cli_installation
       cd /tmp/sentry_cli_installation
       wget https://github.com/getsentry/sentry-cli/releases/download/2.23.0/sentry-cli-Linux-x86_64 -O sentry-cli --quiet
       chmod +x sentry-cli

       export SENTRY_AUTH_TOKEN="{{ $sentryToken }}"
       export SENTRY_ORG="{{ $sentryOrg }}"
       export SENTRY_PROJECT="{{ $sentryProject }}"
       export VERSION="{{ $sentryVersion }}"
       export ENVIRONMENT="{{ $env }}"

       ./sentry-cli releases new -p "$SENTRY_PROJECT" "$VERSION"
       ./sentry-cli releases deploys "$VERSION" new -e "$ENVIRONMENT" --org "$SENTRY_ORG" --project "$SENTRY_PROJECT" --version "$VERSION"

       rm -rf /tmp/sentry_cli_installation
   @else
       echo "Sentry release skipped";
   @endif
@endtask

@task('deploy:complete')
   @if (! empty($slackWebhookUrl))
       curl -X POST -H 'Content-type: application/json' --data '{"text":"{{ $deploymentSuccess }}"}' {{ $slackWebhookUrl }} > /dev/null 2>&1
   @endif

   echo "Deployment complete, live at {{ $appUrl }}";
@endtask

@task('make:rollback', ['on' => $env])
   echo "Starting rollback process on the {{ $env }} environment";

   cd {{ $currentReleasePath }}
   {{ $php }} artisan down
   {{ $php }} artisan cache:clear

   # Resolve the currently linked release
   current_release=$(readlink {{ $currentReleasePath }})

   # Find the previous release
   cd {{ $releasesPath }}
   prev_release=$(ls -t | grep -v $(basename $current_release) | head -1)

   if [ -z "$prev_release" ]; then
       echo "No previous release found to roll back to"
       exit 1
   fi

   # Point current to the previous release
   rm {{ $currentReleasePath }}
   ln -s {{ $releasesPath }}/$prev_release {{ $currentReleasePath }}
   echo "Rolled back to previous release: $prev_release";

   @if($queueRestart === true)
       cd {{ $currentReleasePath }}
       {{ $php }} artisan queue:restart
       echo "Queue worker restarted";
   @endif

   @if($octaneReload === true)
       cd {{ $currentReleasePath }}
       {{ $php }} artisan octane:reload
       echo "Laravel Octane reloaded";
   @endif

   @if($horizonTerminate === true)
       cd {{ $currentReleasePath }}
       {{ $php }} artisan horizon:terminate
       echo "Laravel Horizon terminated";
   @endif

   cd {{ $currentReleasePath }}
   {{ $php }} artisan up
@endtask

@task('rollback:complete')
   @if (! empty($slackWebhookUrl))
       curl -X POST -H 'Content-type: application/json' --data '{"text":"{{ $rollbackSuccessMessage }}"}' {{ $slackWebhookUrl }} > /dev/null 2>&1
   @endif

   echo "Rollback complete, live at {{ $appUrl }}";
@endtask

@task('backups:list')
   for dir in "{{ $backupPath }}"/*; do
       echo "$(basename "$dir") | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)"
   done
@endtask

@task('releases:list')
   for dir in "{{ $releasesPath }}"/*; do
       echo "$(basename "$dir") | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)"
   done
@endtask

@success
   echo "Envoy task has been completed";
@endsuccess

@error
   if (empty($failureMessage)) {
       $failureMessage = "Task $task failed on the $env environment on $appName. Error message: $error";
   }

   @slack($slackWebhookUrl, '', $failureMessage)

   echo "Envoy task has failed";
@enderror
