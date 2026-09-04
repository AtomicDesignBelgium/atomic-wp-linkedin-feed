<?php
/**
 * LinkedIn official embed parsing and URL generation (embed-mode only).
 *
 * Security invariants:
 * - Never persist arbitrary HTML.
 * - Only accept HTTPS LinkedIn embed URLs matching:
 *   https://www.linkedin.com/embed/feed/update/<URN>
 * - Only accept explicitly supported URN forms (v1: urn:li:share:<id>).
 * - Only accept the observed compact variant query: ?collapsed=1
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Providers\LinkedIn;

use RuntimeException;

final class LinkedInEmbed {
	public const HOST = 'www.linkedin.com';
	public const EMBED_PATH_PREFIX = '/embed/feed/update/';

	// Observed (not documented as a stable contract): compact uses ?collapsed=1.
	public const COMPACT_QUERY_KEY = 'collapsed';
	public const COMPACT_QUERY_VALUE = '1';

	// V1 allowlist.
	public const URN_SHARE_REGEX    = '/^urn:li:share:\d+$/';
	public const URN_ACTIVITY_REGEX = '/^urn:li:activity:\d+$/';

	public const STRATEGY_OFFICIAL         = 'official';
	public const STRATEGY_ACTIVITY_FALLBACK = 'activity_fallback';

	public const DEFAULT_HEIGHT_COMPACT = 650;
	// Full embed heights vary widely and are not reliably measurable cross-origin.
	// We only use a large height when an official iframe provided it; otherwise we default to compact-like sizing.
	public const DEFAULT_HEIGHT_FULL    = 900;
	public const DEFAULT_HEIGHT_ACTIVITY = 720;

	public const MIN_HEIGHT = 200;
	public const MAX_HEIGHT = 3000;

	/**
	 * @return array{strategy:string,urn:string,is_compact:bool,height:int|null}
	 */
	public static function parseInput( string $input ): array {
		// Normalize common copy/paste variants:
		// - decode HTML entities (e.g. &quot;, &lt;iframe&gt;)
		// - strip accidental backticks from Markdown contexts
		// - trim whitespace
		$input = html_entity_decode( $input, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$input = str_replace( '`', '', $input );
		$input = trim( $input );
		if ( '' === $input ) {
			throw new RuntimeException( __( 'Paste the LinkedIn embed code, embed URL, or Share URN.', 'atomic-wp-social-sync' ) );
		}

		// Numeric Share ID only.
		if ( ctype_digit( $input ) ) {
			return array(
				'strategy'  => self::STRATEGY_OFFICIAL,
				'urn'       => 'urn:li:share:' . $input,
				'is_compact' => false,
				'height'    => null,
			);
		}

		if ( str_contains( $input, '<' ) ) {
			return self::parseIframeHtml( $input );
		}

		if ( str_starts_with( $input, 'http://' ) || str_starts_with( $input, 'https://' ) ) {
			return self::parseUrl( $input );
		}

		if ( str_starts_with( $input, 'urn:li:' ) ) {
			if ( self::isSupportedShareUrn( $input ) ) {
				return array(
					'strategy'  => self::STRATEGY_OFFICIAL,
					'urn'       => $input,
					'is_compact' => false,
					'height'    => null,
				);
			}
			if ( self::isSupportedActivityUrn( $input ) ) {
				return array(
					'strategy'  => self::STRATEGY_ACTIVITY_FALLBACK,
					'urn'       => $input,
					'is_compact' => false,
					'height'    => null,
				);
			}
			if ( str_starts_with( $input, 'urn:li:activity:' ) ) {
				throw new RuntimeException( __( 'This LinkedIn post link contains an Activity URN that may not have an official embed. Atomic will attempt a compatibility embed only when a valid public LinkedIn post link is provided.', 'atomic-wp-social-sync' ) );
			}
			throw new RuntimeException( __( 'Only LinkedIn Share URNs or supported public LinkedIn post links are accepted.', 'atomic-wp-social-sync' ) );
		}

		throw new RuntimeException( __( 'Unrecognized LinkedIn input. Paste embed code, an embed URL, a Share URN, a Share ID, or a LinkedIn post link.', 'atomic-wp-social-sync' ) );
	}

	public static function isSupportedShareUrn( string $urn ): bool {
		return (bool) preg_match( self::URN_SHARE_REGEX, $urn );
	}

	public static function isSupportedActivityUrn( string $urn ): bool {
		return (bool) preg_match( self::URN_ACTIVITY_REGEX, $urn );
	}

	public static function isSupportedUrn( string $urn ): bool {
		return self::isSupportedShareUrn( $urn ) || self::isSupportedActivityUrn( $urn );
	}

	/**
	 * @return array{strategy:string,urn:string,is_compact:bool,height:int|null}
	 */
	private static function parseIframeHtml( string $html ): array {
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = str_replace( '`', '', $html );

		// Hard rejects: scripts and inline event handlers.
		if ( preg_match( '/<\s*script\b/i', $html ) ) {
			throw new RuntimeException( __( 'Scripts are not allowed in embed input.', 'atomic-wp-social-sync' ) );
		}
		if ( preg_match( '/\son[a-z]+\s*=/i', $html ) ) {
			throw new RuntimeException( __( 'Event handlers are not allowed in embed input.', 'atomic-wp-social-sync' ) );
		}

		// We parse strictly: accept only a single iframe element.
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			throw new RuntimeException( __( 'Embed HTML could not be parsed.', 'atomic-wp-social-sync' ) );
		}

		$iframes = $doc->getElementsByTagName( 'iframe' );
		if ( 1 !== $iframes->length ) {
			throw new RuntimeException( __( 'Embed HTML must contain exactly one iframe.', 'atomic-wp-social-sync' ) );
		}
		$iframe = $iframes->item( 0 );
		if ( ! $iframe instanceof \DOMElement ) {
			throw new RuntimeException( __( 'Embed HTML iframe could not be read.', 'atomic-wp-social-sync' ) );
		}

		$src = trim( (string) $iframe->getAttribute( 'src' ) );
		$src = trim( str_replace( '`', '', $src ) );
		if ( '' === $src ) {
			throw new RuntimeException( __( 'Iframe src is required.', 'atomic-wp-social-sync' ) );
		}
		if ( str_starts_with( strtolower( $src ), 'javascript:' ) ) {
			throw new RuntimeException( __( 'JavaScript URLs are not allowed.', 'atomic-wp-social-sync' ) );
		}

		$height = null;
		$height_raw = trim( (string) $iframe->getAttribute( 'height' ) );
		if ( '' !== $height_raw ) {
			$height_int = (int) $height_raw;
			if ( $height_int < self::MIN_HEIGHT || $height_int > self::MAX_HEIGHT ) {
				throw new RuntimeException( __( 'Iframe height is out of allowed bounds.', 'atomic-wp-social-sync' ) );
			}
			$height = $height_int;
		}

		$parsed = self::parseUrl( $src );
		$parsed['height'] = $height;
		return $parsed;
	}

	/**
	 * @return array{strategy:string,urn:string,is_compact:bool,height:int|null}
	 */
	private static function parseUrl( string $url ): array {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = trim( str_replace( '`', '', $url ) );

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			throw new RuntimeException( __( 'Invalid embed URL.', 'atomic-wp-social-sync' ) );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme ) {
			throw new RuntimeException( __( 'Embed URL must use HTTPS.', 'atomic-wp-social-sync' ) );
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 'www.linkedin.com' !== $host && 'linkedin.com' !== $host ) {
			throw new RuntimeException( __( 'LinkedIn URLs must be hosted on linkedin.com.', 'atomic-wp-social-sync' ) );
		}
		$path = (string) ( $parts['path'] ?? '' );

		// Official embed URL path.
		if ( str_starts_with( $path, self::EMBED_PATH_PREFIX ) ) {
			return self::parseEmbedUrlPath( $parts );
		}

		// Public post permalink (compatibility fallback): extract activity id from known forms.
		$activity_id = self::extractActivityIdFromUrl( $url );
		if ( null !== $activity_id ) {
			return array(
				'strategy'   => self::STRATEGY_ACTIVITY_FALLBACK,
				'urn'        => 'urn:li:activity:' . $activity_id,
				'is_compact' => false,
				'height'     => null,
			);
		}

		throw new RuntimeException( __( 'Unsupported LinkedIn URL. Paste an official LinkedIn embed URL or a public LinkedIn post link.', 'atomic-wp-social-sync' ) );
	}

	/**
	 * @param array<string,mixed> $parts
	 * @return array{strategy:string,urn:string,is_compact:bool,height:int|null}
	 */
	private static function parseEmbedUrlPath( array $parts ): array {
		$path = (string) ( $parts['path'] ?? '' );
		$urn  = substr( $path, strlen( self::EMBED_PATH_PREFIX ) );
		$urn  = rtrim( (string) $urn, '/' );

		$strategy = null;
		if ( self::isSupportedShareUrn( $urn ) ) {
			$strategy = self::STRATEGY_OFFICIAL;
		} elseif ( self::isSupportedActivityUrn( $urn ) ) {
			$strategy = self::STRATEGY_ACTIVITY_FALLBACK;
		} else {
			if ( str_starts_with( $urn, 'urn:li:activity:' ) ) {
				throw new RuntimeException( __( 'Unsupported LinkedIn Activity embed URL. Paste the public LinkedIn post link instead.', 'atomic-wp-social-sync' ) );
			}
			throw new RuntimeException( __( 'Unsupported LinkedIn embed URN.', 'atomic-wp-social-sync' ) );
		}

		$is_compact = false;
		$query = (string) ( $parts['query'] ?? '' );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$params = is_array( $params ) ? $params : array();
			if ( 1 !== count( $params ) || ! array_key_exists( self::COMPACT_QUERY_KEY, $params ) ) {
				throw new RuntimeException( __( 'Embed URL contains unsupported query parameters.', 'atomic-wp-social-sync' ) );
			}
			$is_compact = self::COMPACT_QUERY_VALUE === (string) $params[ self::COMPACT_QUERY_KEY ];
			if ( ! $is_compact ) {
				throw new RuntimeException( __( 'Embed URL contains an unsupported collapsed value.', 'atomic-wp-social-sync' ) );
			}
		}

		return array(
			'strategy'   => $strategy ?? self::STRATEGY_OFFICIAL,
			'urn'        => $urn,
			'is_compact' => $is_compact,
			'height'     => null,
		);
	}

	public static function embedUrl( string $urn, string $presentation, string $strategy = self::STRATEGY_OFFICIAL ): string {
		if ( ! self::isSupportedUrn( $urn ) ) {
			return '';
		}
		$base = 'https://' . self::HOST . self::EMBED_PATH_PREFIX . $urn;

		// Compatibility embeds: do not assume the compact variant works.
		if ( self::STRATEGY_ACTIVITY_FALLBACK === $strategy ) {
			return $base;
		}

		if ( 'compact' === $presentation ) {
			return $base . '?' . rawurlencode( self::COMPACT_QUERY_KEY ) . '=' . rawurlencode( self::COMPACT_QUERY_VALUE );
		}
		return $base;
	}

	private static function extractActivityIdFromUrl( string $url ): ?string {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = str_replace( '`', '', $url );

		if ( preg_match( '/urn:li:activity:(\d+)/i', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/activity-(\d+)/i', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}
}
