@include('vendor/autoload.php')

@setup
    $deploymentSuccess = "
        *Deployment done 🚀 on $appName*
        *→ App name:* $appName
        *→ Environment:* $env
        *→ URL:* $appUrl
        *→ Deployment path:* $path
        *→ Current release:* $release
        *→ Repository:* $repositoryUrl
        *→ Branch:* $branch
        *→ SSH Host:* $ssh_host
        *→ Date:* $date
    ";

    $rollbackSuccessMessage = "🔄 ✅ *$appName* has been rolled back to previous release.";

    $failureMessage = null;

    function octaneIsRunning($php) {
        $octaneStatusCommand = "$php artisan octane:status --no-interaction";
        $output = shell_exec($octaneStatusCommand . ' 2>&1');
        $isOctaneRunning = str_contains($output, 'server is running');
    }

@endsetup

@servers([$env => "{$sshUser}@{$sshHost}"])

@story('setup' , ['on' => $env])
    set:setup_failure_message
    setup:directories
    make:clone_repository
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
    make:clone_repository
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

@task('display_info')
    echo "ℹ️ Deployment Information:";
    echo "→ Deployment path: {{ $releasePath }}";
    echo "→ Current release: {{ $release }}";
    echo "→ Releases path: {{ $releasesPath }}";
    echo "→ Shared path: {{ $sharedPath }}";
    echo "→ Repository: {{ $repositoryUrl }}";
    echo "→ Branch: {{ $branch }}";
    echo "→ URL: {{ $appUrl }}";
    echo "→ Environment: {{ $env }}";
    echo "→ Backup path: {{ $backupPath }}";
@endtask

@story('deploy:rollback', ['on' => $env])
    make:rollback
    run:after_rollback
    rollack:complete
@endstory

@task('set:deploy_failure_message')
    @php
        $failureMessage = "
            *Deloyment failed on 🚨on $appName*
            *→ App name:* $appName
            *→ Environment:* $env
            *→ Deployment path:* $path
            *→ Repository:* $repositoryUrl
            *→ Branch:* $branch
            *→ SSH Host:* $sshHost
            *→ Date:* $date
        ";
    @endphp

    START=$(date +%s)
    true
@endtask

@task('set:setup_failure_message')
    @php
       $failureMessage = "
           * Project setup failed on 🚨on $appName*
           *→ App name:* $appName
           *→ Environment:* $env
           *→ Deployment path:* $path
           *→ Repository:* $repositoryUrl
           *→ Branch:* $branch
           *→ SSH Host:* $sshHost
           *→ Date:* $date
       ";
    @endphp

    true
@endtask

@task('setup:directories')
   #check if, release phat already exit, int thi case return error
   if [ -d {{ $path}}/releases ]; then
       echo "⛔️ Release directory already exists on server. Run 'envoy run deploy' to deploy your application.";
       echo " If you think this is an error, please clean up the project deployment folder manually and try again.";
       exit 1;
   fi

   echo "ℹ️ Creating deployment directories";

   if [ ! -d "{{ $path}}/releases" ]; then
       mkdir -p "{{ $path}}/releases"
   fi

   if [ ! -d "{{ $path}}/shared" ]; then
       mkdir -p "{{ $path}}/shared"
   fi

   if [ ! -d "{{ $path}}/backup" ]; then
       mkdir -p "{{ $path}}/backup"
   fi

   echo "✅ → Deployment directories created";
@endtask

@task('make:clone_repository')
   echo "ℹ️ Cloning repository";

   cd {{ $path}}/releases
   git clone {{ $repositoryUrl }} --branch={{ $branch }} --depth=1 -q {{ $release }}
   echo "✅ → Repository cloned";
@endtask

@task('setup:common_files')
   echo "ℹ️ Copying common files";

   mv {{ $path}}/releases/{{ $release }}/storage {{ $path}}/shared/storage
   echo "✅ → Storage moved";

   # Copy .env.example to .env in shared folder
   cp {{ $path}}/releases/{{ $release }}/.env.example {{ $path}}/shared/.env
   echo "✅ → .env moved";
@endtask

@task('run:after_deploy')
   true
@endtask

@task('run:after_rollback')
   true
@endtask

@task('deploy:durations')
   NOW=$(date +%s)
   ELAPSED=$((NOW-START))
@endtask

@task('deploy:complete')
   @if(! empty($slackWebhookUrl))
       curl -X POST -H 'Content-type: application/json' --data '{"text":"{{ $deploymentSuccess }}"}' {{ $slackWebhookUrl }} > /dev/null 2>&1
   @endif

   echo "✅ → Deployment complete, live at {{ $appUrl }} 🚀";
@endtask

@task('setup:generate_app_key')
   echo "ℹ️ Generating app key";

   # Generate app key
   cd {{ $releasePath }}
   {{ $php }} artisan key:generate
@endtask

@task('setup:finish')
   # Check if Octane reload is enabled
   @if($octaneReload === true)
       echo "⚠️ You have enabled octane, so please make sure you have installed the octane package and configured it properly";
   @endif

   echo "✅ → Deployment path initialized. Edit your .env file in {{ $path}}/shared/.env and run 'envoy run deploy' to deploy your application.";
@endtask

@task('make:npm_install')
   cd {{ $releasePath }}

   if [ -f "package.json" ]; then
           echo "ℹ️ Running npm install"

           if [ -f "yarn.lock" ]; then
               yarn install --immutable
           else
               npm install
           fi

           echo "✅ → Npm install complete"
   else
       echo "🌈 → Npm install skipped, no package.json file found"
   fi
@endtask

@task('make:symlinks')
   echo "ℹ️ Creating symlinks";

   # Delete the storage directory and .env.exemple if exists

   if [ -d {{ $releasePath }}/storage ]; then
       rm -rf {{ $releasePath }}/storage
   fi

   if [ -f {{ $releasePath }}/.env.example ]; then
       rm -rf {{ $releasePath }}/.env.example
   fi

   # Create symlinks
   ln -s {{ $path}}/shared/storage {{ $path}}/releases/{{ $release }}/storage
   ln -s {{ $path}}/shared/.env {{ $path}}/releases/{{ $release }}/.env

   # Link storage folder to public
   cd {{ $releasePath }}
   {{ $php }} artisan storage:link

   echo "✅ → Release '.env' and 'storage' has been symlinked";

@endtask

@task('make:link_current_release')
   echo "ℹ️ Creating current symlink";

    if [ -L {{ $path}}/current ]; then
         rm -rf {{ $path}}/current
    fi

    if [ -d {{ $releasePath }}/current ]; then
        rm -rf {{ $releasePath }}/current
    fi

   # Create current symlink
   ln -nfs {{ $releasePath }} {{ $path}}/current

   if [ ! -L {{ $path}}/current ]; then
       echo "⛔️ Current symlink could not be created";
       exit 1;
   fi

   echo "✅ → Current release symlink created";
@endtask

@task('make:install_composer_dependencies')
   echo "ℹ️ Installing Composer dependencies";

   # Install Composer dependencies
   cd {{ $releasePath }}
   {{ $composer }} install {{ $composerOptions }} --no-progress

   echo "✅ → Composer installed";
@endtask

@task('make:run_migrations')
   @if ($migration === true)
       echo "ℹ️ Running migrations"

       cd {{ $releasePath }}
       {{ $php }} artisan migrate --force

       echo "✅ → Migrations complete"
   @else
       echo "🌈 → Database migrations skipped"
   @endif
@endtask

@task('check_if_release_exists')
   # Check if the releases directory exists
   if [ ! -d {{ $path}}/releases ]; then
       echo "Deploy directory does not exist on server. Run 'envoy run setup' to set up your deployment directory.";
       exit 1;
   fi
@endtask

@task('make:app_down')
   @if ( $maintenance === true )
       # Put app in maintenance mode
       {{ $php }} {{ $path }}/current/artisan down
       echo "✅ -> App is in maintenance mode";
   @else
       echo "🌈 → Application is not in maintenance mode";
   @endif
@endtask

@task('make:migration', ['on' => $env])
   @if ($migration === true)
       echo "ℹ️ Running migrations"

       cd {{ $releasePath }}
       {{ $php }} artisan migrate --force

       echo "✅ → Migrations complete"
   @else
       echo "🌈 → Database migrations skipped"
   @endif
@endtask

@task('make:db_seed', ['on' => $env])
   @if ( $seeder === true )
       echo "ℹ️ Running seeders"

       cd {{ $releasePath }}
       {{ $php }} artisan db:seed --force

       echo "✅ → Database seeding complete"
   @else
       echo "🌈 → Database seeding skipped"
   @endif
@endtask

@task('make:clear_cache', ['on' => $env])
   cd {{ $path }}/current

   # Clear cache
   {{ $php }} artisan optimize:clear
   {{ $php }} artisan config:clear
   {{ $php }} artisan route:clear
   {{ $php }} artisan view:clear

   echo "✅ → App cache cleared";
@endtask

@task('make:cache', ['on' => $env])

        cd {{ $path }}/current
        {{ $php }} artisan optimize:clear
        {{ $php }} artisan config:clear
        {{ $php }} artisan route:clear
        {{ $php }} artisan view:clear
        echo "✅ → App cache cleared in previous release";


       cd {{ $releasePath }}
       #Cache
       {{ $php }} artisan route:cache
       {{ $php }} artisan view:cache
       {{ $php }} artisan config:cache

       echo "✅ → App has been cached";
@endtask

@task('make:horizon:terminate', ['on' => $env])
   @if ( $horizonTerminate === true )
       cd {{ $path }}/current

       #Horizon
       {{ $php }} artisan horizon:terminate

       echo "✅ → Horizon terminated, it should restart automatically";
   @else
       echo "🌈 → Horizon restart skipped";
   @endif
@endtask

@task('make:restart_queue', ['on' => $env])
   @if ($queueRestart === true )
   # -> Restarting queue;
   cd {{ $releasePath }}
   {{ $php }} artisan queue:restart

   echo "✅ → Queue restarted";
   @else
       echo "🌈 → Queue restart skipped";
   @endif
@endtask

@task('make:reload_octane', ['on' => $env])

   cd {{ $path }}/current

   @if ($octaneReload === true )

       if [ $( {{ $php }} artisan octane:status --no-interaction 2>&1 | grep -c 'server is running' ) -gt 0 ]; then

        echo "ℹ️ → Octane is running. Stopping it in old release and it will be restarted in new release by supervisor."

        # Stop Octane
        {{ $php }} artisan octane:stop --no-interaction

        echo "✅ → Octane stopped in old release, it will be restarted in new release by supervisor."
       else
        echo "🌈 → Octane is not running. Skipping restart."
       fi

   @else
       echo "🌈 → Octane restart skipped";
   @endif

@endtask

@task('make:install_octane', ['on' => $env])
   @if ($octaneInstall === true )
       # -> Stopping octane;
       cd {{ $releasePath }}
       {{ $php }} artisan octane:install --server={{ $octaneServer }} --no-interaction

       echo "✅ → Octane installed";
   @else
       echo "🌈 → Octane install skipped";
   @endif
@endtask

@task('make:app_up', ['on' => $env])
   # Take app out of maintenance mode
   {{ $php }} {{ $path }}/current/artisan up
   echo "✅ -> App is up";
@endtask

@task('make:clean_old_release', ['on' => $env])
   # -> Cleanup old releases;
   cd {{ $path}}/releases

   for RELEASE in $(ls -1d * | head -n -3); do
       echo "Deleting old release $RELEASE"
       rm -rf "$RELEASE"
   done

   echo "✅ → Old releases cleaned up, only the latest 3 releases are kept";
@endtask

@task('make:check_app_health', ['on' => $env])
   # -> Check if application is up and running;
   #check if the site is up and return http status code 200
   curl -s -o /dev/null -w "%{http_code}" {{ $appUrl }} | grep 200 > /dev/null 2>&1 || (echo "App is down 😣" && exit 1) && echo "App is up and running 🎉";
@endtask

@task('sentry:release', ['on' => $env])
   # -> Record release in Sentry;

    @if ((bool) $sentryEnabled === true)

        echo "ℹ️ Recording release in Sentry";

        mkdir -p /tmp/sentry_cli_installation
        cd /tmp/sentry_cli_installation
        wget https://github.com/getsentry/sentry-cli/releases/download/2.23.0/sentry-cli-Linux-x86_64 -O sentry-cli --quiet
        chmod +x sentry-cli


        # Setup configuration values
        export SENTRY_AUTH_TOKEN={{ $sentryToken }}
        export SENTRY_ORG={{ $sentryOrg}}
        export SENTRY_PROJECT={{ $sentryProject }}
        export VERSION={{ $sentryVersion }}
        export ENVIRONMENT={{ $env }}

        # Create a release
        # Workflow to create releases

        cd /tmp/sentry_cli_installation
        ./sentry-cli releases new -p "$SENTRY_PROJECT" "$VERSION"
        ./sentry-cli releases deploys "$VERSION" new -e "$ENVIRONMENT" --org "$SENTRY_ORG" --project "$SENTRY_PROJECT" --version "$VERSION"

        rm -rf /tmp/sentry_cli_installation

    @else
        echo "🌈 → Sentry release skipped";
    @endif

@endtask

@story('backups')
   backups:list
@endstory

@task('backups:list')
   for dir in "{{ $path }}/backup"/*; do
       echo "$(basename "$dir") | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)"
   done
@endtask

@story('releases')
   releases:list
@endstory

@task('releases:list')
   for dir in "{{ $path }}/releases"/*; do
       echo "$(basename "$dir") | $(stat -c '%y' "$dir" | cut -d ' ' -f 1)"
   done
@endtask

@task('make:rollback', ['on' => $env])
   echo "ℹ️ Starting rollback process on {{ $env }} environment";

   cd {{$currentRelease}}
   {{ $php }} artisan down
   {{ $php }} artisan cache:clear

   # Fetch the current symlink
   current_release=$(readlink {{ $path }}/current)

   # Get the previous release
   cd {{ $path }}/releases
   prev_release=$(ls -t | grep -v $(basename $current_release) | head -1)

   if [ -z "$prev_release" ]; then
       echo "⛔️ No previous release found to rollback"
       exit 1
   fi

   # Remove the current symlink
   rm {{ $path }}/current

   # Link the previous release as the current release
   ln -s {{ $path }}/releases/$prev_release {{ $path }}/current
   echo "✅ → Rolled back to previous release: $prev_release";

   # Restart services if needed
       @if($queueRestart === true)
           cd {{ $path }}/current
           {{ $php }} artisan queue:restart
           echo "✅ → Queue worker restarted";
       @endif

       @if($octaneReload === true)
           cd {{ $path }}/current
           {{ $php }} artisan octane:reload
           echo "✅ → Laravel Octane reloaded";
       @endif

   @if($horizonTerminate === true)
       cd {{ $path }}/current
       {{ $php }} artisan horizon:terminate
       echo "✅ → Laravel Horizon terminated";
   @endif

   cd {{ $path }}/current
   {{ $php }} artisan up

@endtask

@task('rollack:complete')
   @if(! empty($slackWebhookUrl))
       curl -X POST -H 'Content-type: application/json' --data '{"text":"{{ $rollbackSuccessMessage }}"}' {{ $slackWebhookUrl }} > /dev/null 2>&1
   @endif

   echo "✅ → Rollback complete, live at {{ $appUrl }} 🔄";
@endtask

@success
   echo "✅ ✅ ✅ → Envoy task has been completed 🚀";
@endsuccess

@error

   if(empty($failureMessage)){
       $failureMessage = "🚨 🚨 🚨  Task $task failed on $env environment on $appName. Error message: $error";
   }

   @slack($slackWebhookUrl, '', $failureMessage)

   echo "🚨 🚨 🚨 → Envoy task has failed 🚨";
@enderror