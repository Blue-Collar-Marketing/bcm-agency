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

### 5. Publishing key (write access to the updates repo only)
On your computer:
```
ssh-keygen -t ed25519 -C "bcm-release-bot" -f bcm_updates_deploy -N ""
```
- `bcm-agency-updates` → Settings → Deploy keys → Add → paste `bcm_updates_deploy.pub` → tick **Allow write access**.
- `bcm-agency` → Settings → Environments → New environment **`updates`**:
  - Required reviewers: Chris and Sasha (or whoever approves releases). Tick **Prevent self-review**.
  - Deployment branches and tags: Selected → add tag rule `v*`.
  - Environment secrets → `UPDATES_DEPLOY_KEY` → paste the contents of `bcm_updates_deploy` (the private key).
- `bcm-agency` → Settings → Secrets and variables → Actions → Variables → `UPDATES_REPO` = `<your-org>/bcm-agency-updates`.
- Delete both key files from your computer.

---

## Releasing a new version

1. Make the change on a branch, open a pull request, get it approved, merge to `main`.
   - Bump the version in **both** places in `bcm-agency/bcm-agency.php` (the `Version:` header and `BCM_AGENCY_VERSION`).
   - Add a changelog line at the top of the `## Changelog` list in `bcm-agency/README.md`: `- **1.3.0:** what changed.`
2. Tag it: `git tag v1.3.0 && git push origin v1.3.0`
3. The **Release** action runs. The build job checks the versions match, checks every PHP file, and builds the zip and `info.json`. Any problem stops it here.
4. The publish job waits. A second person opens the run in the Actions tab → **Review deployments** → Approve.
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
