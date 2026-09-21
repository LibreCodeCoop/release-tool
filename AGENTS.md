# AGENTS.md

## Purpose

This repository owns the reusable PHP release engine used by LibreCode/LibreSign release automation.

## Boundaries

- Keep release policy in Domain/Application, not GitHub Actions.
- Domain performs no filesystem, process, GitHub or HTTP I/O.
- Infrastructure owns external I/O and implements ports.
- Do not add a parallel Python release engine.
- Consumer-specific paths and naming belong in versioned consumer configuration.

Authoritative cross-repository contracts are tracked in LibreCodeCoop/github-workflows#70, #78 and #82 until fully represented here.

## Generated artifacts

Do not edit generated PHAR/checksum artifacts by hand. composer.lock is committed and must match composer.json.

## Quality gates

Run the Composer scripts for PHPUnit, PHPStan, Psalm, PHPMD, PHPCS, PHP-CS-Fixer, Rector, Deptrac, Infection and PHAR validation before merging.

## SPDX / REUSE

New source and configuration files must comply with repository REUSE/SPDX policy and AGPL-3.0-or-later licensing.

## Git/GitHub safety

Do not force-push protected branches, retarget published tags, expose tokens in logs, or run untrusted pull-request data in privileged contexts.
