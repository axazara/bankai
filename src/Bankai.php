<?php

declare(strict_types=1);

namespace AxaZara\Bankai;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

class Bankai
{
    /**
     * Bootstrap the host Laravel application and return the deployment variables
     * for the given environment, ready to be extracted in an Envoy script.
     *
     * This keeps the project's Envoy.blade.php to a single line instead of the
     * full framework bootstrap boilerplate. In your Envoy setup block, call:
     * extract(AxaZara\Bankai\Bankai::bootstrap($env));
     *
     * @param string $env The deployment environment (e.g. "staging").
     * @param null|string $basePath The application base path. Defaults to the
     *                              current working directory, which is the
     *                              project root when Envoy runs.
     *
     * @return array<string, mixed> the deployment variables to extract
     */
    public static function bootstrap(string $env, ?string $basePath = null): array
    {
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        $basePath ??= getcwd();
        $bootstrapFile = $basePath . '/bootstrap/app.php';

        $app = is_file($bootstrapFile) ? require $bootstrapFile : null;

        if (! $app instanceof Application) {
            throw new RuntimeException("Unable to bootstrap the Laravel application from {$bootstrapFile}.");
        }

        $app->make(Kernel::class)->bootstrap();

        return (new DeploymentConfig($env))->extractVariables();
    }
}
