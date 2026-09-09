=== Atomic LinkedIn Feed ===
Contributors: bernardcoubeaux
Tags: social media, linkedin, import, sync, gutenberg
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.11.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage a local LinkedIn feed based on manually selected official LinkedIn embeds.

== Description ==

Atomic LinkedIn Feed manages a local LinkedIn feed based on manually selected official LinkedIn embeds. The frontend feed queries only local WordPress Social Posts.

Publisher: Atomic Design Belgium

Author: Bernard Coubeaux

Email: bernard@atomic-design.be

Website: https://atomic-design.be/

Features include a dedicated LinkedIn Posts admin screen, strict official embed parsing (no stored iframe HTML), a dynamic Atomic LinkedIn Feed block, a shared shortcode renderer, local filtering/pagination, and retained provider/OAuth internals for future outbound workflows.

In addition to API import, the plugin supports manually curated **official LinkedIn embeds** (`integration_mode=embed`). An editor can paste an official LinkedIn iframe, embed URL, or Share URN; the plugin stores only a normalized URN and renders a reconstructed official iframe (no stored HTML).

== Installation ==

1. Upload the `atomic-wp-linkedin-feed` directory to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Settings → Atomic LinkedIn Feed.
4. Complete the LinkedIn Developer App and OAuth configuration below.

== Configuration ==

= LinkedIn Developer App setup =

1. Create an app at https://developer.linkedin.com/ and associate the required Company Page.
2. Request access to the LinkedIn Community Management API. Development tier can be used for integration testing; Standard tier is intended for unrestricted production use.
3. Add the exact HTTPS OAuth callback URL displayed in plugin settings to the app's Auth tab.
4. Save the Client ID and Client Secret in WordPress.
5. Choose Connect with LinkedIn, grant access, and select an approved LinkedIn Page.
6. Test the connection and run Sync Now.

LinkedIn access and production-tier approval are controlled by LinkedIn and are not bundled with this plugin.

== Frequently Asked Questions ==

= Does the frontend call LinkedIn? =

The block, shortcode, pagination, and Load More endpoint query local WordPress content only. Official LinkedIn embeds are rendered as iframes, so the visitor’s browser loads `www.linkedin.com` inside the embed frame.

= Will uninstall delete imported content? =

No. Imported Social Posts, media, and editorial changes remain. Only settings, credentials, schedules, locks, transients, and logs are removed.

= Why is a LinkedIn write scope requested by an import plugin? =

LinkedIn's current versioned Images API documents `w_organization_social` as required for organization image retrieval. Version 0.9.0 does not expose publishing and reports create/update/delete provider capabilities as disabled.

= Are refresh tokens supported? =

Yes, when LinkedIn returns one for an approved partner tier. Otherwise the plugin tracks access-token expiry and asks an administrator to reconnect.

= Can I use another provider? =

Not in version 0.1.0. The provider interface and all core storage/feed components are designed for later provider adapters without separate post types.

== Privacy ==

Administrator-initiated authorization and synchronization contact `www.linkedin.com` and `api.linkedin.com`. Content is imported locally. OAuth credentials and tokens are stored locally in encrypted form. The plugin sends no telemetry to Bernard Coubeaux or Atomic Design Belgium, includes no hidden tracking, and contacts no undocumented service.

== External services ==

LinkedIn is used for OAuth, Page discovery, post retrieval, direct post verification, and supported media metadata/download URLs. Service terms and privacy policy are available from LinkedIn. No LinkedIn HTML scraping or fallback service is used.

== Screenshots ==

1. Settings and LinkedIn application configuration (placeholder).
2. Connection list and synchronization diagnostics (placeholder).
3. Social Sync post editor controls (placeholder).
4. Atomic LinkedIn Posts block controls (placeholder).
5. Responsive frontend feed (placeholder).

== Changelog ==

= 0.11.0 =

* Added: native WordPress plugin updates via public GitHub Releases API (no external update server, no credentials shipped; admin installs 0.11.0 manually once, then future 0.11.1 / 0.12.0 appear through the native Plugins screen with Update now).
* Added: WordPress `Update URI` header set to the public GitHub repository URL to prevent collisions with wordpress.org plugins.
* Added: `GitHubReleaseUpdater` class with 6-hour transient cache, `wp_remote_get()` GitHub Releases query, exact canonical asset contract (`atomic-wp-linkedin-feed-v{VERSION}.zip`), `version_compare()` semantics, and safe failures for network errors, rate limits, missing assets, drafts, and prereleases.
* Added: compatibility with WordPress native auto-updates and the "View version details" thickbox modal populated from GitHub release notes.
* Added: read-only updater diagnostics (Updater source, latest checked version, last check timestamp, release asset found/missing) in the Developer Tools diagnostics panel.
* Added: deterministic `scripts/release.ps1` PowerShell release helper that validates semantic version, verifies version references across all canonical files, inspects Git state, builds the canonical ZIP from a tag-ref with `git archive --prefix=atomic-wp-linkedin-feed/`, and computes SHA-256.
* Added: focused fixture-driven updater tests covering: equal version → no update; newer 0.11.0 vs installed 0.10.0 → update; newer 0.11.1 vs installed 0.11.0 → update; exact ZIP missing → no update; HTTP error → safe no-op; draft release ignored; prerelease release ignored.
* Fixed: PHP constant `ATOMIC_WP_SOCIAL_SYNC_VERSION` now correctly mirrors the plugin header version (was 0.9.0 while plugin was 0.10.0).
* Changed: release ZIP packaging now explicitly excludes `.trae`, IDE artifacts, tests, and local credentials; dist/ remains gitignored.

= 0.10.0 =

* Added: dedicated Help / Documentation tab with nine chapters (Quick Start, URN import, Bulk HTML import, Post management, Display layouts: Grid/Carousel/Stacked, Page architecture, LinkedIn limitations, Troubleshooting).
* Added: contextual help links from Design, Import, and Advanced tabs pointing to #ermn-help-* fragments in the Help tab.
* Added: onboarding notice on the LinkedIn Posts admin screen when no posts exist yet.
* Added: Maintenance panel with bulk deletion of imported posts/media (guarded by typed DELETE confirmation string), plugin runtime-data reset, and legacy-post ownership retrofitting.
* Added: strictly guarded Developer Mode behind WP_DEBUG + an admin toggle, exposing diagnostics, on-demand sync, force full resync, dry-run, cache clearing, sync-state reset, content rebuild, and bounded debug logging.
* Added: News navigation sidebar for stacked / single-news-page layouts with sticky positioning, current-post highlight, CSS smooth-scrolling anchors, and scroll-margin-top support.
* Added: configurable "View all news" CTA button below the carousel with a WordPress page selector.
* Added: neutral public anchor IDs using #ermn-news-{post_id} / #ermn-news-article-{post_id} format.
* Added: TitlePolicy strict generator-placeholder detection to protect manual editorial titles from import overwrites, paired with the _atomic_social_title_locked guard.
* Added: Provider ownership tagging on imported Media Library items for bulk-action safety.
* Added: Rebuild architecture using stored normalized payloads (_ermn_linkedin_source_payload) when developer tools or debug logging are enabled at import time.
* Added: SKIPPED — TRASHED status during re-import to prevent trashed imported posts from reappearing; permanent deletion required for a clean re-import of previously imported content.
* Changed: public markup naming normalized from atomic-social-* / atomic-linkedin-* prefixes to BEM ermn-news* and ermn-news-carousel* classes; public IDs neutralized.
* Changed: carousel viewport height now enforced via --ermn-news-carousel-height CSS custom property on .ermn-news-carousel__viewport with overflow:hidden, keeping controls and CTA fully visible on every slide.
* Changed: stacked-mode layout no longer enforces a fixed height; cards flow naturally with estimated strategy.
* Fixed: LinkedIn Posts admin modal workflow regressions; LinkedIn activity-embed fallback robustness.

= 0.9.0 =

* Initial Atomic LinkedIn Feed branding release with dedicated LinkedIn Posts admin screen, Settings/Design/Import/Advanced/Help tabs, strict official-LinkedIn-embed URN parser, Gutenberg Atomic LinkedIn Posts block, [atomic_social_feed] shortcode, LinkedIn OAuth with Company Page selection and encrypted credential vault, scheduled synchronization, idempotent LinkedIn import with external-id deduplication, remote-edit/conflict/detach/two-stage-missing handling, local image import with featured-image locking, provider/account taxonomies, chronological multi-provider feed query, pin/hide controls, numbered pagination, accessible Load More, optional theme-native single pages, LinkedIn embed rendering, multi-column responsive grid, carousel presentation, and stacked presentation.

= 0.1.0 =

* Initial provider-independent MVP with LinkedIn OAuth/import, protected synchronization, local media, scheduling, block, shortcode, filtering, and pagination.
