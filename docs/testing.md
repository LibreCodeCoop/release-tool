<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Testing

Tests are organized by business behavior, not by coverage targets.

The `bamarni/composer-bin-plugin` isolates the PHPUnit, Infection,
quality-analysis and PHAR-builder dependencies in separate `vendor-bin/<tool>`
directories. Each namespace has its own `composer.json` and committed
`composer.lock`; the root `require-dev` contains only the plugin.
This prevents tools such as Box from constraining the Symfony version of the
release-tool engine.

Install development tooling explicitly:

```bash
composer install
composer bin all install
composer qa
composer infection
composer bin box install
composer build:phar
composer test:phar
```

To update a tool, run `composer bin <tool> update` and commit only that
namespace's lockfile. The `forward-command` option is disabled to keep
normal runtime installs lean. Each CI job installs only the namespace it uses;
Infection also installs the PHPUnit namespace. The published PHAR does not
include development-tool dependencies.

- tests/Unit mirrors deterministic src behavior.
- tests/Integration exercises adapters with temporary repositories/filesystems and fake HTTP transports.
- tests/Acceptance exercises public CLI/PHAR behavior.
- tests/Fixtures stores named reusable fixtures.

Use named PHPUnit data providers for matrices of one business rule, such as version/channel transitions, Conventional Commit parsing, changelog rendering, configuration validation, milestone behavior, artifact validation and exit codes.

Coverage is diagnostic and supports mutation testing. Infection is a quality gate for meaningful domain/application behavior; tests must not be added only to kill mutants.

PHPMD 3 uses the auto-detected `phpmd.yml` configuration and analyzes `src/`
against the same unused-code rules as the previous XML ruleset. Run it with
`composer phpmd`. The Rector quality gate first runs `rector validate-config`
to reject deprecated/invalid configuration, then checks source with
`rector process --dry-run`.

PHPStan 2.3 runs at level 8 to check iterable value types and boundary
contracts without ignoring errors or weakening runtime PHP 8.5 support.

Mocks are reserved for interaction boundaries where the interaction itself matters. Prefer real domain objects, fakes, temporary filesystems and temporary Git repositories.

The Behat prototype required by the release roadmap must compare readability and maintenance cost against PHPUnit acceptance tests before adoption. Do not duplicate scenarios in both tools.
