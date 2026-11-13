# BCM Agency Tools (v1.4.0)

Narrow, logged abilities that let Blue Collar Marketing's Claude workflows handle blog posts, images and site identity on client WordPress sites. They appear in Claude automatically through the site's existing WordPress connector (WordPress 6.9+ Abilities API + MCP Adapter), and from 1.4 also through the **BCM Hub** (one connector for every site; see "BCM Hub access" below).

## Abilities

| Ability | What it does | Needs |
|---|---|---|
| `bcm/upload-media` | Adds an image from an approved HTTPS source or inline base64, with alt text. Returns the attachment ID. | upload_files |
| `bcm/create-post` | Creates a standard blog post as a **draft**: title, content, excerpt, slug, categories, tags, featured image, Yoast focus keyphrase. | edit_posts |
| `bcm/update-post` | Edits a post. Live or scheduled posts need `live_edit_approved: true`. | edit_posts + edit_post |
| `bcm/schedule-post` | Schedules a draft for a future time, or moves it back to draft. | publish_posts |
| `bcm/publish-post` | Publishes a draft or scheduled post now. Needs `approved: true`. | publish_posts |
| `bcm/unpublish-post` | Moves a published or scheduled post back to draft (nothing deleted), e.g. after checking a test post in the live template. Needs `approved: true`; clears caches. | publish_posts |
| `bcm/get-post` | Reads one post, with readiness warnings. | edit_posts |
| `bcm/list-posts` | Lists posts by status (draft, scheduled, published). | edit_posts |
| `bcm/list-categories` | Lists existing categories. | edit_posts |
| `bcm/create-term` | Creates a category (optionally nested) or tag; returns the existing one if the name is taken. | manage_categories |
| `bcm/list-tracking-codes` | Finds Search Console, Bing, GA4, GTM, Google Ads and Meta Pixel IDs in WPCode and on the live homepage. | manage_options |
| `bcm/add-tracking-code` | Adds one of those services to WPCode's Header & Footer fields as a marked `<!-- BCM:start … -->` block, appended after existing code. Needs `approved: true`; refuses duplicates; reports whether it's live. | manage_options |
| `bcm/get-site-identity` | Reads site title, tagline, site icon, timezone. | edit_posts |
| `bcm/update-site-identity` | Sets site title, tagline and/or site icon (favicon) from a Media Library image; warns if the icon isn't square or is under 512px. | manage_options |
| `bcm/set-homepage` | Makes a published page the static homepage (Settings → Reading), optionally setting the posts page. Needs `approved: true`; returns before/after for undo. | manage_options |
| `bcm/get-atomic-image-fit` / `bcm/set-atomic-image-fit` | Opt-in, **off by default**. When turned on (`approved: true`), Elementor Atomic images fill the box set on their image element. Classic Elementor images are never affected; fully reversible. | edit_posts / manage_options |
| `bcm/set-page-layout` | Astra only. One page's or post's layout: full width (unboxed, no sidebar), boxed, or theme default; optionally hides Astra's title. Elementor pages set to full width also get the Elementor Full Width template. Live pages need `approved: true`. | edit_pages + edit_post |
| `bcm/purge-page-cache` | Clears Breeze/Varnish and other page caches so changes show. | edit_others_posts (Editor+) |
| `bcm/find-text` | Read-only. Finds text, or a phone number in any format, across Elementor content of every version (classic, containers, Atomic), headers/footers/popups/global widgets, and normal posts/pages. | edit_pages |
| `bcm/replace-text` | Replaces it in the posts the person approved (`post_ids` + `approved: true`). Visible text only; tel: links keep their format; revision per page; Elementor styles rebuilt; caches cleared. | edit_pages + edit_others_posts |
| `bcm/list-forms` | Read-only. Every Elementor form (pages, popups, headers, footers) with Email / Email 2 recipients, each labelled "business" or "submitter" (thank-you). | edit_pages |
| `bcm/update-form-email` | Changes To/CC/BCC of the action that notifies the business (auto-detected, Email or Email 2). The submitter thank-you is never changed. Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/find-images` | Read-only. Where an image is used (Image/Image Box widgets, backgrounds, galleries, Atomic images), by file name, attachment id, or every image on a page. | edit_pages |
| `bcm/list-media` | Read-only. Recent Media Library images with ids, to pick the replacement. | upload_files |
| `bcm/replace-image` | Swaps one placement to another Media Library image (id, url and alt together). Needs `approved: true`; revision for undo; old image never deleted. | edit_pages + edit_others_posts |
| `bcm/list-items` | Read-only. A widget's repeating lists (Icon List bullets, Toggle/Accordion/FAQ items, Price List rows, tabs, slides) with each item's _id and text. | edit_pages |
| `bcm/add-list-item` | Adds an item by copying an existing one (same icon and styling), sets its text/link, inserts at any position. Classic widgets included. Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/remove-list-item` | Removes one item by _id (never the last). Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/list-menus` | Read-only. WordPress menus, where they display, and every item. | edit_theme_options |
| `bcm/add-menu-item` | Adds a page or custom link to a menu, optionally as a dropdown child and at a set position. Needs `approved: true`. | edit_theme_options |
| `bcm/update-menu-item` | Renames, re-links (custom links), re-parents or moves a menu item. Needs `approved: true`. | edit_theme_options |
| `bcm/remove-menu-item` | Removes a menu link (never the page); children move up a level. Needs `approved: true`. | edit_theme_options |
| `bcm/check-for-updates` | Checks the BCM update feed now and reports installed vs latest version. | update_plugins |
| `bcm/add-gallery-image` | Adds a Media Library image to a gallery/carousel (Basic Gallery, Pro Gallery incl. tabbed, Image Carousel, Media Carousel), classic included, at any position. Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/remove-gallery-image` | Removes one image from a gallery/carousel (never the last; never from the Media Library). Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/add-gallery-tab` | Adds a tab (title + images) to a Pro Gallery in tabbed "multiple galleries" mode. Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/remove-tracking-code` | Removes a BCM-added code block from WPCode Header & Footer (never hand-added code). Needs `approved: true`. | manage_options |
| `bcm/get-activity-log` | Recent BCM actions on this site. | manage_options |

SEO title and meta description stay with Yoast's own ability (`yoast-seo/update-post-seo-data`). Yoast's ability can't set the focus keyphrase, so `create-post` / `update-post` handle it.

## Safety rules built in

- **No delete abilities.** Nothing can be deleted or trashed through this plugin. `unpublish-post` only moves a post back to draft, needs `approved: true`, and is logged.
- **Drafts first.** `create-post` always makes a draft. Going live happens through `schedule-post` (at least 5 minutes ahead) or `publish-post`, which requires an explicit `approved: true`.
- **Live edits need an explicit approval flag**, which is logged.
- **Images:** HTTPS only, from this site, Google Drive / googleusercontent.com, Marker.io's attachment storage, or Higgsfield's image CDN (`d8j0ntlcm91z4.cloudfront.net` only, not all of CloudFront); extend with the `bcm_agency_media_hosts` filter. Every redirect hop is re-checked. JPEG, PNG, WebP, GIF only (checked from the file's bytes; no SVG). 10 MB max (5 MB inline).
- **Content is filtered** with `wp_kses_post` even for admins: no scripts, iframes or inline event handlers.
- **Categories are created deliberately.** Posts with an unknown category name are refused (no accidental near-duplicates); Claude creates it first with `create-term`, which returns an existing match instead of duplicating.
- **Tracking codes: known services only.** Claude passes an ID (or whatever the PM pasted); the plugin extracts the ID with a strict pattern and writes the official code itself. Pasted markup is never stored. Codes go into WPCode's global Header & Footer fields (never PHP snippets), appended as marked blocks; existing content is never edited. Remove one by deleting its marked block. Duplicate IDs (in WPCode or on the live homepage) are refused. Anything else is added by a developer in WPCode.
- **Scope:** standard blog posts only. Elementor-built posts can't have their body edited here. Page content stays with Elementor's tools; this plugin only sets a page's Astra layout (`set-page-layout`) and which page is the homepage (`set-homepage`), both with before/after values for undo.
- **Activity log:** every write is recorded (who, what, which object, result). View it under Tools → BCM Activity Log. It can't be cleared from the plugin.
- **Hub requests are signed, single-use and site-bound.** Without a valid hub signature the hub headers do nothing; normal WordPress sign-in still applies everywhere. Code widgets, custom CSS and arbitrary scripts can never be added through the hub; tracking codes (known services only) need Chris's second confirmation.

## BCM Hub access (1.4)

One REST route, `POST /wp-json/bcm-agency/v1/hub`, accepts requests from the BCM Hub (one Claude connector for all our sites). It ignores anything not signed with a key in `includes/hub-keys.php` (public keys only; the private keys live in Cloudflare). Wire format and test vectors: `docs/HUB-PROTOCOL.md` in the repo.

**Checks, in order:** hub access on; key trusted; Ed25519 signature valid; time within 5 minutes; nonce never seen (kept 15 minutes); signed for this site's own host; ability allowed. Any failure is refused and logged (refusals capped at 50 per 10 minutes).

**What can run:**

| Tier | Abilities | Through the hub |
|---|---|---|
| Excluded | `elementor/manage-form-submissions` (changing clients' leads), `elementor/manage-experiments`, `elementor/enable-agent-ready` (developer switches) | Never |
| Second confirmation | `bcm/update-form-email`, `bcm/add-tracking-code` (official code for known services only; a pasted provider snippet is only read for its ID), `elementor/list-form-submissions` (clients' leads), `bcm/set-homepage`, `bcm/update-site-identity`, `bcm/set-atomic-image-fit`; `elementor/manage-global-variable`, `manage-woocommerce-settings`; Yoast site-wide switches and `update-site-representation`; any **unreviewed** write ability in an allowed family (abilities served as MCP resources, such as Elementor's reference documents, count as read-only) | Only with a valid approval signature (Chris approves in Slack) |
| Allowed | Every other `bcm/`, `elementor/` and `yoast-seo/` ability, including `bcm/remove-tracking-code`, Elementor site parts (headers, footers, popups; Pro Custom Code snippets are refused), media uploads, and global classes and default styles | Yes; each ability's own `approved: true` rule still applies |

Other families (`core/`, `imagify/`…) are refused. **Write requests are also scanned** (except `bcm/add-tracking-code`, which never writes pasted markup): script, iframe, style and form tags, event handlers, `javascript:` and non-image `data:` URLs, PHP, Elementor HTML/code/shortcode widgets and dynamic tags, and custom CSS / custom attribute fields are refused; Elementor form email, webhook and redirect fields need a second confirmation. Read-only abilities aren't scanned.

**Runs as** the administrator chosen under Tools → BCM Activity Log → Hub access (default: the oldest administrator), for that request only, **without** `unfiltered_html`, uploads of unfiltered files, or plugin, theme, user or core management. If the chosen account isn't an administrator, requests are refused rather than run as someone else.

**Switches:** untick "Allow requests from the BCM Hub" on that screen, or add `define( 'BCM_AGENCY_HUB_DISABLED', true );` to wp-config.php. A release with an empty `hub-keys.php` disconnects every site. Changes to the switch are logged.

**Log:** hub rows show "Via: hub · <ID>". The ID is the hub's anonymous code for the person; the hub's log maps it to a name. Approved and second-confirmation flags are in the details.

## Install (test site first)

1. In wp-admin: Plugins → Add New → Upload Plugin → choose `bcm-agency-1.0.0.zip` → Install → Activate.
2. In Claude, ask it to run discover-abilities on the site. The ten `bcm/` abilities should be listed alongside Elementor, Yoast and Imagify.

## Test checklist (MCP Test site)

1. `bcm/list-categories` and `bcm/get-site-identity` return data.
2. `bcm/upload-media` from a Google Drive image shared "anyone with the link" → returns an ID; alt text set.
3. `bcm/upload-media` from a blocked host (e.g. https://example.com/a.jpg) → refused.
4. `bcm/create-post` with that image, a category and an excerpt → draft, no warnings.
5. Set Yoast fields with `yoast-seo/update-post-seo-data`.
6. `bcm/schedule-post` 10 minutes ahead → status "future"; wait and confirm it publishes.
7. `bcm/update-post` on the published post **without** `live_edit_approved` → refused; with it → works and is logged.
8. `bcm/schedule-post` on the published post → refused.
9. `bcm/update-site-identity` → before/after shown; then set it back.
10. Tools → BCM Activity Log shows every step above.

### v1.1 additions

11. `bcm/create-term` a category, then again with the same name → second call returns `created: false`.
12. `bcm/create-post` with that category and a `focus_keyphrase`; `bcm/get-post` shows it; Yoast shows it in the editor.
13. `bcm/publish-post` without `approved` → refused; with it → live.
14. `bcm/list-tracking-codes` → inventory of existing codes.
15. `bcm/add-tracking-code` with a test GA4 ID → a marked BCM block appears in Code Snippets → Header & Footer (Header); `live_on_homepage` true (purge cache if not).
16. Same ID again → refused as duplicate.
17. GTM test ID → blocks in both Header and Body.
18. Delete the test blocks from Header & Footer.

## Changelog

- **1.4.0:** BCM Hub access: one signed endpoint that accepts only requests signed by the hub's key (5-minute window, single use, bound to this site) and runs allowed bcm/, elementor/ and yoast-seo/ abilities as an administrator without unfiltered HTML or plugin and user management; code widgets, scripts and custom CSS never allowed; tracking codes, form recipients, form submissions and site-wide settings need a second confirmation; on/off switch and acting user under Tools → BCM Activity Log; activity log shows "via hub" and who asked (anonymous ID). Nothing changes for the existing per-site connector.
- **1.3.4:** `bcm/set-homepage`; `bcm/set-page-layout` (Astra full width / boxed / default per page or post); site icon (favicon) in `bcm/update-site-identity`; `bcm/unpublish-post` (back to draft, for checking test posts); Higgsfield image generations allowed as an image source (Higgsfield's CloudFront host only); opt-in Atomic image-fit fix (`bcm/set-atomic-image-fit`): off by default so updating never changes an existing site's look; when on, Atomic images fill the box their element is sized to.

- **1.3.3:** Marker.io feedback widget as a known tracking service; `bcm/remove-tracking-code`; Marker.io comment attachments allowed as an image source (Marker.io's attachment storage only); add/remove images in galleries and carousels, including inside tabs of tabbed galleries; add gallery tabs.
- **1.3.2:** element-only find/replace (`element_ids`); add/remove items in widget lists (icon lists, FAQs, price lists) including classic widgets; WordPress menu editing; image swaps carry over alt text; update checks every 2 hours plus `bcm/check-for-updates`.
- **1.3.1:** find and swap images in Elementor (classic, backgrounds, Atomic); list Media Library images; client-friendly plugin description.
- **1.3.0:** find and replace across Elementor (classic, containers, Atomic), theme-builder templates and posts, with phone-number mode; list Elementor forms and change where they send (business email auto-detected, submitter thank-you never changed).
- **1.2.4:** cache purge now requires Editor or higher; uninstall keeps the activity log unless BCM_AGENCY_DELETE_LOG_ON_UNINSTALL is set.
- **1.2.3:** bcm/get-site-identity reports the installed BCM Agency Tools version, for checking rollouts.
- **1.2.2:** clearer next-step message after adding a tracking code (service-specific check; purge only mentioned when the code isn't live yet).
- **1.2.1:** after adding a tracking code, clear page caches the way WPCode's own save does (plus Breeze/Varnish), and store the block in WPCode's slashed format. New `bcm/purge-page-cache`.
- **1.2.0:** tracking codes now go into WPCode's Header & Footer fields (live instantly, no snippet cache), as marked BCM blocks.
- **1.1.1:** refresh WPCode's snippet cache after adding a tracking code, then confirm it's on the live homepage (reports `live_on_homepage`).
- **1.1.0:** create categories and tags; publish now; Yoast focus keyphrase; tracking and verification codes through WPCode, with inventory and duplicate check.
- **1.0.0:** media upload, blog post drafts, scheduling, site identity, activity log.

## Updates

The plugin checks `BCM_AGENCY_UPDATE_URL` (default `https://updates.bluecollarmarketing.ca/bcm-agency/info.json`) for new versions using Plugin Update Checker v5.6. Until that address exists, sites simply see no updates. Release by:

1. Bump the version in `bcm-agency.php` (header and `BCM_AGENCY_VERSION`).
2. Build the zip (folder name must stay `bcm-agency`).
3. Publish the zip and an updated `info.json` (template in `release/info.json`).
4. Roll out in waves through Cloudways WP Manager.

Releases should be reviewed by a second person: this plugin's update feed can reach every site that has it installed.
