<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Envoy\Compiler;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Guards that the shipped Envoy template compiles to syntactically valid PHP.
 *
 * Envoy directives that open a closure (@before, @after, @success, @error,
 * @finished) leave the PHP context open for their body, so the body must be
 * written as raw PHP. Wrapping it in @php ... @endphp emits a nested <?php
 * (and a premature ?>), which is a fatal parse error at deploy time. This was
 * the "unexpected token <" regression in the @error handler.
 */
final class EnvoyCompilationTest extends BaseTestCase
{
    private function compiledTemplate(): string
    {
        $source = file_get_contents(__DIR__ . '/../src/Envoy.blade.php');

        return (new Compiler())->compile($source);
    }

    public function test_envoy_template_compiles_to_syntactically_valid_php(): void
    {
        $compiled = $this->compiledTemplate();

        $file = tempnam(sys_get_temp_dir(), 'bankai_envoy_') . '.php';
        file_put_contents($file, $compiled);

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        @unlink($file);

        $this->assertSame(
            0,
            $exitCode,
            'Compiled Envoy template is not valid PHP: ' . implode("\n", $output),
        );
    }

    public function test_compiled_template_never_nests_php_open_tags(): void
    {
        $compiled = $this->compiledTemplate();

        $offset = 0;
        $open = false;

        while (true) {
            $nextOpen = strpos($compiled, '<?php', $offset);
            $nextClose = strpos($compiled, '?>', $offset);

            if ($nextOpen === false && $nextClose === false) {
                break;
            }

            if ($nextOpen !== false && ($nextClose === false || $nextOpen < $nextClose)) {
                $this->assertFalse(
                    $open,
                    'Found a nested <?php open tag at offset ' . $nextOpen . '; an Envoy closure body must hold raw PHP, not @php ... @endphp.',
                );
                $open = true;
                $offset = $nextOpen + 5;

                continue;
            }

            $open = false;
            $offset = $nextClose + 2;
        }

        $this->assertFalse($open, 'Compiled template ended with an unterminated <?php block.');
    }
}
