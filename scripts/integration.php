<?php

declare(strict_types=1);

/**
 * Canonical Integration orchestration (entry point: composer test:integration).
 *
 * Lifecycle: validate prerequisites, start one disposable database service from the
 * single Compose definition, wait for health and for a successful PDO probe, run the
 * Integration suite, verify cleanup and repeat the suite on the same service, tear the
 * service down (also on failure) and prove that nothing of the project remains.
 *
 * Usage: php scripts/integration.php [--cell=<key>] [--fresh-runs=<n>] [--suite-passes=<n>]
 *   --cell          key of docker/integration-matrix.json (env PP_DB_CELL, default from the matrix)
 *   --fresh-runs    independent lifecycles from a fresh service (default 1)
 *   --suite-passes  suite executions per lifecycle on the same service, residue-checked (default 2)
 *
 * Exit status: 0 success, 1 test failure, 2 invalid usage or configuration, 3 infrastructure failure.
 */

require __DIR__ . '/integration-support.php';

/** @var list<string> $argv */
$root = dirname(__DIR__);
$options = parseOptions(array_slice($argv, 1));
$matrix = loadMatrix($root);
// Reject an invalid timeout setting before anything is started.
secondsFromEnvironment('PP_UP_TIMEOUT_SECONDS', 1);
secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 1);
$requested = $options['cell'] ?? (getenv('PP_DB_CELL') !== false && getenv('PP_DB_CELL') !== '' ? (string) getenv('PP_DB_CELL') : $matrix['default']);
if (!array_key_exists($requested, $matrix['cells'])) {
    fail(EXIT_USAGE, 'Unknown cell "' . $requested . '". Allowed: ' . implode(', ', array_keys($matrix['cells'])));
}
$cell = $matrix['cells'][$requested];

foreach ([['docker', '--version'], ['docker', 'compose', 'version'], ['docker', 'info']] as $check) {
    [$status] = execute($check);
    if ($status !== 0) {
        fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ' . implode(' ', $check) . ' (Docker with Compose and a running daemon are required for Integration tests)');
    }
}
if (!extension_loaded('pdo_mysql')) {
    fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ext-pdo_mysql is not loaded');
}
if (!is_file($root . '/vendor/bin/phpunit')) {
    fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: run composer install first');
}

$overall = 0;
for ($run = 1; $run <= $options['freshRuns']; $run++) {
    $result = lifecycle($root, $requested, $cell, $options, $run);
    if ($result !== 0) {
        $overall = $result;

        break;
    }
}
echo $overall === 0 ? "INTEGRATION OK cell={$requested}\n" : "INTEGRATION FAILED cell={$requested} status={$overall}\n";
exit($overall);
