# Release Tool

Production-grade PHP CLI and PHAR for planning and automating reproducible software releases.

Release Tool provides one deterministic release engine for local maintainers and GitHub Actions. It keeps release policy in testable PHP code while reusable workflows remain thin orchestration.

It is designed for projects that maintain multiple release lines and need reviewable release plans, changelogs, milestones, draft releases and post-publication verification without duplicating business logic in workflow YAML.

LibreSign is the first consumer. The engine does not depend on LibreSign application classes.

## Why this exists

Many Nextcloud apps document releases as a maintainer checklist: decide the version, verify backports, update the changelog, create or rename milestones, create tags and releases, publish artifacts, and verify the App Store.

Release Tool turns the repeatable parts of that checklist into a deterministic contract:

1. build a read-only release plan;
2. create a reviewable preparation pull request;
3. revalidate the merged state;
4. synchronize release history;
5. transition milestones;
6. create a GitHub Release draft;
7. verify publication.

Humans still control the important gates: **merge the generated preparation PR** and **publish the generated release draft**.

## For Nextcloud app maintainers

If you maintain another Nextcloud app, start with:

- [Adopting Release Tool for a Nextcloud app](docs/getting-started.md)
- [Consumer configuration reference](docs/consumer-configuration.md)
- [Release lifecycle](docs/release-lifecycle.md)
- [GitHub Actions integration](docs/github-actions.md)
- [Configuring the GitHub App](docs/github-app.md)
- [GitHub App setup](docs/github-app.md)
- [Troubleshooting and operating model](docs/troubleshooting.md)

The recommended GitHub Actions orchestration is maintained separately in [LibreCodeCoop/github-workflows](https://github.com/LibreCodeCoop/github-workflows).

LibreSign is the reference implementation. Its public release-process documentation shows the maintainer experience of a real consumer.

## Project internals

- [Architecture](docs/architecture.md)
- [Testing conventions](docs/testing.md)
- CLI help: `php bin/release-tool --help`
- Security policy: https://github.com/LibreCodeCoop/.github/blob/main/SECURITY.md
- Contributing guidance: `AGENTS.md`
