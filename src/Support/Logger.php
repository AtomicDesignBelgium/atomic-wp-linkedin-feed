<?php
/**
 * Bounded, secret-safe plugin logger.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

final class Logger {
	private const MAX_ENTRIES = 100;

	public function __construct( private readonly PluginSettings $settings ) {}

	/** @param array<string,scalar|null> $context */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/** @param array<string,scalar|null> $context */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->settings->get( 'debug_logging', false ) ) {
			$this->write( 'debug', $message, $context );
		}
	}

	/** @param array<string,scalar|null> $context */
	private function write( string $level, string $message, array $context ): void {
		$forbidden = array( 'access_token', 'refresh_token', 'client_secret', 'authorization_code', 'code' );
		$safe      = array();
		foreach ( $context as $key => $value ) {
			if ( ! in_array( strtolower( $key ), $forbidden, true ) ) {
				$safe[ sanitize_key( $key ) ] = $value;
			}
		}

		$entries   = get_option( PluginSettings::SYNC_LOG, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'time'    => gmdate( 'c' ),
			'level'   => $level,
			'message' => sanitize_text_field( $message ),
			'context' => $safe,
		);
		update_option( PluginSettings::SYNC_LOG, array_slice( $entries, -self::MAX_ENTRIES ), false );
	}
}
