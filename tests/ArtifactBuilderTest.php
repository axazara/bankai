<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\ArtifactBuilder;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ArtifactBuilderTest extends BaseTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        $this->sourceDir = sys_get_temp_dir() . '/bankai-builder-fixture-' . uniqid();

        mkdir($this->sourceDir . '/.git', 0755, true);
        mkdir($this->sourceDir . '/app', 0755, true);
        mkdir($this->sourceDir . '/storage/logs', 0755, true);

        file_put_contents($this->sourceDir . '/composer.json', '{}');
        file_put_contents($this->sourceDir . '/artisan', '<?php // stub');
        file_put_contents($this->sourceDir . '/app/Kernel.php', '<?php // stub');
        file_put_contents($this->sourceDir . '/.env', 'APP_KEY=secret');
        file_put_contents($this->sourceDir . '/auth.json', '{}');
        file_put_contents($this->sourceDir . '/.git/HEAD', 'ref: refs/heads/main');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->sourceDir));
    }

    public function test_it_builds_a_tarball_with_production_vendor_and_without_sensitive_files(): void
    {
        ob_start();

        try {
            $tarball = (new ArtifactBuilder())->build($this->sourceDir);
        } finally {
            ob_end_clean();
        }

        try {
            $this->assertFileExists($tarball);

            exec('tar -tzf ' . escapeshellarg($tarball), $entries);
            $entries = array_map(static fn (string $entry): string => rtrim($entry, '/'), $entries);

            $this->assertContains('./artisan', $entries);
            $this->assertContains('./app/Kernel.php', $entries);
            $this->assertContains('./vendor/autoload.php', $entries);
            $this->assertNotContains('./.env', $entries);
            $this->assertNotContains('./auth.json', $entries);
            $this->assertNotContains('./.git', $entries);
            $this->assertNotContains('./storage', $entries);
        } finally {
            @unlink($tarball);
        }
    }
}
