# Behavior Contract B-01 to B-16

Status: contract for later work units. No test exists in this repository and none was run for this document. Every "required tests" entry is a future obligation.

Type mapping is in [CONTRACT_MATRIX.md](./CONTRACT_MATRIX.md). Baseline strategy is [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md). Column labels: **A** = legacy `v1.4.0` (`fc1459b`), **B** = legacy unreleased `main` (`1f6a7aa`), **C** = this package.

## 1. Evidence rule

1. A behavior is "equivalent to B" only when a named test in this repository passes on the real database matrix of [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md). Reading legacy code or legacy tests is not evidence.
2. Legacy tests are sources for characterization. Porting them follows the provenance gate in [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md) item 6. A ported assertion is not weakened to make it pass.
3. A row marked "documented delta" must be listed in the release notes of the first release.
4. Every row needs at least one failure-path or negative test, not only the success path.
5. Legacy citations below name the study ([Issue #1, comment 3/6](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370377601)); the work unit that ports a row re-reads the cited legacy file at the then-current SHA.

## 2. Ownership vocabulary

- **Caller-owned transaction:** a transaction already active on the PDO connection when the package method is called. The package never begins, commits or finally rolls back it.
- **Package-owned transaction:** started by the package because none was active. The package commits or rolls it back.
- Savepoints are used only inside a caller-owned transaction, where they limit a failed operation without ending the caller's transaction.

## 3. Rows

### Ordering (WU-8, depends on WU-6)

| ID | Contract | A | B | C | Class. | Required tests (future) |
|---|---|---|---|---|---|---|
| B-01 | `getNextPosition` | `MAX(order)+1`, plain read, no transaction, no lock; soft-deleted rows ignored | same logic | Keep. Document that it is not concurrency-safe and does not escape a `REPEATABLE READ` snapshot. | Keep | Integration: empty scope, populated scope, soft-deleted rows, nullable scope; inside and outside a transaction. |
| B-02 | `getNextPositionForUpdate` | does not exist | requires an active transaction, else `InvalidOrderingOperationException`; locking read repeated until two consecutive reads agree, so at least two lock reads even for an empty scope; wide lock footprint without an index on `(scope, order)` | Keep from B | New vs A | Integration: no-transaction rejection; stale snapshot; empty scope; concurrent partner connection; run per engine and, for MariaDB, with `innodb_snapshot_isolation` both states (DEC-009). |
| B-03 | `moveWithinScope`, clamp | scope lock, then separate unlocked `MAX(order)` read, so the clamp can come from an older snapshot | clamp taken from the same locking read as the scope lock | Keep B | Documented delta (B only) | Integration: stale-snapshot cases in a caller-owned `REPEATABLE READ` transaction, clamp above and below the range. |
| B-04 | `moveWithinScope`, failure inside a caller-owned transaction | shares caller's transaction; on failure after shifts it does not undo its own shifts | lazy savepoint after the scope lock and before the first mutation; undo on exception or `false`; no savepoint for no-op or missing row; throws `TransactionExecutionException` before any mutation if the transaction ended | Keep B | Documented delta (B only) | Integration: failure after partial shift, `false` result, release failure, no-op, missing row, transaction ended by the engine. |
| B-05 | `moveWithinScope`, package-owned path | Gen1 runner: begin, callback, commit; `false` result rolls back and returns `false` | same, still Gen1 | Gen2 runner (B-10) | Delta: begin/commit returning `false` becomes an exception | Integration plus unit with a PDO that returns `false` from begin/commit. |
| B-06 | `moveWithinScope` return values | `true` on success or already in place; `false` if the row is absent | same | Keep | Keep | Unit and Integration for each value. |
| B-07 | `rowExistsInScope` | scope-aware existence check | same | Keep | Keep | Integration: in scope, other scope, soft-deleted. |

### Pagination (WU-7)

| ID | Contract | A | B | C | Class. | Required tests (future) |
|---|---|---|---|---|---|---|
| B-08 | Pagination algorithm | `page` normalized: `null`, non-integer text, text beyond `PHP_INT_MIN`/`PHP_INT_MAX`, or a value below 1 → `1`. `perPage` normalized separately: `null`, non-integer text or text beyond the `PHP_INT_*` range → `PaginationConfig::$defaultPerPage` (default 20); an integer below `minPerPage` → `minPerPage` (default 1); an integer above `maxPerPage` → `maxPerPage` (default 200); otherwise the value itself. Accepted text is an optionally signed run of digits after trimming; sort only from whitelist else default; quoted `ORDER BY` with tie-breaker; two count queries (total, filtered) each exactly one row and one column, negative or oversize rejected; `filtered = 0` returns empty page 1 without a data query; `page > totalPages` returns page 1; `LIMIT`/`OFFSET` bound as integers with reserved parameter names `__pagination_*`; no transaction owned or changed | same (one comment line differs) | Keep literally | Keep | Unit for normalization (table-driven: `null`, empty string, `' 5 '`, `'+5'`, `'-5'`, `'abc'`, `'5.5'`, `PHP_INT_MAX` and one digit more, `PHP_INT_MIN` and one digit more, zero, below `min`, above `max`, with non-default `default`/`min`/`max`) asserting `page` and `perPage` separately, and for descriptor validation; Integration for counts, bounds, sorting, failures; golden SQL contract test; test that the transaction state is unchanged. |
| B-08a | Snapshot consistency | three consecutive statements (total, filtered, data) with no single-snapshot guarantee outside a caller transaction | same | Keep, and state in the Package Reference that the three reads are not one snapshot | Keep, documented | Test documenting behavior with a concurrent writer between statements. |
| B-09 | `PageResultDTO` shape | `PageResult` with validated invariants; `toArray()` and JSON: `data` plus `pagination{page, per_page, total, filtered, total_pages, has_next, has_previous, sort_by, sort_direction}` | same | Rename only; shape unchanged | Rename | Golden array and JSON fixtures taken from B before porting; invariant tests (list, element types, `page >= 1`, `count <= perPage`, `totalPages` formula, empty state, navigation flags). |

### Transaction (WU-6)

| ID | Contract | A | B | C | Class. | Required tests (future) |
|---|---|---|---|---|---|---|
| B-10 | Runner ownership and fail-closed | participates if a transaction is active; else begin, callback, commit; on `Throwable` roll back if active (rollback failure swallowed) and rethrow the original; return values of begin/commit unchecked | Gen2: `begin()` returning `false` throws `TransactionExecutionException` before the callback; `commit()` returning `false` triggers best-effort rollback then `TransactionExecutionException` | Gen2 | Delta from A | Unit with a stubbed PDO (begin false, commit false, rollback failure); Integration for the normal paths. |
| B-11 | Savepoint runner | inside a transaction: `SAVEPOINT maatify_persistence_sp_<32 hex>`, callback, `RELEASE`; callback failure triggers `ROLLBACK TO` and `RELEASE` best effort and rethrow of the original; `RELEASE` failure triggers cleanup, then that failure is thrown | same plus the owned path using Gen2 | Gen2; name format kept ([DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md) item 6) | Keep | Unit and Integration: callback failure, release failure, nested use, name format, outside a transaction. |
| B-12 | Deadlock retry | does not exist | limit in `[0, 3]`, else `InvalidTransactionConfigurationException`; default 0 means no retry; replay only for a package-owned transaction, when the callback threw `PDOException` with SQLSTATE `40001` and driver code `1213`, when attempts remain, and when the driver still reports a transaction and then a `DO 0` probe succeeds and reports none; delays: none, 0 to 10 ms, 0 to 20 ms; never retried: caller-owned transaction, failure of begin or commit, lock wait timeout `1205`, any other error; last attempt rethrows the original | Keep B | New vs A | Integration with a partner connection producing real deadlocks, on every PHP version of the matrix (the legacy suite ran Integration on 8.2 and 8.5 only, so 8.4 is unproven); unit for every non-retry condition. |
| B-13 | Exception policy | external `PDOException` and other `Throwable`s propagate unchanged; rollback only for package-owned transactions; cleanup failure never replaces the original; named exceptions only for configuration, execution-control and input errors | same | Keep and document "propagate versus wrap" | Keep | Reflection test that every package exception implements the marker and extends the `maatify/exceptions` hierarchy; negative test that a rethrown `PDOException` is not an instance of the marker; structural test for exception placement and file names. |

### Cross-cutting

| ID | Contract | A | B | C | Class. | Required tests (future) |
|---|---|---|---|---|---|---|
| B-14 | SQL dialect | backtick quoting, `FOR UPDATE`, `SAVEPOINT`, `DO 0` | same | Same statements; engine behavior differs and is verified per engine ([DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md)) | Engine-conditional | The whole matrix, including MariaDB cells and the `1020` cases of DEC-009. |
| B-15 | Dependencies | `php >=8.2`, `ext-pdo`, `maatify/exceptions ^1.0` | same | `php ^8.4`, `ext-pdo`, `maatify/exceptions ^1.0` (compatibility of the lowest `maatify/exceptions` with PHP 8.4 is unverified); `ext-pdo_mysql` decided in WU-4 | Delta | Lowest and latest dependency jobs on PHP 8.4. |
| B-16 | Archive contents | nearly the whole repository | `.gitattributes` `export-ignore` for raw study results | Minimal `.gitattributes`; mandatory Composer files never excluded | Delta | Release-artifact verification in WU-11. |

## 4. Behavior differences that must reach the release notes

1. `moveWithinScope`: B-03 and B-04 (unpublished in the legacy package).
2. Runners: B-10 fail-closed behavior and B-12 optional retry (default off).
3. New `getNextPositionForUpdate` (B-02).
4. Removal of `OrderingTransactionException`, rename of the marker and of `PageResult`, new FQCNs ([CONTRACT_MATRIX.md](./CONTRACT_MATRIX.md) section 5).

## 5. Open items

- The deadlock-retry replay relies on `pdo_mysql` deriving `PDO::inTransaction()` from the last server reply. It is unproven for PHP 8.4 and later and unproven for MariaDB. Until tested per engine, B-12 is not claimed for MariaDB.
- B-02 and B-03 under MariaDB `innodb_snapshot_isolation=ON` are defined by the proposal in [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md), not by this table.
