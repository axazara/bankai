include('vendor/autoload.php')

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


    if ( $slackNotification == true ) {
        if ( empty($slackWebhookUrl) ) throw new Exception('🙁⛔️ Error :: your slack webhook is empty');
    }

    $date = date('Y-m-d_H-i-s');
    $release = $env.'_'.date('YmdHis');
    $releasePath = "{$path}/releases/{$release}";
    $path = rtrim($path, '/');

    $slackSuccessMessage = "
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

    $slackFailedMessage = "
        *Deployment failed 🚨on $appName*
        *→ App name:* $appName
        *→ Environment:* $env
        *→ URL:* $appUrl
        *→ Deployment path:* $path
        *→ Current release:* $release
        *→ Repository:* $repositoryUrl
        *→ Branch:* $branch
        *→ SSH Host:* $sshHost
        *→ Date:* $date
    ";
@endsetup

@servers([$env => "{$sshUser}@{$sshHost}"])


@story('setup' , ['on' => $env])
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
    make:clear_cache
    make:cache
    make:npm_build
    make:restart_queue
    make:horizon:terminate
    make:reload_octane
    make:link_current_release
    make:app_up
    make:clean_old_release
    run:after_deploy
    make:check_app_health
@endstory

@task('display_info')
    echo "ℹ️ Deployment Information:";
    echo "→ Deployment path: {{ $trimmedPath }}";
    echo "→ Current release: {{ $release }}";
    echo "→ Releases path: {{ $releasesPath }}";
    echo "→ Shared path: {{ $sharedPath }}";
    echo "→ Repository: {{ $repositoryUrl }}";
    echo "→ Branch: {{ $branch }}";
    echo "→ URL: {{ $appUrl }}";
    echo "→ Backup path: {{ $backupPath }}";
@endtask

@story('deploy:rollback', ['on' => $env])
    make:rollback
@endstory

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
    if [ -f "package.json" ]; then
            echo "ℹ️ Running npm install"

            cd {{ $releasePath }}

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

@task('make:npm_build')
    cd "{{ $assetsPath }}"

    if [ -f "package.json" ]; then
        if [ -f "yarn.lock" ]; then
            yarn run production
        else
            npm run production
        fi
    else
        echo "🌈 → Npm build skipped, no package.json file found"
    fi
@endtask

@task('make:symlinks')
    echo "ℹ️ Creating symlinks";

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

    # Create current symlink
    ln -s {{ $path}}/releases/{{ $release }} {{ $path}}/current

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

@task('make:roles_and_permissions', ['on' => $env])
    @if ($rolesAndPermissions === true)
        echo "ℹ️ Running roles and permissions seeder"

        cd {{ $releasePath }}
        {{ $php }} artisan db:seed --class=RolesAndPermissionsSeeder

        echo "✅ → Roles and permissions seeder complete"
    @else
        echo "🌈 → Roles and permissions seeder skipped"
    @endif
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

    OLD_RELEASE=$(find {{ $path }}/releases/ -maxdepth 1 -type d -name "{{$env}}*" | sort -r | head -n 2 | tail -n 1)

    cd $OLD_RELEASE

    # Clear cache
    {{ $php }} artisan optimize:clear
    {{ $php }} artisan config:clear
    {{ $php }} artisan route:clear
    {{ $php }} artisan view:clear


    echo "✅ → App cache cleared";
@endtask

@task('make:cache', ['on' => $env])
        cd {{ $path }}/current

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
    #check if octane is running and reload
    @if ($octaneReload === true )
        echo "ℹ️ → Stop Octane in Old Release";

        cd {{ $releasePath }}
        {{ $php }} artisan octane:stop --no-interaction

        echo "✅ → Octane stopped in old release, it will be restarted in new release by supervisor";
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
    echo "✅ -> App is up and running";
@endtask

@task('make:clean_old_release', ['on' => $env])
    # -> Cleanup old releases;

    cd {{ $path}}/releases

    for RELEASE in $(ls -1d * | head -n -3 }); do
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

@task('deploy:slack', ['on' => $env])
    @if ($slackNotification === true)
        # -> Send slack notification;
        curl -X POST -H 'Content-type: application/json' --data '{"text":"{{ $slackSuccessMessage }}"}' {{ $slackWebhookUrl }} > /dev/null 2>&1
        echo "✅ → Slack notification sent";
    @else
        echo "🌈 → Slack notification skipped";
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

    @php
        $rollBackMessage = "🔄 *$appName* has been rolled back to previous release.";
    @endphp

    @if ($slackNotification === true)
        @slack($slackWebhookUrl, '', $slackFailedMessage);
    @else
        echo "🌈 → Slack notification skipped";
    @endif

    echo "✅ → Rollback complete, live at {{ $appUrl }} 🔄";
@endtask

@error
    echo "❌ ❌ ❌ → Envoy task has been failed";
@enderror

@success
    echo "✅ ✅ ✅ → Envoy task has been completed 🚀";
@endsuccess