# Maatify Engineering Standards Adoption Manifest

## Adoption Record

- **Overall Resolution Status:** `VALID`
- **Adoption Model:** Selective pinned adoption
- **Upstream Repository:** `https://github.com/Maatify/php-engineering-standards.git`
- **Adoption Commit:** `ebcf55b0591db61d18a1407bc3effef0197b388f`
- **Adoption Date:** `2026-10-24`
- **Additional Standards:** None
- **Explicit Exceptions/Overrides:** None
- **Mixed-Commit Adoption:** No
- **Operational Assets:** None adopted. The `Pinned Operational Assets Set` is intentionally absent.

## Pinned Adoption Control Set

All files below are copied byte for byte from the exact Adoption Commit recorded above.

- [Standards Adoption Standard](standards/STANDARDS_ADOPTION_STANDARD_AR.md) — `std-standards-adoption` `5.0.0`
- [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md) — `composer-package` `4.0.0`
- [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md) — `repository-governance` `3.0.0`

No inactive or non-inherited Profile is copied.

## Active Profiles

### Activation 1: `composer-package`

- **Profile Version:** `4.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md)

### Activation 2: `repository-governance`

- **Profile Version:** `3.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md)

## Resolved Applicable Standards Set

These are the final Stage 2 applicable standards for the two activations at `/`. The candidate excluded by its canonical applicability is not listed here.

| Standard ID | Version | Local path | Resolution provenance |
|---|---:|---|---|
| `std-package-building` | `3.0.2` | [PACKAGE_BUILDING_STANDARD.md](standards/packages/PACKAGE_BUILDING_STANDARD.md) | Direct requirement of `composer-package` at `/`; the repository is the root of a standalone Composer package |
| `std-composer-package` | `5.0.0` | [COMPOSER_PACKAGE_STANDARD.md](standards/packages/COMPOSER_PACKAGE_STANDARD.md) | Direct requirement of `composer-package` at `/`; the repository is the standalone package root |
| `std-ci-workflow` | `5.0.0` | [CI_WORKFLOW_STANDARD.md](standards/packages/CI_WORKFLOW_STANDARD.md) | Direct requirement of `composer-package` at `/`; package CI scope |
| `std-library-presentation` | `5.0.0` | [LIBRARY_PRESENTATION_STANDARD.md](standards/packages/LIBRARY_PRESENTATION_STANDARD.md) | Direct requirement of `composer-package` at `/`; standalone reusable package presentation scope |
| `std-testing` | `2.0.0` | [TESTING_STANDARD.md](standards/testing/TESTING_STANDARD.md) | Direct requirement of `composer-package` at `/`; package testing scope |
| `std-php-source-documentation` | `1.0.0` | [PHP_SOURCE_DOCUMENTATION_STANDARD.md](standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md) | Direct requirement of `composer-package` at `/`; the declared package scope is manually maintained PHP source (not yet present, see Resolution Summary) |
| `std-php-coding-style` | `1.0.1` | [PHP_CODING_STYLE_STANDARD.md](standards/php/PHP_CODING_STYLE_STANDARD.md) | Direct requirement of `composer-package` at `/`; the declared package scope is manually maintained PHP code (not yet present, see Resolution Summary) |
| `std-documentation-lifecycle` | `3.1.0` | [DOCUMENTATION_LIFECYCLE_STANDARD_AR.md](standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md) | Direct requirement of both active profiles; the repository owns durable documentation and governance records |
| `std-decision-governance` | `1.0.0` | [DECISION_GOVERNANCE_STANDARD_AR.md](standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) | Direct requirement of `repository-governance` at `/`; Maatify repository governance applies |
| `std-ai-collaboration-workflow` | `12.0.0` | [AI_COLLABORATION_WORKFLOW_AR.md](standards/ai/AI_COLLABORATION_WORKFLOW_AR.md) | Direct requirement of `repository-governance` at `/` |
| `std-github-phase-stack-workflow` | `4.0.0` | [GITHUB_PHASE_STACK_WORKFLOW_AR.md](standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md) | Direct requirement of `repository-governance` at `/` |

## Resolution Summary

Stage 1 resolved the two active Profiles at the pinned Adoption Commit. Neither extends another Profile. `composer-package @ /` contributes nine direct candidates and `repository-governance @ /` contributes four; `std-documentation-lifecycle` is shared, yielding 12 unique candidates. Every candidate reference exists at the pinned Adoption Commit.

| Candidate Standard | Activation(s) | Stage 2 result |
|---|---|---|
| `std-package-building` | `composer-package @ /` | Applicable; standalone Composer package repository |
| `std-composer-package` | `composer-package @ /` | Applicable; standalone reusable Composer library |
| `std-ci-workflow` | `composer-package @ /` | Applicable; reusable Composer package CI scope |
| `std-library-presentation` | `composer-package @ /` | Applicable; standalone reusable package presentation scope |
| `std-testing` | `composer-package @ /` | Applicable; package testing scope |
| `std-external-provider-verification` | `composer-package @ /` | Excluded; its canonical applicability (§1) covers only a Scope that owns a provider-specific contract, and this repository owns none. The database service is repository-provisionable, not an externally controlled provider |
| `std-documentation-lifecycle` | both activations | Applicable; the repository owns durable documentation and decision records |
| `std-php-source-documentation` | `composer-package @ /` | Applicable on the declared package scope; see the note below |
| `std-php-coding-style` | `composer-package @ /` | Applicable on the declared package scope; see the note below |
| `std-ai-collaboration-workflow` | `repository-governance @ /` | Applicable; direct governance requirement |
| `std-github-phase-stack-workflow` | `repository-governance @ /` | Applicable; direct governance requirement |
| `std-decision-governance` | `repository-governance @ /` | Applicable; direct governance requirement |

**Artifact facts used for Stage 2.** The repository is the root of the future standalone package `maatify/php-persistence`, whose declared purpose is direct-PDO ordering, pagination, and transaction utilities for MySQL (Issue #1 architecture study, accepted by the Technical Lead; Issue #3). At this commit the repository contains no PHP source, Composer manifest, tests, or CI; the applicability of the package, SQL-persistence, PHP source documentation, and PHP coding style rules is therefore evaluated on the declared package scope and takes effect for the source that later Work Units add. This Adoption makes no Composer, namespace, runtime, or ownership decision.

**Local reference closure.** The final local set is the three Control Set files and the eleven applicable Standards. No `Reference Support Set` exists. The only relative link in the pinned files that leaves this set is in the `composer-package` Profile manifest and points to the excluded `std-external-provider-verification`; it is a structural Profile reference retained for Stage 1 resolution (Adoption §3.D and §7), not a link from an applicable Standard. The official helper's `reconciliation` and `manifest-validation` results for this commit are recorded in the Adoption pull request.

**Frozen Version Baseline (§5.1.1 and Versioning Policy §3.3).** Every one of the fourteen pinned files is byte-identical to the file pinned by the completed `VALID` Adoption of the public consumers `Maatify/php-product` (`main@756b7db7341490b45604aebd5cfcc6996bb15b29`, Adoption Commit `ebcf55b0591db61d18a1407bc3effef0197b388f`) and `Maatify/php-rate-limiter`. No pinned version label is bound to different content than that frozen snapshot. This evidence is not used as this repository's `VALID`; the status above rests on this repository's own source identity, Stage 1, Stage 2, closure, and helper validation.

**Source identity.** The fourteen files are exact bytes of the Adoption Commit; their Git blob identities are listed in [DEC-003](../decisions/DEC-003-pinned-standards-adoption.md). There is no mixed pinning and no Operational Assets set.

**Resolution status.** Under §16.2, both independent Activation/Scope results are `VALID` with `Exception State: NONE`, and there is no unresolved adoption decision, structural failure, or exception; therefore the aggregate `Resolution Status` is `VALID`.
