<?php

declare(strict_types=1);

/**
 * Test-only CLI script: the other side of a real InnoDB deadlock, on its own
 * database connection, reusing the same PHP_PERSISTENCE_IT_* connection
 * settings as the Integration suite (inherited from the parent process).
 *
 * It opens a transaction, makes it heavy (200 inserted rows) so that the
 * server chooses the parent's lighter transaction as the deadlock victim,
 * locks row <second-id> and prints "READY <connection id>". After a line on
 * stdin it requests row <first-id>, which the parent holds, prints
 * "ACQUIRED" once it gets it (after the parent's transaction was rolled back)
 * or "FAILURE <driver code>", and always rolls back, so it leaves no data
 * behind.
 *
 * Usage: php DeadlockPartner.php <table> <first-id> <second-id>
 */

/** @return non-empty-string */
$setting = static function (string $name): string {
    $value = getenv('PHP_PERSISTENCE_IT_' . $name);
    if ($value === false || $value === '') {
        fwrite(STDERR, 'Missing PHP_PERSISTENCE_IT_' . $name . "\n");
        exit(2);
    }

    return $value;
};

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, 'is_string')) : [];
$table = $arguments[1] ?? '';
$firstId = $arguments[2] ?? '';
$secondId = $arguments[3] ?? '';

if ($table === '' || !ctype_digit($firstId) || !ctype_digit($secondId)) {
    fwrite(STDERR, "Usage: DeadlockPartner.php <table> <first-id> <second-id>\n");
    exit(2);
}

$quotedTable = '`' . $table . '`';
$dsn = 'mysql:host=' . $setting('HOST') . ';port=' . $setting('PORT') . ';dbname=' . $setting('DATABASE');
$pdo = new PDO($dsn, $setting('USER'), $setting('PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();

$lockRow = static function (string $id) use ($pdo, $quotedTable): void {
    $statement = $pdo->prepare('SELECT `id` FROM ' . $quotedTable . ' WHERE `id` = :id FOR UPDATE');
    if ($statement === false) {
        throw new RuntimeException('Unable to prepare the partner locking read.');
    }

    $statement->execute(['id' => (int) $id]);
    $statement->fetchAll();
};

try {
    $pdo->exec(
        'INSERT INTO ' . $quotedTable . ' (`payload`)
         WITH RECURSIVE `seq` (`n`) AS (SELECT 1 UNION ALL SELECT `n` + 1 FROM `seq` WHERE `n` < 200)
         SELECT \'partner-heavy\' FROM `seq`',
    );
    $lockRow($secondId);

    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    if ($connectionId === false) {
        throw new RuntimeException('Unable to read the partner connection id.');
    }

    fwrite(STDOUT, 'READY ' . $connectionId->fetchColumn() . "\n");
    fflush(STDOUT);
    fgets(STDIN);

    try {
        $lockRow($firstId);
        fwrite(STDOUT, "ACQUIRED\n");
    } catch (PDOException $exception) {
        $driverCode = $exception->errorInfo[1] ?? null;
        fwrite(STDOUT, 'FAILURE ' . (is_int($driverCode) ? $driverCode : 'unknown') . "\n");
    }

    fflush(STDOUT);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
