<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-owned verifier for the PER Coding Style 3.1 rules
 * that the formatter does not implement.
 */
final class PerCodingStyleDeltaCheckTest extends TestCase
{
    /**
     * @return array{int, string} exit status and standard error output
     */
    private static function check(string $source): array
    {
        $file = tempnam(sys_get_temp_dir(), 'percs');
        self::assertIsString($file);
        file_put_contents($file, $source);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php', $file],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($file);

        return [$status, $errors];
    }

    public function testCleanSourcePasses(): void
    {
        [$status, $errors] = self::check("<?php\n\n\$a = clone(\$b);\n\$c = fn (): int => 1;\n\$d = function (): void {};\n\$e = [\n    1,\n];\n");

        self::assertSame(0, $status, $errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'clone without parentheses' => ["<?php\n\$a = clone \$b;\n", 'clone must be written with parentheses'],
            'switch statement' => ["<?php\nswitch (\$a) {\n    default:\n        break;\n}\n", 'switch is not used'],
            'closure with spaced empty body' => ["<?php\n\$a = function () { };\n", 'empty closure body'],
            'closure with multi-line empty body' => ["<?php\n\$a = function (): void {\n};\n", 'empty closure body'],
            'standalone array bracket' => ["<?php\nfoo(\n    [\n        1,\n    ],\n);\n", 'opening bracket of a multi-line array'],
        ];
    }

    #[DataProvider('violations')]
    public function testViolationIsReported(string $source, string $message): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(1, $status);
        self::assertStringContainsString($message, $errors);
    }
}
