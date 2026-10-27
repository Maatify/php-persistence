# DEC-010 — Package topology: one package, three capabilities

- **Decision ID:** `DEC-010`
- **Title:** Package topology: one package, three capabilities
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Technical Lead` within the WU-3 scope authorized by the Project Owner ("ماشي يلا", 2026-10-27) — the single-package, three-capability scope is stated in [Issue #6](https://github.com/Maatify/php-persistence/issues/6) (Allowed content, item 2) and follows alternative B of the study accepted in [Issue #1](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6371517815). The Lead confirms or rejects this status at the independent review of the WU-3 pull request.
- **Scope / Concern:** How many Composer packages exist, which capabilities they contain, and which top-level source folders are forbidden.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** The topology rules are owned by the pinned [Package Building Standard](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md). The resulting layout is [ARCHITECTURE.md](../architecture/ARCHITECTURE.md) §4.

## Context

The study compared staying inside the legacy package, a new package with a legacy-colliding root, one new package with three capabilities, three separate packages, and a new package with compatibility aliases ([Issue #1, study 4/6 §4.2](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370379269)). The legacy `src/Pdo/...` layout is a technology-rooted layout that the pinned standard forbids for new code.

## Decision

1. One Composer package, `maatify/php-persistence`, with `Source Topology: Multi Capability` and exactly these capabilities: `Ordering`, `Pagination`, `Transaction`.
2. Dependency direction is `Ordering → Transaction` only. `Pagination` and `Transaction` do not depend on each other or on `Ordering`. No shared folder such as `Common/` is created.
3. No technology-rooted folder (`src/Pdo/`) and no `Internal/`, `Shared/`, `Helpers/` or `Utils/` folder. Types meant only for package use are marked `@internal` in PHPDoc and live in the responsibility folder that owns them; the Package Reference states that they are outside the public contract.
4. Exceptions specific to one capability live under `{Capability}/Exception/`. Only the package-wide marker lives in `src/Exception/`.
5. Empty or ceremonial folders are not created.

## Rationale

A single package keeps one release, one CI and one Package Reference. Three packages would triple verification cost while `Ordering` already depends on `Transaction`.

## Consequences

- The exact responsibility folders inside each capability are not closed here; they are proposed in [DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md).
- The Package Reference (WU-9) must declare the topology literally as required by the pinned standard.
