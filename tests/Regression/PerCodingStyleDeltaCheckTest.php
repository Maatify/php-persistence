<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-owned verifier for the PER Coding Style 3.1 rules
 * that the formatter does not implement. Every rule has accepting and rejecting cases.
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
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $source);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php', $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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

    /**
     * @return array<string, array{string}>
     */
    public static function accepted(): array
    {
        return [
            'clone with parentheses' => ["\$a = clone(\$b);\n"],
            'closure with empty body' => ["\$a = function (): void {};\n"],
            'closure with use and empty body' => ["\$a = function () use (\$b): void {};\n"],
            'closure with a statement' => ["\$a = function (): int {\n    return 1;\n};\n"],
            'multi-line array with bracket on the assignment line' => ["\$e = [\n    1,\n];\n"],
            'single-line array as argument' => ["foo([1, 2]);\n"],
            'index access on the next line is not an array' => ["\$x = \$list\n    ['key'];\n"],
            'switch ending every case with a terminator' => [
                "switch (\$a) {\n    case 1:\n        return 1;\n    case 2:\n    case 3:\n        \$b = 2;\n        break;\n    default:\n        throw new Exception('x');\n}\n",
            ],
            'case terminator after an if block' => [
                "switch (\$a) {\n    case 1:\n        if (\$b) {\n            \$c = 1;\n        }\n        break;\n}\n",
            ],
            'case terminator with a match expression' => [
                "switch (\$a) {\n    case 1:\n        return match (\$b) {\n            1 => 'a',\n            default => 'b',\n        };\n}\n",
            ],
            'multi-line case condition wrapped in parentheses' => [
                "switch (\$a) {\n    case (\n        \$b\n        || \$c\n    ):\n        break;\n}\n",
            ],
            'ternary inside a case label' => ["switch (\$a) {\n    case \$b ? 1 : 2:\n        break;\n}\n"],
            'enum with private constant and method' => [
                "enum Suit: string\n{\n    case Hearts = 'H';\n\n    private const X = 1;\n\n    private function f(): void {}\n}\n",
            ],
        ];
    }

    #[DataProvider('accepted')]
    public function testConformingSourcePasses(string $source): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(0, $status, $errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'clone without parentheses' => ["\$a = clone \$b;\n", 'clone must be written with parentheses'],
            'closure with spaced empty body' => ["\$a = function () { };\n", 'empty body must be written'],
            'closure with multi-line empty body' => ["\$a = function (): void {\n};\n", 'empty body must be written'],
            'closure empty body without space before brace' => ["\$a = function (): void{};\n", 'empty body must be written'],
            'standalone array bracket as argument' => ["foo(\n    [\n        1,\n    ],\n);\n", 'opening bracket of a multi-line array'],
            'standalone array bracket after assignment' => ["\$a =\n    [\n        1,\n    ];\n", 'opening bracket of a multi-line array'],
            'case body in braces' => ["switch (\$a) {\n    case 1: {\n        break;\n    }\n}\n", 'must not be wrapped in braces'],
            'last case without terminator' => ["switch (\$a) {\n    case 1:\n        \$b = 1;\n}\n", 'must end with a terminating statement'],
            'middle case without terminator' => ["switch (\$a) {\n    case 1:\n        \$b = 1;\n    case 2:\n        break;\n}\n", 'must end with a terminating statement'],
            'case ending with an if block' => ["switch (\$a) {\n    case 1:\n        if (\$b) {\n            return 1;\n        }\n}\n", 'must end with a terminating statement'],
            'multi-line case condition without parentheses' => [
                "switch (\$a) {\n    case \$b\n        || \$c:\n        break;\n}\n",
                'wrapped in parentheses',
            ],
            'multi-line case condition with closing parenthesis not alone' => [
                "switch (\$a) {\n    case (\n        \$b\n        || \$c):\n        break;\n}\n",
                'stand alone on the last line',
            ],
            'switch with alternative syntax' => ["switch (\$a):\n    case 1:\n        break;\nendswitch;\n", 'alternative syntax'],
            'enum with protected member' => ["enum Suit\n{\n    case Hearts;\n\n    protected function f(): void {}\n}\n", 'private instead of protected'],
            'anonymous class attribute on the new line' => ["\$o = new #[Foo] class {};\n", 'start on the line after new'],
            'anonymous class attribute not indented' => ["\$o = new\n#[Foo]\nclass {};\n", 'indented once'],
            'anonymous class declaration not on a new line' => ["\$o = new\n    #[Foo] class {};\n", 'starts on a new line'],
        ];
    }

    #[DataProvider('violations')]
    public function testViolationIsReported(string $source, string $message): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(1, $status, 'expected a finding');
        self::assertStringContainsString($message, $errors);
    }

    public function testConformingAnonymousClassWithAttributesPasses(): void
    {
        [$status, $errors] = self::check("\$o = new\n    #[Foo]\n    class {};\n");

        self::assertSame(0, $status, $errors);
    }

    public function testDefaultScopeCoversConfigurationAndScriptFiles(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        $scanned = (int) preg_replace('/^.*check: (\d+) files.*$/s', '$1', $output);
        self::assertGreaterThan(0, $scanned, $output);
        $expected = 0;
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            self::assertInstanceOf(\SplFileInfo::class, $entry);
            if (str_contains($entry->getPathname(), '/vendor/') || str_contains($entry->getPathname(), '/.git/')) {
                continue;
            }
            $path = str_replace($root . '/', '', $entry->getPathname());
            if ($entry->getExtension() === 'php' && preg_match('#^(vendor|\.git|docs|node_modules|\.phpunit\.cache)/#', $path) !== 1) {
                $expected++;
            }
        }
        self::assertGreaterThanOrEqual(7, $expected);
        self::assertSame($expected, $scanned);
        self::assertFileExists($root . '/.php-cs-fixer.php');
    }
}
