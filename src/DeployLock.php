<?php

declare(strict_types=1);

namespace AxaZara\Bankai;

use Symfony\Component\Process\Process;

/**
 * Releases the deployment lock after a failed run.
 *
 * deploy:acquire_lock records the id of the run holding the lock, so
 * a failed run only removes its own lock: a run that failed because another
 * deployment holds the lock leaves that lock in place.
 */
final class DeployLock
{
    private const SSH_TIMEOUT = 60;

    /**
     * Release the lock on the server when the failed run is the one holding it.
     * Never throws: it runs while a failure is already being reported.
     */
    public static function releaseIfOwned(string $sshUser, string $sshHost, string $lockPath, string $runId): void
    {
        try {
            $process = new Process(self::command($sshUser, $sshHost, $lockPath, $runId), null, null, null, self::SSH_TIMEOUT);
            $process->run();

            $output = trim($process->getOutput());

            echo ($process->isSuccessful() && $output !== '')
                ? $output . "\n"
                : "Could not release the deployment lock automatically. Release it with: envoy run deploy:unlock\n";
        } catch (\Throwable $e) {
            echo 'Could not release the deployment lock automatically: ' . $e->getMessage() . "\n";
        }
    }

    /**
     * @return list<string>
     */
    public static function command(string $sshUser, string $sshHost, string $lockPath, string $runId): array
    {
        return ['ssh', '-o', 'BatchMode=yes', "{$sshUser}@{$sshHost}", self::script($lockPath, $runId)];
    }

    public static function script(string $lockPath, string $runId): string
    {
        $lock = escapeshellarg($lockPath);
        $owner = escapeshellarg($lockPath . '/owner');
        $expected = escapeshellarg($runId);

        return "if [ ! -d {$lock} ]; then echo 'No deployment lock to release'; "
            . "elif [ -f {$owner} ] && [ \"\$(cat {$owner})\" = {$expected} ]; then rm -rf {$lock} && echo 'Deployment lock released after the failure'; "
            . "else echo 'Deployment lock left in place: this run does not hold it'; fi";
    }
}
