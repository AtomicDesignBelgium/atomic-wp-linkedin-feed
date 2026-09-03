# Synchronization lifecycle

Synchronization is connection-based and protected by a 15-minute expiring lock. Each run asks the registered provider for normalized posts, then reconciles each item against provider + Connection + external ID.

## States and transitions

### New

No local identity match exists. A published `atomic_social_post` is created, a readable title is generated, remote/source metadata is written, provider/account terms are assigned, and supported media is imported. Repeating the same run finds the existing identity and creates zero posts.

### Unchanged

The normalized content hash matches. Verification/sync timestamps and remote lifecycle state are refreshed. A previous `missing` marker is cleared if the item reappears. Editorial fields are untouched.

### Remote edit

The remote hash changed while local source-controlled content still matches its last-sync baseline:

- **Update automatically** updates source content (default).
- **Require review** stores a normalized pending version and marks review.
- **Ignore** records the observed remote state but keeps local display content.

### Local edit / conflict

If WordPress source-controlled content and the remote source both changed, synchronization stores only the normalized pending version and marks `conflict`. It never overwrites local content. An editor chooses **Use Remote Version** or **Keep WordPress Version**.

WordPress title, Website Excerpt, pin/hide/homepage flags, detach, and manually selected featured image are always editorial and are not silently overwritten.

### Missing

Absence from the author finder is ignored as deletion evidence. For a bounded number of known absent IDs, the provider performs a direct single-post lookup.

Only an authoritative `404 NOT_FOUND` result enters `missing` and stores `remote_missing_since`. Authentication, authorization, throttling, network, and service failures propagate as errors and never enter deletion handling.

### Confirmed deleted

A second independent direct lookup returning `NOT_FOUND` applies the configured non-destructive policy:

- Draft (default)
- Keep published
- Trash

The plugin never permanently deletes a Social Post automatically.

### Detached

Detached posts skip remote edits and existence reconciliation forever unless an editor reattaches them. Identity, attribution, and original URL remain.

## Recovery

If a missing item reappears, its remote status and missing timestamp recover on reconciliation. WordPress publication status is not automatically promoted from Draft because that would override a local editorial state.
