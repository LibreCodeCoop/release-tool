<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Architecture

The release tool is a PHP 8.3+ CLI and PHAR shared by local maintainers and GitHub Actions.

## Layers

- Domain contains deterministic release concepts and never performs I/O.
- Application contains use cases and ports. It coordinates domain objects but does not depend on concrete infrastructure.
- Infrastructure implements Git, GitHub HTTP, filesystem and process adapters.
- CLI wiring composes the layers and contains no release policy.

Deptrac enforces these boundaries.

## External boundary

GitHub Actions is orchestration only. Workflows may check out code, provide credentials, invoke explicit CLI use cases and publish summaries/artifacts. Release policy belongs in this repository.

## Contract evolution

Machine-readable stage artifacts and consumer configuration are versioned. A mutating stage consumes the artifact from the preceding stage and may revalidate external state, but must not silently recalculate values already finalized upstream.

The primary lifecycle integration boundary is the CLI plus `actions/prepare`, `actions/post-merge`, and `actions/publication`. Purpose-specific public Actions may also expose release operations that have independent consumers outside that lifecycle, currently stable selection, artifact validation, metadata inspection, release-note generation, manual release preflight, and App Store publication waiting. These Actions are thin adapters over the same versioned PHP product; they must not become parallel policy implementations. Organization workflow templates may consume the public Actions, but they do not own release policy or release runtime code.
