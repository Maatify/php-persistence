# PHP Persistence Package Reference

`maatify/php-persistence` — canonical stable public/runtime/behavioral contract.

Status: **Development / Unpublished.** Nothing described here is published, installable from Packagist, or tagged. This document is the single canonical Package Reference required by the pinned [Package Building Standard](docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md) §3. It owns the stable public Runtime API inventory, behavior and exception guarantees, and package boundaries. It supersedes [docs/architecture/CONTRACT_MATRIX.md](docs/architecture/CONTRACT_MATRIX.md) and [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md) as the *current* public-contract surface; those documents remain as dated design/evidence records (legacy mapping and the test evidence recorded per behavior) and are not a second competing contract.

Composer package identity, PHP/extension requirements, dependencies, autoloading, configuration and distribution are owned exclusively by [`composer.json`](composer.json) under the pinned [Composer Package Standard](docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md). This document explains runtime implications of that manifest; it does not restate or override it.

Every claim below is verified directly against the current `src/` tree and the named test files under `tests/Unit/`, `tests/Regression/` and `tests/Integration/`, re-read for this document. It does not copy the WU-3 design tables unchecked.

---

## 1. Purpose and boundaries

The package offers three independent capabilities over a **caller-supplied `PDO` connection** to MySQL or MariaDB:

- **Transaction** — run a callback inside a transaction, or inside a savepoint when the caller already owns one; optional bounded deadlock replay for a package-owned transaction.
- **Pagination** — parameterized, whitelist-sorted, bounded pagination that returns a validated result DTO.
- **Ordering** — next-position allocation and move operations for integer display-order columns, with locking reads and savepoint-protected moves.

### 1.1 What the package does not do (non-goals)

- It does not open, pool, or configure PDO connections. The host creates and owns the `PDO` instance and its attributes (error mode, charset, etc.).
- It does not change PDO attributes, the session isolation level, `autocommit`, or any server/session setting (including `innodb_snapshot_isolation`; see §7.3).
- It is not an ORM, query builder, schema manager, or migration tool. `Pagination\Repository\PdoPaginator` executes SQL text supplied by the caller through `PdoPaginationQueryDescriptor`; it does not generate SQL from a model.
- It owns no table, schema, or domain-owned persisted state of its own. It operates entirely on tables the host application owns and names.
- It contains no HTTP controllers, routes, middleware, permission checks, UI, or export logic.
- It keeps no state between calls: every public method call is self-contained given its PDO connection and arguments.
- It is not a connection factory and does not perform dependency injection wiring; a host wires it directly or through its own container.

### 1.2 Operational Read / Reporting classification

Per the pinned [Package Building Standard §15](docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md), a package is only In Scope for Operational Read / Reporting contracts when it owns persisted domain or operational state of its own (orders, balances, jobs, statuses, and the like). This package owns none: it is a direct-PDO infrastructure mechanism that reads and writes rows in tables the *host* owns, names, and is responsible for interpreting. It allocates ordering positions and paginates host-supplied queries, but it does not own the meaning of those rows.

**Classification: Out of Scope.** No listing, search, status-summary, count, aggregate, or monitoring-read API is provided or required, and none is added merely for template compliance.

### 1.3 Coexistence with `maatify/persistence`

This package is a separate Composer package from the published `maatify/persistence` (root namespace `Maatify\Persistence\`), not a drop-in replacement. Per [DEC-005](docs/decisions/DEC-005-coexistence-with-legacy-package.md):

- Both packages can be installed together in one Composer root; their namespaces do not collide.
- `composer.json` declares no `replace`, `provide`, or `conflict` against `maatify/persistence`, and the package ships no `class_alias`, bridge, or adapter between the two type systems.
- Published legacy `v1.4.0` and this package's unpublished baseline are different code baselines with different fully-qualified names throughout; nothing here claims import compatibility.
- Consumer migration from `maatify/persistence` to this package is explicitly **not** addressed by this document and needs its own separate authorization ([DEC-006](docs/decisions/DEC-006-successor-intent-and-standards-ownership-boundary.md)).

### 1.4 Development / Unpublished state

No version of this package is published, resolvable, or installable through Packagist or any other Composer distribution channel, and no Git tag exists. `composer.json` governs the declared package identity, PHP/extension requirements (`php ^8.4`, `ext-pdo`, `ext-pdo_mysql`), and dependency (`maatify/exceptions ^1.0`); see that file for the authoritative, current declaration.

---

## 2. Source topology

One Composer package, three independent capability folders under `src/`, per [DEC-010](docs/decisions/DEC-010-package-topology-multi-capability.md) and [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). Dependency direction: `Ordering → Transaction`; `Pagination` and `Transaction` do not depend on each other or on `Ordering`.

```text
src/
├── Exception/PhpPersistenceExceptionInterface.php
├── Ordering/
│   ├── Config/ScopedOrderingConfig.php
│   ├── Exception/InvalidOrderingConfigurationException.php
│   ├── Exception/InvalidOrderingOperationException.php
│   └── Repository/ScopedOrderingManager.php
├── Pagination/
│   ├── Config/PaginationConfig.php
│   ├── Criteria/PageRequest.php
│   ├── Criteria/PdoPaginationQueryDescriptor.php
│   ├── DTO/PageResultDTO.php
│   ├── Enum/SortDirectionEnum.php
│   ├── Exception/InvalidPaginationConfigurationException.php
│   ├── Exception/InvalidPaginationQueryException.php
│   ├── Exception/PaginationExecutionException.php
│   ├── Repository/PdoPaginator.php
│   └── ValueObject/SortWhitelist.php
└── Transaction/
    ├── Exception/InvalidTransactionConfigurationException.php
    ├── Exception/TransactionExecutionException.php
    ├── Repository/TransactionRunnerInterface.php
    ├── Repository/SavepointTransactionRunnerInterface.php
    ├── Repository/PdoTransactionRunner.php
    ├── Repository/PdoSavepointTransactionRunner.php
    ├── Repository/OwnedTransactionExecutor.php   (@internal)
    └── Repository/SavepointScope.php             (@internal)
```

Root PSR-4 namespace: `Maatify\PhpPersistence\` → `src/` ([DEC-004](docs/decisions/DEC-004-package-identity-namespace-and-exception-marker.md)). `@internal` classes are loadable (PHP enforces no visibility on a class itself) but are **not** part of the public contract or SemVer; see §4.5.

---

## 3. Public Runtime API inventory

Conventions used below: every listed class is `final`; `readonly` is noted when declared on the class. `$x = v` marks a constructor default. Promoted constructor parameters that are also public properties are written as `public <type> $name`. Every exception class listed implements `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`, directly or through its base class, unless stated otherwise.

### 3.1 Package-wide exception marker

**`Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`** — `interface … extends \Throwable {}`

Marker interface implemented by every exception this package defines. A consumer catches every package-defined failure with one type:

```php
catch (PhpPersistenceExceptionInterface $e) { ... }
```

External throwables such as `\PDOException` are **not** wrapped and do **not** implement this interface (§6).

### 3.2 Transaction capability — `Maatify\PhpPersistence\Transaction\`

#### `Repository\TransactionRunnerInterface`

```php
interface TransactionRunnerInterface
{
    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
```

#### `Repository\SavepointTransactionRunnerInterface`

```php
interface SavepointTransactionRunnerInterface extends TransactionRunnerInterface {}
```

Adds no members; it exists so a consumer can type-hint "a runner that uses a savepoint when participating in a caller-owned transaction" distinctly from a plain runner.

#### `Repository\PdoTransactionRunner` (`final readonly class`, implements `TransactionRunnerInterface`)

```php
public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
```

- `$pdo` is not a public property (the PDO connection is not readable back from the runner).
- `$maxDeadlockRetries` must be in `[0, 3]`; otherwise the constructor throws `InvalidTransactionConfigurationException` (raised while constructing the internal `OwnedTransactionExecutor`). `0` (the default) means no deadlock replay.

```php
/**
 * @template TResult
 * @param callable(): TResult $callback
 * @return TResult
 */
public function run(callable $callback): mixed
```

Behavior: see §5.1.

#### `Repository\PdoSavepointTransactionRunner` (`final readonly class`, implements `SavepointTransactionRunnerInterface`)

```php
public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
```

Same constructor contract as `PdoTransactionRunner` (range `[0, 3]`, same exception).

```php
/**
 * @template TResult
 * @param callable(): TResult $callback
 * @return TResult
 */
public function run(callable $callback): mixed
```

Behavior: see §5.2.

#### `Repository\InvalidTransactionConfigurationException` / `Repository\TransactionExecutionException`

See §6.1.

### 3.3 Pagination capability — `Maatify\PhpPersistence\Pagination\`

#### `Criteria\PageRequest` (`final readonly class`)

```php
public function __construct(
    public int|string|null $page = null,
    public int|string|null $perPage = null,
    public ?string $sortBy = null,
    public ?string $sortDirection = null,
)
```

No validation is performed here; this is the raw, untrusted request shape. `PdoPaginator::paginate()` performs all normalization (§5.3). Pass `$_GET`/request values directly.

#### `Config\PaginationConfig` (`final readonly class`)

```php
public function __construct(
    public SortWhitelist $sortWhitelist,
    public string $defaultSortBy,
    public SortDirectionEnum $defaultSortDirection,
    public string $tieBreakerSortBy,
    public SortDirectionEnum $tieBreakerDirection,
    public int $defaultPerPage = 20,
    public int $minPerPage = 1,
    public int $maxPerPage = 200,
)
```

Throws `InvalidPaginationConfigurationException` when constructed with:

- `$minPerPage < 1`, or `$maxPerPage < $minPerPage`, or `$defaultPerPage` outside `[$minPerPage, $maxPerPage]`;
- `$defaultSortBy` not matching `^[A-Za-z_][A-Za-z0-9_]*$` or not present in `$sortWhitelist`;
- `$tieBreakerSortBy` failing the same check.

#### `ValueObject\SortWhitelist` (`final readonly class`)

```php
/** @param non-empty-array<non-empty-string, non-empty-string> $sorts */
public function __construct(array $sorts)

public function contains(string $key): bool

/** @return non-empty-string */
public function quotedIdentifierFor(string $key): string
```

- The constructor throws `InvalidPaginationConfigurationException` for an empty array, a non-string/empty key, a key failing `^[A-Za-z_][A-Za-z0-9_]*$`, a non-string identifier value, or an identifier path with more than 3 dot-separated segments or a segment failing the identifier pattern.
- Each identifier value is a dotted path (`table.column`, up to 3 segments) that is quoted with backticks per segment at construction time (e.g. `orders.created_at` → `` `orders`.`created_at` ``).
- `quotedIdentifierFor()` throws `InvalidPaginationConfigurationException` for a key not in the whitelist.

#### `Criteria\PdoPaginationQueryDescriptor` (`final readonly class`)

```php
/**
 * @param array<string, string|int|bool|null> $totalParams
 * @param array<string, string|int|bool|null> $filteredCountParams
 * @param array<string, string|int|bool|null> $dataParams
 */
public function __construct(
    public string $totalSql,
    public array $totalParams,
    public string $filteredCountSql,
    public array $filteredCountParams,
    public string $dataSql,
    public array $dataParams,
)
```

Constructor validation throws `InvalidPaginationQueryException` when:

- any of the three SQL strings is blank, contains a literal `;`, or contains the reserved placeholder prefix `:__pagination_`;
- any parameter key is not a string, starts with `:`, fails `^[A-Za-z_][A-Za-z0-9_]*$`, or starts with the reserved prefix `__pagination_`;
- any parameter value is not `string|int|bool|null`.

The three SQL statements are the caller's own text: a count of the whole dataset (`$totalSql`), a count after filters (`$filteredCountSql`), and the unsorted/unlimited data query (`$dataSql`, to which `PdoPaginator` appends `ORDER BY`/`LIMIT`/`OFFSET`). The reserved placeholder names `__pagination_limit` and `__pagination_offset` are owned by `PdoPaginator` (§3.3, `Repository\PdoPaginator`) and must not be used by caller SQL.

#### `Enum\SortDirectionEnum` (`enum … : string`)

```php
enum SortDirectionEnum: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
```

#### `DTO\PageResultDTO` (`final readonly class`, implements `JsonSerializable`, `@template T of array<array-key, mixed>|object`)

```php
/** @param list<T> $data */
public function __construct(
    public array $data,
    public int $page,
    public int $perPage,
    public int $total,
    public int $filtered,
    public int $totalPages,
    public bool $hasNext,
    public bool $hasPrevious,
    public string $sortBy,
    public SortDirectionEnum $sortDirection,
)

public function toArray(): array
public function jsonSerialize(): array    // returns toArray()
```

The constructor throws `PaginationExecutionException` when the arguments are not an internally consistent page description — see §5.3 for the exact invariant checks. `toArray()`/`jsonSerialize()` return:

```text
array{
    data: list<T>,
    pagination: array{
        page: int, per_page: int, total: int, filtered: int, total_pages: int,
        has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC',
    },
}
```

`PageResultDTO` is normally produced by `PdoPaginator::paginate()`, but its constructor is public and may be used directly by a caller that already has the raw numbers (for example when composing a result from a cache).

#### `Repository\PdoPaginator` (`final readonly class`, no declared constructor)

```php
/**
 * @template TItem of array<array-key, mixed>|object
 * @param callable(array<string, mixed>): TItem $mapper
 * @return PageResultDTO<TItem>
 */
public function paginate(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResultDTO
```

Throws `PaginationExecutionException` on any execution failure (statement preparation/execution/binding failure, a count query returning other than exactly one row and one column, a negative or out-of-range count, a data row with zero columns, or a mapper that does not return an array or object). Behavior: see §5.3.

#### Pagination exceptions

`InvalidPaginationConfigurationException`, `InvalidPaginationQueryException`, `PaginationExecutionException` — see §6.2.

### 3.4 Ordering capability — `Maatify\PhpPersistence\Ordering\`

#### `Config\ScopedOrderingConfig` (`final readonly class`)

```php
public function __construct(
    public string $table,
    public ?string $scopeColumn = null,
    public string $idColumn = 'id',
    public string $orderColumn = 'display_order',
    public ?string $deletedAtColumn = 'deleted_at',
    public bool $nullableScope = false,
    public ?string $updatedAtColumn = null,
)

public function quotedTable(): string
public function quotedIdColumn(): string
public function quotedOrderColumn(): string
public function quotedScopeColumn(): ?string
public function quotedDeletedAtColumn(): ?string
public function quotedUpdatedAtColumn(): ?string
```

Throws `InvalidOrderingConfigurationException` when:

- `$table` is not `table` or `schema.table` (identifier characters only: `^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$`);
- `$idColumn`, `$orderColumn`, a non-null `$scopeColumn`, a non-null `$deletedAtColumn`, or a non-null `$updatedAtColumn` fails `^[A-Za-z_][A-Za-z0-9_]*$`;
- `$nullableScope === true` while `$scopeColumn === null` (a nullable scope requires a configured scope column).

All identifier arguments **must be trusted application constants**, never raw user input: they are validated and quoted but are never bound as PDO parameters (table/column names cannot be). Row ids, scope values, and order numbers *are* bound as PDO parameters by `ScopedOrderingManager`. Passing `null` for `$deletedAtColumn` disables soft-delete filtering for that configuration; passing `null` for `$updatedAtColumn` (the default) means `moveWithinScope()` never sets a mutation timestamp.

#### `Repository\ScopedOrderingManager` (`final readonly class`, no declared constructor)

```php
public function getNextPosition(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null,
): int

public function getNextPositionForUpdate(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null,
): int

public function moveWithinScope(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue,
    int $id, int $newOrder, ?string $updatedAtValue = null,
): bool

public function rowExistsInScope(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id,
): bool
```

`$scopeValue` is required (non-null) whenever `$config->scopeColumn` is set, unless `$config->nullableScope` is `true`, in which case `null` means the single NULL scope; it must be `null` when `$config->scopeColumn` is `null` (global ordering). Every method throws `InvalidOrderingOperationException` for inconsistent scope usage. Full behavior, including the MariaDB hazard, is in §5.4.

#### Ordering exceptions

`InvalidOrderingConfigurationException`, `InvalidOrderingOperationException` — see §6.3.

### 3.5 `@internal` types (excluded from the public contract)

These classes are reachable because PHP does not restrict class visibility, but they carry **no SemVer guarantee** and may change or disappear in any release without notice. A consumer must not construct, type-hint, or depend on them.

- **`Transaction\Repository\OwnedTransactionExecutor`** — the shared begin/callback/commit-with-replay mechanism behind both Transaction runners. Exposes `public const MAX_DEADLOCK_RETRIES = 3`, which is the same bound documented publicly through the runners' `$maxDeadlockRetries` constructor parameter (§3.2); this constant is not a separate contract.
- **`Transaction\Repository\SavepointScope`** — the shared `SAVEPOINT`/`RELEASE SAVEPOINT`/`ROLLBACK TO SAVEPOINT` helper consumed by `PdoSavepointTransactionRunner` and by `ScopedOrderingManager::moveWithinScope()` (per [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md)). Savepoint names use the format `maatify_persistence_sp_<32 lowercase hex characters>`.

---

## 4. Ownership vocabulary

These two terms are used precisely and consistently throughout this document and the source code:

- **Caller-owned transaction** — a transaction already active on the `PDO` connection (`$pdo->inTransaction() === true`) when a package method is called. The package never begins, commits, or performs a final rollback of it. Where it needs to limit the blast radius of a failure, it uses a savepoint scoped to its own operation.
- **Package-owned transaction** — begun by the package itself because none was active when a Transaction-runner method, or `ScopedOrderingManager::moveWithinScope()`, was called. The package commits it on success or rolls it back on failure.

---

## 5. Behavior contracts

### 5.1 `PdoTransactionRunner::run()`

```text
Host Input (callback) → run($callback) → caller-owned: participate
                                        → no active transaction: owned begin/callback/commit
                                 → Result: $callback's return value, or the original Throwable
```

- **Active caller-owned transaction:** the callback runs in place (`return $callback();`). No `BEGIN`, `COMMIT`, `ROLLBACK`, retry, or savepoint is issued by this call.
- **No active transaction:** the package begins one, runs the callback, and commits.
  - `beginTransaction()` returning `false` throws `TransactionExecutionException` **before** the callback runs.
  - `commit()` returning `false` triggers a best-effort rollback, then throws `TransactionExecutionException`; the result is never reported as committed.
  - A `Throwable` from the callback triggers a best-effort rollback (if still active) and the **original** `Throwable` is rethrown unchanged; a rollback failure is swallowed and never replaces it.
- **Deadlock replay** (only on the owned path, only when `$maxDeadlockRetries > 0`): replay happens only when *all* of the following hold, otherwise the failure is handled exactly like a non-retrying owned transaction:
  1. the callback (not `BEGIN`/`COMMIT`) threw a `PDOException` whose `errorInfo` is SQLSTATE `40001` with driver code `1213`;
  2. a retry attempt remains (at most 3 total);
  3. the driver still reports the transaction active right after the failure (otherwise the transaction had already ended for another reason and is not replayed);
  4. a no-op probe statement (`DO 0`) then succeeds and the driver reports **no** active transaction afterward (confirming the server rolled back the whole victim transaction).

  Neither check proves transaction identity; keeping the runner-owned transaction intact (never explicitly begun/committed/rolled back by the callback itself) is a precondition of correct replay. Delays before retries 1, 2, 3: none, a uniform 0–10 ms delay, a uniform 0–20 ms delay. Replay re-executes the **whole** callback; side effects outside this PDO connection (emails, HTTP calls, other connections, in-memory state) are **not** undone and would repeat — keep them outside the callback or make them idempotent. After the final attempt, the exact last `PDOException` is rethrown. Nothing else is ever retried: not lock-wait timeout (`1205`), not any other error, not a caller-owned transaction's failure.

### 5.2 `PdoSavepointTransactionRunner::run()`

- **No active transaction:** identical owned-transaction behavior to §5.1 (including optional deadlock replay); no savepoint SQL is issued on this path.
- **Active caller-owned transaction:** opens an operation-local savepoint through `SavepointScope::begin()`. On success, releases it (`RELEASE SAVEPOINT`). On a callback `Throwable`, performs best-effort `ROLLBACK TO SAVEPOINT` then best-effort `RELEASE SAVEPOINT`, and rethrows the **original** callback `Throwable` unchanged — a cleanup failure never replaces it. It never commits, fully rolls back, or retries the caller-owned transaction or the savepoint itself, even on a deadlock. If the driver reports the transaction no longer active immediately after `SAVEPOINT` succeeds (the server already ended the caller's transaction, for example on an earlier swallowed error), `SavepointScope::begin()` throws `TransactionExecutionException` before the callback runs.

### 5.3 `PdoPaginator::paginate()`

```text
Host Input (PageRequest, PdoPaginationQueryDescriptor, PaginationConfig, mapper)
    → paginate(...)
        → normalize page/perPage/sort → two independent count statements
        → data statement (skipped when filtered = 0) → map rows
    → Result: PageResultDTO<TItem>
```

Never begins, commits, or changes a transaction; it runs whatever statements the caller's connection is already in, or not in.

- **`page` normalization:** `null`, non-integer text, text outside the `PHP_INT_MIN..PHP_INT_MAX` range, or a parsed integer below `1` → `1`. Accepted text is an optionally-signed run of decimal digits after trimming.
- **`perPage` normalization** (independent of `page`): `null`, non-integer text, or out-of-range text → `PaginationConfig::$defaultPerPage`; an in-range integer below `$minPerPage` → `$minPerPage`; above `$maxPerPage` → `$maxPerPage`; otherwise the value itself.
- **Sort resolution:** `sortBy` not present, empty after trimming, failing `^[A-Za-z_][A-Za-z0-9_]*$`, or absent from `$config->sortWhitelist` → `$config->defaultSortBy`. `sortDirection` is matched case-insensitively against `ASC`/`DESC`; anything else → `$config->defaultSortDirection`.
- **Counting:** `$query->totalSql` and `$query->filteredCountSql` are each executed independently and must return exactly one row with exactly one column; a negative count, or a non-numeric/out-of-`PHP_INT_MAX`-range value, throws `PaginationExecutionException`.
  - **These two statements, and the data statement, are three separate round trips with no shared-snapshot guarantee unless the caller wraps the call in its own transaction** (under `REPEATABLE READ`, wrapping the whole call in a caller-owned transaction makes all three statements observe the same snapshot; without one, a concurrent write between them can make `total` and `filtered` individually correct but mutually inconsistent). `PdoPaginator` does not open that transaction itself.
- **Empty-result shortcut:** when `filtered === 0`, the method returns immediately with `page = 1`, `data = []`, `totalPages = 0`, `hasNext = hasPrevious = false`, **without running the data query at all**.
- **Page clamp:** when the normalized `page` exceeds the computed `totalPages`, it is reset to `1` (not clamped to the last page).
- **Data query:** `$query->dataSql` is wrapped with an `ORDER BY <resolved sort column> <direction>[, <tie-breaker column> <tie-breaker direction>]` (the tie-breaker clause is omitted when it resolves to the same quoted column as the primary sort) and a `LIMIT`/`OFFSET` bound through the reserved parameter names `__pagination_limit`/`__pagination_offset` as integers. Each fetched row must be a non-empty associative array with string keys; it is passed to `$mapper`, whose return value must be an `array` or `object`.
- **Result:** a `PageResultDTO` with `hasNext = page < totalPages` and `hasPrevious = page > 1 && totalPages > 0`.

### 5.4 `ScopedOrderingManager`

All four methods first validate scope usage (`$scopeValue` consistent with `$config->scopeColumn`/`$config->nullableScope`); `getNextPosition`, `getNextPositionForUpdate`, and `moveWithinScope` throw `InvalidOrderingOperationException` on misuse, while `rowExistsInScope` additionally just returns `false` for `$id <= 0` without throwing (`moveWithinScope` throws `InvalidOrderingOperationException` for `$id <= 0` or `$newOrder <= 0`).

#### `getNextPosition()`

Returns `MAX(orderColumn) + 1` (or `1` for an empty scope) with a **plain, non-locking read**, excluding soft-deleted rows when `deletedAtColumn` is configured. It does not start a transaction and does not lock anything; concurrent inserts into the same scope must be serialized by the caller (for example a lock on a parent row). Under `REPEATABLE READ`, this read uses the transaction's existing consistent-read snapshot — a locking read taken afterward does **not** refresh it, so this value can be stale inside a long-running caller transaction unless no older snapshot exists yet (outside a transaction, under `READ COMMITTED`, or when the caller's own serialization lock was already taken before this read).

#### `getNextPositionForUpdate()`

Requires an **active caller-owned PDO transaction**; throws `InvalidOrderingOperationException` otherwise. Issues a locking read (`SELECT … ORDER BY orderColumn DESC, idColumn DESC LIMIT 1 FOR UPDATE`) and repeats it until two consecutive reads agree, so every call issues at least two locking reads — including for an empty scope. The returned value reflects the latest **committed** state, not the caller's `REPEATABLE READ` snapshot. Limits: an empty scope has no row to lock, so concurrent first inserts into it are not serialized by this method alone; the row scan (and lock) may cover more than the scope's active rows without a suitable `(scope, order)` index; under `REPEATABLE READ` the lock also covers the gap above the last scanned row; the call may fail with a lock-wait-timeout or deadlock `PDOException`, which propagates unchanged — the caller decides whether to retry.

#### `moveWithinScope()`

Never trusts a caller-supplied "current order"; it locks the whole ordering scope (`SELECT … FOR UPDATE`) first and reads the row's current order from inside that same locked read. `$newOrder` is clamped to `max(1, <locked maximum>)`. Runs through `PdoTransactionRunner` (so it owns a transaction when none is active, or participates in the caller's with the behavior of §5.1).

- Returns `true` on a successful move, and `true` (as a no-op, no SQL mutation issued beyond the scope lock and the read) when the row is already at the requested/clamped position.
- Returns `false` when the target row does not exist in the given scope at the moment it is read under the scope lock — **this is not an exception**, and in this common case **no savepoint statement is issued at all**: the absence is detected before any savepoint is opened.
- **Participating in a caller-owned transaction:** only once the row is confirmed to exist *and* its clamped target order actually differs from its current order (the no-op and missing-row cases above never reach this point) does it open an operation-local savepoint (`SavepointScope::begin()`), immediately before the first mutating statement. A `Throwable` from the range-shift or target-write statements triggers `cleanupAfterFailure()` (best-effort `ROLLBACK TO`/`RELEASE`) and the original `Throwable` is rethrown unchanged. In the narrower case where the shifts succeed but the final target-row `UPDATE` itself still affects zero rows (for example a concurrent modification narrows the match after the row was already locked), the open savepoint is undone through `undoOrFail()` before returning `false` — there, `ROLLBACK TO` is **not** best-effort: its own failure is thrown instead of the `false` result; the following `RELEASE SAVEPOINT` is still best effort. The scope lock, the caller's own earlier work, and the caller's own savepoints are left untouched; the caller still owns the surrounding transaction's commit/rollback.
- **Owned path (no active caller transaction):** no savepoint statement is issued at all; a failure rolls back the whole owned transaction via `PdoTransactionRunner`.
- A deadlock (`1213`), the MariaDB `1020` hazard (§7.3), or a lock-wait timeout with `innodb_rollback_on_timeout=ON` can end the **caller's whole transaction** on the server; these surface as the original exception and are never swallowed or retried by this method.

#### `rowExistsInScope()`

A scope-aware existence check (soft-deleted rows excluded when configured); returns `false` for `$id <= 0` rather than throwing.

---

## 6. Exceptions reference

Every package-defined exception listed below is `final`, extends a `maatify/exceptions` base class, and implements `PhpPersistenceExceptionInterface`. None declares its own constructor: construction, message/code/previous handling, and the `ApiAwareExceptionInterface` accessors (`getErrorCode()`, `getCategory()`, `getHttpStatus()`, `isSafe()`, `getMeta()`, `isRetryable()`) are all inherited from `maatify/exceptions ^1.0`, whose public constructor shape is:

```php
__construct(
    string $message = '',
    int $code = 0,
    ?Throwable $previous = null,
    ?ErrorCodeInterface $errorCodeOverride = null,
    ?int $httpStatusOverride = null,
    ?bool $isSafeOverride = null,
    ?bool $isRetryableOverride = null,
    array $meta = [],
    ?ErrorPolicyInterface $policy = null,
    ?EscalationPolicyInterface $escalationPolicy = null,
)
```

That constructor and the resulting accessor API are owned by `maatify/exceptions`, not by this package; see that package's own documentation for details beyond what a consumer needs to catch and read these exceptions.

**External throwables are never wrapped.** `\PDOException` (and any other `Throwable` raised by the driver, the engine, or a caller's own callback) propagates through every runner and every Ordering/Pagination method completely unchanged, and does **not** implement `PhpPersistenceExceptionInterface`. A consumer that wants to catch "every failure, package-defined or not" must catch `\Throwable` and distinguish with `instanceof PhpPersistenceExceptionInterface`.

### 6.1 Transaction

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidTransactionConfigurationException` | `SystemMaatifyException` | A transaction-runner constructor receives `$maxDeadlockRetries` outside `[0, 3]`. |
| `TransactionExecutionException` | `SystemMaatifyException` | A PDO transaction-control statement (`BEGIN`, `COMMIT`, `SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`) returns `false` instead of throwing; or `SavepointScope::begin()` observes that a caller-owned transaction is no longer active right after `SAVEPOINT` succeeded; or `ScopedOrderingManager::moveWithinScope()`'s own equivalent guard fires before its first mutation. |

### 6.2 Pagination

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidPaginationConfigurationException` | `SystemMaatifyException` | Invalid `PaginationConfig` construction; an empty or malformed `SortWhitelist`; `SortWhitelist::quotedIdentifierFor()` called with a key outside the whitelist. |
| `InvalidPaginationQueryException` | `SystemMaatifyException` | Invalid `PdoPaginationQueryDescriptor` construction (blank/`;`-containing/reserved-placeholder SQL, or an invalid parameter key/value). |
| `PaginationExecutionException` | `SystemMaatifyException` | A `PdoPaginator::paginate()` execution failure (see §5.3), or an internally inconsistent `PageResultDTO` construction (see §5.3/§3.3). |

### 6.3 Ordering

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidOrderingConfigurationException` | `SystemMaatifyException` | Invalid `ScopedOrderingConfig` construction (bad table/column identifier, or `nullableScope` without a `scopeColumn`). |
| `InvalidOrderingOperationException` | `ValidationMaatifyException` | Invalid runtime arguments or an unmet precondition on a `ScopedOrderingManager` call: inconsistent scope usage, an invalid row id or target order in `moveWithinScope()`, an updated-at value/column mismatch, or a locking next-position read (`getNextPositionForUpdate()`) requested without an active PDO transaction. |

---

## 7. Concurrency, transaction, and isolation limits

### 7.1 Transaction ownership summary

| Situation | Package behavior |
|---|---|
| No transaction active | Begin, run the callback, commit. On failure, roll back (best effort) and rethrow the original `Throwable`. A `false` from `beginTransaction()`/`commit()` fails closed with `TransactionExecutionException`. |
| Caller's transaction active | Participate. Never begin, commit, or finally roll back it. A savepoint, when used, only limits the blast radius of this operation's own failure. |
| Callback/operation throws | The original `Throwable` is rethrown unchanged. A cleanup (rollback/savepoint-undo) failure never replaces it. |
| Deadlock (`40001`/`1213`) in a package-owned transaction | Replayed only under the exact conditions of §5.1, default off (`$maxDeadlockRetries = 0`). |
| Deadlock or any error in a caller-owned transaction | Never replayed by this package; it propagates to the caller, which decides. |
| Engine ends the transaction on the server (e.g. MariaDB `1020`, §7.3) | The error propagates unchanged. The package never retries the caller's work and never changes a setting to work around it. |

### 7.2 Savepoint ownership

Savepoints are used **only** inside a caller-owned transaction, and only to limit one operation's own failure without ending the caller's transaction. `PdoSavepointTransactionRunner` and `ScopedOrderingManager::moveWithinScope()` share one `@internal` helper, `SavepointScope` (owned by the Transaction capability per [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md)), rather than each maintaining their own savepoint logic. Savepoint names follow `maatify_persistence_sp_<32 lowercase hex characters>` (a 128-bit random suffix), chosen so that this package and `maatify/persistence` can coexist on the same connection without colliding ([DEC-005](docs/decisions/DEC-005-coexistence-with-legacy-package.md) item 4). **A savepoint does not, and cannot, undo the server having already rolled back the whole surrounding transaction** (§7.3); it only ever limits *this operation's* mutation window within a transaction the server still considers active.

### 7.3 MariaDB `innodb_snapshot_isolation` hazard

This is a **documented hazard with evidence**, not an advertised, unconditional support contract. [DEC-009](docs/decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) (the record governing the `1020` contract itself) remains **`PROPOSED`/Owner-deferred**. Only the *test-version matrix* was ratified separately in [DEC-013](docs/decisions/DEC-013-test-only-database-version-boundary.md) (`ACTIVE`); that ratification is about which versions are exercised in CI, not about what error `1020` means or how a consumer should respond to it.

What is verified, by named Integration tests (`tests/Integration/Ordering/ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest.php`, `ScopedOrderingManagerStaleSnapshotTest.php`, `ScopedOrderingManagerMoveStaleSnapshotTest.php`), on real MariaDB cells:

- With `innodb_snapshot_isolation=ON` (the MariaDB 11.8+ default), a locking read inside `getNextPositionForUpdate()` or the scope lock inside `moveWithinScope()`, taken after an older `REPEATABLE READ` snapshot and touching a row changed since that snapshot, fails with the driver's `PDOException` carrying driver code `1020`. It propagates **completely unchanged** — no savepoint is attempted, no retry happens, and no setting is overridden.
- The server may have **already rolled back the caller's whole transaction** when this happens, while `PDO::inTransaction()` can still report `true`. Continuing to use the connection as if the transaction were alive risks later statements running under an unintended implicit/autocommit transaction.
- With the setting `OFF` (several ratified test cells default to `OFF`; see [docs/architecture/CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md) §3), the ordinary "locking read sees the latest committed state" guarantee holds, matching MySQL 8.0/8.4 behavior.
- The package **never** sets `innodb_snapshot_isolation`, the isolation level, or any other connection/session attribute, on any engine.

**Consumer responsibility when this error can occur:** catch it at the layer that owns the transaction (not inside a savepoint — a savepoint does not help, by design) and restart the whole unit of work; or avoid the hazard by taking the caller's own serialization lock before the first non-locking read, by using `READ COMMITTED` for the transaction, or by setting `innodb_snapshot_isolation=OFF` on that connection (which disables lost-update protection for *all* of that connection's statements — a host-level choice this package does not make for the caller).

### 7.4 Deadlock replay bounds

Deadlock replay (§5.1) is **opt-in** (`$maxDeadlockRetries` defaults to `0`), bounded to at most 3 attempts, applies **only** to a package-owned transaction (never a caller-owned one), and only for the specific SQLSTATE `40001` / driver code `1213` signature confirmed to have actually ended the runner-owned transaction. It is not a generic "retry on any database error" mechanism.

---

## 8. Database engine support

Per [docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md](docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md) §1, **"version tested" and "range verified" are different claims.** This document, like the README, makes only the narrower claim that is actually proven today.

- **No supported version range is declared for MySQL or MariaDB.** Both remain design targets ([DEC-008](docs/decisions/DEC-008-database-engine-targets-and-verification-policy.md)).
- The Transaction, Pagination, and Ordering capabilities **themselves** (not just infrastructure-level checks) have passing real-database Integration evidence, including genuine deadlocks, a deterministic concurrent-writer snapshot test (§5.3/B-08a), and real stale-snapshot concurrency, on the following **named, individually tested** versions ([DEC-013](docs/decisions/DEC-013-test-only-database-version-boundary.md)):

  | Engine | Versions tested |
  |---|---|
  | MySQL | `8.0.16`, `8.4.13`, `9.7.4` |
  | MariaDB | `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3` |

- `mysql-26.10.0` (the MySQL Innovation release) is **exploratory only**: it runs only on manual CI dispatch, is never required, and is excluded from every claim above.
- "Version tested" here means exactly that: these named versions pass the capability's own Integration suite. It is **not** a "range verified" claim under DEC-008's endpoint rule, and it does not imply that an untested intermediate or later version behaves identically — in particular, do not infer MariaDB behavior for a version not listed above, since the `innodb_snapshot_isolation` default itself differs between the listed MariaDB versions (§7.3).
- No workaround, retry, or configuration change is implemented, proposed, or implied for the MariaDB `1020` hazard; see §7.3.

---

## 9. Verification evidence

This section exists so that every behavioral claim above can be checked directly rather than taken on faith. It is not a duplicate of [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md); it names the currently-passing tests this reference's claims were checked against while writing it.

| Capability | Reflection/regression evidence (public API shape) | Representative behavior evidence |
|---|---|---|
| Transaction | `tests/Regression/TransactionPublicApiRegressionTest.php`, `tests/Regression/Exception/*ContractTest.php` | `tests/Unit/Transaction/Repository/{PdoTransactionRunnerTest,PdoSavepointTransactionRunnerTest,PdoSavepointTransactionRunnerRetryTest,OwnedTransactionExecutorTest,DeadlockRetryFailClosedTest,SavepointScopeTest}.php`; `tests/Integration/Transaction/{PdoTransactionRunnerIntegrationTest,PdoSavepointTransactionRunnerIntegrationTest,DeadlockRetryIntegrationTest}.php` (genuine deadlocks via a real partner process) |
| Pagination | `tests/Regression/Pagination/{PaginationPublicApiRegressionTest,PaginationSqlContractRegressionTest}.php` | `tests/Unit/Pagination/*.php`; `tests/Integration/Pagination/{PdoPaginatorQuerySemanticsTest,PdoPaginatorFailureContractTest,PdoPaginatorSnapshotConsistencyTest,PdoPaginatorTransactionTest}.php` (deterministic concurrent-writer snapshot test) |
| Ordering | `tests/Regression/Ordering/OrderingPublicApiRegressionTest.php` | `tests/Unit/Ordering/{Config/ScopedOrderingConfigTest,Repository/ScopedOrderingManagerSavepointWiringTest}.php`; `tests/Integration/Ordering/{ScopedOrderingManagerReadOperationsTest,ScopedOrderingManagerStaleSnapshotTest,ScopedOrderingManagerMoveStaleSnapshotTest,ScopedOrderingManagerLazySavepointTest,ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest,ScopedOrderingManagerGlobalMoveTest,ScopedOrderingManagerScopedMoveTest,ScopedOrderingManagerSoftDeleteMoveTest,ScopedOrderingManagerTransactionTest,ScopedOrderingManagerRollbackTest}.php` (real stale-snapshot concurrency and a genuine deadlock while acquiring the scope lock) |

A detailed, dated per-behavior mapping (the B-01 to B-16 rows, each with its legacy-vs-current delta) remains available in [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md) as a framed historical/evidence record; this section and §§5–8 above are the current normative restatement for a consumer.

---

## 10. Related documentation

- [README.md](README.md) — quick overview, installation/access status, requirements.
- [docs/guides/USAGE_GUIDE.md](docs/guides/USAGE_GUIDE.md) — capability map, walkthroughs, and the boundaries of each capability before its examples.
- [examples/](examples/) — runnable, Public-API-only examples for all three capabilities.
- [llms.txt](llms.txt) — AI consumer navigation map.
- [docs/architecture/ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md), [CONTRACT_MATRIX.md](docs/architecture/CONTRACT_MATRIX.md), [BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md), [DATABASE_SUPPORT_AND_TEST_POLICY.md](docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md), [CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md) — design, legacy-mapping, and test-matrix detail, dated and framed as historical/evidence records rather than a second current contract.
- [docs/decisions/DECISIONS_INDEX.md](docs/decisions/DECISIONS_INDEX.md) — durable decisions this reference applies.
