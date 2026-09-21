<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Behat acceptance-test evaluation

Issue #90 requires evaluating Behat before adopting another acceptance-test stack.

Two representative behaviors were compared:

1. validating a consumer configuration through the public CLI, including JSON output and exit codes;
2. executing a release-channel transition through the same application wiring used by the CLI/PHAR.

A Gherkin version makes the happy-path wording readable, but both scenarios still require PHP context code for fixtures, CLI invocation, JSON decoding and exit-code assertions. The PHPUnit acceptance tests express those same observable behaviors without a second runner, duplicate fixture binding or another dependency tree.

Decision for v1: **do not adopt Behat**.

Acceptance tests remain PHPUnit tests under `tests/Acceptance`. Important scenarios also execute the built PHAR in `tools/test-phar.php`. This decision can be revisited if later cross-step workflows become materially clearer in Gherkin; the same scenario must never be duplicated in PHPUnit and Behat.
