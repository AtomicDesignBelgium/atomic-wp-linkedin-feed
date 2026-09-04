# Competitor Review

This document summarizes a conservative, evidence-based competitor review for **Atomic LinkedIn Feed** (0.1.0) and three reference plugins inspected read-only:

- Feed Them Social 4.4.2

- WP LinkedIn Auto Publish 8.27

- XT Feed for LinkedIn 1.0.4

It is primarily based on `docs/COMPETITOR-REVIEW-HANDOFF.md`, which contains line-level evidence and source links.

## Scope and constraints

- This review focuses on security, correctness, maintainability, UX expectations, and product/terms risk.

- Reference plugins were inspected **read-only**. No code was copied.

- This review does not attempt to “average” architectures: the products have different goals (inbound feeds vs outbound publishing).

## P0 critical

### LinkedIn website-feed import is prohibited by published terms

The most important confirmed finding is that LinkedIn’s published restrictions explicitly prohibit using LinkedIn Community Management API data to build a company website social feed (the restriction page uses displaying company updates on a company website or intranet as the example).

**Implication:** Atomic must not ship or operate a production workflow that imports LinkedIn company posts and displays them as a website feed without an explicit written contractual exception from LinkedIn.

**Allowed direction for Atomic v1:** LinkedIn must use an **official embed integration strategy**, not API-based import for feed display.

## Atomic product review

### Strengths to keep (GOOD AS IS)

- Clear composition root and named WordPress hooks in `src/Plugin.php`.

- Provider isolation: LinkedIn URLs/scopes/headers/response parsing live under `src/Providers/LinkedIn/`.

- Provider-independent domain model: `Connection`, `ProviderCapabilities`, `ProviderRegistry`, and `NormalizedSocialPost`.

- One native `atomic_social_post` CPT with protected metadata + provider/account taxonomies.

- Credential boundary: secrets are stored encrypted in a separate option (`CredentialVault`), never in HTML/REST, and are separated from content records.

- Frontend boundary: feeds query local WordPress content only (no provider calls at render time).

- Reuse: block and shortcode share `FeedQuery` and `FeedRenderer`.

- Data preservation: uninstall removes settings/credentials/schedules/transients/logs but preserves imported posts/media/editorial content.

### Confirmed correctness/security issues (P0/P1)

- **Stable content hashes:** `NormalizedSocialPost::contentHash()` currently hashes the entire `$media` array. LinkedIn normalization can include signed `downloadUrl` / thumbnails, which can change without a semantic post edit. This can cause false “remote edits” and unnecessary updates/conflicts.

- **404/missing classification risk:** `LinkedInClient` maps all HTTP 404 to `NOT_FOUND`, and the sync engine can apply deletion policy after two missing confirmations. LinkedIn error guidance indicates that in some cases 404 can represent restricted access. Ambiguous 404 responses must not drive deletion handling.

- **Missing confirmation independence:** the “two-stage missing” confirmation has no minimum interval or independent-attempt identity; two quick manual syncs can apply deletion policy.

- **Owner-less sync locks:** the lock is an expiring option and release unconditionally deletes it. If a run exceeds TTL, a second run can acquire the lock and the first run can later delete the second run’s lock.

### Noted “before MVP” or “worthwhile” items (P1/P2)

- Sync fetch is currently bounded to the first 25 posts (no historical paging/backfill policy).

- `ConnectionRepository` stores all connections in one option with read-modify-write semantics; concurrent updates across connections can overwrite diagnostics/status.

- Provider/connection taxonomies are editable in wp-admin (`show_ui=true`), which can allow administrators to rename/delete sync-owned identity terms.

- Pin ordering lacks a stable tie-breaker (same timestamp posts can move between pages).

- Multiple feeds on one page share the same pagination query var (`atomic_social_page`).

- `FeedRenderer` contains a LinkedIn-specific label branch; this leaks provider knowledge into generic rendering.

- Failed syncs update error/status but diagnostics can be hard to interpret without per-run timestamp/duration clarity.

- Minor UX: disconnect confirmation and “copy callback URL” feedback.

- OAuth pending-credential cleanup may be fragile if WP-Cron does not run (potentially leaving expired encrypted pending records behind).

## Reference plugins: findings

### Feed Them Social (mature patterns to learn from)

- Mature admin UX, feed configuration patterns, and system information/diagnostics tooling.

- Public Load More includes explicit validation/allowlisting; admin cache clearing verifies nonce + capability.

- Demonstrates one rendering path shared by block + shortcode (re-use principle aligns with Atomic).

### Feed Them Social (do not copy)

- It is primarily a remote-feed + cache architecture; Atomic’s local-content ownership model is more durable and less coupled to provider uptime at render time.

- Several legacy/security debt patterns were observed (debug logging risks, token exposure patterns, scattered dispatch), and no test suite was found in the inspected distribution.

### WP LinkedIn Auto Publish (useful UX lessons)

- Clear token-expiry / reauthorization messaging and practical Page selection hints reflect real-world admin needs.

- Shows ongoing LinkedIn API migration cost (e.g., version headers, endpoint evolution).

### WP LinkedIn Auto Publish (DO NOT COPY)

- Observed unsafe patterns including hardcoded shared credentials / third-party redirect relay, weak/non-cryptographic state handling, plaintext token storage, nonce/capability issues, and TLS-verification disabling.

- Procedural, global-style structure (high coupling / low maintainability).

- Primarily an outbound publishing plugin; not evidence that inbound import/feed is terms-safe.

### XT Feed for LinkedIn (DO NOT COPY)

- Despite the name, it is also an outbound sharing plugin, not a durable inbound LinkedIn feed importer.

- Observed unsafe patterns (hardcoded client ID / OAuth relay, URL-derived state, accepting access token via public query string, plaintext token handling) and a custom-table approach with minimal uninstall behavior.

## Recommendations summary

### P0 CRITICAL

- Do not ship or encourage LinkedIn API-based website-feed importing under the published restricted-use rules, unless there is an explicit written contractual exception.

- Implement LinkedIn using only an official embed integration strategy for Atomic v1.

### P1 BEFORE MVP

- Make content hashing stable: exclude ephemeral signed media URLs and any expiry parameters; hash stable semantic content + stable media identities only.

- Make “remote missing” handling conservative:

  - Do not treat every 404 as confirmed deletion.

  - Require genuinely independent confirmations (not two immediate manual sync clicks).

  - Never advance deletion policy on auth/permission/rate/transient errors.

- Make sync locks owner-aware so a process can only release its own lock.

- Make integration tests database-neutral (no leaked fixture taxonomy terms/options/locks/cron state).

### P2 WORTHWHILE

- Stabilize ordering with a deterministic tie-breaker for same-timestamp posts.

- Avoid pagination conflicts when multiple feeds exist on one page (unique page var per feed instance).

- Remove provider label leakage from `FeedRenderer`.

- Improve sync diagnostics (timestamps/durations) and small admin UX confirmations/feedback.

- Harden the public Load More REST endpoint (abuse controls) if evidence warrants.

### P3 LATER

- Consider saved feed presets and system info export after the MVP is terms-safe and correctness issues are resolved.

### GOOD AS IS

- Provider isolation, provider capability model, local-first frontend boundary, encrypted credential vault, one-CPT design, and shared query/renderer reuse are all strong and should be preserved.

### DO NOT COPY

- Remote-feed cache architectures (for Atomic’s goals).

- OAuth relays, URL-derived state, plaintext credential storage, and TLS-verification disabling patterns seen in reference plugins.

## TOP 5 FINDINGS

1. LinkedIn Community Management API data is published as restricted for building a company website social feed; Atomic must not ship the LinkedIn import-to-feed workflow without a written exception.
2. Atomic’s local-first architecture and credential boundary are strong and should be retained; the product risk is primarily LinkedIn terms compliance, not a need to redesign the core.
3. `NormalizedSocialPost::contentHash()` currently includes unstable media fields (including LinkedIn signed media URLs), which can cause false remote-edit detection and unnecessary updates/conflicts.
4. Missing/deletion handling needs to be more conservative: 404 must not always mean confirmed deletion; independent confirmations and error categorization must prevent accidental drafting/trashing.
5. The sync lock must be owner-aware to prevent a stale run from deleting a newer run’s lock; test cleanup must be made database-neutral to avoid fixture leakage across repeated runs.

## RECOMMENDED NEXT PASS

- Make the LinkedIn provider terms-safe by switching LinkedIn to an official embed integration mode (import workflow must not be the normal production/admin path).

- Fix the P0/P1 correctness issues: stable content hashes, conservative missing classification + independent confirmations, owner-aware locks, and robust test cleanup.

- Remove LinkedIn-specific label logic from the generic `FeedRenderer` and address pagination conflicts where multiple feeds share query vars.

- Review small, low-risk P2 items from the handoff only after the required safety and compliance work is stable.
