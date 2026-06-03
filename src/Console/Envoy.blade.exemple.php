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
    # Commands to run before the deployment starts (the new release is not cloned yet).
    # Useful for pre-flight checks or notifications.
    true
@endtask

@task("run:after_deploy")
    cd "{{ $releasePath }}"
    # Commands to run after the new release is built but before it goes live.
@endtask

@task("run:after_rollback")
    cd "{{ $currentReleasePath }}"
    # Commands to run after a rollback.
@endtask
