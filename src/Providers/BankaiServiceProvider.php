<?php

namespace AxaZara\Bankai\Providers;

use AxaZara\Bankai\Console\BankaiInstall;
use Illuminate\Support\ServiceProvider;

class BankaiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(commands: [
                BankaiInstall::class,
            ]);

            $this->publishes([
                __DIR__ . '/../../config/bankai.php' => config_path('bankai.php'),
            ], 'config');
        }
    }
}
