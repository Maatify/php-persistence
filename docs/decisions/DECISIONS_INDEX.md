# Decisions Index

Current-state discovery surface for durable decisions of this repository. It is not an ADR and does not copy decision rationale. Decision IDs are repository-local (`DEC-NNN`) and are unrelated to central decision IDs of `Maatify/php-engineering-standards`. Statuses follow the Decision Governance status model (`PROPOSED`, `ACTIVE`, `SUPERSEDED`, `REJECTED`, `DEFERRED`).

| Decision ID | Title | Status | Scope / Concern | Decision Record | Canonical Contract / Current Owner | Supersedes | Superseded By |
|---|---|---|---|---|---|---|---|
| `DEC-001` | Initial default-branch bootstrap of the empty repository | `ACTIVE` | One-time creation of `main` by direct initial commit; contents and authorized Git operations. | [DEC-001-initial-repository-bootstrap.md](./DEC-001-initial-repository-bootstrap.md) | This record (the central standards define no empty-repository procedure). | — | — |
| `DEC-002` | Proprietary licensing | `ACTIVE` | Repository license: Maatify Proprietary License, not MIT. | [DEC-002-proprietary-licensing.md](./DEC-002-proprietary-licensing.md) | `LICENSE` | — | — |
| `DEC-003` | Selective pinned standards adoption at an exact upstream commit | `ACTIVE` | Pinned engineering standards snapshot `ebcf55b…` (Owner decision M-5) and adoption scope. | [DEC-003-pinned-standards-adoption.md](./DEC-003-pinned-standards-adoption.md) | [STANDARDS_MANIFEST.md](../php-engineering-standards/STANDARDS_MANIFEST.md) | — | — |
