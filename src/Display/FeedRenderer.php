<?php
/**
 * Accessible local Social Post cards and pagination.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Display;

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
		if ( 'load_more' === $attributes['pagination'] ) {
			wp_enqueue_script( 'atomic-wp-social-sync-load-more' );
		}
		$styles = sprintf(
			'--atomic-social-columns-desktop:%d;--atomic-social-columns-tablet:%d;--atomic-social-columns-mobile:%d;--atomic-social-gap:%s;',
			$attributes['desktopColumns'],
			$attributes['tabletColumns'],
			$attributes['mobileColumns'],
			array( 'small' => '1rem', 'large' => '2rem' )[ $attributes['gap'] ] ?? '1.5rem'
		);
		$output = '<section class="atomic-social-feed" style="' . esc_attr( $styles ) . '"><div class="atomic-social-feed__grid">';
		$output .= $this->renderCards( $query->posts, $attributes );
		$output .= '</div>' . $this->pagination( $query, $attributes ) . '</section>';
		return $output;
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
		$link     = $this->cardLink( $post, $attributes['cardLink'] );
		$excerpt  = (string) get_post_meta( $post->ID, MetaKeys::EXCERPT_OVERRIDE, true );
		if ( '' === trim( $excerpt ) ) {
			$excerpt = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		}
		$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), $attributes['excerptLength'], '…' );

		$output = '<article class="atomic-social-card atomic-social-card--' . esc_attr( $provider ) . '">';
		if ( $attributes['showImage'] && has_post_thumbnail( $post ) ) {
			$image = get_the_post_thumbnail( $post, 'large', array( 'class' => 'atomic-social-card__image', 'loading' => 'lazy' ) );
			$output .= '<div class="atomic-social-card__media atomic-social-card__media--' . esc_attr( $attributes['imageRatio'] ) . '">' . ( $link ? '<a href="' . esc_url( $link ) . '">' . $image . '</a>' : $image ) . '</div>';
		}
		$output .= '<div class="atomic-social-card__body"><div class="atomic-social-card__meta">';
		if ( $attributes['showDate'] ) {
			$output .= '<time class="atomic-social-card__date" datetime="' . esc_attr( get_post_time( DATE_ATOM, true, $post ) ) . '">' . esc_html( get_the_date( '', $post ) ) . '</time>';
		}
		if ( $attributes['showSource'] && $provider ) {
			$provider_label = 'linkedin' === $provider ? __( 'LinkedIn', 'atomic-wp-social-sync' ) : ucfirst( $provider );
			$output .= '<span class="atomic-social-card__source"><span class="atomic-social-card__source-icon" aria-hidden="true">●</span>' . esc_html( $provider_label ) . '</span>';
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
		if ( 'load_more' === $attributes['pagination'] ) {
			$data = $attributes;
			$data['page'] = $current + 1;
			return '<div class="atomic-social-pagination"><button type="button" class="atomic-social-pagination__button" data-atomic-social-load-more data-attributes="' . esc_attr( wp_json_encode( $data ) ) . '" data-max-pages="' . esc_attr( (string) $query->max_num_pages ) . '">' . esc_html__( 'Load More', 'atomic-wp-social-sync' ) . '</button><noscript><a href="' . esc_url( add_query_arg( 'atomic_social_page', $current + 1 ) ) . '">' . esc_html__( 'Next page', 'atomic-wp-social-sync' ) . '</a></noscript><p class="screen-reader-text" aria-live="polite"></p></div>';
		}

		$links = paginate_links(
			array(
				'base'      => esc_url_raw( add_query_arg( 'atomic_social_page', '%#%' ) ),
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

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function attributes( array $input ): array {
		$defaults = array(
			'postsPerPage' => 12, 'page' => max( 1, (int) wp_unslash( $_GET['atomic_social_page'] ?? 1 ) ), 'order' => 'newest', 'pinnedFirst' => true,
			'homepageOnly' => false, 'providers' => array(), 'connections' => array(), 'desktopColumns' => 3, 'tabletColumns' => 2,
			'mobileColumns' => 1, 'gap' => 'medium', 'showImage' => true, 'imageRatio' => 'auto', 'showDate' => true,
			'showExcerpt' => true, 'excerptLength' => 30, 'showSource' => true, 'showCta' => true,
			'ctaLabel' => __( 'View post', 'atomic-wp-social-sync' ), 'cardLink' => 'original', 'pagination' => 'none',
		);
		$attributes = wp_parse_args( $input, $defaults );
		$attributes['postsPerPage'] = max( 1, min( 100, (int) $attributes['postsPerPage'] ) );
		$attributes['page'] = max( 1, (int) $attributes['page'] );
		$attributes['desktopColumns'] = max( 1, min( 6, (int) $attributes['desktopColumns'] ) );
		$attributes['tabletColumns'] = max( 1, min( 4, (int) $attributes['tabletColumns'] ) );
		$attributes['mobileColumns'] = max( 1, min( 2, (int) $attributes['mobileColumns'] ) );
		$attributes['excerptLength'] = max( 1, min( 200, (int) $attributes['excerptLength'] ) );
		$attributes['gap'] = in_array( $attributes['gap'], array( 'small', 'medium', 'large' ), true ) ? $attributes['gap'] : 'medium';
		$attributes['imageRatio'] = in_array( $attributes['imageRatio'], array( 'auto', '1-1', '4-3', '3-2', '16-9' ), true ) ? $attributes['imageRatio'] : 'auto';
		$attributes['cardLink'] = in_array( $attributes['cardLink'], array( 'original', 'local', 'none' ), true ) ? $attributes['cardLink'] : 'original';
		$attributes['pagination'] = in_array( $attributes['pagination'], array( 'none', 'numbers', 'load_more' ), true ) ? $attributes['pagination'] : 'none';
		$attributes['ctaLabel'] = sanitize_text_field( (string) $attributes['ctaLabel'] );
		return $attributes;
	}
}
