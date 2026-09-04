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
	public const URN_SHARE_REGEX = '/^urn:li:share:\d+$/';

	public const DEFAULT_HEIGHT_COMPACT = 650;
	public const DEFAULT_HEIGHT_FULL    = 1350;

	public const MIN_HEIGHT = 200;
	public const MAX_HEIGHT = 3000;

	/**
	 * @return array{urn:string,is_compact:bool,height:int|null}
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
				'urn'       => 'urn:li:share:' . $input,
				'is_compact' => false,
				'height'    => null,
			);
		}

		if ( str_contains( $input, '<' ) ) {
			return self::parseIframeHtml( $input );
		}

		if ( str_starts_with( $input, 'http://' ) || str_starts_with( $input, 'https://' ) ) {
			return self::parseEmbedUrl( $input );
		}

		if ( str_starts_with( $input, 'urn:li:' ) ) {
			if ( ! self::isSupportedUrn( $input ) ) {
				throw new RuntimeException( __( 'Only LinkedIn Share URNs are supported in v1 (urn:li:share:<id>).', 'atomic-wp-social-sync' ) );
			}
			return array(
				'urn'       => $input,
				'is_compact' => false,
				'height'    => null,
			);
		}

		throw new RuntimeException( __( 'Unrecognized LinkedIn embed input. Paste iframe code, an embed URL, or a Share URN.', 'atomic-wp-social-sync' ) );
	}

	public static function isSupportedUrn( string $urn ): bool {
		return (bool) preg_match( self::URN_SHARE_REGEX, $urn );
	}

	/**
	 * @return array{urn:string,is_compact:bool,height:int|null}
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

		$parsed = self::parseEmbedUrl( $src );
		$parsed['height'] = $height;
		return $parsed;
	}

	/**
	 * @return array{urn:string,is_compact:bool,height:int|null}
	 */
	public static function parseEmbedUrl( string $url ): array {
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
		if ( self::HOST !== $host ) {
			throw new RuntimeException( __( 'Embed URL must be hosted on www.linkedin.com.', 'atomic-wp-social-sync' ) );
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! str_starts_with( $path, self::EMBED_PATH_PREFIX ) ) {
			throw new RuntimeException( __( 'Embed URL must start with /embed/feed/update/.', 'atomic-wp-social-sync' ) );
		}

		$urn = substr( $path, strlen( self::EMBED_PATH_PREFIX ) );
		$urn = rtrim( $urn, '/' );
		if ( ! self::isSupportedUrn( $urn ) ) {
			if ( str_starts_with( $urn, 'urn:li:activity:' ) ) {
				throw new RuntimeException( __( 'Activity URNs are not supported for embeds in v1. Use the Share URN from the official embed.', 'atomic-wp-social-sync' ) );
			}
			throw new RuntimeException( __( 'Unsupported embed URN. Only urn:li:share:<id> is supported in v1.', 'atomic-wp-social-sync' ) );
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
			'urn'        => $urn,
			'is_compact' => $is_compact,
			'height'     => null,
		);
	}

	public static function embedUrl( string $urn, string $presentation ): string {
		if ( ! self::isSupportedUrn( $urn ) ) {
			return '';
		}
		$base = 'https://' . self::HOST . self::EMBED_PATH_PREFIX . $urn;
		if ( 'compact' === $presentation ) {
			return $base . '?' . rawurlencode( self::COMPACT_QUERY_KEY ) . '=' . rawurlencode( self::COMPACT_QUERY_VALUE );
		}
		return $base;
	}
}
