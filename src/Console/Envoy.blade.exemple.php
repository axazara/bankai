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

@task("run:after_deploy")
    cd "{{ $releasePath }}"
    # Here you can add your own commands to run after deploy
@endtask


