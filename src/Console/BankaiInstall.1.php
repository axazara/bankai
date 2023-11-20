<?php

namespace AxaZara\Bankai\Console;

use AxaZara\Bankai\Providers\BankaiServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BankaiInstall extends Command
{
    protected $signature = 'bankai:install';

    protected $description = 'Setup Bankai Laravel Package';

    public function handle(): void
    {
        $this->info(string: 'Setting up Bankai Laravel Package...');

        $this->info(string: 'Publishing configuration...');

        if (! $this->configExists()) {
            $this->publishConfiguration();
            $this->info(string: 'Published configuration');
        } elseif ($this->shouldOverwriteConfig()) {
            $this->info(string: 'Overwriting configuration file...');
            $this->publishConfiguration($force = true);
        } else {
            $this->info(string: 'Existing configuration was not overwritten');
        }

        $this->info(string: 'Publishing Envoy file...');
        $this->publishEnvoyFile();
        $this->info(string: 'Published Envoy file');

        $this->info(string: 'Bankai Laravel Package setup successfully.');
    }

    private function configExists(): bool
    {
        return File::exists(config_path(path: 'bankai.php'));
    }

    private function shouldOverwriteConfig(): bool
    {
        return $this->confirm(
            question: 'Config file already exists. Do you want to overwrite it?',
            default: false
        );
    }

    private function publishConfiguration($forcePublish = false): void
    {
        $params = [
            '--provider' => BankaiServiceProvider::class,
            '--tag'      => 'config',
        ];

        if ($forcePublish === true) {
            $params['--force'] = true;
        }
        $this->call('vendor:publish', $params);
    }

    /**
     * Publish the Envoy file.
     */
    public function publishEnvoyFile(): void
    {
        $envoyFile = app()->path() . '/../Envoy.blade.php';

        if (! File::exists($envoyFile)) {
            File::copy(
                path: __DIR__ . '/Envoy.blade.exemple.php',
                target: $envoyFile
            );
        }
    }
}
