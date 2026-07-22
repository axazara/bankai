<?php

declare(strict_types=1);

namespace AxaZara\Bankai\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class BankaiArtifact extends Command
{
    /**
     * Paths that must never ship in a release artifact: VCS data, local
     * environment files, credentials, tests, and the shared storage directory
     * (the server symlinks its own).
     *
     * @var list<string>
     */
    private const EXCLUDED_PATHS = [
        './.git',
        './.github',
        './node_modules',
        './tests',
        './storage',
        './.env',
        './.env.*',
        './auth.json',
    ];

    protected $signature = 'bankai:artifact
        {--output=release.tar.gz : Path of the tarball to create}';

    protected $description = 'Package the application into a deployable release artifact (run after composer install --no-dev and the asset build)';

    public function handle(): int
    {
        $output = (string) $this->option('output');

        $command = ['tar'];

        foreach (self::EXCLUDED_PATHS as $excludedPath) {
            $command[] = "--exclude={$excludedPath}";
        }

        $command[] = '--exclude=' . $this->outputAsExclusion($output);
        $command[] = '-czf';
        $command[] = $output;
        $command[] = '.';

        $process = new Process($command, base_path(), null, null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Artifact build failed: ' . trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $size = round(filesize($this->absoluteOutput($output)) / 1_048_576, 1);

        $this->info("Artifact created at {$output} ({$size} MB).");
        $this->line('Upload it to the server before running the deploy, for example:');
        $this->line('  scp ' . $output . ' <user>@<host>:<deploy-path>/artifacts/incoming.tar.gz');

        return self::SUCCESS;
    }

    private function outputAsExclusion(string $output): string
    {
        return str_starts_with($output, '/') ? $output : './' . ltrim($output, './');
    }

    private function absoluteOutput(string $output): string
    {
        return str_starts_with($output, '/') ? $output : base_path($output);
    }
}
