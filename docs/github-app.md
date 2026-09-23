<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Configuring the GitHub App

Release Tool uses a GitHub App as a short-lived mutation credential for release preparation and finalization.

The App does **not** need to run a web service, receive webhooks, or authorize users. It is used only to mint installation access tokens inside GitHub Actions.

## Recommended model

For an organization-owned app repository, create the GitHub App under the same organization that owns the repository.

For example, if the consumer repository is:

```text
example-org/example-app
```

create a GitHub App owned by `example-org` and install it only on `example-app` unless you intentionally want to reuse the same App for several repositories.

For an internal automation App, choose **Only on this account** as the installation scope.

## Create the App

On GitHub:

1. Open your profile menu.
2. Open **Your organizations**.
3. Open **Settings** for the organization that should own the App.
4. In the left sidebar, open **Developer settings**.
5. Open **GitHub Apps**.
6. Click **New GitHub App**.

Use a clear name, for example:

```text
example-org-release-automation
```

The GitHub App slug derived from that name is what the release workflow receives as `app-slug`.

## General settings

Recommended values:

### GitHub App name

Use a name that clearly identifies the automation purpose.

Example:

```text
Example App Release Automation
```

### Homepage URL

Use the repository, project documentation, or organization URL.

Example:

```text
https://github.com/example-org/example-app
```

### User authorization

Do not enable **Request user authorization (OAuth) during installation**.

Release Tool does not request user access tokens and does not act on behalf of an interactive user.

No callback URL is required.

### Device flow

Leave **Enable Device Flow** disabled.

### Setup URL

No setup URL is required.

### Webhooks

Disable **Active** under Webhooks.

The release automation does not rely on GitHub App webhooks. GitHub Actions events trigger the release workflow.

Therefore no webhook URL, webhook secret, or subscribed events are required.

## Repository permissions

Configure only these repository permissions:

| Permission | Access | Why |
| --- | --- | --- |
| **Contents** | **Read and write** | Create generated branches/commits and create/update GitHub Releases |
| **Pull requests** | **Read and write** | Create and inspect release preparation/history synchronization pull requests |
| **Metadata** | Read-only | Implicit GitHub App repository metadata access |

All other repository permissions should remain **No access** unless your own integration adds another requirement.

In particular, the shared release actions do **not** currently require GitHub App access to:

- Actions;
- Administration;
- Checks;
- Deployments;
- Issues;
- Workflows;
- Secrets;
- Environments.

Read access to workflow runs/artifacts and maintainer permission checks is performed with the workflow's own `GITHUB_TOKEN`, not the GitHub App token.

> If the shared tooling later starts requesting another GitHub App permission, update this document and the consumer installation together. GitHub requires installed accounts to approve newly requested permissions.

## Organization and account permissions

No organization permissions are required.

No account/user permissions are required.

Leave all of them at **No access**.

## Installation visibility

For an App that exists only to automate releases inside the organization, choose:

```text
Only on this account
```

Choose **Any account** only if you intentionally plan to distribute the same GitHub App to repositories owned by other GitHub accounts.

The tooling itself is reusable across organizations; the App credential does not need to be shared.

## Create the App

After reviewing the settings, click **Create GitHub App**.

## Generate a private key

Open the newly created GitHub App settings.

In **General**, locate **Private keys** and click **Generate a private key**.

GitHub downloads a PEM private key.

Treat this file like a production credential:

- do not commit it;
- do not paste it into issues or pull requests;
- do not store it in repository files;
- rotate it if it is exposed.

The release workflow needs the complete PEM value, including:

```text
-----BEGIN RSA PRIVATE KEY-----
...
-----END RSA PRIVATE KEY-----
```

GitHub may generate a PKCS#8-style header instead; store the generated PEM exactly as downloaded.

## Install the App

From the GitHub App settings:

1. Click **Install App**.
2. Find the organization or account that owns the consumer repository.
3. Click **Install**.
4. Prefer **Only select repositories**.
5. Select the app repository, for example `example-app`.
6. Review the requested permissions.
7. Click **Install**.

For a dedicated release automation App, granting access to all repositories is normally unnecessary.

GitHub organization owners can install GitHub Apps. Repository administrators may also be able to install an App for repositories they administer when organization policy permits it and the App does not request organization or repository-administration permissions.

## Add the private key to Actions secrets

The reference LibreSign workflow uses:

```text
LIBRECODE_WORKFLOW_APP_PRIVATE_KEY
```

External consumers should normally choose a project-specific name, for example:

```text
RELEASE_AUTOMATION_APP_PRIVATE_KEY
```

You can store it at repository or organization level.

### Repository secret

Open:

**Repository → Settings → Secrets and variables → Actions → New repository secret**

Create the secret and paste the entire PEM private key.

### Organization secret

If the same App is intentionally shared by multiple repositories:

**Organization → Settings → Secrets and variables → Actions → New organization secret**

Restrict repository access to the repositories that should be allowed to use the release App.

Do not expose the private key as a variable. It must be a secret.

## Configure the App slug

The shared actions accept the public GitHub App slug separately from the private key.

Example:

```yaml
with:
  app-slug: example-org-release-automation
  app-private-key: ${{ secrets.RELEASE_AUTOMATION_APP_PRIVATE_KEY }}
```

The action resolves the App's public client ID from its slug at runtime. Consumers do not need to copy an App ID/client ID into their release configuration.

## Workflow token permissions

The GitHub App is not the only token involved.

The workflow's built-in `GITHUB_TOKEN` is also used for read-only orchestration tasks such as:

- restoring Actions artifacts;
- inspecting workflow runs;
- checking repository permissions;
- applying milestone transitions where configured.

Keep top-level workflow permissions empty:

```yaml
permissions: {}
```

Then grant only the permissions required by each job.

The reference workflow is the source of truth for the exact `GITHUB_TOKEN` job permissions.

## Verify the setup

Before attempting a production release, run **Prepare release** on a safe release line.

A correctly configured App should allow the workflow to:

1. resolve the App from the slug;
2. mint a short-lived installation token;
3. create the generated `release-tool/...` branch;
4. create the release preparation pull request.

A `403` from GitHub usually means one of:

- the App is not installed on the repository;
- the installation does not include that repository;
- the App permission is too restrictive;
- the private key belongs to another GitHub App;
- the App slug is wrong.

## Changing permissions later

GitHub App permissions can be changed under:

**Organization/Account Settings → Developer settings → GitHub Apps → Edit → Permissions & events**

If you add permissions after installations already exist, GitHub requires the installed account to approve the new permissions before they become effective.

Keep the permission set minimal and review changes as part of release-tool upgrades.

## GitHub documentation

GitHub's official references:

- Registering a GitHub App: https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app
- Choosing GitHub App permissions: https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/choosing-permissions-for-a-github-app
- Installing your own GitHub App: https://docs.github.com/en/apps/using-github-apps/installing-your-own-github-app
