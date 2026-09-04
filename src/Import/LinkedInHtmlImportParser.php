<?php
/**
 * LinkedIn HTML import parser (manual / admin-supplied).
 *
 * Security goals:
 * - Treat input as untrusted
 * - Never execute scripts
 * - Never load external resources (LIBXML_NONET)
 * - Never persist raw HTML
 *
 * Parsing goals:
 * - Extract activity URNs from `data-urn="urn:li:activity:<id>"`
 * - Deduplicate by normalized URN
 * - Extract public permalinks when available and safe
 *
 * @package AtomicWPSocialSync
 */
namespace AtomicWPSocialSync\Import;

use DOMDocument;
use DOMXPath;

final class LinkedInHtmlImportParser {
	/**
	 * @param string $html
	 * @return array<int,array{urn:string,activity_id:string,permalink:string|null}>
	 */
	public function parse( string $html ): array {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}

		$doc = new DOMDocument();
		$prior = libxml_use_internal_errors( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@$doc->loadHTML( $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		$xpath = new DOMXPath( $doc );

		$urns = array();
		/** @var \DOMAttr $attr */
		foreach ( $xpath->query( '//@data-urn' ) as $attr ) {
			$urn = trim( (string) $attr->value );
			if ( preg_match( '/^urn:li:activity:(\d+)$/', $urn, $m ) ) {
				$activity_id = (string) $m[1];
				$urns[ $urn ] = array(
					'urn'         => $urn,
					'activity_id' => $activity_id,
					'permalink'   => null,
				);
			}
		}

		// Attempt to find permalinks in hrefs.
		/** @var \DOMAttr $href */
		foreach ( $xpath->query( '//a/@href' ) as $href ) {
			$url = trim( (string) $href->value );
			if ( '' === $url ) {
				continue;
			}
			$permalink = $this->normalizeLinkedInPermalink( $url );
			if ( null === $permalink ) {
				continue;
			}
			if ( preg_match( '/urn:li:activity:(\d+)/i', $permalink, $m ) ) {
				$urn = 'urn:li:activity:' . (string) $m[1];
				if ( isset( $urns[ $urn ] ) && null === $urns[ $urn ]['permalink'] ) {
					$urns[ $urn ]['permalink'] = $permalink;
				}
			}
			if ( preg_match( '/activity-(\d+)/i', $permalink, $m ) ) {
				$urn = 'urn:li:activity:' . (string) $m[1];
				if ( isset( $urns[ $urn ] ) && null === $urns[ $urn ]['permalink'] ) {
					$urns[ $urn ]['permalink'] = $permalink;
				}
			}
		}

		return array_values( $urns );
	}

	private function normalizeLinkedInPermalink( string $url ): ?string {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		// Ignore protocol-relative and relative URLs.
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $host, array( 'linkedin.com', 'www.linkedin.com' ), true ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path ) {
			return null;
		}

		// Drop query/fragment to remove obvious tracking.
		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );

		return 'https://www.linkedin.com' . $path;
	}
}

