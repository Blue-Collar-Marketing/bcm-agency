# BCM Agency Tools: update pipeline

How new versions of the plugin reach client sites.

```
public repo   bcm-agency            → code, pull requests, version tags
GitHub Action "Release"             → checks, builds, waits for a second approver
public repo   bcm-agency-updates    → GitHub Pages at https://updates.bluecollarmarketing.ca
client sites                        → check https://updates.bluecollarmarketing.ca/bcm-agency/info.json twice a day
Cloudways WP Manager (via Claude)   → installs the update, in waves
```

The plugin code holds no passwords or keys, so a public download is fine. What must stay protected is **who can publish**: anyone who can publish a release can run code on every client site that has the plugin.

---

## One-time setup (about an hour)

### 1. GitHub organization
- Use (or create) a GitHub organization for BCM, not a personal account.
- Organization settings → Authentication security → **Require two-factor authentication**.

### 2. Code repo: `bcm-agency` (public)
- New repository → **Public** → name `bcm-agency`.
  - Why public: on GitHub Free/Pro/Team, the release approval step (required reviewers) only works on public repos; private repos would need GitHub Enterprise. The code is public anyway once it's on the updates site, and it never contains secrets.
  - **Never commit passwords, API keys, tokens or client data to this repo.**
- Upload the contents of this folder (`bcm-agency/`, `scripts/`, `.github/`, `docs/`, `README.md`, `.gitignore`).
- Settings → Rules → Rulesets:
  - **Branch ruleset** on `main`: require a pull request with 1 approval, block force pushes.
  - **Tag ruleset** on `v*`: restrict creation, update and deletion to admins only.
- Settings → Moderation options → Interaction limits → **Limit to repository collaborators** (set it to the longest period and renew it, or keep issues/PRs off in Settings → General → Features).
- Settings → Code security → turn on **Secret scanning** and **Push protection**, so an accidentally committed key is blocked.

### 3. Public updates repo: `bcm-agency-updates`
- New repository → Public → name `bcm-agency-updates` → add a README.
- Add a file named `CNAME` containing exactly: `updates.bluecollarmarketing.ca`
- Settings → Pages → Source: deploy from branch `main`, folder `/ (root)`.
- Custom domain: `updates.bluecollarmarketing.ca` → Save → tick **Enforce HTTPS** once it's available.

### 4. DNS
Where `bluecollarmarketing.ca` DNS is managed, add:

| Type | Name | Value |
|---|---|---|
| CNAME | `updates` | `<your-org>.github.io` |

Then **verify the domain** in GitHub: Organization settings → Pages → Add a verified domain. This stops anyone else's GitHub Pages site from claiming `updates.bluecollarmarketing.ca`. Don't skip it.

### 5. Publishing token (write access to the updates repo only)
- Organization settings → Personal access tokens → Settings → allow **fine-grained personal access tokens** (approval can stay on; approve your own request in the Pending tab).
- Your profile → Settings → Developer settings → Personal access tokens → **Fine-grained tokens** → Generate new token:
  - Name: `bcm-release-bot`
  - Resource owner: **Blue-Collar-Marketing**
  - Expiration: up to 1 year. **Put the expiry date in your calendar**: releases stop working when it lapses (the run fails with "UPDATES_TOKEN … missing or has expired").
  - Repository access: **Only select repositories** → `bcm-agency-updates`
  - Permissions → Repository → **Contents: Read and write** (Metadata: read is added automatically). Nothing else.
  - Generate, then copy the token (it's shown once).
- `bcm-agency` → Settings → Environments → New environment **`updates`**:
  - Required reviewers: yourself. While only one person manages GitHub, leave **Prevent self-review** off; turn it on when a second person joins.
  - Deployment branches and tags: Selected → add tag rule `v*`.
  - Environment secrets → `UPDATES_TOKEN` → paste the token.
- `bcm-agency` → Settings → Secrets and variables → Actions → Variables → `UPDATES_REPO` = `Blue-Collar-Marketing/bcm-agency-updates`.

**Renewing the token:** generate a new one with the same settings, replace the `UPDATES_TOKEN` environment secret, delete the old token.

### One-person setup
While Chris is the only person managing GitHub: the `main` ruleset requires a pull request with **0** approvals (no "most recent push" approval), and the `updates` environment has Chris as reviewer without "Prevent self-review". Every release still pauses for a deliberate Approve. When a developer joins, set required approvals to 1, re-tick both review settings, and add them as a reviewer.

---

## Releasing a new version

1. Make the change on a branch, open a pull request, get it approved, merge to `main`.
   - Bump the version in **both** places in `bcm-agency/bcm-agency.php` (the `Version:` header and `BCM_AGENCY_VERSION`).
   - Add a changelog line at the top of the `## Changelog` list in `bcm-agency/README.md`: `- **1.3.0:** what changed.`
2. Tag it: `git tag v1.3.0 && git push origin v1.3.0`
3. The **Release** action runs. The build job checks the versions match, checks every PHP file, and builds the zip and `info.json`. Any problem stops it here.
4. The publish job waits. The reviewer (a second person, once there is one) opens the run in the Actions tab → **Review deployments** → Approve.
5. Within a minute or two, `https://updates.bluecollarmarketing.ca/bcm-agency/info.json` shows the new version.
6. Sites notice within about 12 hours (or immediately via Dashboard → Updates → Check again).

## Rolling it out

Sites see the update but don't install it automatically. Ask Claude to roll it out through Cloudways WP Manager, in waves:

1. **Wave 1:** MCP Test and internal sites. Check the abilities still work.
2. **Wave 2:** about 50 client sites. Wait a day.
3. **Wave 3:** everyone else.

For an urgent security fix, the waves can run hours apart.

## Rolling back

Older zips stay on the updates site. To roll back, revert the change in `bcm-agency`, release it as a **new, higher** version (e.g. 1.3.1), and roll it out. Sites never "downgrade"; they always move forward to the fixed version.

## Testing a build locally

```
python3 scripts/build_release.py 1.3.0
```
Builds `dist/bcm-agency/bcm-agency-1.3.0.zip` and `info.json` without publishing anything.
