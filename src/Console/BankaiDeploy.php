<?php

namespace AxaZara\Bankai\Console;

use Illuminate\Console\Command;

final class BankaiDeploy extends Command
{
    protected $signature = 'bankai:deploy {--env=}';

    protected $description = 'Deploy your project';

    public function handle(): void
    {
        shell_exec(command: "vendor/bin/envoy run deploy --env={$this->option(key: 'env')}");
    }
}
