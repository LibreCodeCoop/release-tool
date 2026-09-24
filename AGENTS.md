# AGENTS.md

## Purpose

This repository owns the reusable PHP release engine used by LibreCode/LibreSign release automation.

## Boundaries

- Keep release policy in Domain/Application, not GitHub Actions.
- Domain performs no filesystem, process, GitHub or HTTP I/O.
- Infrastructure owns external I/O and implements ports.
- Do not add a parallel Python release engine.
- Consumer-specific paths and naming belong in versioned consumer configuration.

Authoritative release behavior, public Action contracts, persisted schemas, and architecture are documented and tested in this repository. Organization workflow templates are maintained separately in `LibreCodeCoop/.github` and must remain orchestration-only consumers of this product.

## Generated artifacts

Do not edit generated PHAR/checksum artifacts by hand. composer.lock is committed and must match composer.json.

## Quality gates

Run the Composer scripts for PHPUnit, PHPStan, Psalm, PHPMD, PHPCS, PHP-CS-Fixer, Rector, Deptrac, Infection and PHAR validation before merging.

## SPDX / REUSE

New source and configuration files must comply with repository REUSE/SPDX policy and AGPL-3.0-or-later licensing.

## Git/GitHub safety

Do not force-push protected branches, retarget published tags, expose tokens in logs, or run untrusted pull-request data in privileged contexts.
