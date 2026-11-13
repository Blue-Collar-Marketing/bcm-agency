#!/usr/bin/env python3
"""
Builds a release of the BCM Agency Tools plugin.

    python3 scripts/build_release.py 1.3.0

Checks that the version in the plugin header, the BCM_AGENCY_VERSION constant
and the requested version all match, lints every PHP file, checks that
hub-keys.php holds real public keys (no placeholders, never a private key),
then writes:

    dist/bcm-agency/bcm-agency-1.3.0.zip
    dist/bcm-agency/info.json

dist/ mirrors the layout of the public updates site, so the release workflow
copies it as-is. Fails loudly on any problem; never publishes a half-built release.
"""
import datetime
import html
import json
import os
import re
import shutil
import subprocess
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN_DIR = os.path.join(ROOT, "bcm-agency")
MAIN_FILE = os.path.join(PLUGIN_DIR, "bcm-agency.php")
BASE_URL = os.environ.get("BCM_UPDATES_BASE_URL", "https://updates.bluecollarmarketing.ca/bcm-agency")
EXCLUDE_DIRS = {".git", ".github", "node_modules", "tests", "release"}
EXCLUDE_FILES = {".DS_Store", ".gitignore", "phpcs.xml"}


def fail(msg):
    print(f"ERROR: {msg}", file=sys.stderr)
    sys.exit(1)


def read_versions():
    src = open(MAIN_FILE, encoding="utf-8").read()
    header = re.search(r"^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", src, re.M)
    const = re.search(r"define\(\s*'BCM_AGENCY_VERSION',\s*'([0-9.]+)'\s*\)", src)
    requires = re.search(r"Requires at least:\s*([0-9.]+)", src)
    requires_php = re.search(r"Requires PHP:\s*([0-9.]+)", src)
    if not header or not const:
        fail("Could not find the Version header and BCM_AGENCY_VERSION constant in bcm-agency.php.")
    return header.group(1), const.group(1), requires.group(1) if requires else "6.9", requires_php.group(1) if requires_php else "7.4"


def lint():
    php = shutil.which("php")
    if not php:
        fail("php is not installed, so the code can't be checked. Install PHP CLI first.")
    bad = []
    for dirpath, dirnames, filenames in os.walk(PLUGIN_DIR):
        dirnames[:] = [d for d in dirnames if d not in EXCLUDE_DIRS and d != "vendor"]
        for f in filenames:
            if f.endswith(".php"):
                path = os.path.join(dirpath, f)
                r = subprocess.run([php, "-l", path], capture_output=True, text=True)
                if r.returncode != 0:
                    bad.append(r.stdout + r.stderr)
    if bad:
        fail("PHP syntax errors:\n" + "\n".join(bad))


def check_hub_keys():
    """hub-keys.php must hold real PUBLIC keys: no placeholders, never a private key."""
    path = os.path.join(PLUGIN_DIR, "includes", "hub-keys.php")
    if not os.path.exists(path):
        return
    src = open(path, encoding="utf-8").read()
    # Ed25519 private keys exported as PKCS#8 base64 start with this.
    if "MC4CAQAwBQYDK2Vw" in src or "PRIVATE KEY" in src:
        fail("hub-keys.php contains what looks like a PRIVATE key. Remove it, do not commit it, and generate new keys.")
    if re.search(r"'REPLACE_WITH_[A-Z0-9_]*'", src):
        fail("bcm-agency/includes/hub-keys.php still has placeholder keys. Put the real PUBLIC keys in before releasing.")
    entries = re.findall(r"'([A-Za-z0-9._-]+)'\s*=>\s*'([^']*)'", src)
    for key_id, value in entries:
        if not re.fullmatch(r"[A-Za-z0-9_-]{43}", value):
            fail(f"hub-keys.php: key '{key_id}' is not a 43-character base64url public key.")
    if not entries:
        print("NOTE: hub-keys.php trusts no keys. Sites on this version will refuse every hub request.")


def changelog_html(version):
    """Turns the README's '## Changelog' bullets into simple HTML for the update screen."""
    readme = os.path.join(PLUGIN_DIR, "README.md")
    if not os.path.exists(readme):
        return f"<h4>{version}</h4>"
    text = open(readme, encoding="utf-8").read()
    m = re.search(r"^## Changelog\s*$(.*?)(?=^## |\Z)", text, re.M | re.S)
    if not m:
        return f"<h4>{version}</h4>"
    items = re.findall(r"^- \*\*([0-9.]+):\*\*\s*(.+)$", m.group(1), re.M)
    if not items or items[0][0] != version:
        fail(f"README.md changelog must start with an entry for {version} (\"- **{version}:** ...\").")
    out = []
    for ver, note in items[:5]:
        out.append(f"<h4>{html.escape(ver)}</h4><p>{html.escape(note)}</p>")
    return "".join(out)


def build_zip(version, out_dir):
    zip_path = os.path.join(out_dir, f"bcm-agency-{version}.zip")
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
        for dirpath, dirnames, filenames in os.walk(PLUGIN_DIR):
            dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDE_DIRS)
            for f in sorted(filenames):
                if f in EXCLUDE_FILES:
                    continue
                full = os.path.join(dirpath, f)
                arc = os.path.join("bcm-agency", os.path.relpath(full, PLUGIN_DIR))
                z.write(full, arc)
    # Sanity check: folder name must stay bcm-agency/ or WordPress installs it as a second plugin.
    with zipfile.ZipFile(zip_path) as z:
        names = z.namelist()
        if "bcm-agency/bcm-agency.php" not in names or any(not n.startswith("bcm-agency/") for n in names):
            fail("Zip layout is wrong: everything must sit inside bcm-agency/.")
    return zip_path


def main():
    if len(sys.argv) != 2 or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", sys.argv[1]):
        fail("Usage: build_release.py X.Y.Z")
    version = sys.argv[1]
    header, const, requires, requires_php = read_versions()
    if not (version == header == const):
        fail(f"Version mismatch. Tag: {version}, plugin header: {header}, BCM_AGENCY_VERSION: {const}. Update both in bcm-agency.php.")

    lint()
    check_hub_keys()
    notes = changelog_html(version)

    out_dir = os.path.join(ROOT, "dist", "bcm-agency")
    shutil.rmtree(os.path.join(ROOT, "dist"), ignore_errors=True)
    os.makedirs(out_dir)
    zip_path = build_zip(version, out_dir)

    info = {
        "name": "BCM Agency Tools",
        "slug": "bcm-agency",
        "version": version,
        "download_url": f"{BASE_URL}/bcm-agency-{version}.zip",
        "requires": requires,
        "requires_php": requires_php,
        "tested": "7.1",
        "last_updated": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        "sections": {
            "description": "Narrow, logged abilities for Blue Collar Marketing's Claude workflows.",
            "changelog": notes,
        },
    }
    with open(os.path.join(out_dir, "info.json"), "w", encoding="utf-8") as f:
        json.dump(info, f, indent=2)

    print(f"Built {os.path.relpath(zip_path, ROOT)} ({os.path.getsize(zip_path):,} bytes) and info.json for {version}.")


if __name__ == "__main__":
    main()
