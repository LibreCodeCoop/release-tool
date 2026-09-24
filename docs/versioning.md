<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Versioning and compatibility

Release Tool is one product: the PHP CLI/PHAR, its public GitHub Actions, and persisted release contracts are versioned together.

## Source of truth

The repository root `VERSION` file is the declared product version.

A release tag must be exactly `v<VERSION>`. The publication workflow rejects any tag that does not match `VERSION` before building or publishing artifacts.

The PHAR embeds the Git tag version through Box. Because publication first proves that the tag equals `VERSION`, the embedded PHAR version and the product version cannot diverge in a published release.

Public GitHub Actions use the same `VERSION` to select the PHAR release they execute. There is no independent Action version stream.

## Consumer pins

Consumers should pin public Actions by immutable commit SHA. A readable release comment may be kept beside the pin, for example:

```yaml
uses: LibreCodeCoop/release-tool/actions/prepare@<40-character-commit-sha> # vX.Y.Z
```

Consumers should pin the immutable commit referenced by a published release tag. That tagged commit and its `VERSION` identify the product release. A development commit that merely carries the same `VERSION` is not a published release. Organization templates may update these pins centrally and materialize the full workflow into consumers.

## Compatibility policy

Before 1.0, minor releases may contain intentional breaking changes to public Action interfaces or persisted release contracts. Patch releases must remain backward compatible within the same minor line unless a security fix makes that impossible.

After 1.0, semantic versioning applies normally:

- patch: backward-compatible fixes;
- minor: backward-compatible features;
- major: breaking public changes.

Public compatibility covers:

- the documented CLI behavior used by consumers;
- inputs and outputs of `actions/prepare`, `actions/post-merge`, and `actions/publication`;
- persisted release contracts whose schema is documented and consumed across lifecycle stages.

Internal PHP classes, private scripts under `actions/_internal`, and implementation details are not public APIs.

A change to a public Action input/output or persisted contract must update tests and documentation in the same change. Internal helper refactors do not create a supported public Action path.

## Release procedure

1. Set `VERSION` to the intended release version in a reviewed change.
2. Merge all code, Action, test, and documentation changes that belong to that product release.
3. Create tag `v<VERSION>` on that exact commit.
4. The release workflow verifies the tag against `VERSION`, builds and tests the PHAR, verifies the embedded version, generates the checksum, and publishes the GitHub release.

Do not create a release tag from a commit whose `VERSION` differs from the tag.
