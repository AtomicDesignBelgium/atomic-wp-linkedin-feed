<?php
/**
 * Classic-editor feed entry point using the shared query and renderer.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Display;

final class Shortcode {
	public function __construct(
		private readonly FeedQuery $query,
		private readonly FeedRenderer $renderer
	) {}

	/** @param array<string,mixed>|string $attributes */
	public function render( array|string $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'posts' => 4, 'columns' => 4, 'tablet_columns' => 2, 'mobile_columns' => 1, 'providers' => '', 'connections' => '',
				'order' => 'newest', 'pinned_first' => 'true', 'homepage_only' => 'false', 'show_image' => 'true', 'show_date' => 'true',
				'show_excerpt' => 'true', 'excerpt_length' => 30, 'show_source' => 'true', 'show_cta' => 'true', 'cta_label' => __( 'View post', 'atomic-wp-social-sync' ),
				'card_link' => 'original', 'pagination' => 'none', 'gap' => 'medium', 'image_ratio' => 'auto',
				'presentation' => 'auto',
				'layout' => 'grid',
				'min_width' => 340,
				'carousel_height' => 640,
				'stack_height_strategy' => 'estimated',
				'show_full_news_cta' => 'false',
				'full_news_cta_label' => __( 'View all news', 'atomic-wp-social-sync' ),
				'news_page' => 0,
			),
			is_array( $attributes ) ? $attributes : array(),
			'atomic_social_feed'
		);
		$block_attributes = array(
			'postsPerPage' => (int) $attributes['posts'],
			'desktopColumns' => (int) $attributes['columns'],
			'tabletColumns' => (int) $attributes['tablet_columns'],
			'mobileColumns' => (int) $attributes['mobile_columns'],
			'providers' => (string) $attributes['providers'],
			'connections' => (string) $attributes['connections'],
			'order' => sanitize_key( (string) $attributes['order'] ),
			'pinnedFirst' => filter_var( $attributes['pinned_first'], FILTER_VALIDATE_BOOLEAN ),
			'homepageOnly' => filter_var( $attributes['homepage_only'], FILTER_VALIDATE_BOOLEAN ),
			'showImage' => filter_var( $attributes['show_image'], FILTER_VALIDATE_BOOLEAN ),
			'showDate' => filter_var( $attributes['show_date'], FILTER_VALIDATE_BOOLEAN ),
			'showExcerpt' => filter_var( $attributes['show_excerpt'], FILTER_VALIDATE_BOOLEAN ),
			'excerptLength' => (int) $attributes['excerpt_length'],
			'showSource' => filter_var( $attributes['show_source'], FILTER_VALIDATE_BOOLEAN ),
			'showCta' => filter_var( $attributes['show_cta'], FILTER_VALIDATE_BOOLEAN ),
			'ctaLabel' => sanitize_text_field( (string) $attributes['cta_label'] ),
			'cardLink' => sanitize_key( (string) $attributes['card_link'] ),
			'pagination' => sanitize_key( (string) $attributes['pagination'] ),
			'gap' => sanitize_key( (string) $attributes['gap'] ),
			'imageRatio' => sanitize_key( (string) $attributes['image_ratio'] ),
			'presentation' => sanitize_key( (string) $attributes['presentation'] ),
			'layout' => sanitize_key( (string) $attributes['layout'] ),
			'minWidth' => (int) $attributes['min_width'],
			'carouselHeight' => (int) $attributes['carousel_height'],
			'stackHeightStrategy' => in_array( (string) $attributes['stack_height_strategy'], array( 'estimated', 'minimum', 'maximum' ), true )
				? (string) $attributes['stack_height_strategy']
				: 'estimated',
			'showFullNewsCta' => filter_var( $attributes['show_full_news_cta'], FILTER_VALIDATE_BOOLEAN ),
			'fullNewsCtaLabel' => sanitize_text_field( (string) $attributes['full_news_cta_label'] ),
			'newsPageId' => (int) $attributes['news_page'],
		);
		$block_attributes = $this->renderer->attributes( $block_attributes );
		return $this->renderer->render( $this->query->query( $block_attributes ), $block_attributes );
	}
}
