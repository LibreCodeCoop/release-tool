<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Adopting Release Tool for a Nextcloud app

This guide is for maintainers of Nextcloud apps that currently release through a documented checklist and want the repeatable parts of that process to become deterministic, reviewable, and auditable.

Release Tool does **not** remove maintainer control. The normal operating model keeps two explicit human gates:

1. review and merge the generated release preparation pull request;
2. review and publish the generated GitHub Release draft.

Everything around those gates can be validated and reproduced by code.

## What it automates

A typical Nextcloud app release involves several coordinated tasks:

- choose the target stable branch;
- determine the next version;
- identify changes since the previous release;
- check pending backports;
- update the changelog and version files;
- rotate milestones;
- create a release tag/draft;
- build and publish the package;
- verify that the expected artifact and App Store entry exist.

Release Tool models those steps as explicit, machine-readable contracts instead of relying on a maintainer to reconstruct state from memory.

The current integration is especially suited to apps that:

- maintain one stable branch per supported Nextcloud major;
- use GitHub milestones for patch/RC planning;
- publish through GitHub Releases and the Nextcloud App Store;
- keep a changelog per release line or can map one deterministically;
- already have a package/build workflow that can remain the publisher.

## What it does not replace

Release Tool does not need to own your packaging implementation, signing infrastructure, App Store credentials, QA process, or project-specific smoke tests.

It plans and coordinates the release around those existing systems.

For example, a project may continue to use its existing `make appstore`, signing workflow, deployment secrets, and manual smoke tests. Release Tool only needs a deterministic way to identify the package command, expected artifact, and publication workflow.

## Prerequisites

Before adoption, your repository should have:

- predictable stable branch names;
- a clear authoritative version file;
- Git tags for released versions;
- a changelog source;
- a reproducible package/build command;
- GitHub milestones if you want milestone automation;
- an existing or planned GitHub Actions publisher;
- a GitHub App installed in the consumer repository for short-lived mutation tokens.

The LibreCode GitHub App is not a public credential for third-party repositories. External organizations should create and install their own GitHub App and provide its slug/private key to the reusable actions.

## Adoption path

### 1. Add consumer configuration

Create `.nextcloud-release.yml` at the repository root.

Start from the example in [Consumer configuration](consumer-configuration.md). Keep repository-specific behavior in configuration rather than copying policy into workflow YAML.

### 2. Validate locally

Download a published `release-tool.phar` and checksum from a Release Tool release, then verify it:

```bash
sha256sum --check release-tool.phar.sha256
php release-tool.phar --version
```

Validate your configuration without mutating GitHub:

```bash
php release-tool.phar config:validate \
  --config .nextcloud-release.yml \
  --root . \
  --json
```

### 3. Test read-only planning

Run a plan against one stable branch:

```bash
php release-tool.phar release:plan \
  --config .nextcloud-release.yml \
  --root . \
  --branch stableXX \
  --channel final \
  --json
```

Review at least:

- previous reachable release tag;
- exact planning SHA;
- proposed version;
- milestone;
- changelog target;
- release activity;
- open backport blockers;
- readiness.

Do not enable mutations until the read-only plan matches your project policy.

### 4. Add GitHub Actions orchestration

Use the tested actions from `LibreCodeCoop/github-workflows` rather than reimplementing release policy in a large consumer workflow.

See [GitHub Actions integration](github-actions.md).

### 5. Run a non-production proof

Before relying on the flow for a real release, use a branch/release line where you can safely inspect:

- generated preparation PR contents;
- version updates;
- changelog text;
- milestone selection;
- post-merge synchronization;
- generated release draft;
- publication verification.

The first production release should not be the first time the consumer configuration is exercised.

## Expected maintainer experience

Once adopted, the normal release should be:

1. open **Actions → Prepare release**;
2. choose the stable branch and channel;
3. review the generated PR;
4. merge it;
5. review the generated GitHub Release draft;
6. publish it;
7. verify the existing package/publish workflow completes.

The release contracts and state artifacts provide the audit trail for how that release was derived.

## Reference consumer

LibreSign is the first production consumer. Its repository and public documentation are useful as a working example, but they are not part of the Release Tool runtime dependency graph.

Use LibreSign as a reference for integration patterns, not as configuration to copy blindly.
