# LinkedIn embeds (manual)

Atomic LinkedIn Feed supports LinkedIn posts in two distinct ways:

- `integration_mode=import`: content is imported via the LinkedIn Posts API and stored as WordPress content.
- `integration_mode=embed`: an editor pastes an official LinkedIn embed; WordPress stores only a normalized URN + safe metadata, and the frontend renders a reconstructed official LinkedIn iframe.

For LinkedIn V1 website feeds, **the intended production path is manual official embeds**. Embed-mode posts are editorially managed and do not participate in synchronization or remote reconciliation.

## Why embeds

LinkedIn’s public embed is the official supported way to show a LinkedIn post on a website without building a derived “feed” from API data. Embed mode:

- avoids scraping or undocumented discovery endpoints,
- keeps rendering local (WordPress queries only), while the visitor’s browser loads the LinkedIn iframe,
- does not require storing or processing arbitrary iframe HTML.

## Admin workflow (V1)

1. LinkedIn → “Embed this post” → copy the embed code (or copy the embed URL / URN).
2. WordPress → **Atomic LinkedIn Feed → LinkedIn Posts**.
3. Select **+ Add LinkedIn Post**.
4. Paste the value into **LinkedIn Embed / Share URN**.
5. Set the **Original publication date**.
6. Save. The UI confirms: “LinkedIn embed validated” and shows the normalized URN.

After saving, the plugin stores only the normalized URN and derived metadata. The submitted raw HTML is discarded.

## Accepted inputs

The field accepts any one of:

- Share URN:
  - `urn:li:share:7500553868422897664`
- Official LinkedIn embed URL:
  - `https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664`
- Official LinkedIn iframe code (the plugin extracts only the `src` + `height`):
  - `<iframe src="https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664?collapsed=1" height="603"></iframe>`

## Normalized storage (no raw HTML)

For embed-mode records the plugin stores:

- `provider=linkedin`
- `integration_mode=embed`
- `embed_urn=urn:li:share:<numeric-id>`
- optional embed heights:
  - `embed_height_compact`
  - `embed_height_full`
- a remote publication timestamp field, and the core WordPress post date is set to the same value so feeds order by the original publication time.

The plugin **does not store** the submitted iframe HTML after parsing.

## Security / validation rules

The embed parser is strict:

- only HTTPS URLs are accepted,
- only host `www.linkedin.com` is accepted,
- only embed paths matching `https://www.linkedin.com/embed/feed/update/...` are accepted,
- only the proven V1 URN format is accepted:
  - `urn:li:share:<numeric-id>`
- `urn:li:activity:*` is rejected (no conversion),
- `javascript:` URLs are rejected,
- scripts, event-handler attributes, and unknown HTML are rejected,
- unexpected LinkedIn embed query parameters are rejected; only the observed compact flag is allowed:
  - `collapsed=1`

## Presentation: auto / compact / full

LinkedIn posts have been observed in two official embed presentations:

- **full**: base embed URL
- **compact**: base embed URL + `?collapsed=1`

The feed block and shortcode expose:

- `presentation=auto|compact|full`

If compact URL generation is not possible for a given record, the renderer falls back to full.

## Heights and responsive width

LinkedIn iframes are cross-origin and the plugin cannot reliably measure their dynamic height.

- When an official iframe is pasted, its numeric `height` is extracted and stored against the detected presentation (compact vs full).
- When only a URN is pasted, the renderer uses centrally-defined fallback heights.
- The iframe width is always rendered responsively (`width: 100%`). Width is not treated as identity.

V1 does not expose height override fields in the dedicated LinkedIn Posts UI. Heights are extracted from pasted iframes when available, otherwise centralized fallbacks are used.

## “View full news” CTA

The feed block can optionally render a “View full news” CTA per item. When enabled and a target WordPress Page is selected:

- each item has a stable anchor: `atomic-linkedin-post-{post_id}`
- the CTA links to: `{target_page_permalink}#atomic-linkedin-post-{post_id}`

The iframe itself is never wrapped in another link.

## Pagination

Pagination is always local WordPress pagination over `atomic_social_post`. Rendering and paging never call LinkedIn APIs.

## Limitations (V1)

- Only `urn:li:share:<numeric-id>` is supported.
- Video-only embed behaviors are not implemented yet.
- The plugin does not attempt to discover embed URNs from a company page listing.
