<?php
/**
 * Title policy utilities (editorial vs generated placeholders).
 *
 * @package AtomicWPSocialSync
 */
namespace AtomicWPSocialSync\Support;

final class TitlePolicy {
	/**
	 * Detects Atomic-generated placeholder titles only.
	 *
	 * IMPORTANT: This must be strict to avoid misclassifying real editorial titles.
	 */
	public static function isGeneratedPlaceholderTitle( string $title ): bool {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return false;
		}

		// Historical Atomic placeholders (strict, numeric-only).
		// Examples:
		// - "LinkedIn post 7500553868422897664"
		// - "LinkedIn Activity 7500553869874069507"
		//
		// Some historical imports also used the exact labels without an ID (still Atomic-generated).
		// Examples:
		// - "LinkedIn post"
		// - "LinkedIn Activity"
		if ( 'LinkedIn post' === $title || 'LinkedIn Activity' === $title ) {
			return true;
		}
		if ( 1 === preg_match( '/^LinkedIn post ([0-9]+)$/', $title ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^LinkedIn Activity ([0-9]+)$/', $title ) ) {
			return true;
		}

		// Historical faulty parser output (strict, structural, localized accessibility label).
		//
		// Examples (must be considered replaceable):
		// - "Post du fil d’activité numéro 8 ERMN-European Rural Mobility Network 121"
		// - "Post du fil d’actualité numéro 8"
		//
		// IMPORTANT: do NOT hardcode actor names, follower counts, or organizations.
		// Match only the known accessibility/title structure.
		if (
			1 === preg_match(
				'/^Post du fil d[’\'](?:activit[ée]|actualit[ée])\s+num[ée]ro\s+([0-9]+)(?:\b.*)?$/u',
				$title
			)
		) {
			return true;
		}

		return false;
	}

	private function __construct() {}
}
