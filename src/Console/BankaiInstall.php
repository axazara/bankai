<?php

declare(strict_types=1);

namespace AxaZara\Bankai\Console;

use AxaZara\Bankai\Providers\BankaiServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BankaiInstall extends Command
{
    protected $signature = 'bankai:install';

    protected $description = 'Install Bankai: publish the configuration and the base Envoy.blade.php';

    public function handle(): int
    {
        $this->info('Installing Bankai...');

        $this->publishConfigurationFile();
        $this->publishEnvoyFile();

        $this->info('Bankai installed successfully.');

        return self::SUCCESS;
    }

    private function publishConfigurationFile(): void
    {
        if (! $this->configurationExists()) {
            $this->publishConfiguration();
            $this->info('Published the configuration file.');

            return;
        }

        if ($this->shouldOverwriteConfiguration()) {
            $this->publishConfiguration(force: true);
            $this->info('Overwrote the configuration file.');

            return;
        }

        $this->info('The existing configuration file was left untouched.');
    }

    private function publishEnvoyFile(): void
    {
        $envoyFile = base_path('Envoy.blade.php');

        if (File::exists($envoyFile)) {
            $this->info('The existing Envoy.blade.php was left untouched.');

            return;
        }

        File::copy(__DIR__ . '/stubs/Envoy.blade.php.stub', $envoyFile);
        $this->info('Created Envoy.blade.php in the project root.');
    }

    private function configurationExists(): bool
    {
        return File::exists(config_path('bankai.php'));
    }

    private function shouldOverwriteConfiguration(): bool
    {
        return $this->confirm('The bankai.php config file already exists. Overwrite it?', default: false);
    }

    private function publishConfiguration(bool $force = false): void
    {
        $params = [
            '--provider' => BankaiServiceProvider::class,
            '--tag'      => 'config',
        ];

        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
    }
}
