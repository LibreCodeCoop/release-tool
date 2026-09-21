# Release Tool

Production-grade PHP CLI and PHAR for planning and automating reproducible software releases.

Release Tool provides one deterministic release engine for local maintainers and GitHub Actions. It keeps release policy in testable PHP code while reusable workflows remain thin orchestration.

It is designed for projects that maintain multiple release lines and need reviewable release plans, changelogs, milestones, draft releases and post-publication verification without duplicating business logic in workflow YAML.

LibreSign is the first consumer. The engine is reusable and does not depend on LibreSign application classes.

## Start here

- Architecture: `docs/architecture.md`
- Testing conventions: `docs/testing.md`
- CLI help: `php bin/release-tool --help`
- Release automation architecture: LibreCodeCoop/github-workflows#70
- Security policy: https://github.com/LibreCodeCoop/.github/blob/main/SECURITY.md
- Contributing guidance: `AGENTS.md`
