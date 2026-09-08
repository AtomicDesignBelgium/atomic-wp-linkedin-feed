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
		if ( 'stacked' === $attributes['layout'] && ! empty( $attributes['showNewsNavigation'] ) && ! empty( $attributes['highlightCurrentPost'] ) ) {
			wp_enqueue_script( 'atomic-wp-social-sync-news-nav' );
		}
		if ( 'stacked' === $attributes['layout'] ) {
			wp_enqueue_script( 'atomic-wp-social-sync-stack-height' );
		}
		$gap = array( 'small' => '1rem', 'large' => '2rem' )[ $attributes['gap'] ] ?? '1.5rem';
		$styles = sprintf(
			'--atomic-social-columns-desktop:%d;--atomic-social-columns-tablet:%d;--atomic-social-columns-mobile:%d;--atomic-social-gap:%s;--atomic-linkedin-min-width:%dpx;--atomic-linkedin-separator-thickness:%dpx;--atomic-linkedin-separator-color:%s;--atomic-linkedin-separator-spacing:%dpx;--atomic-linkedin-native-width:504px;--atomic-linkedin-preview-height:%dpx;--ermn-news-carousel-height:%dpx;',
			$attributes['desktopColumns'],
			$attributes['tabletColumns'],
			$attributes['mobileColumns'],
			$gap,
			$attributes['minWidth'],
			$attributes['separatorThickness'],
			$attributes['separatorColor'],
			$attributes['separatorSpacing'],
			$attributes['postPreviewHeight'],
			max( 320, min( 1600, (int) ( $attributes['carouselHeight'] ?? 640 ) ) )
		);
		$styles .= $this->designStyleVars( $attributes );

		$classes = array(
			'atomic-social-feed',
			'ermn-news',
			'atomic-social-feed--layout-' . $attributes['layout'],
			'ermn-news--layout-' . $attributes['layout'],
		);
		if ( ! empty( $attributes['postPreviewMode'] ) ) {
			$classes[] = 'atomic-social-feed--post-preview';
		}
		if ( empty( $attributes['allowEmbedScrolling'] ) ) {
			$classes[] = 'atomic-social-feed--reduce-embed-scroll';
		}
		if ( 'stacked' === $attributes['layout'] ) {
			$classes[] = 'atomic-social-feed--stacked-' . $attributes['stackedWidth'];
			$classes[] = 'ermn-news--stacked-' . $attributes['stackedWidth'];
			if ( ! empty( $attributes['showSeparator'] ) ) {
				$classes[] = 'atomic-social-feed--stacked-separator';
			}
		}

		$output = '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $styles ) . '"';
		if ( 'carousel' === $attributes['layout'] ) {
			$output .= ' data-atomic-linkedin-carousel data-ermn-news-carousel';
		}
		$output .= '>';

		$controls_html = '';
		if ( 'carousel' === $attributes['layout'] ) {
			$controls_html .= '<div class="atomic-social-feed__carousel-controls ermn-news-carousel__controls" aria-label="' . esc_attr__( 'Carousel controls', 'atomic-wp-social-sync' ) . '">';
			$controls_html .= '<button type="button" class="atomic-social-feed__carousel-button ermn-news-carousel__button" data-atomic-linkedin-carousel-prev aria-label="' . esc_attr__( 'Previous posts', 'atomic-wp-social-sync' ) . '">' . esc_html__( 'Previous', 'atomic-wp-social-sync' ) . '</button>';
			$controls_html .= '<button type="button" class="atomic-social-feed__carousel-button ermn-news-carousel__button" data-atomic-linkedin-carousel-next aria-label="' . esc_attr__( 'Next posts', 'atomic-wp-social-sync' ) . '">' . esc_html__( 'Next', 'atomic-wp-social-sync' ) . '</button>';
			$controls_html .= '</div>';
		}

		if ( 'carousel' === $attributes['layout'] ) {
			$output .= $controls_html;
			$output .= '<div class="ermn-news-carousel__viewport">';
			$output .= '<div class="atomic-social-feed__grid ermn-news-carousel__track">';
			$output .= $this->renderCards( $query->posts, $attributes );
			$output .= '</div></div>';
		} else {
			$output .= '<div class="atomic-social-feed__grid ermn-news__grid">';
			$output .= $this->renderCards( $query->posts, $attributes );
			$output .= '</div>';
		}
		$output .= $this->pagination( $query, $attributes ) . '</section>';

		$feed = $output;

		if ( 'stacked' === $attributes['layout'] ) {
			$has_nav = ! empty( $attributes['showNewsNavigation'] );
			$position = (string) ( $attributes['newsNavigationPosition'] ?? 'left' );
			$position = in_array( $position, array( 'left', 'right' ), true ) ? $position : 'left';

			$layout_classes = array(
				'atomic-linkedin-news-layout',
				'ermn-news-layout',
				'ermn-news-layout--stack',
			);
			if ( $has_nav ) {
				$layout_classes[] = 'atomic-linkedin-news-layout--' . $position;
			}
			$layout_attrs = '';
			$layout_attrs .= ' data-stack-height-strategy="' . esc_attr( $attributes['stackHeightStrategy'] ) . '"';
			if ( ! empty( $attributes['highlightCurrentPost'] ) ) {
				$layout_attrs .= ' data-highlight-current="1"';
			}

			$feed_wrapped = '<div class="atomic-linkedin-posts atomic-linkedin-posts--stacked ermn-news-feed">' . $feed . '</div>';

			if ( $has_nav ) {
				$nav = $this->newsNavigation( $query->posts, $attributes );
				$parts = ( 'right' === $position )
					? $feed_wrapped . $nav
					: $nav . $feed_wrapped;
				$feed = '<div class="' . esc_attr( implode( ' ', $layout_classes ) ) . '"' . $layout_attrs . '>' . $parts . '</div>';
			} else {
				$feed = '<div class="' . esc_attr( implode( ' ', $layout_classes ) ) . '"' . $layout_attrs . '>' . $feed_wrapped . '</div>';
			}
		}

		$feed .= $this->feedCta( $attributes );
		return $feed;
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

		return '<div class="atomic-linkedin-posts__cta ermn-news-carousel__cta ermn-news__cta"><a class="atomic-linkedin-posts__cta-link ermn-news-carousel__cta-link button" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div>';
	}

	/** @param array<string,mixed> $attributes */
	private function postCta( WP_Post $post, array $attributes, string $anchor ): string {
		if ( empty( $attributes['showPostCta'] ) ) {
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

		if ( ! empty( $attributes['readFullNewsLinks'] ) ) {
			$resolved = $this->deepLinkToNewsPost( (int) $post->ID, $news_page_id, $anchor );
			if ( '' !== $resolved ) {
				$href = $resolved;
			}
		}

		$label = sanitize_text_field( (string) ( $attributes['postCtaLabel'] ?? '' ) );
		$label = '' !== $label ? $label : __( 'Read full news', 'atomic-wp-social-sync' );

		return '<div class="atomic-linkedin-post__cta"><a class="atomic-linkedin-post__cta-link" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div>';
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

		// Post CTA (per-post "Read full news" link) styling.
		$vars[] = '--atomic-linkedin-post-cta-font-size:' . (int) $this->settings->get( 'post_cta_font_size', 16 ) . 'px';
		$vars[] = '--atomic-linkedin-post-cta-font-weight:' . (int) $this->settings->get( 'post_cta_font_weight', 600 );
		$post_cta_color = (string) $this->settings->get( 'post_cta_color', '' );
		if ( '' !== $post_cta_color ) {
			$vars[] = '--atomic-linkedin-post-cta-color:' . $post_cta_color;
		}

		// Editorial header (title/date) styling.
		$vars[] = '--atomic-linkedin-editorial-title-size:' . (int) $this->settings->get( 'editorial_title_size', 18 ) . 'px';
		$vars[] = '--atomic-linkedin-editorial-title-weight:' . (int) $this->settings->get( 'editorial_title_weight', 650 );
		$vars[] = '--atomic-linkedin-editorial-date-size:' . (int) $this->settings->get( 'editorial_date_size', 14 ) . 'px';
		$vars[] = '--atomic-linkedin-scroll-margin-top:' . (int) $this->settings->get( 'scroll_margin_top', 80 ) . 'px';
		$editorial_title_color = (string) $this->settings->get( 'editorial_title_color', '' );
		if ( '' !== $editorial_title_color ) {
			$vars[] = '--atomic-linkedin-editorial-title-color:' . $editorial_title_color;
		}
		$editorial_date_color = (string) $this->settings->get( 'editorial_date_color', '' );
		if ( '' !== $editorial_date_color ) {
			$vars[] = '--atomic-linkedin-editorial-date-color:' . $editorial_date_color;
		}

		// News navigation styling.
		$vars[] = '--atomic-linkedin-news-nav-top:' . (int) $this->settings->get( 'news_nav_top_offset', 80 ) . 'px';
		$vars[] = '--atomic-linkedin-news-nav-font-size:' . (int) $this->settings->get( 'news_nav_font_size', 16 ) . 'px';
		$vars[] = '--atomic-linkedin-news-nav-date-size:' . (int) $this->settings->get( 'news_nav_date_size', 13 ) . 'px';
		$vars[] = '--atomic-linkedin-news-nav-item-gap:' . (int) $this->settings->get( 'news_nav_item_gap', 12 ) . 'px';
		$news_nav_active_color = (string) $this->settings->get( 'news_nav_active_color', '' );
		if ( '' !== $news_nav_active_color ) {
			$vars[] = '--atomic-linkedin-news-nav-active-color:' . $news_nav_active_color;
		}

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

	/** @param WP_Post[] $posts @param array<string,mixed> $attributes */
	private function newsNavigation( array $posts, array $attributes ): string {
		$title = trim( (string) ( $attributes['newsNavigationTitle'] ?? '' ) );
		$sticky = ! empty( $attributes['stickyNewsNavigation'] );

		$items = '';
		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$anchor = $this->postAnchorId( $post );
			$label  = trim( (string) get_the_title( $post ) );
			if ( '' === $label ) {
				$label = __( '(Untitled)', 'atomic-wp-social-sync' );
			}
			$ts = (int) get_post_timestamp( $post );
			$date = $ts > 0 ? wp_date( 'j M', $ts ) : '';

			$items .= '<li class="atomic-linkedin-news-nav__item ermn-news-layout__nav-item">';
			$items .= '<a class="atomic-linkedin-news-nav__link ermn-news-scroll-link" href="#' . esc_attr( $anchor ) . '">';
			if ( '' !== $date ) {
				$items .= '<span class="atomic-linkedin-news-nav__date">' . esc_html( $date ) . '</span> ';
			}
			$items .= esc_html( $label ) . '</a></li>';
		}

		$nav_classes = array( 'atomic-linkedin-news-nav' );
		if ( $sticky ) {
			$nav_classes[] = 'atomic-linkedin-news-nav--sticky';
		}

		$output  = '<nav class="' . esc_attr( implode( ' ', $nav_classes ) ) . '">';
		$output .= '<div class="atomic-linkedin-news-nav__inner">';
		if ( '' !== $title ) {
			$output .= '<h2 class="atomic-linkedin-news-nav__title">' . esc_html( $title ) . '</h2>';
		}
		$output .= '<ul class="atomic-linkedin-news-nav__list">' . $items . '</ul>';
		$output .= '</div>';

		// Mobile: collapse into a lightweight <details> above the feed.
		$output .= '<div class="atomic-linkedin-news-nav__mobile">';
		$output .= '<details>';
		$output .= '<summary>' . esc_html__( 'Browse news', 'atomic-wp-social-sync' ) . '</summary>';
		$output .= '<ul class="atomic-linkedin-news-nav__list">' . $items . '</ul>';
		$output .= '</details>';
		$output .= '</div>';

		$output .= '</nav>';
		return $output;
	}

	private function postAnchorId( WP_Post $post ): string {
		$provider = sanitize_html_class( (string) get_post_meta( $post->ID, MetaKeys::PROVIDER, true ) );
		$mode     = (string) get_post_meta( $post->ID, MetaKeys::INTEGRATION_MODE, true );
		$mode     = '' === $mode ? IntegrationMode::IMPORT : IntegrationMode::sanitize( $mode );
		return ( IntegrationMode::EMBED === $mode && 'linkedin' === $provider )
			? 'ermn-news-' . (int) $post->ID
			: 'ermn-news-article-' . (int) $post->ID;
	}

	/** @param array<string,mixed> $attributes */
	private function card( WP_Post $post, array $attributes ): string {
		$provider = sanitize_html_class( (string) get_post_meta( $post->ID, MetaKeys::PROVIDER, true ) );
		$mode     = (string) get_post_meta( $post->ID, MetaKeys::INTEGRATION_MODE, true );
		$mode     = '' === $mode ? IntegrationMode::IMPORT : IntegrationMode::sanitize( $mode );
		$anchor   = $this->postAnchorId( $post );

		if ( IntegrationMode::EMBED === $mode && 'linkedin' === $provider ) {
			return $this->linkedinEmbedCard( $post, $attributes, $anchor );
		}

		$link     = $this->cardLink( $post, $attributes['cardLink'] );
		$excerpt  = (string) get_post_meta( $post->ID, MetaKeys::EXCERPT_OVERRIDE, true );
		if ( '' === trim( $excerpt ) ) {
			$excerpt = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		}
		$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), $attributes['excerptLength'], '…' );

		$classes = array(
			'atomic-social-card',
			'atomic-social-card--' . $provider,
			'ermn-news-item',
		);
		if ( 'linkedin' === $provider ) {
			$classes[] = 'atomic-linkedin-post';
		}
		$output = '<article id="' . esc_attr( $anchor ) . '" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
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
		$output .= '</div><div class="atomic-social-card__content">';
		if ( ! empty( $attributes['showPostTitles'] ) ) {
			$output .= '<h3>';
			$output .= $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a>' : esc_html( get_the_title( $post ) );
			$output .= '</h3>';
		}
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
			return '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin ermn-news-item"><div class="atomic-social-card__body"><p class="atomic-social-feed__empty">' . esc_html__( 'LinkedIn embed is not configured.', 'atomic-wp-social-sync' ) . '</p></div></article>';
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
			return '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin ermn-news-item"><div class="atomic-social-card__body"><p class="atomic-social-feed__empty">' . esc_html__( 'LinkedIn embed URL could not be generated.', 'atomic-wp-social-sync' ) . '</p></div></article>';
		}

		$height = 0;
		if ( LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy ) {
			$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_OVERRIDE, true );
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_FULL, true );
			}
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = (int) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_COMPACT, true );
			}
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

		$iframe_attrs = '';
		if ( empty( $attributes['allowEmbedScrolling'] ) ) {
			// Best-effort hint only: LinkedIn embeds are cross-origin and may keep internal scrollbars.
			$iframe_attrs .= ' scrolling="no"';
		}

		$output  = '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin atomic-social-card--embed atomic-linkedin-post ermn-news-item ermn-news-item--embed">';
		$title_html = '';
		if ( ! empty( $attributes['showPostTitles'] ) ) {
			$title = trim( (string) get_the_title( $post ) );
			if ( '' !== $title ) {
				$title_html = '<h2 class="atomic-linkedin-post__title">' . esc_html( $title ) . '</h2>';
			}
		}

		$date_html = '';
		if ( ! empty( $attributes['showDate'] ) ) {
			$ts = (int) get_post_timestamp( $post );
			if ( $ts > 0 ) {
				$date_html = sprintf(
					'<time class="atomic-linkedin-post__date" datetime="%s">%s</time>',
					esc_attr( wp_date( 'Y-m-d', $ts ) ),
					esc_html( wp_date( get_option( 'date_format' ), $ts ) )
				);
			}
		}

		if ( '' !== $title_html || '' !== $date_html ) {
			$output .= '<header class="atomic-linkedin-post__header">' . $title_html . $date_html . '</header>';
		}
		$output .= '<div class="atomic-social-card__embed atomic-linkedin-post__embed">';
		$output .= '<iframe class="atomic-social-card__embed-frame" src="' . esc_url( $src ) . '" height="' . esc_attr( (string) $height ) . '"' . $iframe_attrs . ' title="' . esc_attr__( 'Embedded LinkedIn post', 'atomic-wp-social-sync' ) . '" loading="lazy" allowfullscreen></iframe>';
		$output .= '</div>';
		$output .= $this->postCta( $post, $attributes, $anchor );
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

	private function paginationUrlFromBase( string $base_url, string $public_key, int $page ): string {
		$legacy_key = 'atomic_social_page_' . substr( $public_key, strlen( 'feed_page_' ) );
		$url = remove_query_arg( array( $public_key, $legacy_key, 'atomic_social_page' ), $base_url );
		$page = max( 1, (int) $page );
		if ( 1 === $page ) {
			return $url;
		}
		return add_query_arg( $public_key, $page, $url );
	}

	private function deepLinkToNewsPost( int $post_id, int $news_page_id, string $anchor ): string {
		$base = get_permalink( $news_page_id );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		$target_attributes = $this->resolveNewsFeedAttributes( $news_page_id );
		if ( null === $target_attributes ) {
			return '';
		}

		$page = $this->resolvePostPageInFeed( $post_id, $target_attributes );
		if ( $page <= 0 ) {
			return '';
		}

		$key = sanitize_key( (string) ( $target_attributes['paginationKey'] ?? '' ) );
		if ( '' === $key ) {
			return '';
		}
		$public_key = $this->publicPaginationKey( $key );
		$url = $this->paginationUrlFromBase( $base, $public_key, $page );

		return $url . '#' . $anchor;
	}

	/** @return array<string,mixed>|null */
	private function resolveNewsFeedAttributes( int $news_page_id ): ?array {
		$content = (string) get_post_field( 'post_content', $news_page_id );
		if ( '' === trim( $content ) ) {
			return null;
		}
		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return null;
		}
		$block = $this->firstFeedBlock( $blocks );
		if ( null === $block ) {
			return null;
		}
		$attrs = $block['attrs'] ?? array();
		$attrs = is_array( $attrs ) ? $attrs : array();
		$attrs['page'] = 1; // do not inherit current request page param.

		return $this->attributes( $attrs );
	}

	/** @param array<int,array<string,mixed>> $blocks @return array<string,mixed>|null */
	private function firstFeedBlock( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['blockName'] ) && 'atomic-wp-social-sync/feed' === (string) $block['blockName'] ) {
				return $block;
			}
			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) ) {
				$found = $this->firstFeedBlock( $inner );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/** @param array<string,mixed> $target_attributes */
	private function resolvePostPageInFeed( int $post_id, array $target_attributes ): int {
		$per_page = max( 1, min( 100, (int) ( $target_attributes['postsPerPage'] ?? 12 ) ) );
		$feed_query = new FeedQuery();
		$ids = $feed_query->orderedIds( array_merge( $target_attributes, array( 'page' => 1, 'postsPerPage' => $per_page ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$idx = array_search( $post_id, $ids, true );
		if ( false === $idx ) {
			return 0;
		}
		return (int) floor( ((int) $idx) / $per_page ) + 1;
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
		$stack_strategy_default = (string) $this->settings->get( 'layout_stack_height_strategy', 'estimated' );
		$stack_strategy_default = in_array( $stack_strategy_default, array( 'estimated', 'minimum', 'maximum' ), true ) ? $stack_strategy_default : 'estimated';

		$defaults = array(
			'postsPerPage' => 12, 'page' => 1, 'paginationKey' => '', 'order' => 'newest', 'pinnedFirst' => true,
			'homepageOnly' => false, 'providers' => array(), 'connections' => array(), 'desktopColumns' => 3, 'tabletColumns' => 2,
			'mobileColumns' => 1, 'gap' => $gap_default, 'showImage' => true, 'imageRatio' => 'auto', 'showDate' => true,
			'showPostTitles' => true,
			'showExcerpt' => true, 'excerptLength' => 30, 'showSource' => true, 'showCta' => true,
			'ctaLabel' => __( 'View post', 'atomic-wp-social-sync' ), 'cardLink' => 'original', 'pagination' => 'none',
			'presentation' => 'auto',
			'layout' => 'grid',
			'minWidth' => $min_width_default,
			'carouselHeight' => 640,
			'stackedWidth' => 'native',
			'stackHeightStrategy' => $stack_strategy_default,
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
			'postPreviewMode' => false,
			'postPreviewHeight' => 620,
			'allowEmbedScrolling' => true,
			'readFullNewsLinks' => false,
			'showPostCta' => false,
			'postCtaLabel' => __( 'Read full news', 'atomic-wp-social-sync' ),
			'showNewsNavigation' => false,
			'newsNavigationTitle' => __( 'Latest news', 'atomic-wp-social-sync' ),
			'newsNavigationPosition' => 'left',
			'stickyNewsNavigation' => true,
			'highlightCurrentPost' => true,
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
		$attributes['carouselHeight'] = max( 320, min( 1600, (int) $attributes['carouselHeight'] ) );
		$attributes['stackedWidth'] = in_array( $attributes['stackedWidth'], array( 'native', 'full' ), true ) ? $attributes['stackedWidth'] : 'native';
		$attributes['stackHeightStrategy'] = in_array( (string) ( $attributes['stackHeightStrategy'] ?? '' ), array( 'estimated', 'minimum', 'maximum' ), true )
			? (string) $attributes['stackHeightStrategy']
			: $stack_strategy_default;
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
		$attributes['postPreviewMode'] = (bool) $attributes['postPreviewMode'];
		$attributes['postPreviewHeight'] = max( 280, min( 2000, (int) $attributes['postPreviewHeight'] ) );
		$attributes['allowEmbedScrolling'] = (bool) $attributes['allowEmbedScrolling'];
		$attributes['readFullNewsLinks'] = (bool) $attributes['readFullNewsLinks'];
		$attributes['showPostCta'] = (bool) $attributes['showPostCta'];
		$attributes['postCtaLabel'] = sanitize_text_field( (string) $attributes['postCtaLabel'] );
		$attributes['showPostTitles'] = (bool) $attributes['showPostTitles'];
		$attributes['showNewsNavigation'] = (bool) $attributes['showNewsNavigation'];
		$attributes['newsNavigationTitle'] = sanitize_text_field( (string) $attributes['newsNavigationTitle'] );
		$attributes['newsNavigationPosition'] = in_array( $attributes['newsNavigationPosition'], array( 'left', 'right' ), true ) ? $attributes['newsNavigationPosition'] : 'left';
		$attributes['stickyNewsNavigation'] = (bool) $attributes['stickyNewsNavigation'];
		$attributes['highlightCurrentPost'] = (bool) $attributes['highlightCurrentPost'];
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
