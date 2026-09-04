<?php
/**
 * Social Post integration mode.
 *
 * Legacy behavior: missing meta implies import-mode rendering.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Support;

final class IntegrationMode {
	public const IMPORT = 'import';
	public const EMBED  = 'embed';
	public const LINK   = 'link';

	/** @return array<string,string> */
	public static function all(): array {
		return array(
			self::IMPORT => self::IMPORT,
			self::EMBED  => self::EMBED,
			self::LINK   => self::LINK,
		);
	}

	public static function sanitize( mixed $value ): string {
		$value = sanitize_key( (string) $value );
		return array_key_exists( $value, self::all() ) ? $value : self::IMPORT;
	}
}

