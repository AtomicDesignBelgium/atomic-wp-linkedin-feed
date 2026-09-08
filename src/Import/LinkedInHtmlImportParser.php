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
	 * @return array<int,array{
	 *   urn:string,
	 *   activity_id:string,
	 *   permalink:string|null,
	 *   suggested_title:string,
	 *   text_length:int,
	 *   media_type:array<int,string>,
	 *   media_count:int,
	 *   source_width:int,
	 *   source_height:int,
	 *   aspect_data:array{width:int,height:int,ratio:float}|array{},
	 *   content_profile:string
	 * }>
	 */
	public function parse( string $html ): array {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}

		$doc = new DOMDocument();
		$prior = libxml_use_internal_errors( true );
		// DOMDocument assumes ISO-8859-1 when HTML has no charset declaration, which corrupts
		// LinkedIn exports that contain emoji and Mathematical Alphanumeric Symbols.
		// Force UTF-8 deterministically via XML prolog trick.
		if ( 0 !== strpos( $html, '<?xml' ) ) {
			$html = '<?xml encoding="UTF-8">' . $html;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@$doc->loadHTML( $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		$xpath = new DOMXPath( $doc );

		$urns = array();
		/** @var \DOMElement $node */
		foreach ( $xpath->query( '//*[@data-urn]' ) as $node ) {
			$urn = trim( (string) $node->getAttribute( 'data-urn' ) );
			if ( preg_match( '/^urn:li:activity:(\d+)$/', $urn, $m ) ) {
				$activity_id = (string) $m[1];
				if ( ! isset( $urns[ $urn ] ) ) {
					$urns[ $urn ] = array(
						'urn'             => $urn,
						'activity_id'     => $activity_id,
						'permalink'       => null,
						'suggested_title' => '',
						'text_length'     => 0,
						'media_type'      => array(),
						'media_count'     => 0,
						'source_width'    => 0,
						'source_height'   => 0,
						'aspect_data'     => array(),
						'content_profile' => 'unknown',
					);
				}

				if ( '' === $urns[ $urn ]['suggested_title'] ) {
					$title = $this->suggestTitleFromNode( $node );
					if ( '' !== $title ) {
						$urns[ $urn ]['suggested_title'] = $title;
					}
				}

				$metadata = $this->extractMetadataFromNode( $node );
				if ( $metadata['text_length'] > (int) $urns[ $urn ]['text_length'] ) {
					$urns[ $urn ]['text_length'] = $metadata['text_length'];
				}
				if ( $metadata['media_count'] > (int) $urns[ $urn ]['media_count'] ) {
					$urns[ $urn ]['media_count'] = $metadata['media_count'];
				}
				if ( $metadata['source_width'] > 0 && (int) $urns[ $urn ]['source_width'] <= 0 ) {
					$urns[ $urn ]['source_width'] = $metadata['source_width'];
				}
				if ( $metadata['source_height'] > 0 && (int) $urns[ $urn ]['source_height'] <= 0 ) {
					$urns[ $urn ]['source_height'] = $metadata['source_height'];
				}
				if ( ! empty( $metadata['aspect_data'] ) && empty( $urns[ $urn ]['aspect_data'] ) ) {
					$urns[ $urn ]['aspect_data'] = $metadata['aspect_data'];
				}
				if ( 'unknown' === (string) $urns[ $urn ]['content_profile'] && 'unknown' !== $metadata['content_profile'] ) {
					$urns[ $urn ]['content_profile'] = $metadata['content_profile'];
				}
				if ( ! empty( $metadata['media_type'] ) ) {
					$urns[ $urn ]['media_type'] = array_values(
						array_unique(
							array_merge(
								is_array( $urns[ $urn ]['media_type'] ) ? $urns[ $urn ]['media_type'] : array(),
								$metadata['media_type']
							)
						)
					);
				}
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

	private function suggestTitleFromNode( \DOMElement $node ): string {
		// IMPORTANT:
		// LinkedIn saved HTML includes accessibility / actor / UI text before the actual post commentary.
		// We must never derive titles from the full article textContent. Prefer the commentary node only.

		$context_root = $this->findPostContextRoot( $node );
		$commentary_node = $this->findCommentaryNode( $context_root );
		if ( ! $commentary_node instanceof \DOMElement ) {
			// No reliable commentary: return no suggested title rather than a contaminated one.
			return '';
		}

		$commentary = $this->extractMeaningfulText( $commentary_node );
		$commentary = $this->normalizeCommentaryText( $commentary );
		if ( '' === $commentary ) {
			return '';
		}

		$title = $this->deriveDeterministicTitleFromCommentary( $commentary );
		return $this->finalizeTitle( $title );
	}

	private function findPostContextRoot( \DOMElement $node ): \DOMElement {
		$cur = $node;
		for ( $i = 0; $i < 12; $i++ ) {
			if ( 'article' === strtolower( $cur->tagName ) ) {
				return $cur;
			}
			$class = (string) $cur->getAttribute( 'class' );
			if ( '' !== $class && ( str_contains( $class, 'feed-shared-update-v2' ) || str_contains( $class, 'occludable-update' ) ) ) {
				return $cur;
			}
			$parent = $cur->parentNode;
			if ( ! $parent instanceof \DOMElement ) {
				break;
			}
			$cur = $parent;
		}
		return $node;
	}

	private function findCommentaryNode( \DOMElement $context_root ): ?\DOMElement {
		$xpath = new DOMXPath( $context_root->ownerDocument );

		// Preferred selector order (from real saved HTML fixtures):
		// 1) .feed-shared-update-v2__description .update-components-update-v2__commentary
		$nodes = $xpath->query(
			'.//*[contains(concat(" ", normalize-space(@class), " "), " feed-shared-update-v2__description ")]' .
			'//*[contains(concat(" ", normalize-space(@class), " "), " update-components-update-v2__commentary ")]',
			$context_root
		);
		if ( $nodes instanceof \DOMNodeList && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
			return $nodes->item( 0 );
		}

		// 2) .feed-shared-update-v2__description .update-components-text
		$nodes = $xpath->query(
			'.//*[contains(concat(" ", normalize-space(@class), " "), " feed-shared-update-v2__description ")]' .
			'//*[contains(concat(" ", normalize-space(@class), " "), " update-components-text ")]',
			$context_root
		);
		if ( $nodes instanceof \DOMNodeList && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
			return $nodes->item( 0 );
		}

		// 3) Verified equivalents (only if present in fixtures).
		$nodes = $xpath->query(
			'.//*[contains(concat(" ", normalize-space(@class), " "), " update-components-update-v2__commentary ")]',
			$context_root
		);
		if ( $nodes instanceof \DOMNodeList && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
			return $nodes->item( 0 );
		}

		return null;
	}

	private function extractMeaningfulText( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return (string) $node->nodeValue;
		}
		if ( ! $node instanceof \DOMElement ) {
			$out = '';
			foreach ( $node->childNodes as $child ) {
				$out .= $this->extractMeaningfulText( $child );
			}
			return $out;
		}

		$tag = strtolower( $node->tagName );
		if ( in_array( $tag, array( 'script', 'style', 'svg', 'noscript' ), true ) ) {
			return '';
		}

		// Explicit exclusion: accessibility + actor + UI contamination.
		$class = ' ' . trim( (string) $node->getAttribute( 'class' ) ) . ' ';
		if ( str_contains( $class, ' visually-hidden ' ) ) {
			return '';
		}
		if ( str_contains( $class, ' update-components-actor__' ) || str_contains( $class, ' feed-shared-actor__' ) ) {
			return '';
		}
		if ( str_contains( $class, ' feed-shared-social-action-bar ' ) || str_contains( $class, ' feed-shared-social-actions ' ) ) {
			return '';
		}

		$aria_hidden = strtolower( trim( (string) $node->getAttribute( 'aria-hidden' ) ) );
		if ( 'true' === $aria_hidden ) {
			return '';
		}

		// Preserve line breaks / block boundaries.
		if ( 'br' === $tag ) {
			return "\n";
		}

		$is_block = in_array(
			$tag,
			array( 'p', 'div', 'li', 'ul', 'ol', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ),
			true
		);

		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $this->extractMeaningfulText( $child );
		}

		if ( $is_block ) {
			$out = trim( $out );
			if ( '' !== $out ) {
				$out .= "\n";
			}
		}

		return $out;
	}

	private function normalizeCommentaryText( string $text ): string {
		$text = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $text );

		// Drop invalid UTF-8 sequences deterministically (historical bad exports/imports).
		// This helps keep downstream regex operations stable when the source text is partially corrupted.
		$iconv = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
		if ( is_string( $iconv ) && '' !== $iconv ) {
			$text = $iconv;
		}

		// Safe Unicode normalization (math bold/italic etc) when intl is available.
		if ( class_exists( '\\Normalizer' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$text = @\Normalizer::normalize( $text, \Normalizer::FORM_KC ) ?: $text;
		} else {
			// Fallback: fold Mathematical Alphanumeric Symbols (e.g. 𝗔, 𝙎, 𝟮) to plain ASCII.
			// This is required for deterministic matching when `intl` is not present.
			$text = $this->foldMathAlphanumericsToAscii( $text );
		}

		// Collapse spaces but preserve newlines.
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", (string) $text );
		$text = trim( (string) $text );

		return (string) $text;
	}

	private function foldMathAlphanumericsToAscii( string $text ): string {
		if ( ! \function_exists( 'mb_ord' ) ) {
			return $text;
		}
		// Covers the common contiguous ranges used by LinkedIn's "bold/italic" text styling.
		// Reference ranges (subset): U+1D400..U+1D7FF.
		return (string) preg_replace_callback(
			'/[\x{1D400}-\x{1D7FF}]/u',
			static function ( array $m ): string {
				$cp = \mb_ord( (string) $m[0], 'UTF-8' );

				// Helper: map contiguous uppercase/lowercase blocks.
				$mapAlpha = static function ( int $cp, int $startUpper, int $startLower ): ?string {
					if ( $cp >= $startUpper && $cp <= ( $startUpper + 25 ) ) {
						return chr( ord( 'A' ) + ( $cp - $startUpper ) );
					}
					if ( $cp >= $startLower && $cp <= ( $startLower + 25 ) ) {
						return chr( ord( 'a' ) + ( $cp - $startLower ) );
					}
					return null;
				};

				// Helper: map contiguous digit blocks.
				$mapDigits = static function ( int $cp, int $startDigit ): ?string {
					if ( $cp >= $startDigit && $cp <= ( $startDigit + 9 ) ) {
						return chr( ord( '0' ) + ( $cp - $startDigit ) );
					}
					return null;
				};

				// Bold.
				$r = $mapAlpha( $cp, 0x1D400, 0x1D41A );
				if ( null !== $r ) { return $r; }
				$r = $mapDigits( $cp, 0x1D7CE );
				if ( null !== $r ) { return $r; }

				// Italic.
				$r = $mapAlpha( $cp, 0x1D434, 0x1D44E );
				if ( null !== $r ) { return $r; }

				// Bold italic.
				$r = $mapAlpha( $cp, 0x1D468, 0x1D482 );
				if ( null !== $r ) { return $r; }

				// Sans-serif.
				$r = $mapAlpha( $cp, 0x1D5A0, 0x1D5BA );
				if ( null !== $r ) { return $r; }
				$r = $mapDigits( $cp, 0x1D7E2 );
				if ( null !== $r ) { return $r; }

				// Sans-serif bold.
				$r = $mapAlpha( $cp, 0x1D5D4, 0x1D5EE );
				if ( null !== $r ) { return $r; }
				$r = $mapDigits( $cp, 0x1D7EC );
				if ( null !== $r ) { return $r; }

				// Sans-serif italic.
				$r = $mapAlpha( $cp, 0x1D608, 0x1D622 );
				if ( null !== $r ) { return $r; }

				// Sans-serif bold italic.
				$r = $mapAlpha( $cp, 0x1D63C, 0x1D656 );
				if ( null !== $r ) { return $r; }

				// Monospace.
				$r = $mapAlpha( $cp, 0x1D670, 0x1D68A );
				if ( null !== $r ) { return $r; }
				$r = $mapDigits( $cp, 0x1D7F6 );
				if ( null !== $r ) { return $r; }

				return (string) $m[0];
			},
			$text
		);
	}

	private function deriveDeterministicTitleFromCommentary( string $commentary ): string {
		$lines = $this->meaningfulLines( $commentary );
		if ( empty( $lines ) ) {
			return '';
		}

		// PRIORITY 1: explicit standalone headline / first meaningful short line.
		$first = $lines[0] ?? '';
		if ( '' !== $first && $this->isHeadlineCandidateLine( $first ) ) {
			return $first;
		}

		// PRIORITY 2: explicit quoted title candidate (may also serve as webinar base).
		$quoted = $this->extractQuotedTitle( $commentary );

		// PRIORITY 3: recognizable series identifier (Rural Mobility Pills).
		$series = $this->extractSeriesTitle( $commentary );
		if ( '' !== $series ) {
			return $series;
		}

		// PRIORITY 4: event/webinar semantics (wrap quoted base when present).
		$webinar = $this->extractWebinarTitle( $commentary, $quoted );
		if ( '' !== $webinar ) {
			return $webinar;
		}

		// If not a webinar context, the quoted title can stand on its own.
		if ( '' !== $quoted ) {
			return $quoted;
		}

		// PRIORITY 5: strong announcement sentence.
		$announcement = $this->extractAnnouncementLine( $lines );
		if ( '' !== $announcement ) {
			return $this->titleCase( $announcement );
		}

		// PRIORITY 6: first meaningful sentence as fallback (allow deterministic cleanup).
		$fallback = $this->firstMeaningfulSentence( $commentary );
		return $fallback;
	}

	/** @return array<int,string> */
	private function meaningfulLines( string $text ): array {
		$raw = preg_split( "/\n+/u", $text ) ?: array();
		$out = array();
		foreach ( $raw as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$line = $this->stripDecorativeLeadingEmoji( $line );
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	private function stripDecorativeLeadingEmoji( string $line ): string {
		// Remove leading non-alphanumeric symbols when clearly decorative (followed by whitespace).
		// Example: "📰 Stay updated..." => "Stay updated..."
		$line = preg_replace( '/^[^\p{L}\p{N}]+\s+/u', '', $line );
		return (string) $line;
	}

	private function isGenericIntroLine( string $line ): bool {
		$line = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $line ) : strtolower( $line );
		return str_contains( $line, 'rural mobility is complex' );
	}

	private function isHeadlineCandidateLine( string $line ): bool {
		$line = trim( $line );
		if ( '' === $line ) {
			return false;
		}
		// Sentence-like lead-ins are not headlines.
		if ( preg_match( '/[.?!]\s*$/u', $line ) === 1 ) {
			return false;
		}
		$len = \function_exists( 'mb_strlen' ) ? \mb_strlen( $line ) : strlen( $line );
		if ( $len > 110 ) {
			return false;
		}
		if ( str_ends_with( $line, ':' ) ) {
			return false;
		}
		if ( $this->isGenericIntroLine( $line ) ) {
			return false;
		}

		$lc = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $line ) : strtolower( $line );
		if ( str_contains( $lc, 'invites everyone' ) ) {
			return false;
		}
		if ( str_starts_with( $lc, 'thank you' ) || str_starts_with( $lc, 'thanks' ) ) {
			return false;
		}
		if ( str_contains( $lc, 'stay updated' ) || str_contains( $lc, 'get engaged' ) || str_contains( $lc, 'become a friend' ) ) {
			return false;
		}
		if ( str_contains( $lc, 'upcoming webinar' ) || str_contains( $lc, 'join our webinar' ) ) {
			return false;
		}
		return true;
	}

	private function extractQuotedTitle( string $commentary ): string {
		// Supports “...”, "..." and « ... ».
		//
		// IMPORTANT: Only treat quotes as a title when they look like an explicit title line,
		// or when they occur very early in the commentary. This avoids picking up incidental
		// quotes in the middle of a long post (e.g. newsletter posts referencing older event titles).
		$lines = $this->meaningfulLines( $commentary );
		foreach ( array_slice( $lines, 0, 3 ) as $line ) {
			if ( preg_match( '/^(?:[“"«]\s*)([^”"»]{8,180}?)(?:\s*[”"»])$/u', $line, $m ) ) {
				$title = trim( (string) $m[1] );
				$title = preg_replace( '/[.!?]\s*$/u', '', (string) $title );
				return trim( (string) $title );
			}
		}

		$early = $commentary;
		if ( \function_exists( 'mb_substr' ) ) {
			$early = (string) \mb_substr( $commentary, 0, 300 );
		} else {
			$early = substr( $commentary, 0, 300 );
		}
		if ( preg_match( '/[“"«]\s*([^”"»]{8,180}?)\s*[”"»]/u', $early, $m ) ) {
			$title = trim( (string) $m[1] );
			$title = preg_replace( '/[.!?]\s*$/u', '', (string) $title );
			return trim( (string) $title );
		}
		return '';
	}

	private function extractSeriesTitle( string $commentary ): string {
		if ( ! preg_match( '/Rural Mobility Pills/iu', $commentary ) ) {
			return '';
		}
		$n = 0;
		if ( preg_match( '/Pill\s*#\s*([0-9]+)/iu', $commentary, $m ) ) {
			$n = (int) $m[1];
		} elseif ( preg_match( '/\bfirst\s+Pill\b/iu', $commentary ) ) {
			$n = 1;
		} elseif ( preg_match( '/\bsecond\s+Pill\b/iu', $commentary ) ) {
			$n = 2;
		}
		if ( $n <= 0 ) {
			return '';
		}

		$topic = '';
		if ( preg_match( '/fund(?:ing)?|financ/iu', $commentary ) ) {
			$topic = 'Funding and Financing for Rural Mobility';
		} elseif ( preg_match( '/challenge/iu', $commentary ) ) {
			$topic = 'Rural Mobility Challenges';
		} else {
			$lines = $this->meaningfulLines( $commentary );
			foreach ( $lines as $line ) {
				if ( preg_match( '/Pill\s*#\s*' . preg_quote( (string) $n, '/' ) . '/iu', $line ) ) {
					continue;
				}
				$ll = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $line ) : strtolower( $line );
				if ( preg_match( '/\b(?:first|second)\s+Pill\b/iu', $line ) === 1 || str_contains( $ll, 'pill of the series' ) ) {
					continue;
				}
				if ( $this->isGenericIntroLine( $line ) ) {
					continue;
				}
				$line_len = \function_exists( 'mb_strlen' ) ? \mb_strlen( $line ) : strlen( $line );
				if ( $line_len <= 80 ) {
					$topic = $line;
					break;
				}
			}
		}

		$topic = trim( (string) $topic );
		if ( '' === $topic && 1 === $n ) {
			// Deterministic fallback for the common ERMN series structure.
			$topic = 'Rural Mobility Challenges';
		}
		if ( '' === $topic ) {
			return '';
		}
		return 'Rural Mobility Pills #' . $n . ' — ' . $topic;
	}

	private function extractWebinarTitle( string $commentary, string $quoted_title = '' ): string {
		$lc = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $commentary ) : strtolower( $commentary );
		$is_webinar = str_contains( $lc, 'webinar' );
		if ( ! $is_webinar ) {
			return '';
		}

		$is_recap = preg_match( '/thank you to everyone who joined|thanks to everyone who joined/iu', $commentary ) === 1;
		$is_upcoming = preg_match( '/upcoming webinar|join (?:our )?webinar|register (?:now|today)|save the date/iu', $commentary ) === 1;

		// IMPORTANT: some non-webinar posts mention webinars (e.g. newsletters recapping past activities).
		// Only apply the webinar heuristic when the post is clearly a recap or an upcoming invite.
		if ( ! $is_recap && ! $is_upcoming ) {
			return '';
		}

		$base = trim( (string) $quoted_title );

		// If a title is already explicitly present, keep it as-is for upcoming invites.
		// For recaps, keep it as the base and append "— Webinar Recap" deterministically.
		if ( '' !== $base ) {
			$base = preg_replace( '/[.!?]\s*$/u', '', (string) $base );
			$base = trim( (string) $base );
			if ( '' === $base ) {
				return '';
			}
			if ( $is_recap ) {
				$base_lc = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $base ) : strtolower( $base );
				return str_contains( $base_lc, 'webinar recap' ) ? $base : ( $base . ' — Webinar Recap' );
			}
			// Upcoming: do not append a suffix when the title is explicit.
			return $base;
		}

		// Extract explicit topics when present.
		// Prefer "webinar on <topic>", but fall back to "dedicated to <topic>" when the "webinar on"
		// capture is actually a date/time segment.
		if ( '' === $base && preg_match( '/webinar on\s+([^.!?\n]{5,120})/iu', $commentary, $m_on ) ) {
			$candidate = trim( (string) $m_on[1] );
			// Ignore "webinar on <date/time>" captures (common in invites). We want the topic.
			if ( preg_match( '/^(?:\d{1,2}(?:st|nd|rd|th)?\b|\d{4}\b)/iu', $candidate ) !== 1 ) {
				$base = $candidate;
			}
		}
		if ( '' === $base && preg_match( '/dedicated to\s+([^.!?\n]{5,120})/iu', $commentary, $m_ded ) ) {
			$candidate = trim( (string) $m_ded[1] );
			$candidate_lc = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $candidate ) : strtolower( $candidate );
			// Avoid generic "exploring how ..." phrasing which is not a stable title.
			if ( ! str_starts_with( $candidate_lc, 'exploring ' ) && ! str_starts_with( $candidate_lc, 'how ' ) ) {
				$base = $candidate;
			}
		}

		if ( '' !== $base ) {
			$base = preg_replace( '/\s+solutions$/iu', '', $base );
			$base = preg_replace( '/[.!?]\s*$/u', '', (string) $base );
			$base = trim( (string) $base );
		}

		if ( '' === $base ) {
			$lines = $this->meaningfulLines( $commentary );
			foreach ( $lines as $line ) {
				$ll = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $line ) : strtolower( $line );
				if ( str_contains( $ll, 'thank you' ) || str_contains( $ll, 'thanks' ) || str_contains( $ll, 'join our webinar' ) ) {
					continue;
				}
				$line_len = \function_exists( 'mb_strlen' ) ? \mb_strlen( $line ) : strlen( $line );
				if ( $line_len <= 90 ) {
					$base = $line;
					break;
				}
			}
		}

		$base = trim( (string) $base );
		if ( '' === $base ) {
			return '';
		}

		// Avoid duplicate suffixes if already present.
		$base_lc = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $base ) : strtolower( $base );
		$already_recap = str_contains( $base_lc, 'webinar recap' );
		$already_upcoming = str_contains( $base_lc, 'upcoming webinar' );

		if ( $is_recap ) {
			return $already_recap ? $base : ( $base . ' — Webinar Recap' );
		}
		if ( $is_upcoming ) {
			return $already_upcoming ? $base : ( $base . ' — Upcoming Webinar' );
		}
		return $base;
	}

	private function extractAnnouncementLine( array $lines ): string {
		foreach ( $lines as $line ) {
			$ll = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $line ) : strtolower( $line );
			if ( str_contains( $ll, 'stay updated' ) || str_contains( $ll, 'get engaged' ) || str_contains( $ll, 'become a friend' ) ) {
				return $line;
			}
			$line_len = \function_exists( 'mb_strlen' ) ? \mb_strlen( $line ) : strlen( $line );
			if ( $line_len <= 90 && str_ends_with( $line, '!' ) ) {
				return $line;
			}
		}
		return '';
	}

	private function firstMeaningfulSentence( string $commentary ): string {
		$text = preg_replace( "/\n+/u", ' ', $commentary );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		// Deterministic editorial cleanup (explicitly allowed by spec).
		if ( preg_match( '/^We are (?:pleased|delighted) to introduce\s+(.+?)(?:\.\s*|$)/iu', $text, $m ) ) {
			$rest = trim( (string) $m[1] );
			if ( '' !== $rest ) {
				return 'Introducing ' . $rest;
			}
		}

		// Otherwise: first sentence boundary.
		if ( preg_match( '/^(.+?[.!?])\s+/u', $text, $m ) ) {
			return trim( (string) $m[1] );
		}
		return $text;
	}

	private function titleCase( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		// Keep acronyms and already-title-cased strings mostly intact.
		$words = preg_split( '/(\s+|—|-)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
		$stop = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'if', 'in', 'into', 'nor', 'of', 'on', 'or', 'over', 'per', 'the', 'to', 'via', 'vs', 'with', 'without' );

		$out = '';
		$word_index = 0;
		foreach ( $words as $token ) {
			if ( preg_match( '/^\s+|—|-$/u', $token ) ) {
				$out .= $token;
				continue;
			}

			$raw = (string) $token;
			$raw_trim = trim( $raw );
			if ( '' === $raw_trim ) {
				$out .= $raw;
				continue;
			}

			// Preserve all-caps (including a trailing punctuation mark like "ERMN!").
			$raw_caps = rtrim( $raw_trim, '.!?' );
			$punct = substr( $raw_trim, strlen( $raw_caps ) );
			if ( preg_match( '/^[A-Z0-9]{2,}$/', $raw_caps ) ) {
				$out .= $raw_caps . $punct;
				$word_index++;
				continue;
			}

			$lower = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $raw_trim ) : strtolower( $raw_trim );
			if ( $word_index > 0 && in_array( $lower, $stop, true ) ) {
				$out .= $lower;
				$word_index++;
				continue;
			}

			$first = \function_exists( 'mb_substr' ) ? \mb_substr( $lower, 0, 1 ) : substr( $lower, 0, 1 );
			$rest = \function_exists( 'mb_substr' ) ? \mb_substr( $lower, 1 ) : substr( $lower, 1 );
			$out .= ( \function_exists( 'mb_strtoupper' ) ? \mb_strtoupper( $first ) : strtoupper( $first ) ) . $rest;
			$word_index++;
		}

		return $out;
	}

	private function finalizeTitle( string $title ): string {
		$title = trim( (string) $title );
		$title = $this->stripDecorativeLeadingEmoji( $title );
		$title = trim( $title );

		if ( '' === $title ) {
			return '';
		}

		// Strip enclosing quotes when the whole line is quoted.
		$title = preg_replace( '/^(?:“|")\s*(.+?)\s*(?:”|")$/u', '$1', $title );
		$title = preg_replace( '/^\s*«\s*(.+?)\s*»\s*$/u', '$1', (string) $title );
		$title = trim( (string) $title );

		// Drop trivial trailing punctuation.
		$title = preg_replace( '/[.]\s*$/u', '', (string) $title );
		$title = trim( (string) $title );

		// Target maximum: ~110 chars; prefer natural boundaries.
		$title_len = \function_exists( 'mb_strlen' ) ? \mb_strlen( $title ) : strlen( $title );
		if ( $title_len > 110 ) {
			$cut = \function_exists( 'mb_substr' ) ? \mb_substr( $title, 0, 110 ) : substr( $title, 0, 110 );
			$cut = preg_replace( '/\s+\S*$/u', '', (string) $cut ); // avoid mid-word.
			$title = trim( (string) $cut );
		}
		return $title;
	}

	/**
	 * @return array{
	 *   text_length:int,
	 *   media_type:array<int,string>,
	 *   media_count:int,
	 *   source_width:int,
	 *   source_height:int,
	 *   aspect_data:array{width:int,height:int,ratio:float}|array{},
	 *   content_profile:string
	 * }
	 */
	private function extractMetadataFromNode( \DOMElement $node ): array {
		$text = preg_replace( '/\s+/u', ' ', (string) $node->textContent );
		$text = trim( (string) $text );
		if ( '' !== $text ) {
			$text_length = \function_exists( 'mb_strlen' ) ? \mb_strlen( $text ) : strlen( $text );
		} else {
			$text_length = 0;
		}

		$media_type = array();
		$media_count = 0;
		$source_width = 0;
		$source_height = 0;

		$xpath = new DOMXPath( $node->ownerDocument );
		/** @var \DOMNodeList<\DOMElement> $media_nodes */
		$media_nodes = $xpath->query( './/*[self::img or self::video]', $node );
		if ( false !== $media_nodes ) {
			foreach ( $media_nodes as $media_node ) {
				if ( ! $media_node instanceof \DOMElement ) {
					continue;
				}
				$tag = strtolower( $media_node->tagName );
				if ( ! in_array( $tag, array( 'img', 'video' ), true ) ) {
					continue;
				}
				$media_type[] = 'video' === $tag ? 'video' : 'image';
				$media_count++;
				if ( $source_width <= 0 || $source_height <= 0 ) {
					$dimensions = $this->extractDimensionsFromElement( $media_node );
					if ( $dimensions['width'] > 0 && $dimensions['height'] > 0 ) {
						$source_width = $dimensions['width'];
						$source_height = $dimensions['height'];
					}
				}
			}
		}

		$media_type = array_values( array_unique( array_filter( $media_type ) ) );
		$content_profile = $this->contentProfile( $media_type, $media_count, $text_length );
		$aspect_data = array();
		if ( $source_width > 0 && $source_height > 0 ) {
			$aspect_data = array(
				'width'  => $source_width,
				'height' => $source_height,
				'ratio'  => round( $source_width / $source_height, 4 ),
			);
		}

		return array(
			'text_length'     => $text_length,
			'media_type'      => $media_type,
			'media_count'     => $media_count,
			'source_width'    => $source_width,
			'source_height'   => $source_height,
			'aspect_data'     => $aspect_data,
			'content_profile' => $content_profile,
		);
	}

	/** @return array{width:int,height:int} */
	private function extractDimensionsFromElement( \DOMElement $element ): array {
		$width = $this->readPositiveDimension( (string) $element->getAttribute( 'width' ) );
		$height = $this->readPositiveDimension( (string) $element->getAttribute( 'height' ) );

		if ( $width > 0 && $height > 0 ) {
			return array( 'width' => $width, 'height' => $height );
		}

		$style = (string) $element->getAttribute( 'style' );
		if ( '' !== $style ) {
			if ( $width <= 0 && preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)px/i', $style, $matches ) ) {
				$width = (int) $matches[1];
			}
			if ( $height <= 0 && preg_match( '/(?:^|;)\s*height\s*:\s*(\d+)px/i', $style, $matches ) ) {
				$height = (int) $matches[1];
			}
		}

		return array(
			'width'  => max( 0, $width ),
			'height' => max( 0, $height ),
		);
	}

	private function readPositiveDimension( string $raw ): int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( preg_match( '/(\d+)/', $raw, $matches ) ) {
			return max( 0, (int) $matches[1] );
		}
		return 0;
	}

	/**
	 * @param array<int,string> $media_type
	 */
	private function contentProfile( array $media_type, int $media_count, int $text_length ): string {
		$has_image = in_array( 'image', $media_type, true );
		$has_video = in_array( 'video', $media_type, true );

		if ( $has_video && $has_image ) {
			return 'mixed_media';
		}
		if ( $has_video ) {
			return $media_count > 1 ? 'video_multi' : 'video_single';
		}
		if ( $has_image ) {
			return $media_count > 1 ? 'image_multi' : 'image_single';
		}
		if ( $text_length > 0 ) {
			return 'text_only';
		}
		return 'unknown';
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
