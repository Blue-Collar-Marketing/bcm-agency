# BCM Agency Tools (v1.3.1)

Narrow, logged abilities that let Blue Collar Marketing's Claude workflows handle blog posts, images and site identity on client WordPress sites. They appear in Claude automatically through the site's existing WordPress connector (WordPress 6.9+ Abilities API + MCP Adapter). Nothing changes on the Claude side.

## Abilities

| Ability | What it does | Needs |
|---|---|---|
| `bcm/upload-media` | Adds an image from an approved HTTPS source or inline base64, with alt text. Returns the attachment ID. | upload_files |
| `bcm/create-post` | Creates a standard blog post as a **draft**: title, content, excerpt, slug, categories, tags, featured image, Yoast focus keyphrase. | edit_posts |
| `bcm/update-post` | Edits a post. Live or scheduled posts need `live_edit_approved: true`. | edit_posts + edit_post |
| `bcm/schedule-post` | Schedules a draft for a future time, or moves it back to draft. | publish_posts |
| `bcm/publish-post` | Publishes a draft or scheduled post now. Needs `approved: true`. | publish_posts |
| `bcm/get-post` | Reads one post, with readiness warnings. | edit_posts |
| `bcm/list-posts` | Lists posts by status (draft, scheduled, published). | edit_posts |
| `bcm/list-categories` | Lists existing categories. | edit_posts |
| `bcm/create-term` | Creates a category (optionally nested) or tag; returns the existing one if the name is taken. | manage_categories |
| `bcm/list-tracking-codes` | Finds Search Console, Bing, GA4, GTM, Google Ads and Meta Pixel IDs in WPCode and on the live homepage. | manage_options |
| `bcm/add-tracking-code` | Adds one of those services to WPCode's Header & Footer fields as a marked `<!-- BCM:start … -->` block, appended after existing code. Needs `approved: true`; refuses duplicates; reports whether it's live. | manage_options |
| `bcm/get-site-identity` | Reads site title, tagline, timezone. | edit_posts |
| `bcm/update-site-identity` | Sets site title and/or tagline. | manage_options |
| `bcm/purge-page-cache` | Clears Breeze/Varnish and other page caches so changes show. | edit_others_posts (Editor+) |
| `bcm/find-text` | Read-only. Finds text, or a phone number in any format, across Elementor content of every version (classic, containers, Atomic), headers/footers/popups/global widgets, and normal posts/pages. | edit_pages |
| `bcm/replace-text` | Replaces it in the posts the person approved (`post_ids` + `approved: true`). Visible text only; tel: links keep their format; revision per page; Elementor styles rebuilt; caches cleared. | edit_pages + edit_others_posts |
| `bcm/list-forms` | Read-only. Every Elementor form (pages, popups, headers, footers) with Email / Email 2 recipients, each labelled "business" or "submitter" (thank-you). | edit_pages |
| `bcm/update-form-email` | Changes To/CC/BCC of the action that notifies the business (auto-detected, Email or Email 2). The submitter thank-you is never changed. Needs `approved: true`. | edit_pages + edit_others_posts |
| `bcm/find-images` | Read-only. Where an image is used (Image/Image Box widgets, backgrounds, galleries, Atomic images), by file name, attachment id, or every image on a page. | edit_pages |
| `bcm/list-media` | Read-only. Recent Media Library images with ids, to pick the replacement. | upload_files |
| `bcm/replace-image` | Swaps one placement to another Media Library image (id, url and alt together). Needs `approved: true`; revision for undo; old image never deleted. | edit_pages + edit_others_posts |
| `bcm/get-activity-log` | Recent BCM actions on this site. | manage_options |

SEO title and meta description stay with Yoast's own ability (`yoast-seo/update-post-seo-data`). Yoast's ability can't set the focus keyphrase, so `create-post` / `update-post` handle it.

## Safety rules built in

- **No delete abilities.** Nothing can be deleted, trashed or unpublished through this plugin.
- **Drafts first.** `create-post` always makes a draft. Going live happens through `schedule-post` (at least 5 minutes ahead) or `publish-post`, which requires an explicit `approved: true`.
- **Live edits need an explicit approval flag**, which is logged.
- **Images:** HTTPS only, from this site or Google Drive / googleusercontent.com (extend with the `bcm_agency_media_hosts` filter). Every redirect hop is re-checked. JPEG, PNG, WebP, GIF only (checked from the file's bytes; no SVG). 10 MB max (5 MB inline).
- **Content is filtered** with `wp_kses_post` even for admins: no scripts, iframes or inline event handlers.
- **Categories are created deliberately.** Posts with an unknown category name are refused (no accidental near-duplicates); Claude creates it first with `create-term`, which returns an existing match instead of duplicating.
- **Tracking codes: known services only.** Claude passes an ID (or whatever the PM pasted); the plugin extracts the ID with a strict pattern and writes the official code itself. Pasted markup is never stored. Codes go into WPCode's global Header & Footer fields (never PHP snippets), appended as marked blocks; existing content is never edited. Remove one by deleting its marked block. Duplicate IDs (in WPCode or on the live homepage) are refused. Anything else is added by a developer in WPCode.
- **Scope:** standard blog posts only. Elementor-built posts can't have their body edited here. Pages stay with Elementor's tools.
- **Activity log:** every write is recorded (who, what, which object, result). View it under Tools → BCM Activity Log. It can't be cleared from the plugin.

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
