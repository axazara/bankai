<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\File;

class BankaiInstallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanGeneratedFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanGeneratedFiles();

        parent::tearDown();
    }

    public function test_it_publishes_the_config_and_creates_the_envoy_file(): void
    {
        $this->artisan('bankai:install')->assertSuccessful();

        $this->assertFileExists(config_path('bankai.php'));
        $this->assertFileExists(base_path('Envoy.blade.php'));
    }

    public function test_it_does_not_overwrite_an_existing_envoy_file(): void
    {
        File::put(base_path('Envoy.blade.php'), '@setup @endsetup');

        $this->artisan('bankai:install')->assertSuccessful();

        $this->assertSame('@setup @endsetup', File::get(base_path('Envoy.blade.php')));
    }

    private function cleanGeneratedFiles(): void
    {
        File::delete(base_path('Envoy.blade.php'));
        File::delete(config_path('bankai.php'));
    }
}
