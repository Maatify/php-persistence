# DEC-012 — Mandatory PDO MySQL driver

- **Decision ID:** `DEC-012`
- **Title:** Mandatory PDO MySQL driver
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Technical Lead` — engineer-resolved choice recorded in [Issue #8](https://github.com/Maatify/php-persistence/issues/8) ("Engineer-resolved choice: mandatory PDO MySQL driver"), bounded by the Owner-approved MySQL and MariaDB targets of [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md). It adds no database backend.
- **Scope / Concern:** Which PHP extensions the package declares as unconditional runtime requirements in `composer.json`.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** `composer.json` (`require`) for the declaration; the pinned [Composer Package Standard](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md) §15.2 for the rule.

## Context

The package operates on a caller-supplied `PDO` connection to MySQL or MariaDB and relies on their SQL, locking, savepoint and error-code behavior ([DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md), [BEHAVIOR_CONTRACT.md](../architecture/BEHAVIOR_CONTRACT.md)). The WU-3 architecture left the extension declaration open ([ARCHITECTURE.md](../architecture/ARCHITECTURE.md) §10, WU-4). The pinned standard requires every extension directly required by the core runtime or baseline capability to be declared in `require`, and allows an omission only through the Optional Runtime Capability path of §15.4.

## Decision

- `composer.json` declares `"ext-pdo": "*"` and `"ext-pdo_mysql": "*"` in `require`.
- The driver is not moved to `suggest` or `require-dev`, and the optional-capability path of §15.4 is not used: the package has no second backend and no neutral storage contract, and the MySQL/MariaDB dialect is part of the baseline contract, so §15.4.1 eligibility does not apply.
- The same driver serves MySQL and MariaDB; the declaration says nothing about which database versions are supported. Support claims stay governed by [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md).
- `maatify/exceptions` (`^1.0`) is declared in `require` as the exception hierarchy required for package-defined exceptions by the pinned Package Building Standard §2. WU-4 contains only the marker interface, which does not extend it, so the dependency is declared ahead of the first exception class (WU-6). The lowest and latest stable versions were loaded under PHP 8.4 in WU-4 (see [QUALITY_TOOLING.md](../architecture/QUALITY_TOOLING.md)).

## Rationale

An unconditional runtime prerequisite must be visible to Composer so that installation on a host without `pdo_mysql` fails at resolution time instead of at the first query. The decision follows the standard literally and keeps one mandatory driver instead of an unproven optional path.

## Consequences

- A host that uses another PDO driver cannot install the package. Adding a driver would need a new decision and the contract work of §15.4.
- The smoke tests assert the declaration and that the extensions are loaded in the test environment. They open no database connection.
- WU-5 provisions MySQL and MariaDB services for the Integration suite; WU-4 does not.
