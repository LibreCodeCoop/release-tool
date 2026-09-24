<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# GitHub Actions integration

The recommended integration uses the tested composite actions published by `LibreCodeCoop/release-tool`.

The consumer workflow should orchestrate release stages, not reimplement release policy.

## Why a GitHub App

Mutating release steps use short-lived GitHub App installation tokens instead of a long-lived personal access token.

A third-party organization should create and install its own GitHub App in the consumer repository. Follow [Configuring the GitHub App](github-app.md) for the exact registration settings, repository permissions, installation scope, private-key generation, and Actions secret configuration.

At minimum, the release flow needs to be able to create/update release branches and pull requests, create GitHub Releases, and read Actions state. Configure only the permissions required by the actions you adopt.

Keep the App private key as an Actions secret.

The reusable actions accept the App slug and private key; the default LibreCode App slug is only appropriate where that App is installed.

## Install the workflows

Use the managed `prepare-release.yml` template instead of maintaining a private copy of the orchestration. Install `sync-workflow-templates.yml` beside it so updates to managed workflows arrive as reviewable pull requests.

The updater has its own authentication contract and needs **Workflows: write** in addition to Contents and Pull requests write access. Those permissions are not required by the release App itself. See the [workflow synchronization guide](https://github.com/LibreCodeCoop/.github/blob/main/docs/cross-repository-automation.md).

## Consumer workflow shape

A normal consumer workflow has three entry points:

- `workflow_dispatch` for release preparation;
- `pull_request_target: closed` for post-merge finalization;
- `release: published` for publication verification.

`pull_request_target` is intentional for the post-merge event because generated release commits use `[skip ci]`. The job itself must still validate that the closed PR:

- was merged;
- came from a generated `release-tool/` branch;
- contains the release preparation marker.

Do not run mutation logic for arbitrary pull requests.

## Actions

The main building blocks are:

- `actions/prepare`;
- `actions/post-merge`;
- `actions/publication`.

Pin them to an immutable commit SHA and keep a comment with the corresponding release version.

Do not reference `main` or a floating tag in production release automation.

## Consumer responsibilities

The consumer repository supplies:

- `.nextcloud-release.yml`;
- GitHub App credentials;
- publisher workflow;
- project packaging command;
- changelog/version files;
- stable branches and milestones.

The shared tooling supplies:

- release planning;
- authorization checks;
- deterministic preparation;
- state contracts;
- history synchronization;
- milestone transition;
- release draft generation;
- publication verification.

## Security boundary

Treat `pull_request_target` workflows carefully because they run in the base repository security context.

The release workflow should:

- check out the trusted base/release branch, not arbitrary PR code;
- validate the generated PR metadata before mutation;
- keep permissions empty at workflow level and grant them per job;
- use short-lived GitHub App tokens for mutations;
- pin third-party Actions by immutable SHA.

These properties are part of the reference integration and should not be weakened when adapting it.

## Reference

`LibreCodeCoop/release-tool` is the source of truth for release behavior and the three public lifecycle Actions. `LibreCodeCoop/.github` owns LibreCode's managed workflow templates that orchestrate those Actions. LibreSign's `.github/workflows/prepare-release.yml` is the reference consumer and should be materialized from that catalog rather than becoming a second implementation.
