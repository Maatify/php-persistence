# php-persistence

Direct-PDO ordering, pagination, and transaction utilities, designed to target MySQL and MariaDB (a design target; no database version is verified yet), developed as a separate package from the earlier `maatify/persistence`.

| | |
|---|---|
| **Status** | Development |
| **License** | Proprietary — see [LICENSE](LICENSE) |

This repository is in development. It contains a Composer manifest, quality tooling and the package exception marker interface (`Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`); the ordering, pagination and transaction capabilities are not implemented yet. There is no release. Nothing here is published, on Packagist or otherwise installable, and no usage rights are granted beyond those stated in [LICENSE](LICENSE).

Requirements declared in `composer.json`: PHP `^8.4`, `ext-pdo`, `ext-pdo_mysql` and `maatify/exceptions`. These are declared requirements, not a statement that any database version is supported or verified.

Local checks: `composer test`, `composer analyse`, `composer format:check` (see [docs/architecture/QUALITY_TOOLING.md](docs/architecture/QUALITY_TOOLING.md)).

Architecture: [docs/architecture/ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md). Study and decision tracking: [Issue #1](https://github.com/Maatify/php-persistence/issues/1). Durable decisions: [docs/decisions/DECISIONS_INDEX.md](docs/decisions/DECISIONS_INDEX.md).
