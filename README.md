# Atomic WP Social Sync

Atomic WP Social Sync imports supported social content into native WordPress content. LinkedIn is the first provider; the connection, synchronization, storage, and feed layers are provider-independent.

Version 0.1.0 · GPL-2.0-or-later

Bernard Coubeaux · [bernard@atomic-design.be](mailto:bernard@atomic-design.be)

Atomic Design Belgium · [atomic-design.be](https://atomic-design.be/)

## What the MVP provides

- Generic Connections with separate encrypted OAuth credential storage.
- LinkedIn 3-legged OAuth, Page discovery, connection testing, reconnect, and disconnect.
- Current LinkedIn `/rest/posts` retrieval and normalization before persistence.
- One native `atomic_social_post` post type with provider/account taxonomies.
- Deduplicated imports using provider + connection + external ID.
- protected remote edits, conflicts, detach, and two-stage remote-missing handling.
- local image import and featured-image locking; video files are never downloaded.
- per-connection schedules and expiring concurrency locks.
- dynamic Atomic Social Feed block and `[atomic_social_feed]` shortcode using one query and renderer.
- local provider/connection filters, chronological merging, pin/hide controls, numbered pagination, and accessible Load More.
- optional theme-native Social Post single pages.

Frontend rendering only queries WordPress. It never contacts LinkedIn.

## Installation

1. Copy this directory to `wp-content/plugins/atomic-wp-social-sync`.
2. Activate **Atomic WP Social Sync** in Plugins.
3. Open **Settings → Atomic WP Social Sync**.
4. Configure the LinkedIn Developer App as described below.

Requires WordPress 6.0+ and PHP 8.1+ with OpenSSL.

## LinkedIn Developer App and OAuth setup

1. Create or select an app in the [LinkedIn Developer Portal](https://developer.linkedin.com/).
2. Associate and verify the LinkedIn Company Page required by LinkedIn.
3. Request **Community Management API** Development tier, then Standard tier for unrestricted production use.
4. On the app Auth tab, add the exact HTTPS callback URL shown by the plugin. LinkedIn requires absolute HTTPS redirect URLs.
5. Copy Client ID and Client Secret into the plugin settings and save.
6. Select **Connect with LinkedIn**, approve the requested scopes, and select an approved Page.
7. Select **Test Connection**, then **Sync Now**.

The OAuth scopes requested are `r_organization_admin`, `r_organization_social`, and `w_organization_social`. The write scope is currently required by LinkedIn's versioned Images API to retrieve image download URLs; this plugin declares create/update/delete capabilities as disabled and does not publish in version 0.1.0.

Access tokens are normally valid for 60 days. Programmatic refresh tokens are used only when LinkedIn actually returns one for the approved partner tier; otherwise the connection clearly requires reconnection.

## Usage

Insert **Atomic Social Feed** in the block editor, or use:

```text
[atomic_social_feed posts="4" columns="4"]
```

Useful shortcode attributes include `providers`, `connections`, `order`, `pinned_first`, `homepage_only`, `show_image`, `image_ratio`, `card_link`, and `pagination` (`none`, `numbers`, or `load_more`).

Override the default design without editing plugin files:

```css
.atomic-social-feed {
    --atomic-social-gap: 2rem;
    --atomic-social-card-radius: 0;
}
```

## Privacy and external services

When an administrator connects or synchronizes LinkedIn, the plugin contacts LinkedIn's authorization service (`www.linkedin.com`) and API (`api.linkedin.com`). Official, temporary LinkedIn media URLs may be downloaded by WordPress when local image import is enabled.

Imported post content and images are stored locally. Client secrets and OAuth tokens are stored locally in the WordPress database encrypted with an authenticated cipher and a key derived from WordPress salts. They are not exposed through REST responses or frontend markup.

Atomic WP Social Sync sends no telemetry to Bernard Coubeaux, Atomic Design Belgium, or any other hidden service. It adds no tracking.

## Content ownership and uninstall

Uninstall removes plugin settings, encrypted credentials, schedules, locks, transients, and the bounded plugin log. It deliberately does **not** remove imported Social Posts, imported Media Library items, or editorial changes.

## Development

See [Architecture](docs/ARCHITECTURE.md), [Sync lifecycle](docs/SYNC-LIFECYCLE.md), [Provider guide](docs/PROVIDERS.md), and [Development guide](docs/DEVELOPMENT.md).

## Changelog

### 0.1.0

- Initial LinkedIn import/synchronization MVP and provider-independent local feed architecture.
