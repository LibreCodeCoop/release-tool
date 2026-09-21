<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Testing

Tests are organized by business behavior, not by coverage targets.

- tests/Unit mirrors deterministic src behavior.
- tests/Integration exercises adapters with temporary repositories/filesystems and fake HTTP transports.
- tests/Acceptance exercises public CLI/PHAR behavior.
- tests/Fixtures stores named reusable fixtures.

Use named PHPUnit data providers for matrices of one business rule, such as version/channel transitions, Conventional Commit parsing, changelog rendering, configuration validation, milestone behavior, artifact validation and exit codes.

Coverage is diagnostic and supports mutation testing. Infection is a quality gate for meaningful domain/application behavior; tests must not be added only to kill mutants.

Mocks are reserved for interaction boundaries where the interaction itself matters. Prefer real domain objects, fakes, temporary filesystems and temporary Git repositories.

The Behat prototype required by the release roadmap must compare readability and maintenance cost against PHPUnit acceptance tests before adoption. Do not duplicate scenarios in both tools.
