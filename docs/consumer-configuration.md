<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Consumer configuration

Release Tool reads repository policy from `.nextcloud-release.yml`.

The goal is to keep project-specific decisions declarative and keep orchestration YAML thin.

## Example

```yaml
schema: 1
repository: example/app

app:
  id: example
  main_branch: main

branches:
  stable_pattern: '^stable(?<nextcloud>\d+)$'

version:
  source: appinfo/info.xml
  mirrors:
    - package.json
    - package-lock.json
  tag_prefix: v

history:
  previous_release: reachable-tag

changelog:
  strategy: per-major
  path: 'docs/changelogs/changelog-{major}.md'
  package_root: CHANGELOG.md

milestones:
  patch: 'Next Patch ({nextcloud})'
  rc: 'Next RC ({nextcloud})'

authorization:
  prepare_min_permission: maintain
  merge_min_permission: maintain

package:
  command:
    - make
    - appstore
  required_paths:
    - appinfo
    - lib
  forbidden_paths:
    - .git
    - .github
    - node_modules
    - src

publication:
  publisher_workflow: appstore-build-publish.yml
  asset_name: '{app}-{tag}.tar.gz'
  appstore_api: https://apps.nextcloud.com/api/v1/platform/{nextcloud}.0.0/apps.json
```

Treat this as a starting point, not a template to copy without review.

## Sections

### Repository and app

`repository` identifies the GitHub repository.

`app.id` is the Nextcloud app id used in artifact naming and publication checks.

`app.main_branch` identifies the aggregate development/history branch.

### Stable branches

`branches.stable_pattern` maps stable branch names to the Nextcloud major represented by that branch.

The named `nextcloud` capture is used by milestone, changelog, and publication templates.

Example:

```yaml
branches:
  stable_pattern: '^stable(?<nextcloud>\d+)$'
```

This maps `stable35` to Nextcloud 35.

### Version

`version.source` is authoritative.

`version.mirrors` contains files that must be kept in sync with that version.

`version.tag_prefix` defines the Git tag prefix, typically `v`.

The release tool should update only configured version files. If a file is merely derived during the package build, do not add it as a mirror.

### Release history

`history.previous_release: reachable-tag` tells planning to use the most recent release tag reachable from the selected release branch.

This avoids selecting a tag from another stable line simply because it is newer globally.

### Changelog

The current Nextcloud-oriented strategy supports per-major changelog sources.

Example:

```yaml
changelog:
  strategy: per-major
  path: 'docs/changelogs/changelog-{major}.md'
  package_root: CHANGELOG.md
```

The selected release line owns its changelog. After a stable release is finalized, the exact released section can be synchronized to newer stable lines and the main branch as release history without copying stable version files.

### Milestones

Milestone templates describe the planning milestones for each release line.

Example:

```yaml
milestones:
  patch: 'Next Patch ({nextcloud})'
  rc: 'Next RC ({nextcloud})'
```

During finalization, the release milestone can be renamed/closed and a follow-up milestone can be created according to the release plan.

### Authorization

The prepare and merge gates are explicit:

```yaml
authorization:
  prepare_min_permission: maintain
  merge_min_permission: maintain
```

The workflow checks repository permission before mutating release state.

### Package contract

The package section describes how to build and sanity-check the release artifact.

`package.command` is the existing project package command.

`required_paths` lists files/directories that must exist in the package.

`forbidden_paths` lists development-only content that must not ship.

This is intended as a release integrity contract, not a replacement for app-specific tests.

### Publication

`publisher_workflow` identifies the existing GitHub Actions workflow responsible for packaging/signing/uploading.

`asset_name` describes the expected GitHub Release artifact.

`appstore_api` is used for best-effort public visibility verification after the publisher succeeded.

Publication success should primarily be based on deterministic signals owned by the repository: release identity, successful publisher workflow, and expected release asset.

## Validation

Run:

```bash
php release-tool.phar config:validate \
  --config .nextcloud-release.yml \
  --root . \
  --json
```

Unknown keys and invalid values fail closed.

Configuration should be reviewed like code because it defines release policy.
