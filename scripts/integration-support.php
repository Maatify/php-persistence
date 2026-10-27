<?php

declare(strict_types=1);

/**
 * Functions of the Integration orchestration (see integration.php).
 * Kept apart from the entry point so the regression suite can exercise them without Docker.
 */

const EXIT_TEST_FAILURE = 1;
const EXIT_USAGE = 2;
const EXIT_INFRASTRUCTURE = 3;
const HEALTH_TIMEOUT_SECONDS = 240;
const PROBE_TIMEOUT_SECONDS = 120;

/**
 * Runs a command without a shell and returns its exit status, standard output and standard error.
 * Both pipes are drained concurrently, so a process that writes a lot to either stream cannot
 * block on a full pipe while this function waits on the other one.
 *
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{int, string, string} exit status, standard output, standard error
 */
function execute(array $command, array $environment = [], ?string $cwd = null): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment === [] ? null : [...getenv(), ...$environment],
    );
    if (!is_resource($process)) {
        return [127, '', 'cannot start ' . $command[0]];
    }

    $buffers = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    foreach ($open as $stream) {
        stream_set_blocking($stream, false);
    }
    while ($open !== []) {
        $read = array_values($open);
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }
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

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function passthrough(array $command, array $environment, string $cwd): int
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd, [...getenv(), ...$environment]);

    return is_resource($process) ? proc_close($process) : 127;
}

function fail(int $code, string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @return array{default: string, cells: array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}>}
 */
function loadMatrix(string $root): array
{
    $json = file_get_contents($root . '/docker/integration-matrix.json');
    if ($json === false) {
        fail(EXIT_USAGE, 'docker/integration-matrix.json is missing');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['default'], $data['cells']) || !is_string($data['default']) || !is_array($data['cells'])) {
        fail(EXIT_USAGE, 'docker/integration-matrix.json has an invalid shape');
    }

    /** @var array{default: string, cells: array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}>} $data */
    return $data;
}

/**
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 */
function imageReference(array $cell): string
{
    return $cell['image'] . ':' . $cell['tag'] . '@' . $cell['digest'];
}

/**
 * @param list<string> $arguments
 * @return array{cell: ?string, freshRuns: int, suitePasses: int}
 */
function parseOptions(array $arguments): array
{
    $options = ['cell' => null, 'freshRuns' => 1, 'suitePasses' => 2];
    foreach ($arguments as $argument) {
        if (preg_match('/^--cell=(.+)$/', $argument, $match) === 1) {
            $options['cell'] = $match[1];
        } elseif (preg_match('/^--fresh-runs=([1-9][0-9]?)$/', $argument, $match) === 1) {
            $options['freshRuns'] = (int) $match[1];
        } elseif (preg_match('/^--suite-passes=([1-9][0-9]?)$/', $argument, $match) === 1) {
            $options['suitePasses'] = (int) $match[1];
        } else {
            fail(EXIT_USAGE, 'Unknown or invalid option: ' . $argument);
        }
    }

    return $options;
}

/**
 * @param list<string> $compose docker compose prefix including the project and file
 * @param array<string, string> $environment
 */
function teardown(array $compose, string $project, array $environment, string $root): bool
{
    execute([...$compose, 'down', '--volumes', '--remove-orphans', '--timeout', '30'], $environment, $root);
    $label = 'label=com.docker.compose.project=' . $project;
    $remaining = [];
    foreach ([['ps', '-aq'], ['volume', 'ls', '-q'], ['network', 'ls', '-q']] as $query) {
        [$status, $out] = execute(['docker', ...$query, '--filter', $label]);
        if ($status !== 0 || trim($out) !== '') {
            $remaining[] = implode(' ', $query);
        }
    }
    if ($remaining !== []) {
        fwrite(STDERR, 'Teardown left resources for project ' . $project . ': ' . implode(', ', $remaining) . "\n");

        return false;
    }
    echo 'TEARDOWN VERIFIED project=' . $project . " containers=0 volumes=0 networks=0\n";

    return true;
}

function scalar(PDO $pdo, string $sql): string
{
    $statement = $pdo->query($sql);
    $value = $statement === false ? false : $statement->fetchColumn();

    return is_scalar($value) ? (string) $value : '';
}

function globalVariable(PDO $pdo, string $name): string
{
    $statement = $pdo->query("SHOW GLOBAL VARIABLES LIKE '" . $name . "'");
    $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);

    return is_array($row) && isset($row[1]) && is_scalar($row[1]) ? (string) $row[1] : 'absent';
}

/**
 * @param array<string, string> $suiteEnvironment
 */
function connect(array $suiteEnvironment, string $user, string $password): PDO
{
    return new PDO(
        'mysql:host=' . $suiteEnvironment['PHP_PERSISTENCE_IT_HOST'] . ';port=' . $suiteEnvironment['PHP_PERSISTENCE_IT_PORT']
            . ';dbname=' . $suiteEnvironment['PHP_PERSISTENCE_IT_DATABASE'],
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
}

/**
 * Starts the service, waits for readiness, runs the suite passes and returns the exit status.
 * The caller owns teardown.
 *
 * @param array<string, string> $environment
 * @param list<string> $compose
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 * @param array{cell: ?string, freshRuns: int, suitePasses: int} $options
 */
function runOnService(string $root, string $cellKey, array $cell, array $options, array $environment, array $compose): int
{
    [$upStatus, , $upError] = execute([...$compose, 'up', '--detach', '--wait', '--wait-timeout', (string) HEALTH_TIMEOUT_SECONDS], $environment, $root);
    if ($upStatus !== 0) {
        fwrite(STDERR, "Service did not become healthy:\n" . $upError . "\n");
        [, $logs] = execute([...$compose, 'logs', '--tail', '40'], $environment, $root);
        fwrite(STDERR, $logs . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    [$portStatus, $portOut] = execute([...$compose, 'port', 'database', '3306'], $environment, $root);
    if ($portStatus !== 0 || preg_match('/^(?:127\.0\.0\.1|\[::1\]|0\.0\.0\.0):(\d+)$/m', trim($portOut), $portMatch) !== 1) {
        fwrite(STDERR, 'Cannot discover the published loopback port: ' . $portOut . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    $password = $environment['PP_DB_PASSWORD'];
    $suiteEnvironment = [
        'PHP_PERSISTENCE_IT_ENGINE' => $cell['engine'],
        'PHP_PERSISTENCE_IT_EXPECTED_VERSION' => $cell['expectedVersion'],
        'PHP_PERSISTENCE_IT_CELL' => $cellKey,
        'PHP_PERSISTENCE_IT_HOST' => '127.0.0.1',
        'PHP_PERSISTENCE_IT_PORT' => $portMatch[1],
        'PHP_PERSISTENCE_IT_DATABASE' => $environment['PP_DB_DATABASE'],
        'PHP_PERSISTENCE_IT_USER' => $environment['PP_DB_USER'],
        'PHP_PERSISTENCE_IT_PASSWORD' => $password,
    ];

    $deadline = time() + PROBE_TIMEOUT_SECONDS;
    $pdo = null;
    while ($pdo === null) {
        try {
            $pdo = connect($suiteEnvironment, $environment['PP_DB_USER'], $password);
            scalar($pdo, 'SELECT 1');
        } catch (PDOException $exception) {
            $pdo = null;
            if (time() >= $deadline) {
                fwrite(STDERR, 'PDO readiness probe failed: ' . $exception->getMessage() . "\n");

                return EXIT_INFRASTRUCTURE;
            }
            usleep(500_000);
        }
    }

    $image = $environment['PP_DB_IMAGE'];
    $version = scalar($pdo, 'SELECT VERSION()');
    $comment = scalar($pdo, 'SELECT @@version_comment');
    [, $digestOut] = execute(['docker', 'image', 'inspect', $image, '--format', '{{index .RepoDigests 0}} {{.Architecture}}']);
    echo 'SERVER-IDENTITY ' . json_encode([
        'cell' => $cellKey,
        'image' => $image,
        'imageInspect' => trim($digestOut),
        'selectVersion' => $version,
        'versionComment' => $comment,
        'snapshotIsolationDefault' => globalVariable($pdo, 'innodb_snapshot_isolation'),
        'hostPhp' => PHP_VERSION,
    ], JSON_THROW_ON_ERROR) . "\n";
    if (!str_starts_with($version, $cell['expectedVersion'])) {
        fwrite(STDERR, 'Server identity mismatch: SELECT VERSION() = ' . $version . ', expected prefix ' . $cell['expectedVersion'] . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    $phpunit = [PHP_BINARY, $root . '/vendor/bin/phpunit', '--testsuite', 'integration', '--no-progress'];
    for ($pass = 1; $pass <= $options['suitePasses']; $pass++) {
        echo '--- suite pass ' . $pass . '/' . $options['suitePasses'] . "\n";
        if (passthrough($phpunit, $suiteEnvironment, $root) !== 0) {
            return EXIT_TEST_FAILURE;
        }
        try {
            // The application user has no PROCESS privilege; the disposable root account inspects the server.
            $admin = connect($suiteEnvironment, 'root', $environment['PP_DB_ROOT_PASSWORD']);
            $residue = (int) scalar($admin, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . $environment['PP_DB_DATABASE'] . "'");
            $openTransactions = (int) scalar($admin, 'SELECT COUNT(*) FROM information_schema.innodb_trx');
        } catch (PDOException $exception) {
            fwrite(STDERR, 'Residue check failed: ' . $exception->getMessage() . "\n");

            return EXIT_INFRASTRUCTURE;
        }
        echo 'RESIDUE-CHECK pass=' . $pass . ' tables=' . $residue . ' openTransactions=' . $openTransactions . "\n";
        if ($residue !== 0 || $openTransactions !== 0) {
            fwrite(STDERR, "The suite left database objects or transactions behind.\n");

            return EXIT_TEST_FAILURE;
        }
    }

    return 0;
}

/**
 * Runs one lifecycle (fresh service, suite passes, teardown) and returns the exit status.
 *
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 * @param array{cell: ?string, freshRuns: int, suitePasses: int} $options
 */
function lifecycle(string $root, string $cellKey, array $cell, array $options, int $number): int
{
    $project = 'phppersist-it-' . bin2hex(random_bytes(4));
    $environment = [
        'PP_COMPOSE_PROJECT' => $project,
        'PP_DB_IMAGE' => imageReference($cell),
        'PP_DB_ROOT_PASSWORD' => bin2hex(random_bytes(12)),
        'PP_DB_DATABASE' => 'php_persistence_it',
        'PP_DB_USER' => 'pp_it',
        'PP_DB_PASSWORD' => bin2hex(random_bytes(12)),
    ];
    if ($cell['platform'] !== null) {
        $environment['DOCKER_DEFAULT_PLATFORM'] = $cell['platform'];
    }
    $compose = ['docker', 'compose', '--project-name', $project, '--file', $root . '/compose.integration.yaml'];

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function () use ($compose, $project, $environment, $root): void {
                teardown($compose, $project, $environment, $root);
                exit(130);
            });
        }
    }

    echo '--- lifecycle ' . $number . '/' . $options['freshRuns'] . ' cell=' . $cellKey . ' image=' . $environment['PP_DB_IMAGE'] . ' project=' . $project . "\n";

    $status = EXIT_INFRASTRUCTURE;
    try {
        $status = runOnService($root, $cellKey, $cell, $options, $environment, $compose);
    } finally {
        $torndown = teardown($compose, $project, $environment, $root);
    }

    return $status === 0 && !$torndown ? EXIT_INFRASTRUCTURE : $status;
}
