# Release Tool

Production-grade PHP CLI and PHAR for planning and automating reproducible software releases.

Release Tool keeps release policy in testable PHP code while GitHub Actions remain orchestration. It supports multiple release lines, reviewable preparation pull requests, changelogs, milestones, draft releases, and post-publication verification.

LibreSign is the reference consumer; the engine does not depend on LibreSign application code.

## Nextcloud app maintainers

Start with:

1. [Getting started](docs/getting-started.md)
2. [Consumer configuration](docs/consumer-configuration.md)
3. [Release lifecycle](docs/release-lifecycle.md)
4. [GitHub Actions integration](docs/github-actions.md)
5. [GitHub App setup](docs/github-app.md)
6. [Troubleshooting](docs/troubleshooting.md)

Reusable workflow templates and their updater are maintained in [LibreCodeCoop/github-workflows](https://github.com/LibreCodeCoop/github-workflows).

The normal flow has two human gates: merge the generated release preparation PR, then publish the generated GitHub Release draft.

## Project internals

- [Architecture](docs/architecture.md)
- [Testing conventions](docs/testing.md)
- CLI help: `php bin/release-tool --help`
- Security policy: https://github.com/LibreCodeCoop/.github/blob/main/SECURITY.md
- Contributing guidance: `AGENTS.md`
