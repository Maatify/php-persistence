# DEC-001 — Initial default-branch bootstrap of the empty repository

- **Decision ID:** `DEC-001`
- **Title:** Initial default-branch bootstrap of the empty repository
- **Status:** `ACTIVE`
- **Date:** `2026-10-23`
- **Decision Authority:** `Project Owner` — M-7 authorized on 2026-10-23: one root commit and one non-force push to `main` containing exactly the six paths listed below, after independent Technical Lead review of the exact final content and Git tree. Authorization record: [Issue #1, comment 6373127782](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6373127782).
- **Scope / Concern:** Creation of the first commit and the default branch `main` of `Maatify/php-persistence`.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** This record. Central Phase Stack, DEC-017 and AI Collaboration define no procedure for a repository without a base branch.

## Context

The repository was created empty: no commit, no branch, no pull request. A pull request needs an existing base branch, so the very first commit on the default branch cannot be reviewed through a pull request. The central rule that requires a pull request applies to published non-default governed work branches and does not require one for the default branch itself. No central rule is therefore deviated from; the standards are silent on this single step.

Architecture, alternatives and the sequencing of work units are recorded in [Issue #1](https://github.com/Maatify/php-persistence/issues/1), including the Technical Lead procedure statement [comment 6371923025](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6371923025).

## Decision

1. The root commit of `main` contains exactly six files: `LICENSE`, `.gitignore`, `README.md`, `docs/decisions/DECISIONS_INDEX.md`, this record, and `docs/decisions/DEC-002-proprietary-licensing.md`. It contains no source code, no Composer manifest, no workflow, no `AGENTS.md` and no `STANDARDS_MANIFEST.md`.
2. A named Executor prepares the files, stages explicit paths only, verifies the staged content and captures the candidate Git tree. The Technical Lead independently reviews the staged content and the candidate tree. No separate Owner review of local files is required.
3. After the Lead issues a PASS for the exact final tree, the Executor (the one named in the authorization record) makes one ordinary commit and one non-force push creating `main`. No amend, no force push, no tag, no pull request.
4. The Technical Lead then reconciles the remote commit and tree against the accepted tree and verifies branch protection, which the Owner configures.
5. Every later change runs on a non-default branch with a linked pull request, review, CI where applicable, and separate Owner merge authorization.

## Rationale

A bounded, reviewed, single-commit bootstrap is the only way to create a default branch. Recording the procedure in the same tree it creates satisfies the before-or-atomic persistence rule of Decision Governance. Corrections after the push are made by pull request; recreating the repository is not a rollback option because it would delete Issue #1 and its history.

## Consequences

- The bootstrap is a one-time event and creates no standing exception.
- Work Unit WU-2 (standards adoption) is not part of this decision and is blocked by central issues #179 and #180.
