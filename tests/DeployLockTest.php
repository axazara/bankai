<?php

declare(strict_types=1);

namespace Tests;

use AxaZara\Bankai\DeployLock;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Runs the remote release script against a real directory, as the server would.
 */
final class DeployLockTest extends BaseTestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/bankai_lock_' . uniqid() . '/.bankai-deploy.lock';
        mkdir($this->lockPath, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->lockPath)));

        parent::tearDown();
    }

    public function test_a_failed_run_releases_the_lock_it_holds(): void
    {
        file_put_contents($this->lockPath . '/owner', "production_20260101_000000\n");

        $output = $this->runScript('production_20260101_000000');

        $this->assertDirectoryDoesNotExist($this->lockPath);
        $this->assertStringContainsString('released', $output);
    }

    public function test_a_failed_run_leaves_another_deployments_lock_in_place(): void
    {
        file_put_contents($this->lockPath . '/owner', "production_20260101_000000\n");

        $output = $this->runScript('production_20260101_000500');

        $this->assertDirectoryExists($this->lockPath);
        $this->assertStringContainsString('does not hold it', $output);
    }

    public function test_a_lock_without_an_owner_is_left_in_place(): void
    {
        $this->runScript('production_20260101_000000');

        $this->assertDirectoryExists($this->lockPath);
    }

    public function test_it_says_so_when_there_is_no_lock(): void
    {
        rmdir($this->lockPath);

        $this->assertStringContainsString('No deployment lock', $this->runScript('production_20260101_000000'));
    }

    public function test_it_runs_the_script_over_ssh_without_prompting(): void
    {
        $command = DeployLock::command('forge', 'example.com', '/home/forge/app/.bankai-deploy.lock', 'production_x');

        $this->assertSame(['ssh', '-o', 'BatchMode=yes', 'forge@example.com'], array_slice($command, 0, 4));
        $this->assertSame(DeployLock::script('/home/forge/app/.bankai-deploy.lock', 'production_x'), $command[4]);
    }

    private function runScript(string $release): string
    {
        exec('sh -c ' . escapeshellarg(DeployLock::script($this->lockPath, $release)), $output, $exitCode);

        $this->assertSame(0, $exitCode);

        return implode("\n", $output);
    }
}
