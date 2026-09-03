<?php
/**
 * Plugin setting names, defaults, and sanitization.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

final class PluginSettings {
	public const OPTION_NAME       = 'atomic_wp_social_sync_settings';
	public const SCHEMA_OPTION     = 'atomic_wp_social_sync_schema_version';
	public const CONNECTIONS       = 'atomic_wp_social_sync_connections';
	public const CREDENTIALS       = 'atomic_wp_social_sync_credentials';
	public const SYNC_LOG          = 'atomic_wp_social_sync_log';
	public const EDIT_AUTOMATIC    = 'automatic';
	public const EDIT_REVIEW       = 'review';
	public const EDIT_IGNORE       = 'ignore';
	public const DELETE_DRAFT      = 'draft';
	public const DELETE_KEEP       = 'keep';
	public const DELETE_TRASH      = 'trash';
	public const FREQUENCY_OFF     = 'disabled';
	public const FREQUENCY_HOURLY  = 'hourly';
	public const FREQUENCY_TWICE   = 'twicedaily';
	public const FREQUENCY_DAILY   = 'daily';

	/** @return array<string,mixed> */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		$settings = $this->all();
		return $settings[ $key ] ?? $fallback;
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'default_sync_frequency' => self::FREQUENCY_TWICE,
			'remote_edit_policy'      => self::EDIT_AUTOMATIC,
			'remote_delete_policy'    => self::DELETE_DRAFT,
			'import_images'           => true,
			'enable_single_pages'     => false,
			'linkedin_client_id'      => '',
			'debug_logging'           => false,
		);
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'default_sync_frequency' => self::allowed( $input['default_sync_frequency'] ?? '', self::frequencies(), self::FREQUENCY_TWICE ),
			'remote_edit_policy'      => self::allowed( $input['remote_edit_policy'] ?? '', self::editPolicies(), self::EDIT_AUTOMATIC ),
			'remote_delete_policy'    => self::allowed( $input['remote_delete_policy'] ?? '', self::deletePolicies(), self::DELETE_DRAFT ),
			'import_images'           => ! empty( $input['import_images'] ),
			'enable_single_pages'     => ! empty( $input['enable_single_pages'] ),
			'linkedin_client_id'      => sanitize_text_field( (string) ( $input['linkedin_client_id'] ?? '' ) ),
			'debug_logging'           => ! empty( $input['debug_logging'] ),
		);
	}

	/** @return array<string,string> */
	public static function frequencies(): array {
		return array(
			self::FREQUENCY_OFF    => __( 'Disabled', 'atomic-wp-social-sync' ),
			self::FREQUENCY_HOURLY => __( 'Hourly', 'atomic-wp-social-sync' ),
			self::FREQUENCY_TWICE  => __( 'Twice daily', 'atomic-wp-social-sync' ),
			self::FREQUENCY_DAILY  => __( 'Daily', 'atomic-wp-social-sync' ),
		);
	}

	/** @return array<string,string> */
	public static function editPolicies(): array {
		return array(
			self::EDIT_AUTOMATIC => __( 'Update automatically', 'atomic-wp-social-sync' ),
			self::EDIT_REVIEW    => __( 'Require review', 'atomic-wp-social-sync' ),
			self::EDIT_IGNORE    => __( 'Ignore remote edits', 'atomic-wp-social-sync' ),
		);
	}

	/** @return array<string,string> */
	public static function deletePolicies(): array {
		return array(
			self::DELETE_DRAFT => __( 'Move local copy to Draft', 'atomic-wp-social-sync' ),
			self::DELETE_KEEP  => __( 'Keep local copy published', 'atomic-wp-social-sync' ),
			self::DELETE_TRASH => __( 'Move local copy to Trash', 'atomic-wp-social-sync' ),
		);
	}

	/** @param array<string,string> $choices */
	private static function allowed( mixed $value, array $choices, string $default ): string {
		$value = sanitize_key( (string) $value );
		return array_key_exists( $value, $choices ) ? $value : $default;
	}
}
