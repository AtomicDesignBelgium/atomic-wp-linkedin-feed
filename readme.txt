=== Atomic WP Social Sync ===
Contributors: bernardcoubeaux
Tags: social media, linkedin, import, sync, gutenberg
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and synchronize social content into native WordPress content.

== Description ==

Atomic WP Social Sync treats external platforms as sources and WordPress as the local content layer. Version 0.1.0 supports LinkedIn Company Pages through the official LinkedIn API. Frontend feeds query only local WordPress Social Posts.

Publisher: Atomic Design Belgium

Author: Bernard Coubeaux

Email: bernard@atomic-design.be

Website: https://atomic-design.be/

Features include multiple Connections, deduplicated import, protected editorial changes, local images, scheduling, a dynamic Atomic Social Feed block, a shared shortcode renderer, filtering, merged chronological feeds, and pagination.

== Installation ==

1. Upload the `atomic-wp-social-sync` directory to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Settings → Atomic WP Social Sync.
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

No. The block, shortcode, pagination, and Load More endpoint query local WordPress content only.

= Will uninstall delete imported content? =

No. Imported Social Posts, media, and editorial changes remain. Only settings, credentials, schedules, locks, transients, and logs are removed.

= Why is a LinkedIn write scope requested by an import plugin? =

LinkedIn's current versioned Images API documents `w_organization_social` as required for organization image retrieval. Version 0.1.0 does not expose publishing and reports create/update/delete provider capabilities as disabled.

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
4. Atomic Social Feed block controls (placeholder).
5. Responsive frontend feed (placeholder).

== Changelog ==

= 0.1.0 =

* Initial provider-independent MVP with LinkedIn OAuth/import, protected synchronization, local media, scheduling, block, shortcode, filtering, and pagination.
