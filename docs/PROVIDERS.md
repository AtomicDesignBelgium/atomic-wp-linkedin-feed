# Provider guide

Adding a provider should require a provider adapter, its authentication/configuration layer, and normalization—not another CPT, scheduler, renderer, or feed.

## Required implementation

1. Implement `SocialProviderInterface` with a stable sanitized slug and translated label.
2. Declare factual capabilities with `ProviderCapabilities`; do not emulate unsupported operations.
3. Keep URLs, scopes, headers, remote IDs, response parsing, lifecycle mapping, and auth inside the provider directory.
4. Convert every remote record to `NormalizedSocialPost` before returning it from `fetchPosts` or `fetchPost`.
5. Return `VerificationResult::missing()` only for an authoritative, documented entity-not-found response. Throw a categorized `ProviderException` for auth, permission, rate, network, and temporary API failures.
6. Register the provider in `Plugin`, then add provider-specific onboarding UI without adding provider logic to the core sync classes.

## Normalization contract

Provider + Connection ID + external ID must uniquely identify a record. Dates must be immutable UTC-aware values. Media entries should contain a provider source ID, normalized type, useful alt text, and a temporary official download/thumbnail URL where access permits.

Do not persist raw responses by default. A future opt-in diagnostic payload store must define retention and strip credentials.

## Optional publishing

Version 0.1.0 has no push UI. LinkedIn declares `can_create`, `can_update`, and `can_delete` false even though the underlying Posts API supports those actions and media retrieval currently requires a write scope. A future publishing extension must add explicit provider methods, capability/permission checks, confirmation, and remote-ID persistence. It must never couple WordPress trashing to remote deletion.

## LinkedIn contract used by 0.1.0

Verified on 2026-09-03 against official Microsoft Learn documentation:

- Marketing API version/header: `202608` (August 2026, then documented latest).
- Posts: `https://api.linkedin.com/rest/posts` with `Linkedin-Version: 202608` and `X-Restli-Protocol-Version: 2.0.0`.
- OAuth: `https://www.linkedin.com/oauth/v2/authorization` and `https://www.linkedin.com/oauth/v2/accessToken`.
- Finder: `q=author`, encoded organization URN, `start`, `count` (maximum 100), `sortBy`; paging links may indicate another page.
- Single and batch lookup exist, but Community Management Development tier documents BATCH_GET as unavailable. The plugin uses single lookup.
- `lastModifiedAt` and `lifecycleStateInfo.isEditedByAuthor` expose edit state. Normalization hashes source fields instead of relying solely on the boolean.
- Single-post `404 NOT_FOUND` means the requested post was not found. `401`, `403`, `429`, and 5xx are not deletion evidence.
- Image and Video APIs expose signed, potentially expiring download/thumbnail URLs. V1 imports images/posters and never downloads video files.
- Posts API supports create, partial update, and idempotent delete, but V1 does not expose them.
- Organization post read scope is `r_organization_social`; organization write scope is `w_organization_social`. Page discovery uses approved organization ACL roles and organization admin access.
- Accepted post roles documented by Posts include Administrator, Direct Sponsored Content Poster, and Content Admin. Discovery filters to these equivalents.
- Access tokens are normally 60 days. Programmatic refresh tokens are limited to approved partners and are used only when returned.
- Development tier documents 500 requests/app/day, 100 requests/member/day, no batch get, and no social-action webhooks. General limits reset at midnight UTC; endpoint limits for an app are visible in the Developer Portal.

Official references:

- https://learn.microsoft.com/en-us/linkedin/marketing/versioning
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-lookup-api
- https://learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow
- https://learn.microsoft.com/en-us/linkedin/shared/authentication/programmatic-refresh-tokens
- https://learn.microsoft.com/en-us/linkedin/shared/api-guide/concepts/error-handling
- https://learn.microsoft.com/en-us/linkedin/shared/api-guide/concepts/rate-limits
- https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/videos-api

The API version is centralized in `LinkedInClient::API_VERSION` and must be reviewed before its annual sunset.
