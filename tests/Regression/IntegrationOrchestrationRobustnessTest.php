<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * Failure-path protection of scripts/integration.php that needs no Docker: a stub `docker`
 * executable stands in for the CLI. Every child process runs under a deadline so that a
 * regression shows up as a failure instead of a hung suite.
 */
final class IntegrationOrchestrationRobustnessTest extends TestCase
{
    private const DEADLINE_SECONDS = 60;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     *
     * @return array{int, string, string} status (-1 when the deadline expired), standard output, standard error
     */
    private static function runWithDeadline(array $command, array $environment = []): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment === [] ? null : [...getenv(), ...$environment],
        );
        self::assertIsResource($process);

        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }
        $deadline = time() + self::DEADLINE_SECONDS;
        while ($open !== []) {
            if (time() >= $deadline) {
                proc_terminate($process, 9);
                foreach ($open as $stream) {
                    fclose($stream);
                }
                proc_close($process);

                return [-1, $buffers[1], $buffers[2]];
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);
            foreach ($open as $index => $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buffers[$index] .= $chunk;
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
        }

        return [proc_close($process), $buffers[1], $buffers[2]];
    }

    public function testExecuteDrainsLargeOutputOnBothStreamsWithoutBlocking(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';'
            . '[$status, $out, $err] = execute([PHP_BINARY, "-r", "fwrite(STDERR, str_repeat(\"e\", 262144)); fwrite(STDOUT, str_repeat(\"o\", 262144)); exit(7);"]);'
            . 'echo json_encode([$status, strlen($out), strlen($err)]);';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertNotSame(-1, $status, 'execute() hung on a full pipe');
        self::assertSame(0, $status, $err);
        self::assertSame('[7,262144,262144]', $out);
    }

    public function testExecuteReportsAMissingBinary(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';echo json_encode(execute(["/nonexistent/binary"]));';

        [$status, $out] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status);
        self::assertStringContainsString('127', $out);
    }

    public function testDockerFailureWithHugeStderrStillTearsDownAndFailsClosed(): void
    {
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $log = $directory . '/calls.log';
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $log, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            case " $* " in
              *" up "*)
                yes 'simulated docker failure line' | head -c 262144 >&2
                exit 1
                ;;
              *" logs "*) echo "simulated log"; exit 0 ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);

        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            ['PATH' => $directory . ':/usr/bin:/bin'],
        );

        self::assertNotSame(-1, $status, 'the orchestration hung on a failing Docker command');
        self::assertSame(3, $status, $out . $err);
        self::assertStringContainsString('Service did not become healthy', $err);
        self::assertStringContainsString('TEARDOWN VERIFIED', $out);

        $calls = file_get_contents($log);
        self::assertIsString($calls);
        self::assertMatchesRegularExpression('/compose .* up --detach --wait/', $calls);
        self::assertMatchesRegularExpression('/compose .* down --volumes --remove-orphans/', $calls, 'teardown ran after the failure');
    }

    public function testTeardownResidueTurnsAnOtherwiseSuccessfulRunIntoAnInfrastructureFailure(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            case " $* " in
              *" ps "*) echo leftover-container ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(teardown(["docker", "compose"], "phppersist-it-test", [], "/tmp"));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child], ['PATH' => $directory . ':/usr/bin:/bin']);

        self::assertSame(0, $status, $err);
        self::assertSame('false', $out);
        self::assertStringContainsString('Teardown left resources', $err);
    }
}
