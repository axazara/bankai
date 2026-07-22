<?php

declare(strict_types=1);

namespace Tests;

class BankaiArtifactTest extends TestCase
{
    public function test_it_packages_the_application_without_sensitive_files(): void
    {
        $envFile = base_path('.env');
        $authFile = base_path('auth.json');
        $output = sys_get_temp_dir() . '/bankai_artifact_test_' . uniqid() . '.tar.gz';

        $envFileExisted = file_exists($envFile);

        if (! $envFileExisted) {
            file_put_contents($envFile, 'APP_KEY=secret');
        }

        file_put_contents($authFile, '{"http-basic":{}}');

        try {
            $this->artisan('bankai:artifact', ['--output' => $output])
                ->assertExitCode(0);

            $this->assertFileExists($output);

            exec('tar -tzf ' . escapeshellarg($output), $entries);
            $entries = array_map(static fn (string $entry): string => rtrim($entry, '/'), $entries);

            $this->assertContains('./composer.json', $entries);
            $this->assertNotContains('./.env', $entries);
            $this->assertNotContains('./auth.json', $entries);
            $this->assertNotContains('./.git', $entries);
        } finally {
            @unlink($output);
            @unlink($authFile);

            if (! $envFileExisted) {
                @unlink($envFile);
            }
        }
    }
}
