<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every production file must sit where PSR-4 and the package namespace decision put it.
 */
final class ProductionSourceStructureTest extends TestCase
{
    private const NAMESPACE_ROOT = 'Maatify\PhpPersistence';

    /**
     * @return list<string> paths relative to src/
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryProductionFileIsPhpAndDeclaresItsPsr4Type(): void
    {
        $files = self::sourceFiles();
        self::assertNotSame([], $files);

        foreach ($files as $relative) {
            self::assertStringEndsWith('.php', $relative, $relative);
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/' . $relative);
            self::assertIsString($contents);
            self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $contents, $relative);

            $directory = dirname($relative);
            $namespace = self::NAMESPACE_ROOT . ($directory === '.' ? '' : '\\' . str_replace('/', '\\', $directory));
            self::assertStringContainsString("\nnamespace " . $namespace . ";\n", $contents, $relative);

            $type = basename($relative, '.php');
            self::assertMatchesRegularExpression(
                '/^(final |abstract |readonly )*(class|interface|enum|trait) ' . preg_quote($type, '/') . '\b/m',
                $contents,
                $relative,
            );
        }
    }

    public function testForbiddenFoldersAndLegacyRootAreAbsent(): void
    {
        foreach (self::sourceFiles() as $relative) {
            foreach (['Pdo/', 'Internal/', 'Shared/', 'Helpers/', 'Utils/', 'Service/'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, '/' . $relative, $relative);
            }
        }
    }

    public function testMarkerIsLoadedThroughTheProductionAutoloader(): void
    {
        $composer = require dirname(__DIR__, 2) . '/vendor/autoload.php';
        self::assertInstanceOf(\Composer\Autoload\ClassLoader::class, $composer);

        $file = $composer->findFile('Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface');
        self::assertIsString($file);
        self::assertSame(
            realpath(dirname(__DIR__, 2) . '/src/Exception/PhpPersistenceExceptionInterface.php'),
            realpath($file),
        );

        $prefixes = $composer->getPrefixesPsr4();
        self::assertArrayHasKey('Maatify\PhpPersistence\\', $prefixes);
        self::assertSame(
            [realpath(dirname(__DIR__, 2) . '/src')],
            array_map('realpath', $prefixes['Maatify\PhpPersistence\\']),
        );
    }
}
