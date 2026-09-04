<?php
/**
 * Central post metadata registry.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

final class MetaKeys {
	public const EXTERNAL_ID            = '_atomic_social_external_id';
	public const EXTERNAL_URL           = '_atomic_social_external_url';
	public const PROVIDER               = '_atomic_social_provider';
	public const CONNECTION_ID          = '_atomic_social_connection_id';
	public const REMOTE_TEXT            = '_atomic_social_remote_text';
	public const REMOTE_PUBLISHED_AT    = '_atomic_social_remote_published_at';
	public const REMOTE_MODIFIED_AT     = '_atomic_social_remote_modified_at';
	public const REMOTE_AUTHOR_ID       = '_atomic_social_remote_author_id';
	public const REMOTE_AUTHOR_NAME     = '_atomic_social_remote_author_name';
	public const LAST_SYNCED_AT         = '_atomic_social_last_synced_at';
	public const LAST_VERIFIED_AT       = '_atomic_social_last_verified_at';
	public const CONTENT_HASH           = '_atomic_social_content_hash';
	public const LOCAL_CONTENT_HASH     = '_atomic_social_local_content_hash';
	public const REMOTE_STATUS          = '_atomic_social_remote_status';
	public const RAW_TYPE               = '_atomic_social_raw_type';
	public const REMOTE_MISSING_SINCE   = '_atomic_social_remote_missing_since';
	public const REMOTE_MISSING_CONFIRMATIONS = '_atomic_social_remote_missing_confirmations';
	public const REMOTE_MISSING_LAST_CONFIRMED_AT = '_atomic_social_remote_missing_last_confirmed_at';
	public const MEDIA_TYPE             = '_atomic_social_media_type';
	public const MEDIA_SOURCE_ID        = '_atomic_social_media_source_id';
	public const GENERATED_TITLE        = '_atomic_social_generated_title';
	public const SHOW_ON_HOMEPAGE       = '_atomic_social_show_on_homepage';
	public const HIDDEN_FROM_FEED       = '_atomic_social_hidden_from_feed';
	public const PINNED                  = '_atomic_social_pinned';
	public const DETACHED                = '_atomic_social_detached';
	public const TITLE_LOCKED            = '_atomic_social_title_locked';
	public const EXCERPT_OVERRIDE        = '_atomic_social_excerpt_override';
	public const FEATURED_IMAGE_LOCKED   = '_atomic_social_featured_image_locked';
	public const SYNC_CONFLICT           = '_atomic_social_sync_conflict';
	public const PENDING_REMOTE          = '_atomic_social_pending_remote';

	// Integration modes (import/embed/link). Missing means legacy import behavior.
	public const INTEGRATION_MODE       = '_atomic_social_integration_mode';
	// LinkedIn embed storage (embed-mode only).
	public const EMBED_STRATEGY         = '_atomic_social_embed_strategy';
	public const EMBED_URN              = '_atomic_social_embed_urn';
	public const EMBED_HEIGHT_COMPACT   = '_atomic_social_embed_height_compact';
	public const EMBED_HEIGHT_FULL      = '_atomic_social_embed_height_full';

	private function __construct() {}
}
