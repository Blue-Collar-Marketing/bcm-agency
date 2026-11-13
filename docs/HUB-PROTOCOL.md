# BCM Hub ↔ BCM Agency Tools: request protocol (v1)

How the BCM Hub (Cloudflare Worker) talks to BCM Agency Tools 1.4+ on a client site. Source of truth for the hub build and for the independent review. Plugin side: `bcm-agency/includes/class-bcm-hub.php` and `class-bcm-hub-policy.php`. Reference signer (WebCrypto, runs unchanged in a Worker): `tests/hub/sign.mjs`.

## Transport

- `POST https://<site host>/wp-json/bcm-agency/v1/hub`, HTTPS only, `Content-Type: application/json`.
- Body at most 1 MB. The hub enforces a 30-second timeout and its own reply-size cap, and treats every reply as untrusted data.
- Sites never call the hub.

## Body

```json
{"action": "ping"}
{"action": "list"}
{"action": "info", "ability": "bcm/replace-text"}
{"action": "run",  "ability": "bcm/replace-text", "input": { ... }}
```

No other top-level keys. `input` is a JSON object or omitted; only `run` takes it. Ability names are `family/name` in lower case.

## Headers

| Header | Value |
|---|---|
| `X-BCM-Hub-Key` | Key ID, e.g. `hub-1` (must be in the plugin's `hub-keys.php` "signing" list) |
| `X-BCM-Hub-Time` | Unix seconds when signed |
| `X-BCM-Hub-Nonce` | 22–128 chars `[A-Za-z0-9_-]`, random, never reused (the reference signer uses 24 random bytes) |
| `X-BCM-Hub-Actor` | Anonymous person ID, 2–64 chars `[A-Za-z0-9_-]`. Never an email or name |
| `X-BCM-Hub-Signature` | base64url (no padding) of the 64-byte Ed25519 signature |

Second-confirmation requests add:

| Header | Value |
|---|---|
| `X-BCM-Hub-Approval-Key` | Approval key ID, e.g. `approve-1` (the plugin's "approval" list) |
| `X-BCM-Hub-Approval-Time` | Unix seconds when Chris approved |
| `X-BCM-Hub-Approval` | base64url Ed25519 signature by the approval key |

## Signed message

UTF-8, fields joined by a single `\n`, no trailing newline:

```
BCM-HUB-V1
POST
<host>                 site host exactly as in the URL: lower case, no port, no trailing dot
/bcm-agency/v1/hub     the REST route (not the /wp-json URL path)
<key id>
<time>
<nonce>
<actor>
<sha256 of the raw body bytes, lower-case hex>
```

The plugin rebuilds this from the request it received, using the `Host` header, so a request signed for one site fails against any other.

Approval message (signed by the approval key over the same request, same nonce):

```
BCM-HUB-APPROVAL-V1
<approval key id>
<host>
<nonce>
<actor>
<approval time>
<sha256 of the raw body, hex>
```

## Plugin checks, in order

1. Hub access on (`bcm_agency_hub_enabled` option, default on; `BCM_AGENCY_HUB_DISABLED` in wp-config forces off) → `bcm_hub_disabled` 403
2. Key ID trusted, sodium available → `bcm_hub_unknown_key` 401, `bcm_hub_unavailable` 500
3. Headers well-formed, body ≤ 1 MB, signature valid → `bcm_hub_bad_request` 400, `bcm_hub_too_large` 413, `bcm_hub_bad_signature` 401
4. `|now − time| ≤ 300` → `bcm_hub_expired` 401 (data: `site_time`)
5. Nonce inserted into a primary-keyed table (kept 900 s) → `bcm_hub_replay` 401
6. Host is the site's home/site host (± `www.`) → `bcm_hub_wrong_host` 403 (data: `home_host`)
7. Body valid → `bcm_hub_bad_request`; family allowed → `bcm_hub_not_allowed` 403; ability exists → `bcm_hub_not_found` 404; tier and content scan → `bcm_hub_excluded` 403; confirmation tier without approval → `bcm_hub_needs_confirmation` 403 (data: `reason`); bad approval → `bcm_hub_bad_approval` 401; no usable administrator → `bcm_hub_no_user` 500

Requests with none of the hub headers get `bcm_hub_unsigned` 401 and are not logged. Every other refusal is logged on the site (actor recorded only once the signature has checked out).

## Replies

- `ping` → `{ok, protocol: 1, plugin_version, wp_version, php_version, home_host, site_time, hub_user_ready, abilities: {bcm, elementor, yoast-seo}}`. A site is *ready* when a signed ping returns `ok`.
- `list` → `{ok, abilities: [{name, label, description, readonly, tier}]}` (excluded abilities and other families omitted; `tier` is `allowed` or `confirm`).
- `info` → `{ok, ability: {name, label, description, input_schema, output_schema, readonly, tier}}`.
- `run` → `{ok, ability, result}`. An ability's own error comes back as `{code, message, data: {status: 400}}` with the ability's code kept (e.g. `bcm_needs_approval`, so Claude asks the person and retries with `approved: true`).

## Tiers

The lists live in `BCM_Hub_Policy` and must be mirrored in the hub; the plugin's copy is the one that holds if the signing key leaks. An ability in `bcm/`, `elementor/` or `yoast-seo/` that is on no list is *unreviewed*: allowed if its `readonly` annotation is true, otherwise it needs confirmation. Write requests are content-scanned; the scan can only make a request stricter, and a second confirmation never unlocks excluded content.

## Test vector

Test key (never use outside tests): Ed25519 seed `0707…07` (32 bytes of 0x07). As PKCS#8 (what a Worker secret holds): DER prefix `302e020100300506032b657004220420` followed by the seed, base64-encoded. Public key (base64url): `6kpsY-KcUgq-9VB7Ey7F-ZVHdq6-vnuSQh7qaRRG0iw`.

```
host     kozyhomes.ca
key id   hub-1
time     1794500000
nonce    n0nce-Q2hyaXMtdGVzdC12ZWN0b3I
actor    a7f3
body     {"action":"run","ability":"bcm/replace-text","input":{"find":"Sat 9-1","replace":"Sat 9-2","post_ids":[12],"approved":true}}
sha256   73a4de660bb431ae4853bf0f07b7476b98b9ffd41dc2c198364ed046a6a54da0
signature           fGyzWR7bwPkGnfMudMX-V9iyEYssA_D92MxfD11ksdzKWckNbaJ__vN1pEOUb76kUahNeCocUIHKYEP-f6QQAQ
approval (approve-1, time 1794500010, same key)
                    soV7u3j3mPqX2LPjQX-9uZb44EziNSTkfbKATF6IaS2zcv9X37k0Y473zR6mGlKhDr1PMvQrO2GdnDtAryXkDA
```

PHP (sodium) and WebCrypto (Node 22, Workers) both produce these exact bytes; `tests/hub/run.php` checks it.

## Keys and rotation

- Private keys: Worker secrets only (`HUB_SIGNING_KEY_1/2` in `bcm-hub`, `HUB_APPROVAL_KEY_1/2` in `bcm-hub-approve`), base64 PKCS#8. Trim whitespace on read.
- Public keys: `bcm-agency/includes/hub-keys.php`. Each list ships the current and next key.
- Rotate: hub switches to the next key; next plugin release trusts {next, new next} and drops the old one.
- Disconnect every site: release with both lists empty.
