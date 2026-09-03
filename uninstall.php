<?php
/**
 * Credential and runtime cleanup only.
 *
 * Imported Social Posts, Media Library items, and editorial changes deliberately
 * remain owned by the site after uninstall.
 *
 * @package AtomicWPSocialSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'atomic_wp_social_sync_scheduler' );
wp_clear_scheduled_hook( 'atomic_social_cleanup_pending_credentials' );

delete_option( 'atomic_wp_social_sync_settings' );
delete_option( 'atomic_wp_social_sync_schema_version' );
delete_option( 'atomic_wp_social_sync_connections' );
delete_option( 'atomic_wp_social_sync_credentials' );
delete_option( 'atomic_wp_social_sync_log' );
delete_option( 'atomic_wp_social_sync_flush_rewrite' );

// Locks and short-lived OAuth transients contain no content and are safe to remove.
global $wpdb;
$runtime_prefixes = array(
	'atomic_social_sync_lock_',
	'_transient_atomic_social_oauth_',
	'_transient_timeout_atomic_social_oauth_',
	'_transient_atomic_social_pending_',
	'_transient_timeout_atomic_social_pending_',
);
foreach ( $runtime_prefixes as $runtime_prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $runtime_prefix ) . '%'
		)
	);
}
