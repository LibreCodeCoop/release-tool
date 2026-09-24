<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Temporary Python reference implementation

These files are test fixtures for the Python-to-PHP compatibility migration tracked in #55.

They were copied from `LibreCodeCoop/github-workflows` at commit
`41c110486f0c5da331847cbf25c673d5e9378876` and must not be used as production
runtime code.

| Fixture | Original path |
| --- | --- |
| `check_release_authorization.py` | `scripts/check_release_authorization.py` |
| `restore_release_artifact.py` | `scripts/restore_release_artifact.py` |
| `validate_release_artifact.py` | `scripts/validate_release_artifact.py` |
| `release_notes_from_pull_requests.py` | `actions/release-notes-from-pull-requests/generate.py` |
| `release_stable_select.py` | `actions/release-stable-select/resolve.py` |

The compatibility tests execute these fixtures as subprocesses and assert observable
contracts. Once equivalent PHP behavior passes the same scenarios, these Python
fixtures should be deleted.
