# Quality Tooling (WU-4)

Status: describes the repository-owned toolchain introduced by WU-4. It records what the local gates check and what they do not. It is not a CI definition and not a support claim; CI, the version matrix and the database services belong to WU-5.

## 1. Toolchain

| Concern | Tool and configuration | Constraint in `composer.json` |
|---|---|---|
| Tests | PHPUnit, `phpunit.xml`, suites `unit` and `regression` | `phpunit/phpunit ^12.5` |
| Static analysis | PHPStan, `phpstan.neon`, level `max`, paths `src` and `tests`, no baseline, no `ignoreErrors` | `phpstan/phpstan ^2.3` |
| Code style | PHP CS Fixer, `.php-cs-fixer.php`, rule set `@PER-CS3x0`, plus `scripts/check-per-cs-3-1.php` | `friendsofphp/php-cs-fixer ^3.95` |
| Archive boundary | `.gitattributes` `export-ignore` for repository-only files | none |

The constraints are caret ranges, not patch pins. Versions actually resolved on the delivery machine are in the pull request evidence.

## 2. Composer scripts

| Script | Meaning |
|---|---|
| `composer analyse` | PHPStan, level `max`, over `src` and `tests` |
| `composer format` | Mutating formatter (`php-cs-fixer fix`) |
| `composer format:check` | Non-mutating formatter check, then the PER-CS 3.1 delta check |
| `composer test` | The complete suite: `unit` and `regression` |
| `composer test:unit` | The `unit` suite |
| `composer test:regression` | The `regression` suite |

There is deliberately no `test:integration`: no Integration suite and no database service lifecycle exist yet. WU-5 owns that orchestration, and the script is added only together with it.

## 3. PER Coding Style 3.1 coverage

The pinned PHP Coding Style Standard names PHP-FIG **PER Coding Style 3.1** as the baseline and requires the combined verification surface to cover all of it. PHP CS Fixer 3.95 offers rule sets up to `@PER-CS3x0` only (checked with `php-cs-fixer list-sets`). The 3.1 changes, taken from the PHP-FIG [migration document to 3.1](https://www.php-fig.org/per/coding-style/meta/migration-3.1/), and how this repository covers each:

| 3.1 change | Coverage |
|---|---|
| `clone` always with parentheses | `scripts/check-per-cs-3-1.php` |
| `switch`/`case` body, terminating statement and multi-line condition rules | Not mechanically verified. The script rejects any `switch`, so the rules cannot be violated until a verifier exists. |
| Pipe operator `|>` spacing and placement | PHP 8.4 cannot parse the operator, so it cannot appear in a file that passes the syntax check. |
| Empty closure written as `{}` | `scripts/check-per-cs-3-1.php` |
| Attributes on anonymous classes | Not mechanically verified. Not used in package code. |
| Enum constants follow the `private` rule | Not mechanically verified. No enum exists yet. |
| Multi-line array opening bracket not alone on its line | `scripts/check-per-cs-3-1.php` (line-based, conservative) |

The check script is itself covered by `tests/Regression/PerCodingStyleDeltaCheckTest.php`. Items marked "Not mechanically verified" are a known gap in the verification surface, not compliance evidence. They close when the formatter implements 3.1 or when a verifier covers them; the rule set moves to `@PER-CS3x1` (or the successor name) then.

## 4. Planning inputs for WU-5 (not a CI matrix)

Local runs on one PHP version are not a substitute for the CI matrix. WU-5 must provide:

- PHP: the oldest supported minor, `8.4`, and the latest released minor allowed by `^8.4`, for Unit and Regression; Integration on both once it exists.
- Dependencies: a latest-compatible resolution and a lowest-supported resolution (`composer update --prefer-lowest --prefer-stable`) on PHP 8.4, running analysis and tests.
- Composer: a dependency-policy audit with a Composer release that supports `config.policy` (2.10 or later, per the CI Workflow Standard §5.1). The delivery machine's Composer is older, so `composer audit` there checks advisories and abandoned packages only; it is not the authoritative gate.
- Database services, matrix cells and pinned images per [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md) and the open items of [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md).
- A required gate job, workflow lint, immutable action pinning, and the whitespace check (`git diff --check`).

## 5. Dependency compatibility checks done in WU-4

`maatify/exceptions` is declared as `^1.0` ([DEC-012](../decisions/DEC-012-mandatory-pdo-mysql-driver.md)). Its published stable versions are `v1.0.0` and `v1.1.0`, and it declares `php ^8.2`. WU-4 resolved the lowest and the latest stable versions with PHP 8.4, loaded every class of the dependency with all errors and deprecations reported, and ran the repository gates against the lowest resolution. The recorded results are in the pull request evidence. This does not replace the WU-5 jobs.
