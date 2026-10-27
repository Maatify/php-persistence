# Contract Matrix — legacy `v1.4.0`, legacy `main`, new package

Status: design contract for WU-4 and later. Nothing here is implemented, and no row claims verified equivalence. Behavior is in [BEHAVIOR_CONTRACT.md](./BEHAVIOR_CONTRACT.md); decisions are in [DECISIONS_INDEX.md](../decisions/DECISIONS_INDEX.md).

## 1. Sources and how to reproduce

| Label | Reference | Observed on 2026-10-27 |
|---|---|---|
| **A** — legacy published | `Maatify/persistence` tag `v1.4.0` → commit `fc1459b49fe5af5ead17814eeb3ada7a3fa81791`, tree `b477b06d23fc2101581513ca97c0948259079b67` | 21 files under `src/` |
| **B** — legacy unreleased `main` | `1f6a7aafe601090b7e55f60d83794364afdefe67`, tree `c1e25e7653a3173c3339a269b1ad23b26f335b9d` | 26 files under `src/` |
| **C** — new package | this repository | no source yet; the column is the intended contract |

```bash
git ls-tree -r --name-only v1.4.0 -- src                 # A: 21 files
git ls-tree -r --name-only 1f6a7aa -- src                # B: 26 files
git diff --name-status v1.4.0 1f6a7aa -- src             # 5 added, 5 modified
git grep -l '@deprecated' 1f6a7aa -- src                 # exactly 3 files
```

Paths in columns A and B are relative to `src/` of the legacy repository. Both A and B are read-only references. The rows below were derived from the Issue #1 study ([3/6](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370377601)) and the file lists above; signature-level claims must be re-verified against the pinned SHA by the work unit that ports the type.

## 2. Classification terms

| Term | Meaning |
|---|---|
| RELOCATE | Same short name and intended signature; new fully-qualified name. This is not import compatibility. |
| RENAME | Different short name, same role. |
| REDESIGN | Short name kept or reused, implementation is the newer generation. |
| OMIT | Not carried. |
| NEW | Exists in B but not in A. |

Counts over the 21 types of A: RELOCATE 16, RENAME 2, REDESIGN 2, OMIT 1. No type keeps its fully-qualified name. Namespace root of C is `Maatify\PhpPersistence\` (written `P\` below), per [DEC-004](../decisions/DEC-004-package-identity-namespace-and-exception-marker.md).

Column C paths are proposals until [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md) is decided. Folders `Config/`, `Criteria/`, `DTO/`, `Enum/`, `ValueObject/` apply the responsibility vocabulary of the pinned Package Building Standard and are reviewed with this document.

## 3. Type matrix

### 3.1 Exceptions

| ID | A (`v1.4.0`) | B (`main`) | C (new) | Class. | Notes |
|---|---|---|---|---|---|
| T-01 | `Exception/PersistenceException` (interface, extends `Throwable`) | same | `P\Exception\PhpPersistenceExceptionInterface` | RENAME | Marker naming per [DEC-004](../decisions/DEC-004-package-identity-namespace-and-exception-marker.md). |
| T-02 | `Exception/InvalidOrderingConfigurationException` | same | `P\Ordering\Exception\InvalidOrderingConfigurationException` | RELOCATE | |
| T-03 | `Exception/InvalidOrderingOperationException` | same, docblock extended | `P\Ordering\Exception\InvalidOrderingOperationException` | RELOCATE | Thrown by `getNextPositionForUpdate` outside a transaction (B only). |
| T-04 | `Exception/InvalidPaginationConfigurationException` | same | `P\Pagination\Exception\InvalidPaginationConfigurationException` | RELOCATE | |
| T-05 | `Exception/InvalidPaginationQueryException` | same | `P\Pagination\Exception\InvalidPaginationQueryException` | RELOCATE | |
| T-06 | `Exception/PaginationExecutionException` | same | `P\Pagination\Exception\PaginationExecutionException` | RELOCATE | |
| T-07 | `Exception/TransactionExecutionException` | same | `P\Transaction\Exception\TransactionExecutionException` | RELOCATE | |
| T-08 | — | `Exception/InvalidTransactionConfigurationException` | `P\Transaction\Exception\InvalidTransactionConfigurationException` | NEW | Invalid retry limit. |
| T-09 | `Exception/OrderingTransactionException` (`@deprecated`) | same | — | OMIT | [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md). |

### 3.2 Ordering

| ID | A | B | C (new) | Class. | Notes |
|---|---|---|---|---|---|
| T-10 | `Pdo/Ordering/ScopedOrderingConfig` | same | `P\Ordering\Config\ScopedOrderingConfig` | RELOCATE | Validates and quotes identifiers. |
| T-11 | `Pdo/Ordering/ScopedOrderingManager` | same; adds `getNextPositionForUpdate`; changed `moveWithinScope`; uses deprecated runner | `P\Ordering\Repository\ScopedOrderingManager` | RELOCATE, with internal rewiring to the Gen2 runner | Behavior deltas B-02 to B-05 in [BEHAVIOR_CONTRACT.md](./BEHAVIOR_CONTRACT.md). |
| T-12 | — | `Pdo/Ordering/Internal/OperationSavepoint` (`@internal`) | merged into the `@internal` savepoint helper owned by Transaction | REDESIGN | Proposal in [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). |

### 3.3 Pagination

| ID | A | B | C (new) | Class. | Notes |
|---|---|---|---|---|---|
| T-13 | `Pdo/Pagination/PageRequest` | same | `P\Pagination\Criteria\PageRequest` | RELOCATE | Name kept ([DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md) item 5). |
| T-14 | `Pdo/Pagination/PageResult` | same | `P\Pagination\DTO\PageResultDTO` | RENAME | Array and JSON shape unchanged (B-09). |
| T-15 | `Pdo/Pagination/PaginationConfig` | same | `P\Pagination\Config\PaginationConfig` | RELOCATE | |
| T-16 | `Pdo/Pagination/PdoPaginationQueryDescriptor` | same | `P\Pagination\Criteria\PdoPaginationQueryDescriptor` | RELOCATE | |
| T-17 | `Pdo/Pagination/PdoPaginator` | same plus one comment line | `P\Pagination\Repository\PdoPaginator` | RELOCATE | Returns `PageResultDTO`. |
| T-18 | `Pdo/Pagination/SortDirectionEnum` | same | `P\Pagination\Enum\SortDirectionEnum` | RELOCATE | |
| T-19 | `Pdo/Pagination/SortWhitelist` | same | `P\Pagination\ValueObject\SortWhitelist` | RELOCATE | |

### 3.4 Transaction

| ID | A | B | C (new) | Class. | Notes |
|---|---|---|---|---|---|
| T-20 | `Pdo/Transaction/TransactionRunnerInterface` | same | `P\Transaction\Repository\TransactionRunnerInterface` | RELOCATE | Interface sits with the responsibility it describes. |
| T-21 | `Pdo/Transaction/SavepointTransactionRunnerInterface` | same | `P\Transaction\Repository\SavepointTransactionRunnerInterface` | RELOCATE | |
| T-22 | `Pdo/Transaction/PdoTransactionRunner` (Gen1) | `@deprecated`, Gen1 kept | `P\Transaction\Repository\PdoTransactionRunner`, constructor `(PDO $pdo, int $maxDeadlockRetries = 0)`, Gen2 behavior | REDESIGN | Derived from B `PdoDeadlockRetryTransactionRunner`. |
| T-23 | `Pdo/Transaction/PdoSavepointTransactionRunner` (Gen1) | `@deprecated`, Gen1 kept | `P\Transaction\Repository\PdoSavepointTransactionRunner`, Gen2 behavior | REDESIGN | Derived from B `PdoDeadlockRetrySavepointTransactionRunner`. |
| T-24 | — | `Pdo/Transaction/PdoDeadlockRetryTransactionRunner` | merged into T-22; name not carried | NEW in B, name OMIT | |
| T-25 | — | `Pdo/Transaction/PdoDeadlockRetrySavepointTransactionRunner` | merged into T-23; name not carried | NEW in B, name OMIT | |
| T-26 | — | `Pdo/Transaction/Internal/OwnedTransactionExecutor` (`@internal`) | `@internal` class in Transaction, no `Internal/` folder | RELOCATE | |

## 4. Totals

| | A | B | C (proposed) |
|---|---|---|---|
| Public types | 21 | 24 | 21 public + 2 `@internal` |
| `@internal` types | 0 | 2 | 2 (executor, savepoint helper) |
| `@deprecated` types | 1 (`OrderingTransactionException`, verified with `git grep` at `v1.4.0`) | 3 (verified) | 0 |

B has 26 files: 24 public plus 2 `@internal`. Of the 24 public types in B, 3 are deprecated and not carried as such ([DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md)); the two Gen1 runner names are reused with Gen2 behavior. C keeps 21 public types: the 21 of A, minus the omitted exception, plus `InvalidTransactionConfigurationException`, and with the four runner classes of B (two Gen1, two `PdoDeadlockRetry*`) collapsed to the two runner names of A (T-22 to T-25).

## 5. Consumer impact (informational)

| Change | Effect on a consumer | Migration |
|---|---|---|
| Package name | New `require` line | Replace; both can stay installed ([DEC-005](../decisions/DEC-005-coexistence-with-legacy-package.md)) |
| Every FQCN | New `use` statements | Mechanical, 1:1 by section 3 |
| `PageResult` → `PageResultDTO` | Type hints and `instanceof` | Rename; array and JSON output unchanged |
| `PersistenceException` → `PhpPersistenceExceptionInterface` | `catch` clauses | Rename; whether consumers catch the old marker was not surveyed (unknown) |
| Runner behavior | Fail-closed when `beginTransaction()` or `commit()` returns `false`; optional deadlock retry (default 0) | Read B-10 to B-12 |
| Ordering `moveWithinScope` | Outcome changes in caller-owned transactions (B-03, B-04), originating only in unreleased legacy `main` | Read the behavior contract |
| `OrderingTransactionException` | Removed; the legacy code does not throw it | Delete `catch` clauses |

Consumer migration itself needs separate authorization ([DEC-006](../decisions/DEC-006-successor-intent-and-standards-ownership-boundary.md)).
