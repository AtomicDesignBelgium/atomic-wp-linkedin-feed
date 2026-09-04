# LinkedIn Embed Feasibility (Atomic v1)

## Goal

Determine whether Atomic can **automatically discover identifiers for LinkedIn’s official public-post embeds** without importing LinkedIn post content via the Community Management API.

In scope is a potential flow:

Public LinkedIn Company Page → discover public post URLs / activity IDs / URNs → construct or obtain official LinkedIn embed references → LinkedIn renders via its official embed.

Out of scope:

- Importing LinkedIn text/media via Community Management API and recreating a website feed.
- Any bypass of access controls (auth, cookies, CAPTCHA, session spoofing, proxies, aggressive retries).
- Any production code changes (this is a feasibility-only investigation).

## Constraints and terms context

LinkedIn’s Microsoft Learn documentation explicitly prohibits using Community Management API data for “social feed” use cases, including displaying a feed of LinkedIn company updates on a company website or intranet.  
Source: https://learn.microsoft.com/en-us/linkedin/marketing/restricted-use-cases?view=li-lms-2025-09

This does **not** prohibit using LinkedIn’s own official embed feature for eligible public posts, but it does mean Atomic must not implement “API → import → website feed” for LinkedIn.

## 1) Officially documented embed behavior

### OFFICIALLY DOCUMENTED

LinkedIn’s Help Center documents that:

- Members can embed LinkedIn feed content on third-party sites when the post visibility is set to **Anyone** (public).
- The “Embed this post” flow is available (desktop) via the post’s “More” menu.
- When embedding, LinkedIn offers presentation choices:
  - **Embed with less text**
  - **Embed full post**
  - For video posts: **Embed video only**
- LinkedIn does **not** offer embedding for multi-photo posts or reposts with commentary.
- If the author deletes the content, the embed also gets deleted.

Source: https://www.linkedin.com/help/linkedin/answer/86529

### NOT OFFICIALLY DOCUMENTED (in the above sources)

The help documentation does not describe:

- a stable, documented `iframe src` URL structure,
- whether the embed URL can be constructed deterministically from a public post URL,
- whether `urn:li:activity:*` / `urn:li:share:*` / `urn:li:ugcPost:*` identifiers are guaranteed to be present in public permalinks,
- any official endpoint returning embed references/metadata.

Those items remain **undocumented** (at least in the sources reviewed during this pass).

## 2) Public post URL structure (inspection-only)

### OBSERVED / UNDOCUMENTED BUT WORKING (common patterns)

These URL patterns are commonly seen in the wild:

- Post permalinks containing an activity ID, e.g. `...activity-1234567890...`
- Canonical feed update URLs containing a URN, e.g.:
  - `https://www.linkedin.com/feed/update/urn:li:activity:123.../`
  - `https://www.linkedin.com/feed/update/urn:li:share:123.../`
  - `https://www.linkedin.com/feed/update/urn:li:ugcPost:123.../`

This suggests a **possible** extraction strategy that does not require fetching post content:

- If the administrator supplies a public LinkedIn post URL, Atomic may be able to extract:
  - `activity_id` from `activity-<id>` segments, and/or
  - `activity_urn` directly if the URL already contains `urn:li:*:<id>`.

However, this relationship is not documented as a contract in the official help article above and should be treated as **best-effort** parsing.

## 3) Development-only WordPress HTTP probe (ONE request)

### Method

A development-only PHP probe was executed using WordPress core’s HTTP stack (`wp_remote_get`) without full WordPress runtime boot (to avoid DB dependency). It performed:

- exactly **one** request
- no authentication
- no cookies
- no retries
- no storage of the HTML response
- `redirection = 0` (so the single request does not follow redirects)

Target:

`https://www.linkedin.com/company/european-rural-mobility-network/posts/?feedView=all`

### Result (exact output)

```json
{
  "request_url": "https://www.linkedin.com/company/european-rural-mobility-network/posts/?feedView=all",
  "http_status": 302,
  "redirect_location": "https://www.linkedin.com/uas/login?session_redirect=https%3A%2F%2Fwww.linkedin.com%2Fcompany%2Feuropean-rural-mobility-network%2Fposts%2F%3FfeedView%3Dall",
  "response_size_bytes": 0,
  "marker_authwall_or_gate": {
    "authwall": false,
    "checkpoint": false,
    "challenge": false,
    "captcha": false,
    "login_form": false
  },
  "marker_from_redirect": {
    "authwall": false,
    "checkpoint": false,
    "challenge": false,
    "captcha": false,
    "login": true
  },
  "found_post_urls_count": 0,
  "found_activity_urns_count": 0,
  "found_activity_ids_count": 0,
  "sample_post_urls": [],
  "sample_activity_urns": [],
  "sample_activity_ids": []
}
```

### Interpretation

The public Company Page posts listing appears to redirect anonymous requests to a login endpoint (at least via this single-request WordPress HTTP probe). As a result:

- No HTML was available to parse.
- No post URLs / activity IDs / URNs could be discovered via anonymous `wp_remote_get()` from that page.

This strongly suggests that “automatic discovery from the Company posts page” is **not reliable** without authentication or a browser session, which we explicitly do not want.

## 4) Is `activity_urn` alone sufficient to render an official embed?

### OFFICIALLY DOCUMENTED

LinkedIn documents that embeds exist and can be copied via “Embed this post” (and that variants like “less text/full/video only” exist).  
Source: https://www.linkedin.com/help/linkedin/answer/86529

### OBSERVED / UNDOCUMENTED BUT WORKING

In practice, embeds are widely observed to be implemented as an `<iframe>` whose `src` resembles:

`https://www.linkedin.com/embed/feed/update/<URN>`

Where `<URN>` is often `urn:li:activity:<id>` or `urn:li:share:<id>` or `urn:li:ugcPost:<id>`.

However, because this URL structure is not documented as a stable contract in the official help content, Atomic should treat it as “best-effort/subject to change” unless additional official documentation can be found.

## 5) Feasibility conclusions

### Automatic public-post discovery (from Company posts page)

Based on the probe result, **automatic discovery appears technically not possible** in a robust way using:

- anonymous WordPress HTTP requests,
- a single request,
- no redirect-following,
- no authentication.

### Extraction from a post permalink (admin-supplied)

If an administrator manually provides a *public* post URL, Atomic can likely extract an `activity_id` / `activity_urn` in many cases without fetching content, but this is **undocumented** and must be handled as best-effort with clear admin feedback/fallback.

### Terms/maintenance implications

- The embed strategy aligns with the “no social feeds from API data” restriction for Community Management APIs.  
  Source: https://learn.microsoft.com/en-us/linkedin/marketing/restricted-use-cases?view=li-lms-2025-09
- Any automatic discovery that depends on parsing the HTML of LinkedIn pages is likely to be brittle and may trend toward “scraping infrastructure”, which is explicitly out of scope.

## Recommended architecture

### Recommendation: **A. Manual official embeds** (with optional best-effort parsing)

Atomic v1 should treat LinkedIn as **embed-only**, and require administrators to select posts individually:

- Primary path: administrator pastes the official LinkedIn embed code (or at least the embed URL) obtained via “Embed this post”.
- Optional ergonomic path: administrator pastes the public post URL; Atomic attempts to extract an `activity_urn` if present, otherwise asks for embed code.

Reject “automatic discovery from Company posts listings” for v1 due to the observed anonymous redirect-to-login behavior and the lack of documented APIs for embed discovery.

