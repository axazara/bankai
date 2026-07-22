<?php

declare(strict_types=1);

namespace AxaZara\Bankai;

use Symfony\Component\Process\Process;

/**
 * Builds the release artifact on the machine running Envoy and uploads it to
 * the server, so `envoy run deploy` is the only command a pipeline needs.
 *
 * The build never touches the working directory: the project is copied to a
 * temporary directory, production dependencies are installed there, front-end
 * assets are built when present, and the result is packed into a tarball.
 */
class ArtifactBuilder
{
    /**
     * Paths that must never ship in a release artifact: VCS data, local
     * environment files, credentials, tests, and the shared storage directory
     * (the server symlinks its own).
     *
     * @var list<string>
     */
    public const EXCLUDED_PATHS = [
        '.git',
        '.github',
        'node_modules',
        'tests',
        'storage',
        '.env',
        '.env.*',
        'auth.json',
    ];

    private const PROCESS_TIMEOUT = 600;

    public static function buildAndUpload(
        string $sourceDir,
        string $sshUser,
        string $sshHost,
        string $remoteArtifactPath
    ): void {
        $builder = new self();

        $tarball = $builder->build($sourceDir);

        try {
            $builder->upload($tarball, $sshUser, $sshHost, $remoteArtifactPath);
        } finally {
            @unlink($tarball);
        }
    }

    public function build(string $sourceDir): string
    {
        $buildDir = sys_get_temp_dir() . '/bankai-build-' . uniqid();
        $tarball = sys_get_temp_dir() . '/bankai-artifact-' . uniqid() . '.tar.gz';

        mkdir($buildDir, 0755, true);

        try {
            echo "Copying the project to the build directory\n";
            $this->copyProject($sourceDir, $buildDir);

            echo "Installing production dependencies\n";
            $this->runOrFail(
                ['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-progress', '--no-interaction'],
                $buildDir
            );

            $this->buildAssets($buildDir);

            echo "Packing the release artifact\n";
            $this->runOrFail(
                ['tar', '--exclude=./node_modules', '-czf', $tarball, '.'],
                $buildDir
            );

            return $tarball;
        } finally {
            $this->removeDirectory($buildDir);
        }
    }

    public function upload(string $tarball, string $sshUser, string $sshHost, string $remoteArtifactPath): void
    {
        echo "Uploading the release artifact to {$sshHost}\n";

        $this->runOrFail(
            ['scp', '-q', $tarball, "{$sshUser}@{$sshHost}:{$remoteArtifactPath}"],
            null
        );

        echo "Artifact uploaded\n";
    }

    private function copyProject(string $sourceDir, string $buildDir): void
    {
        $command = ['rsync', '-a'];

        foreach (self::EXCLUDED_PATHS as $excludedPath) {
            $command[] = "--exclude=/{$excludedPath}";
        }

        // The vendor directory is rebuilt from the lock file inside the build
        // directory, so the (dev-polluted) local one is never copied.
        $command[] = '--exclude=/vendor';
        $command[] = rtrim($sourceDir, '/') . '/';
        $command[] = $buildDir . '/';

        $this->runOrFail($command, null);
    }

    private function buildAssets(string $buildDir): void
    {
        if (! file_exists("{$buildDir}/package.json")) {
            return;
        }

        echo "Installing front-end dependencies\n";

        if (file_exists("{$buildDir}/yarn.lock")) {
            $this->runOrFail(['yarn', 'install', '--immutable'], $buildDir);
            $buildCommand = ['yarn', 'build'];
        } elseif (file_exists("{$buildDir}/package-lock.json")) {
            $this->runOrFail(['npm', 'ci'], $buildDir);
            $buildCommand = ['npm', 'run', 'build'];
        } else {
            $this->runOrFail(['npm', 'install'], $buildDir);
            $buildCommand = ['npm', 'run', 'build'];
        }

        $packageJson = json_decode((string) file_get_contents("{$buildDir}/package.json"), true);

        if (isset($packageJson['scripts']['build'])) {
            echo "Building front-end assets\n";
            $this->runOrFail($buildCommand, $buildDir);
        }
    }

    private function runOrFail(array $command, ?string $workingDir): void
    {
        $process = new Process($command, $workingDir, null, null, self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "Command '%s' failed:\n%s",
                implode(' ', $command),
                trim($process->getErrorOutput() . "\n" . $process->getOutput())
            ));
        }
    }

    private function removeDirectory(string $directory): void
    {
        (new Process(['rm', '-rf', $directory]))->run();
    }
}
