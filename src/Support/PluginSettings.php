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
			'news_page_id'            => 0,
			'linkedin_client_id'      => '',
			'debug_logging'           => false,
			'developer_tools'         => false,

			// LinkedIn Sources (metadata only; no HTTP fetch).
			'linkedin_sources'        => array(),

			// Design / appearance (Atomic-controlled outer wrapper styling).
			'design_border_style'     => 'solid', // solid|none
			'design_border_width'     => 1,
			'design_border_color'     => '',
			'design_radius'           => 12,
			'design_background'       => '',
			'design_shadow'           => 'none', // none|subtle
			'design_hover'            => 'none', // none|lift|scale
			'design_transition_ms'    => 200,

			// Layout & spacing defaults (used when blocks don't override).
			'layout_gap'              => 'medium', // small|medium|large
			'layout_min_width'        => 340,
			'layout_separator_enabled'   => false,
			'layout_separator_thickness' => 1,
			'layout_separator_color'     => '',
			'layout_separator_spacing'   => 40,
			'layout_stack_height_strategy' => 'estimated', // estimated|minimum|maximum

			// Pagination defaults (visual only; semantics unchanged).
			'pagination_font_size'            => 16,
			'pagination_font_weight'          => 600,
			'pagination_min_width'            => 44,
			'pagination_padding_x'            => 14,
			'pagination_padding_y'            => 10,
			'pagination_gap'                  => 8,
			'pagination_radius'               => 6,
			'pagination_border_style'         => 'solid', // solid|none
			'pagination_border_width'         => 1,
			'pagination_border_color'         => '',
			'pagination_color'                => '',
			'pagination_background'           => '',
			'pagination_active_color'         => '',
			'pagination_active_background'    => '',
			'pagination_active_border_color'  => '',
			'pagination_hover_color'          => '',
			'pagination_hover_background'     => '',
			'pagination_hover_border_color'   => '',
			'pagination_shadow'               => 'none', // none|subtle
			'pagination_transition_ms'        => 200,

			// Post CTA defaults (visual only; block decides whether to render CTA).
			'post_cta_font_size'              => 16,
			'post_cta_font_weight'            => 600,
			'post_cta_color'                  => '',

			// Editorial defaults (visual only; block decides whether to show title/date).
			'editorial_title_size'            => 18,
			'editorial_title_weight'          => 650,
			'editorial_title_color'           => '',
			'editorial_date_size'             => 14,
			'editorial_date_color'            => '',
			'scroll_margin_top'               => 80,

			// News navigation defaults (visual only; block decides whether to render navigation).
			'news_nav_top_offset'             => 80,
			'news_nav_font_size'              => 16,
			'news_nav_date_size'              => 13,
			'news_nav_item_gap'               => 12,
			'news_nav_active_color'           => '',
		);
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$border_style = self::allowed( $input['design_border_style'] ?? '', array( 'solid' => 'solid', 'none' => 'none' ), 'solid' );
		$shadow = self::allowed( $input['design_shadow'] ?? '', array( 'none' => 'none', 'subtle' => 'subtle' ), 'none' );
		$hover = self::allowed( $input['design_hover'] ?? '', array( 'none' => 'none', 'lift' => 'lift', 'scale' => 'scale' ), 'none' );

		$layout_gap = self::allowed( $input['layout_gap'] ?? '', array( 'small' => 'small', 'medium' => 'medium', 'large' => 'large' ), 'medium' );
		$stack_height_strategy = self::allowed(
			$input['layout_stack_height_strategy'] ?? '',
			array( 'estimated' => 'estimated', 'minimum' => 'minimum', 'maximum' => 'maximum' ),
			'estimated'
		);

		$pagination_border_style = self::allowed( $input['pagination_border_style'] ?? '', array( 'solid' => 'solid', 'none' => 'none' ), 'solid' );
		$pagination_shadow = self::allowed( $input['pagination_shadow'] ?? '', array( 'none' => 'none', 'subtle' => 'subtle' ), 'none' );

		$color_or_empty = static function ( mixed $value ): string {
			return self::sanitizeColorValue( (string) $value );
		};

		return array(
			'default_sync_frequency' => self::allowed( $input['default_sync_frequency'] ?? '', self::frequencies(), self::FREQUENCY_TWICE ),
			'remote_edit_policy'      => self::allowed( $input['remote_edit_policy'] ?? '', self::editPolicies(), self::EDIT_AUTOMATIC ),
			'remote_delete_policy'    => self::allowed( $input['remote_delete_policy'] ?? '', self::deletePolicies(), self::DELETE_DRAFT ),
			'import_images'           => ! empty( $input['import_images'] ),
			'enable_single_pages'     => ! empty( $input['enable_single_pages'] ),
			'news_page_id'            => absint( $input['news_page_id'] ?? 0 ),
			'linkedin_client_id'      => sanitize_text_field( (string) ( $input['linkedin_client_id'] ?? '' ) ),
			'debug_logging'           => ! empty( $input['debug_logging'] ),
			'developer_tools'         => ! empty( $input['developer_tools'] ),

			'linkedin_sources'        => self::sanitizeLinkedInSources( $input['linkedin_sources'] ?? array() ),

			'design_border_style'     => $border_style,
			'design_border_width'     => max( 0, min( 12, absint( $input['design_border_width'] ?? 1 ) ) ),
			'design_border_color'     => $color_or_empty( $input['design_border_color'] ?? '' ),
			'design_radius'           => max( 0, min( 40, absint( $input['design_radius'] ?? 12 ) ) ),
			'design_background'       => $color_or_empty( $input['design_background'] ?? '' ),
			'design_shadow'           => $shadow,
			'design_hover'            => $hover,
			'design_transition_ms'    => max( 0, min( 2000, absint( $input['design_transition_ms'] ?? 200 ) ) ),

			'layout_gap'              => $layout_gap,
			'layout_min_width'        => max( 280, min( 600, absint( $input['layout_min_width'] ?? 340 ) ) ),
			'layout_separator_enabled'   => ! empty( $input['layout_separator_enabled'] ),
			'layout_separator_thickness' => max( 0, min( 12, absint( $input['layout_separator_thickness'] ?? 1 ) ) ),
			'layout_separator_color'     => $color_or_empty( $input['layout_separator_color'] ?? '' ),
			'layout_separator_spacing'   => max( 0, min( 120, absint( $input['layout_separator_spacing'] ?? 40 ) ) ),
			'layout_stack_height_strategy' => $stack_height_strategy,

			'pagination_font_size'           => max( 10, min( 26, absint( $input['pagination_font_size'] ?? 16 ) ) ),
			'pagination_font_weight'         => max( 200, min( 900, absint( $input['pagination_font_weight'] ?? 600 ) ) ),
			'pagination_min_width'           => max( 28, min( 120, absint( $input['pagination_min_width'] ?? 44 ) ) ),
			'pagination_padding_x'           => max( 0, min( 40, absint( $input['pagination_padding_x'] ?? 14 ) ) ),
			'pagination_padding_y'           => max( 0, min( 30, absint( $input['pagination_padding_y'] ?? 10 ) ) ),
			'pagination_gap'                 => max( 0, min( 30, absint( $input['pagination_gap'] ?? 8 ) ) ),
			'pagination_radius'              => max( 0, min( 30, absint( $input['pagination_radius'] ?? 6 ) ) ),
			'pagination_border_style'        => $pagination_border_style,
			'pagination_border_width'        => max( 0, min( 12, absint( $input['pagination_border_width'] ?? 1 ) ) ),
			'pagination_border_color'        => $color_or_empty( $input['pagination_border_color'] ?? '' ),
			'pagination_color'               => $color_or_empty( $input['pagination_color'] ?? '' ),
			'pagination_background'          => $color_or_empty( $input['pagination_background'] ?? '' ),
			'pagination_active_color'        => $color_or_empty( $input['pagination_active_color'] ?? '' ),
			'pagination_active_background'   => $color_or_empty( $input['pagination_active_background'] ?? '' ),
			'pagination_active_border_color' => $color_or_empty( $input['pagination_active_border_color'] ?? '' ),
			'pagination_hover_color'         => $color_or_empty( $input['pagination_hover_color'] ?? '' ),
			'pagination_hover_background'    => $color_or_empty( $input['pagination_hover_background'] ?? '' ),
			'pagination_hover_border_color'  => $color_or_empty( $input['pagination_hover_border_color'] ?? '' ),
			'pagination_shadow'              => $pagination_shadow,
			'pagination_transition_ms'       => max( 0, min( 2000, absint( $input['pagination_transition_ms'] ?? 200 ) ) ),

			'post_cta_font_size'             => max( 10, min( 26, absint( $input['post_cta_font_size'] ?? 16 ) ) ),
			'post_cta_font_weight'           => max( 200, min( 900, absint( $input['post_cta_font_weight'] ?? 600 ) ) ),
			'post_cta_color'                 => $color_or_empty( $input['post_cta_color'] ?? '' ),

			'editorial_title_size'           => max( 12, min( 40, absint( $input['editorial_title_size'] ?? 18 ) ) ),
			'editorial_title_weight'         => max( 200, min( 900, absint( $input['editorial_title_weight'] ?? 650 ) ) ),
			'editorial_title_color'          => $color_or_empty( $input['editorial_title_color'] ?? '' ),
			'editorial_date_size'            => max( 10, min( 26, absint( $input['editorial_date_size'] ?? 14 ) ) ),
			'editorial_date_color'           => $color_or_empty( $input['editorial_date_color'] ?? '' ),
			'scroll_margin_top'              => max( 0, min( 200, absint( $input['scroll_margin_top'] ?? 80 ) ) ),

			'news_nav_top_offset'            => max( 0, min( 240, absint( $input['news_nav_top_offset'] ?? 80 ) ) ),
			'news_nav_font_size'             => max( 10, min( 26, absint( $input['news_nav_font_size'] ?? 16 ) ) ),
			'news_nav_date_size'             => max( 10, min( 20, absint( $input['news_nav_date_size'] ?? 13 ) ) ),
			'news_nav_item_gap'              => max( 0, min( 40, absint( $input['news_nav_item_gap'] ?? 12 ) ) ),
			'news_nav_active_color'          => $color_or_empty( $input['news_nav_active_color'] ?? '' ),
		);
	}

	private static function sanitizeColorValue( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// Theme preset reference (keep identity).
		if ( preg_match( '/^var\(--wp--preset--color--[a-z0-9-]+\)$/', $value ) ) {
			return $value;
		}

		$hex = sanitize_hex_color( $value );
		if ( $hex ) {
			return $hex;
		}

		// Allow rgb()/rgba() and transparent for backward compatibility / advanced usage.
		$lower = strtolower( $value );
		if ( 'transparent' === $lower ) {
			return 'transparent';
		}
		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/', $lower ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * @param mixed $input
	 * @return array<int,array{id:string,label:string,url:string,is_default:bool}>
	 */
	public static function sanitizeLinkedInSources( mixed $input ): array {
		$rows = is_array( $input ) ? $input : array();
		$sources = array();
		$default_candidate = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$url   = trim( (string) ( $row['url'] ?? '' ) );
			$id    = sanitize_text_field( (string) ( $row['id'] ?? '' ) );
			$is_default = ! empty( $row['is_default'] );

			// Ignore blank rows.
			if ( '' === $label && '' === $url ) {
				continue;
			}

			$normalized_url = self::normalizeLinkedInSourceUrl( $url );
			if ( '' === $label || '' === $normalized_url ) {
				// Invalid rows are dropped (caller UI should show a notice on save).
				continue;
			}

			if ( '' === $id ) {
				$id = wp_generate_uuid4();
			}

			if ( $is_default && '' === $default_candidate ) {
				$default_candidate = $id;
			}

			$sources[] = array(
				'id'         => $id,
				'label'      => $label,
				'url'        => $normalized_url,
				'is_default' => false, // set in a second pass.
			);
		}

		// Enforce "at most one default".
		if ( '' !== $default_candidate ) {
			foreach ( $sources as &$s ) {
				$s['is_default'] = ( $s['id'] === $default_candidate );
			}
			unset( $s );
		}

		return array_values( $sources );
	}

	private static function normalizeLinkedInSourceUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if ( 'https' !== $scheme ) {
			return '';
		}
		if ( ! in_array( $host, array( 'linkedin.com', 'www.linkedin.com' ), true ) ) {
			return '';
		}

		// Normalize common harmless variations:
		// - Allow /company/<slug> (append /posts/)
		// - Allow /company/<slug>/posts (append trailing slash)
		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		$path = rtrim( $path, '/' );

		if ( preg_match( '#^/(company|showcase|school)/[^/]+$#', $path ) ) {
			$path .= '/posts';
		}

		if ( ! preg_match( '#^/(company|showcase|school)/[^/]+/posts$#', $path ) ) {
			return '';
		}

		return 'https://www.linkedin.com' . $path . '/';
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
