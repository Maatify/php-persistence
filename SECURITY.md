# Security Policy

[![Maatify PHP Persistence](https://img.shields.io/badge/Maatify-PHP_Persistence-blue?style=for-the-badge)](https://github.com/Maatify/php-persistence)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Supported Versions

This package is in **Development** and has never published a Stable release or a SemVer Release Candidate. There is currently no supported Stable release line. There is no version of this package to upgrade to and no older line to compare a vulnerability against; every finding applies to the current `main` branch.

## Reporting a Vulnerability

**Do not open a public GitHub issue for a security vulnerability.** Report it privately to:

```text
support@maatify.dev
```

This is the same Maatify licensing and support contact already published in [LICENSE](LICENSE). Include the affected file(s) or capability (Transaction, Pagination, or Ordering), the commit SHA you observed it on, and, where possible, a minimal reproduction.

Because this package is unpublished, there is no installed-consumer exposure window to manage: a confirmed issue is fixed directly on `main` before any release is ever made, and no advisory process for an already-distributed version applies yet. This policy will be updated, as part of the same owner-approved change that publishes a release, once a Stable or Release Candidate version exists.

## Scope

This policy covers the source in this repository (`maatify/php-persistence`). It does not cover the separate, published `maatify/persistence` package; report an issue in that package through its own repository.
