<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Troubleshooting and operating model

Release automation should fail closed when release identity is ambiguous.

## Plan is stale

If the selected release branch advanced after planning and the preparation is no longer valid, generate a new plan.

Do not edit the stored plan to match a newer branch state.

## Generated PR contains unexpected files

Stop.

A release preparation PR should only contain the configured version/changelog files.

Unexpected application code or unrelated metadata usually means the consumer configuration or preparation base is wrong.

## Backport blocker

Open backports for the selected release line are blockers unless the maintainer explicitly chooses the configured override.

The override should be visible in the release plan; it must never be inferred silently.

## Post-merge finalization does not run

Check:

- the workflow listens to `pull_request_target: closed`;
- the PR is actually merged;
- its head starts with the generated `release-tool/` prefix;
- its body contains the preparation marker;
- the preparation artifact has not expired;
- the GitHub App is installed and authorized.

Do not add a permanent generic recovery workflow solely for a one-off incident. Diagnose the missing stage and replay the existing tested stage only when necessary.

## Release draft points to an SHA

That is expected.

The finalized commit SHA is immutable and prevents a stable branch advancing after preparation from silently changing the release target.

## Publication verification fails but the App Store page looks correct

Check deterministic signals first:

- GitHub Release publication;
- publisher workflow conclusion;
- expected release asset.

Public App Store indexes may be cached or rate-limited. Visibility is useful evidence but should not cause repeated package downloads or aggressive polling.

## Artifact verification

The release package should be validated by the publisher/build workflow that produced it.

The post-publication verifier should not repeatedly download a large release tarball merely to prove that the publisher already produced it successfully.

## Security releases

Keep confidential work in the GitHub security advisory/private-fork process.

Before a fix reaches the public repository, ensure public pull request/commit titles are safe to publish. Release notes should never reconstruct or expose advisory-private details.

## Manual fallback

The CLI is available for diagnosis:

```bash
php release-tool.phar config:validate --config .nextcloud-release.yml --root . --json

php release-tool.phar release:plan \
  --config .nextcloud-release.yml \
  --root . \
  --branch stableXX \
  --channel final \
  --json
```

Prefer repairing the automated path over maintaining a parallel undocumented manual release process.
