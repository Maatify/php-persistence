# DEC-009 — Proposed database version matrix and MariaDB snapshot-isolation handling

- **Decision ID:** `DEC-009`
- **Title:** Proposed database version matrix and MariaDB snapshot-isolation handling
- **Status:** `PROPOSED`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — not yet ratified. The matrix is an evidence-backed proposal from [Issue #5, comment 6441236923](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6441236923) and [Issue #6](https://github.com/Maatify/php-persistence/issues/6). This record is not implementation authority.
- **Scope / Concern:** Exact version endpoints for MySQL and MariaDB, the MySQL Innovation track, and the contract for MariaDB error `1020` with `innodb_snapshot_isolation`.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** [DATABASE_SUPPORT_AND_TEST_POLICY.md](../architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md) describes the proposal and the required tests. Policy that is already ratified is in [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md).

## Context

Version lines were observed on 2026-10-24 and 2026-10-27 from the cited announcements and are not tested in this repository: [Oracle, October 2026 releases](https://blogs.oracle.com/mysql/mysql-october-2026-ga-releases-now-available) and [MariaDB Foundation, Q3 2026 maintenance releases](https://mariadb.org/mariadb-server-12-3-11-8-11-4-and-10-11-q3-2026-maintenance-releases-and-goodbye-10-6/). The legacy study of Issue #47 ([REPORT.md at `1f6a7aa`](https://github.com/Maatify/persistence/blob/1f6a7aafe601090b7e55f60d83794364afdefe67/docs/studies/issue-47-mariadb-snapshot/REPORT.md)) measured the following on real engines with the legacy code: with `innodb_snapshot_isolation=ON`, a locking read or an update after an older `REPEATABLE READ` snapshot raises error `1020` when it touches a row changed after that snapshot; the server then rolls back the caller's whole transaction while `PDO::inTransaction()` still reports it as active; behavior after the error differs between 10.11.18, 11.4.7 and 11.8.9; the setting, not the version, decides. These measurements were made in the legacy repository, on the legacy code, and are evidence for the hazard, not proof for the new package.

## Proposal (not ratified)

**Proposed version matrix**

| Engine | Proposed oldest endpoint | Intermediate rows | Proposed newest endpoint | Status |
|---|---|---|---|---|
| MySQL | `8.0.16` (ratified in DEC-008) | `8.4.13` LTS | `9.7.4` LTS | Newest endpoint and upper-bound policy `PROPOSED` |
| MySQL Innovation | — | — | `26.10.0` as an optional evaluation row | Not a support promise; not a substitute for the LTS endpoint |
| MariaDB | `10.11` LTS (`10.11.19` observed) | `11.4.13`, `11.8.9` | `12.3` LTS (`12.3.3` observed) | All bounds `PROPOSED` and `UNVERIFIED` |

Exact image tags or digests are fixed when the Compose definition is written (WU-5), not here.

**Proposed MariaDB `1020` contract**

1. Positive and negative tests run with `innodb_snapshot_isolation` both `ON` and `OFF` at each MariaDB endpoint.
2. When the engine raises `1020`, the error reaches the caller unchanged. It is not swallowed, translated into success, or retried.
3. Documentation states that after `1020` the caller's transaction may already be rolled back by the server even though `PDO::inTransaction()` reports it as active.
4. The package does not change `innodb_snapshot_isolation`, the isolation level, or any connection attribute.
5. If an honest and safe contract cannot be demonstrated for caller-owned transactions, MariaDB support is not advertised unconditionally; the claim is narrowed or withheld.

## Open decisions and what they block

| Open decision | Must be settled before |
|---|---|
| MySQL newest endpoint and whether a fixed upper cap exists | WU-5 (CI matrix) and any published MySQL range |
| MySQL Innovation track: in or out of the declared range | WU-5, and any published statement about Innovation versions |
| MariaDB oldest and newest endpoints | WU-5 for MariaDB cells, and any MariaDB claim |
| Whether the safe `1020` contract in item 5 is achievable | WU-8 (Ordering) acceptance for MariaDB, and any MariaDB claim |

## Consequences

- Nothing in this record may be cited as a supported range. The README and Package Reference must not repeat these versions as support claims.
- When the Owner ratifies or amends any row, a new `ACTIVE` record supersedes this one in the same change as the index update.
