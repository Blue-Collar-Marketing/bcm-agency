# bcm-agency

Source for **BCM Agency Tools**, Blue Collar Marketing's WordPress plugin that gives Claude narrow, logged abilities on client sites (images, blog posts, categories, tracking codes, site identity, cache purge).

- Plugin code and its own README: [`bcm-agency/`](bcm-agency/)
- How releases reach client sites: [`docs/RELEASING.md`](docs/RELEASING.md)
- Release automation: [`.github/workflows/release.yml`](.github/workflows/release.yml), [`scripts/build_release.py`](scripts/build_release.py)
- BCM Hub wire format: [`docs/HUB-PROTOCOL.md`](docs/HUB-PROTOCOL.md); tests: `php tests/hub/run.php` and `php tests/images/run.php` (run automatically before every release)

Every change goes through a pull request. Every release needs a second person's approval before it's published.

This repo is public on purpose (see docs/RELEASING.md). **Never commit passwords, API keys, tokens or client data.**
