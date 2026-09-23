<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Release lifecycle

Release Tool uses explicit contracts between release stages so that later stages do not silently recalculate decisions made earlier.

## 1. Plan

`release:plan` is read-only.

It resolves the selected release line and records:

- exact planning commit;
- previous reachable release;
- proposed version/channel;
- changelog target;
- milestone;
- release activity;
- blockers and warnings;
- whether the release is ready.

The resulting ReleasePlan is the basis for preparation.

If the branch changes in a way that invalidates the plan, build a new plan instead of mutating the old contract.

## 2. Prepare

Preparation consumes the ReleasePlan and creates a deterministic PR containing only the configured release files.

For a typical Nextcloud app that means:

- authoritative version source;
- configured version mirrors;
- selected changelog file.

The generated commit uses `[skip ci]` so a metadata-only release preparation does not consume the full application CI matrix.

The preparation state is persisted as an Actions artifact.

## 3. Human review and merge

A maintainer reviews the exact diff and merges the generated PR.

This is the first semantic human gate.

The post-merge flow must revalidate the merged state rather than trusting that the repository still matches the preparation blindly.

## 4. Finalize

Finalization validates the merged release, then performs repository-level bookkeeping:

- records the finalized release commit;
- synchronizes the exact released changelog section to configured newer history branches;
- transitions the release milestone;
- optionally creates a follow-up milestone.

For a release from an older stable line, history propagation may target several newer stable branches plus main. For the newest stable line it normally targets main.

History synchronization PRs contain only the released changelog section and use `[skip ci]`.

## 5. Draft

The tool creates a GitHub Release draft for the exact finalized commit.

The draft contains the release changelog, contributors, milestone link, and comparison link.

The target is an exact commit SHA intentionally. A branch name could advance after preparation; a SHA is deterministic.

## 6. Human publication

A maintainer reviews and publishes the draft.

This is the second semantic human gate.

Publishing the GitHub Release starts the consumer's existing publisher workflow.

## 7. Publish and verify

The publisher remains project-owned. It may:

- build the Nextcloud package;
- sign it;
- attach the release asset;
- upload to the Nextcloud App Store.

Publication verification should use deterministic repository-owned evidence first:

1. GitHub Release is published;
2. configured publisher workflow succeeded;
3. expected release asset exists.

Nextcloud App Store visibility is useful as a best-effort public check, but public indexes can be cached or rate-limited and should not replace the publisher success signal.

## Audit trail

The workflow persists machine-readable state artifacts for the release stages.

A release can therefore be traced back through:

- ReleasePlan;
- ReleasePreparation;
- PreparedRelease;
- milestone transition;
- ReleaseDraft.

Human-readable step summaries expose the important IDs, branch, version, planning SHA, PR/release URLs, warnings, and artifact names without dumping the full contracts into logs.
