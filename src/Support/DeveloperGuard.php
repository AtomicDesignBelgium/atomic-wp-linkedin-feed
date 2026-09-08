<?php
/**
 * Server-side guards for Developer / Maintenance context.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

final class DeveloperGuard {
	private function __construct() {}

	public static function isDeveloperContext(): bool {
		return current_user_can( 'manage_options' )
			&& defined( 'WP_DEBUG' )
			&& true === WP_DEBUG;
	}

	public static function areDeveloperToolsEnabled(): bool {
		if ( ! self::isDeveloperContext() ) {
			return false;
		}
		$settings = new PluginSettings();
		return (bool) $settings->get( 'developer_tools', false );
	}

	public static function requireAdminOrDie(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage these settings.', 'atomic-wp-social-sync' ),
				esc_html__( 'Forbidden', 'atomic-wp-social-sync' ),
				array( 'response' => 403 )
			);
		}
	}

	public static function requireDeveloperToolsOrDie(): void {
		self::requireAdminOrDie();
		if ( ! self::areDeveloperToolsEnabled() ) {
			wp_die(
				esc_html__( 'Developer tools are not enabled in this context.', 'atomic-wp-social-sync' ),
				esc_html__( 'Forbidden', 'atomic-wp-social-sync' ),
				array( 'response' => 403 )
			);
		}
	}
}
