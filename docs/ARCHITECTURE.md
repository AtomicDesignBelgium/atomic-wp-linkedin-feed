# Architecture

Atomic WP Social Sync 0.1.0 is a provider-oriented import pipeline:

```text
Provider API → Provider adapter → NormalizedSocialPost → SyncService
→ ReconciliationService → SocialPostRepository → atomic_social_post
→ FeedQuery → FeedRenderer → block / shortcode / Load More
```

Provider dependencies point inward only as normalized value objects and explicit verification results. The synchronization, persistence, scheduler, and frontend layers do not parse LinkedIn data.

## Major modules

- `Plugin` is the composition root and the only place that builds the object graph and registers top-level hooks.
- `Providers` defines the provider contract, capabilities, safe exceptions, and registry. `Providers/LinkedIn` owns every LinkedIn URL, header, scope, URN, response shape, lifecycle value, and OAuth behavior.
- `Connections` stores one record per configured remote account in a non-autoloaded WordPress option. Multiple records may reference the same provider.
- `CredentialVault` stores secrets in a separate non-autoloaded option. AES-256-GCM encryption uses a key derived from WordPress authentication salts. Tokens never enter connection records, REST output, HTML, or logs.
- `NormalizedSocialPost` is the only post representation accepted by generic sync code.
- `SyncService` dispatches due Connections, owns the expiring lock, imports the current window, and separately verifies a bounded set of absent known IDs.
- `ReconciliationService` enforces source/editorial ownership and conflict policies.
- `SocialPostRepository` maps normalized source fields to core posts and centralized protected metadata.
- `MediaImporter` deduplicates Media Library attachments by provider and source media ID.
- `FeedQuery` and `FeedRenderer` are shared by the dynamic block, shortcode, and local REST Load More endpoint.

## Storage decisions

Connections use a WordPress option because their volume is small, they are configuration rather than public content, and the repository boundary permits a later migration. Credentials are deliberately stored in a separate encrypted option.

Imported items use `wp_posts` and `wp_postmeta`. Two non-public taxonomies—`atomic_social_source` and `atomic_social_account`—provide efficient, composable provider and Connection filters. The unique remote identity is provider + Connection ID + external ID; the repository applies all three during lookup.

The normalized remote publication time is also written to the core post date. This lets WordPress perform merged chronological pagination without provider-specific SQL or frontend API calls.

## Data ownership

Protected metadata records original remote text, timestamps, lifecycle, identity, and hashes. WordPress title decisions, Website Excerpt, pin/hide/homepage state, detach state, featured-image lock, and conflicts are editorial.

Remote content updates are only applied when the last synchronized local source content hash still matches the current WordPress content. Title and featured-image locks are independent because those fields are editorial even when initially generated/imported.

## Frontend boundary

The renderer receives only `WP_Post` objects returned by `FeedQuery`. The public Load More route accepts display/query attributes, sanitizes them, and queries `atomic_social_post`; it has no provider or credential dependency.

Optional singles reuse normal theme templates. No plugin template system is installed.
