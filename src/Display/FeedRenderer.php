<?php
/**
 * Accessible local Social Post cards and pagination.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Display;

use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\WordPress\SocialPostType;
use WP_Post;
use WP_Query;

final class FeedRenderer {
	public function __construct( private readonly PluginSettings $settings ) {}

	/** @param array<string,mixed> $attributes */
	public function render( WP_Query $query, array $attributes ): string {
		$attributes = $this->attributes( $attributes );
		wp_enqueue_style( 'atomic-wp-social-sync-frontend' );
		if ( 'carousel' === $attributes['layout'] ) {
			wp_enqueue_script( 'atomic-wp-social-sync-carousel' );
		}
		if ( 'load_more' === $attributes['pagination'] ) {
			wp_enqueue_script( 'atomic-wp-social-sync-load-more' );
		}
		$gap = array( 'small' => '1rem', 'large' => '2rem' )[ $attributes['gap'] ] ?? '1.5rem';
		$styles = sprintf(
			'--atomic-social-columns-desktop:%d;--atomic-social-columns-tablet:%d;--atomic-social-columns-mobile:%d;--atomic-social-gap:%s;--atomic-linkedin-min-width:%dpx;--atomic-linkedin-separator-thickness:%dpx;--atomic-linkedin-separator-color:%s;--atomic-linkedin-separator-spacing:%dpx;--atomic-linkedin-native-width:504px;',
			$attributes['desktopColumns'],
			$attributes['tabletColumns'],
			$attributes['mobileColumns'],
			$gap,
			$attributes['minWidth'],
			$attributes['separatorThickness'],
			$attributes['separatorColor'],
			$attributes['separatorSpacing']
		);
		$styles .= $this->designStyleVars( $attributes );

		$classes = array(
			'atomic-social-feed',
			'atomic-social-feed--layout-' . $attributes['layout'],
		);
		if ( 'stacked' === $attributes['layout'] ) {
			$classes[] = 'atomic-social-feed--stacked-' . $attributes['stackedWidth'];
			if ( ! empty( $attributes['showSeparator'] ) ) {
				$classes[] = 'atomic-social-feed--stacked-separator';
			}
		}

		$output = '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $styles ) . '"';
		if ( 'carousel' === $attributes['layout'] ) {
			$output .= ' data-atomic-linkedin-carousel';
		}
		$output .= '>';

		if ( 'carousel' === $attributes['layout'] ) {
			$output .= '<div class="atomic-social-feed__carousel-controls" aria-label="' . esc_attr__( 'Carousel controls', 'atomic-wp-social-sync' ) . '">';
			$output .= '<button type="button" class="atomic-social-feed__carousel-button" data-atomic-linkedin-carousel-prev aria-label="' . esc_attr__( 'Previous posts', 'atomic-wp-social-sync' ) . '">' . esc_html__( 'Previous', 'atomic-wp-social-sync' ) . '</button>';
			$output .= '<button type="button" class="atomic-social-feed__carousel-button" data-atomic-linkedin-carousel-next aria-label="' . esc_attr__( 'Next posts', 'atomic-wp-social-sync' ) . '">' . esc_html__( 'Next', 'atomic-wp-social-sync' ) . '</button>';
			$output .= '</div>';
		}

		$output .= '<div class="atomic-social-feed__grid">';
		$output .= $this->renderCards( $query->posts, $attributes );
		$output .= '</div>' . $this->pagination( $query, $attributes ) . '</section>';
		$output .= $this->feedCta( $attributes );
		return $output;
	}

	/** @param array<string,mixed> $attributes */
	private function feedCta( array $attributes ): string {
		if ( empty( $attributes['showFullNewsCta'] ) ) {
			return '';
		}
		$news_page_id = (int) ( $attributes['newsPageId'] ?? 0 );
		if ( $news_page_id <= 0 ) {
			// CTA enabled but no target selected: render nothing on frontend.
			return '';
		}
		$href = get_permalink( $news_page_id );
		if ( ! $href ) {
			return '';
		}
		$label = sanitize_text_field( (string) ( $attributes['fullNewsCtaLabel'] ?? '' ) );
		$label = '' !== $label ? $label : __( 'View all news', 'atomic-wp-social-sync' );

		return '<div class="atomic-linkedin-posts__cta"><a class="atomic-linkedin-posts__cta-link" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div>';
	}

	/** @param array<string,mixed> $attributes */
	private function designStyleVars( array $attributes ): string {
		$vars = array();

		$border_style = (string) $this->settings->get( 'design_border_style', 'solid' );
		$border_width = (int) $this->settings->get( 'design_border_width', 1 );
		$border_color = (string) $this->settings->get( 'design_border_color', '' );

		// Optional per-block override.
		if ( ! empty( $attributes['overrideStyles'] ) && isset( $attributes['borderStyle'] ) && 'global' !== (string) $attributes['borderStyle'] ) {
			$border_style = (string) $attributes['borderStyle'];
			$border_width = (int) ( $attributes['borderWidth'] ?? $border_width );
			$border_color = (string) ( $attributes['borderColor'] ?? $border_color );
		}

		$border_style = in_array( $border_style, array( 'solid', 'none' ), true ) ? $border_style : 'solid';
		$border_width = max( 0, min( 12, $border_width ) );
		if ( 'none' === $border_style ) { $border_width = 0; }

		$vars[] = '--atomic-social-card-border-style:' . $border_style;
		$vars[] = '--atomic-social-card-border-width:' . $border_width . 'px';

		if ( '' !== $border_color ) {
			$vars[] = '--atomic-social-card-border-color:' . $border_color;
		}

		$radius = (int) $this->settings->get( 'design_radius', 12 );
		$radius = max( 0, min( 40, $radius ) );
		$vars[] = '--atomic-social-card-radius:' . $radius . 'px';

		$bg = (string) $this->settings->get( 'design_background', '' );
		if ( '' !== $bg ) {
			$vars[] = '--atomic-social-card-background:' . $bg;
		}

		$shadow = (string) $this->settings->get( 'design_shadow', 'none' );
		$vars[] = '--atomic-social-card-shadow:' . ( 'subtle' === $shadow ? '0 6px 18px rgba(0,0,0,0.08)' : 'none' );

		$hover = (string) $this->settings->get( 'design_hover', 'none' );
		$vars[] = '--atomic-social-card-hover-translate:' . ( 'lift' === $hover ? '-2px' : '0px' );
		$vars[] = '--atomic-social-card-hover-scale:' . ( 'scale' === $hover ? '1.015' : '1' );

		$transition = (int) $this->settings->get( 'design_transition_ms', 200 );
		$transition = max( 0, min( 2000, $transition ) );
		$vars[] = '--atomic-social-transition:' . $transition . 'ms';

		// Pagination.
		$vars[] = '--atomic-linkedin-pagination-font-size:' . (int) $this->settings->get( 'pagination_font_size', 16 ) . 'px';
		$vars[] = '--atomic-linkedin-pagination-font-weight:' . (int) $this->settings->get( 'pagination_font_weight', 600 );
		$vars[] = '--atomic-linkedin-pagination-min-width:' . (int) $this->settings->get( 'pagination_min_width', 44 ) . 'px';
		$vars[] = '--atomic-linkedin-pagination-padding-x:' . (int) $this->settings->get( 'pagination_padding_x', 14 ) . 'px';
		$vars[] = '--atomic-linkedin-pagination-padding-y:' . (int) $this->settings->get( 'pagination_padding_y', 10 ) . 'px';
		$vars[] = '--atomic-linkedin-pagination-gap:' . (int) $this->settings->get( 'pagination_gap', 8 ) . 'px';
		$vars[] = '--atomic-linkedin-pagination-radius:' . (int) $this->settings->get( 'pagination_radius', 6 ) . 'px';

		$pg_border_style = (string) $this->settings->get( 'pagination_border_style', 'solid' );
		$pg_border_style = in_array( $pg_border_style, array( 'solid', 'none' ), true ) ? $pg_border_style : 'solid';
		$pg_border_width = (int) $this->settings->get( 'pagination_border_width', 1 );
		$pg_border_width = max( 0, min( 12, $pg_border_width ) );
		if ( 'none' === $pg_border_style ) {
			$pg_border_width = 0;
		}
		$vars[] = '--atomic-linkedin-pagination-border-style:' . $pg_border_style;
		$vars[] = '--atomic-linkedin-pagination-border-width:' . $pg_border_width . 'px';

		foreach ( array(
			'pagination_border_color'        => '--atomic-linkedin-pagination-border-color',
			'pagination_color'               => '--atomic-linkedin-pagination-color',
			'pagination_background'          => '--atomic-linkedin-pagination-background',
			'pagination_active_color'        => '--atomic-linkedin-pagination-active-color',
			'pagination_active_background'   => '--atomic-linkedin-pagination-active-background',
			'pagination_active_border_color' => '--atomic-linkedin-pagination-active-border-color',
			'pagination_hover_color'         => '--atomic-linkedin-pagination-hover-color',
			'pagination_hover_background'    => '--atomic-linkedin-pagination-hover-background',
			'pagination_hover_border_color'  => '--atomic-linkedin-pagination-hover-border-color',
		) as $key => $css_var ) {
			$value = (string) $this->settings->get( $key, '' );
			if ( '' !== $value ) {
				$vars[] = $css_var . ':' . $value;
			}
		}

		$pg_shadow = (string) $this->settings->get( 'pagination_shadow', 'none' );
		$vars[] = '--atomic-linkedin-pagination-shadow:' . ( 'subtle' === $pg_shadow ? '0 2px 10px rgba(0,0,0,0.08)' : 'none' );

		$pg_transition = (int) $this->settings->get( 'pagination_transition_ms', 200 );
		$pg_transition = max( 0, min( 2000, $pg_transition ) );
		$vars[] = '--atomic-linkedin-pagination-transition:' . $pg_transition . 'ms';

		return $vars ? implode( ';', $vars ) . ';' : '';
	}

	/** @param WP_Post[] $posts @param array<string,mixed> $attributes */
	public function renderCards( array $posts, array $attributes ): string {
		$attributes = $this->attributes( $attributes );
		if ( ! $posts ) {
			return '<p class="atomic-social-feed__empty">' . esc_html__( 'No social posts found.', 'atomic-wp-social-sync' ) . '</p>';
		}
		$output = '';
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$output .= $this->card( $post, $attributes );
			}
		}
		return $output;
	}

	/** @param array<string,mixed> $attributes */
	private function card( WP_Post $post, array $attributes ): string {
		$provider = sanitize_html_class( (string) get_post_meta( $post->ID, MetaKeys::PROVIDER, true ) );
		$mode     = (string) get_post_meta( $post->ID, MetaKeys::INTEGRATION_MODE, true );
		$mode     = '' === $mode ? IntegrationMode::IMPORT : IntegrationMode::sanitize( $mode );
		$anchor   = ( IntegrationMode::EMBED === $mode && 'linkedin' === $provider )
			? 'atomic-linkedin-post-' . (int) $post->ID
			: 'atomic-social-post-' . (int) $post->ID;

		if ( IntegrationMode::EMBED === $mode && 'linkedin' === $provider ) {
			return $this->linkedinEmbedCard( $post, $attributes, $anchor );
		}

		$link     = $this->cardLink( $post, $attributes['cardLink'] );
		$excerpt  = (string) get_post_meta( $post->ID, MetaKeys::EXCERPT_OVERRIDE, true );
		if ( '' === trim( $excerpt ) ) {
			$excerpt = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		}
		$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), $attributes['excerptLength'], '…' );

		$output = '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--' . esc_attr( $provider ) . '">';
		if ( $attributes['showImage'] && has_post_thumbnail( $post ) ) {
			$image = get_the_post_thumbnail( $post, 'large', array( 'class' => 'atomic-social-card__image', 'loading' => 'lazy' ) );
			$output .= '<div class="atomic-social-card__media atomic-social-card__media--' . esc_attr( $attributes['imageRatio'] ) . '">' . ( $link ? '<a href="' . esc_url( $link ) . '">' . $image . '</a>' : $image ) . '</div>';
		}
		$output .= '<div class="atomic-social-card__body"><div class="atomic-social-card__meta">';
		if ( $attributes['showDate'] ) {
			$output .= '<time class="atomic-social-card__date" datetime="' . esc_attr( get_post_time( DATE_ATOM, true, $post ) ) . '">' . esc_html( get_the_date( '', $post ) ) . '</time>';
		}
		if ( $attributes['showSource'] && $provider ) {
			$provider_label = apply_filters( 'atomic_social_provider_label', ucfirst( $provider ), $provider );
			$output .= '<span class="atomic-social-card__source"><span class="atomic-social-card__source-icon" aria-hidden="true">●</span>' . esc_html( (string) $provider_label ) . '</span>';
		}
		$output .= '</div><div class="atomic-social-card__content"><h3>';
		$output .= $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a>' : esc_html( get_the_title( $post ) );
		$output .= '</h3>';
		if ( $attributes['showExcerpt'] ) {
			$output .= '<p class="atomic-social-card__excerpt">' . esc_html( $excerpt ) . '</p>';
		}
		if ( $attributes['showCta'] && $link ) {
			$output .= '<a class="atomic-social-card__cta" href="' . esc_url( $link ) . '">' . esc_html( $attributes['ctaLabel'] ) . '</a>';
		}
		$output .= '</div></div></article>';
		return $output;
	}

	/** @param array<string,mixed> $attributes */
	private function linkedinEmbedCard( WP_Post $post, array $attributes, string $anchor ): string {
		$urn = (string) get_post_meta( $post->ID, MetaKeys::EMBED_URN, true );
		if ( '' === $urn || ! LinkedInEmbed::isSupportedUrn( $urn ) ) {
			return '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin"><div class="atomic-social-card__body"><p class="atomic-social-feed__empty">' . esc_html__( 'LinkedIn embed is not configured.', 'atomic-wp-social-sync' ) . '</p></div></article>';
		}

		$strategy = (string) get_post_meta( $post->ID, MetaKeys::EMBED_STRATEGY, true );
		$strategy = '' !== $strategy ? $strategy : LinkedInEmbed::STRATEGY_OFFICIAL;

		$presentation = $attributes['presentation'];
		if ( 'auto' === $presentation ) {
			$presentation = '' !== (string) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_COMPACT, true ) ? 'compact' : 'full';
		}
		if ( ! in_array( $presentation, array( 'compact', 'full' ), true ) ) {
			$presentation = 'full';
		}
		// Activity fallback: do not assume collapsed=1 works. Treat compact requests as full for now.
		if ( LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy && 'compact' === $presentation ) {
			$presentation = 'full';
		}

		$src = LinkedInEmbed::embedUrl( $urn, $presentation, $strategy );
		if ( '' === $src && 'compact' === $presentation ) {
			$presentation = 'full';
			$src          = LinkedInEmbed::embedUrl( $urn, $presentation, $strategy );
		}
		if ( '' === $src ) {
			return '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin"><div class="atomic-social-card__body"><p class="atomic-social-feed__empty">' . esc_html__( 'LinkedIn embed URL could not be generated.', 'atomic-wp-social-sync' ) . '</p></div></article>';
		}

		$height = 0;
		if ( LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy ) {
			$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_OVERRIDE, true );
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = LinkedInEmbed::DEFAULT_HEIGHT_ACTIVITY;
			}
		} elseif ( 'compact' === $presentation ) {
			$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_COMPACT, true );
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = LinkedInEmbed::DEFAULT_HEIGHT_COMPACT;
			}
		} else {
			// Full embeds: only use very tall heights when the official iframe supplied them.
			$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_FULL, true );
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$fallback = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_COMPACT, true );
				if ( $fallback >= LinkedInEmbed::MIN_HEIGHT && $fallback <= LinkedInEmbed::MAX_HEIGHT ) {
					$height = $fallback;
				} else {
					$height = LinkedInEmbed::DEFAULT_HEIGHT_COMPACT;
				}
			}
		}

		$output  = '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin atomic-social-card--embed">';
		$output .= '<div class="atomic-social-card__embed">';
		$output .= '<iframe class="atomic-social-card__embed-frame" src="' . esc_url( $src ) . '" height="' . esc_attr( (string) $height ) . '" title="' . esc_attr__( 'Embedded LinkedIn post', 'atomic-wp-social-sync' ) . '" loading="lazy" allowfullscreen></iframe>';
		$output .= '</div>';

		$output .= '</article>';
		return $output;
	}

	private function cardLink( WP_Post $post, string $mode ): string {
		if ( 'original' === $mode ) {
			return (string) get_post_meta( $post->ID, MetaKeys::EXTERNAL_URL, true );
		}
		if ( 'local' === $mode && $this->settings->get( 'enable_single_pages', false ) ) {
			return (string) get_permalink( $post );
		}
		return '';
	}

	/** @param array<string,mixed> $attributes */
	private function pagination( WP_Query $query, array $attributes ): string {
		if ( $query->max_num_pages <= 1 || 'none' === $attributes['pagination'] ) {
			return '';
		}
		$current = max( 1, (int) ( $attributes['page'] ?? 1 ) );
		$key     = sanitize_key( (string) ( $attributes['paginationKey'] ?? 'atomic_social_page' ) );
		$public_key = $this->publicPaginationKey( $key );
		if ( 'load_more' === $attributes['pagination'] ) {
			$data = $attributes;
			$data['page'] = $current + 1;
			return '<div class="atomic-social-pagination"><button type="button" class="atomic-social-pagination__button" data-atomic-social-load-more data-attributes="' . esc_attr( wp_json_encode( $data ) ) . '" data-max-pages="' . esc_attr( (string) $query->max_num_pages ) . '">' . esc_html__( 'Load More', 'atomic-wp-social-sync' ) . '</button><noscript><a href="' . esc_url( $this->paginationUrl( $public_key, $current + 1 ) ) . '">' . esc_html__( 'Next page', 'atomic-wp-social-sync' ) . '</a></noscript><p class="screen-reader-text" aria-live="polite"></p></div>';
		}

		$links = paginate_links(
			array(
				'base'      => esc_url_raw( $this->paginationUrl( $public_key, '%#%' ) ),
				'format'    => '',
				'current'   => $current,
				'total'     => $query->max_num_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'atomic-wp-social-sync' ),
				'next_text' => __( 'Next', 'atomic-wp-social-sync' ),
			)
		);
		return '<nav class="atomic-social-pagination" aria-label="' . esc_attr__( 'Social posts pagination', 'atomic-wp-social-sync' ) . '">' . $links . '</nav>';
	}

	private function publicPaginationKey( string $instance_key ): string {
		// e.g. atomic_social_page_ac44ece9 -> feed_page_ac44ece9
		if ( str_starts_with( $instance_key, 'atomic_social_page_' ) ) {
			return 'feed_page_' . substr( $instance_key, strlen( 'atomic_social_page_' ) );
		}
		// Safety fallback: if something custom is passed in, keep it stable but avoid atomic branding.
		if ( str_starts_with( $instance_key, 'atomic_' ) ) {
			return 'feed_' . substr( $instance_key, strlen( 'atomic_' ) );
		}
		return 'feed_' . $instance_key;
	}

	/**
	 * Build a pagination URL that:
	 * - removes both legacy and new params for this feed instance
	 * - generates clean page 1 URLs
	 * - preserves unrelated query parameters
	 *
	 * @param string $public_key e.g. feed_page_ac44ece9
	 * @param int|string $page page number or '%#%'
	 */
	private function paginationUrl( string $public_key, int|string $page ): string {
		// Derive the legacy key for this instance for cleanup.
		$legacy_key = 'atomic_social_page_' . substr( $public_key, strlen( 'feed_page_' ) );

		$url = remove_query_arg( array( $public_key, $legacy_key, 'atomic_social_page' ) );
		if ( '%#%' === $page ) {
			return add_query_arg( $public_key, '%#%', $url );
		}
		$page = max( 1, (int) $page );
		if ( 1 === $page ) {
			return $url;
		}
		return add_query_arg( $public_key, $page, $url );
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function attributes( array $input ): array {
		$page_provided = array_key_exists( 'page', $input );
		$gap_default = (string) $this->settings->get( 'layout_gap', 'medium' );
		$gap_default = in_array( $gap_default, array( 'small', 'medium', 'large' ), true ) ? $gap_default : 'medium';

		$min_width_default = (int) $this->settings->get( 'layout_min_width', 340 );
		$min_width_default = max( 280, min( 600, $min_width_default ) );

		$sep_enabled_default = (bool) $this->settings->get( 'layout_separator_enabled', false );
		$sep_thickness_default = (int) $this->settings->get( 'layout_separator_thickness', 1 );
		$sep_thickness_default = max( 0, min( 12, $sep_thickness_default ) );
		$sep_spacing_default = (int) $this->settings->get( 'layout_separator_spacing', 40 );
		$sep_spacing_default = max( 0, min( 120, $sep_spacing_default ) );
		$sep_color_default = (string) $this->settings->get( 'layout_separator_color', '' );

		$defaults = array(
			'postsPerPage' => 12, 'page' => 1, 'paginationKey' => '', 'order' => 'newest', 'pinnedFirst' => true,
			'homepageOnly' => false, 'providers' => array(), 'connections' => array(), 'desktopColumns' => 3, 'tabletColumns' => 2,
			'mobileColumns' => 1, 'gap' => $gap_default, 'showImage' => true, 'imageRatio' => 'auto', 'showDate' => true,
			'showExcerpt' => true, 'excerptLength' => 30, 'showSource' => true, 'showCta' => true,
			'ctaLabel' => __( 'View post', 'atomic-wp-social-sync' ), 'cardLink' => 'original', 'pagination' => 'none',
			'presentation' => 'auto',
			'layout' => 'grid',
			'minWidth' => $min_width_default,
			'stackedWidth' => 'native',
			'showSeparator' => $sep_enabled_default,
			'separatorThickness' => $sep_thickness_default,
			'separatorColor' => '' !== $sep_color_default ? $sep_color_default : '#dcdcde',
			'separatorSpacing' => $sep_spacing_default,
			'overrideStyles' => false,
			'borderStyle' => 'global',
			'borderWidth' => 1,
			'borderColor' => '',
			'showFullNewsCta' => false,
			'fullNewsCtaLabel' => __( 'View all news', 'atomic-wp-social-sync' ),
			'newsPageId' => 0,
		);
		$attributes = wp_parse_args( $input, $defaults );
		$attributes['postsPerPage'] = max( 1, min( 100, (int) $attributes['postsPerPage'] ) );
		$attributes['paginationKey'] = sanitize_key( (string) $attributes['paginationKey'] );
		$attributes['desktopColumns'] = max( 1, min( 6, (int) $attributes['desktopColumns'] ) );
		$attributes['tabletColumns'] = max( 1, min( 4, (int) $attributes['tabletColumns'] ) );
		$attributes['mobileColumns'] = max( 1, min( 2, (int) $attributes['mobileColumns'] ) );
		$attributes['excerptLength'] = max( 1, min( 200, (int) $attributes['excerptLength'] ) );
		$attributes['gap'] = in_array( $attributes['gap'], array( 'small', 'medium', 'large' ), true ) ? $attributes['gap'] : 'medium';
		$attributes['imageRatio'] = in_array( $attributes['imageRatio'], array( 'auto', '1-1', '4-3', '3-2', '16-9' ), true ) ? $attributes['imageRatio'] : 'auto';
		$attributes['cardLink'] = in_array( $attributes['cardLink'], array( 'original', 'local', 'none' ), true ) ? $attributes['cardLink'] : 'original';
		$attributes['pagination'] = in_array( $attributes['pagination'], array( 'none', 'numbers', 'load_more' ), true ) ? $attributes['pagination'] : 'none';
		$attributes['ctaLabel'] = sanitize_text_field( (string) $attributes['ctaLabel'] );
		$attributes['presentation'] = in_array( $attributes['presentation'], array( 'auto', 'compact', 'full' ), true ) ? $attributes['presentation'] : 'auto';
		$attributes['layout'] = in_array( $attributes['layout'], array( 'grid', 'carousel', 'stacked' ), true ) ? $attributes['layout'] : 'grid';
		$attributes['minWidth'] = max( 280, min( 600, (int) $attributes['minWidth'] ) );
		$attributes['stackedWidth'] = in_array( $attributes['stackedWidth'], array( 'native', 'full' ), true ) ? $attributes['stackedWidth'] : 'native';
		$attributes['showSeparator'] = (bool) $attributes['showSeparator'];
		$attributes['separatorThickness'] = max( 0, min( 12, (int) $attributes['separatorThickness'] ) );
		$attributes['separatorSpacing'] = max( 0, min( 120, (int) $attributes['separatorSpacing'] ) );
		$sep_color = trim( (string) $attributes['separatorColor'] );
		if ( preg_match( '/^var\(--wp--preset--color--[a-z0-9-]+\)$/', $sep_color ) ) {
			$attributes['separatorColor'] = $sep_color;
		} else {
			$color = sanitize_hex_color( $sep_color );
			$attributes['separatorColor'] = $color ? $color : '#dcdcde';
		}
		$attributes['overrideStyles'] = (bool) $attributes['overrideStyles'];
		$attributes['borderStyle'] = in_array( $attributes['borderStyle'], array( 'global', 'none', 'solid' ), true ) ? $attributes['borderStyle'] : 'global';
		$attributes['borderWidth'] = max( 0, min( 12, (int) $attributes['borderWidth'] ) );
		$border = sanitize_hex_color( (string) $attributes['borderColor'] );
		$attributes['borderColor'] = $border ? $border : '';
		$attributes['showFullNewsCta'] = (bool) $attributes['showFullNewsCta'];
		$attributes['fullNewsCtaLabel'] = sanitize_text_field( (string) $attributes['fullNewsCtaLabel'] );
		$attributes['newsPageId'] = absint( $attributes['newsPageId'] );
		// Important: per-block `newsPageId` is the source of truth.
		// Do not silently fall back to a hidden global option when the CTA target is not selected.

		// Pagination key: try to avoid conflicts when multiple feeds are rendered on the same page.
		if ( '' === $attributes['paginationKey'] ) {
			$fingerprint = array(
				'providers'     => $attributes['providers'],
				'connections'   => $attributes['connections'],
				'homepageOnly'  => (bool) $attributes['homepageOnly'],
				'postsPerPage'  => (int) $attributes['postsPerPage'],
				'order'         => (string) $attributes['order'],
				'pinnedFirst'   => (bool) $attributes['pinnedFirst'],
				'pagination'    => (string) $attributes['pagination'],
				'presentation'  => (string) $attributes['presentation'],
			);
			$attributes['paginationKey'] = 'atomic_social_page_' . substr( sha1( wp_json_encode( $fingerprint ) ), 0, 8 );
		}

		if ( ! $page_provided ) {
			$key = $attributes['paginationKey'];
			$public_key = $this->publicPaginationKey( $key );
			$page = (int) wp_unslash( $_GET[ $public_key ] ?? 0 );
			if ( $page <= 0 ) {
				$page = (int) wp_unslash( $_GET[ $key ] ?? 0 );
			}
			if ( $page <= 0 ) {
				$page = (int) wp_unslash( $_GET['atomic_social_page'] ?? 1 );
			}
			$attributes['page'] = max( 1, $page );
		} else {
			$attributes['page'] = max( 1, (int) $attributes['page'] );
		}

		return $attributes;
	}
}
