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

		$src = LinkedInEmbed::embedUrl( $urn, $presentation, $strategy );
		if ( '' === $src && 'compact' === $presentation ) {
			$presentation = 'full';
			$src          = LinkedInEmbed::embedUrl( $urn, $presentation, $strategy );
		}
		if ( '' === $src ) {
			return '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin"><div class="atomic-social-card__body"><p class="atomic-social-feed__empty">' . esc_html__( 'LinkedIn embed URL could not be generated.', 'atomic-wp-social-sync' ) . '</p></div></article>';
		}

		$height_key = 'compact' === $presentation ? MetaKeys::EMBED_HEIGHT_COMPACT : MetaKeys::EMBED_HEIGHT_FULL;
		$height     = (int) get_post_meta( $post->ID, $height_key, true );
		if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
			$height = 'compact' === $presentation ? LinkedInEmbed::DEFAULT_HEIGHT_COMPACT : LinkedInEmbed::DEFAULT_HEIGHT_FULL;
		}

		$output  = '<article id="' . esc_attr( $anchor ) . '" class="atomic-social-card atomic-social-card--linkedin atomic-social-card--embed">';
		$output .= '<div class="atomic-social-card__embed">';
		$output .= '<iframe class="atomic-social-card__embed-frame" src="' . esc_url( $src ) . '" height="' . esc_attr( (string) $height ) . '" title="' . esc_attr__( 'Embedded LinkedIn post', 'atomic-wp-social-sync' ) . '" loading="lazy" allowfullscreen></iframe>';
		$output .= '</div>';

		if ( ! empty( $attributes['showFullNewsCta'] ) && ! empty( $attributes['newsPageId'] ) ) {
			$news_url = get_permalink( (int) $attributes['newsPageId'] );
			if ( $news_url ) {
				$href   = $news_url . '#' . $anchor;
				$label  = sanitize_text_field( (string) $attributes['fullNewsCtaLabel'] );
				$label  = '' !== $label ? $label : __( 'View full news', 'atomic-wp-social-sync' );
				$output .= '<div class="atomic-social-card__full-link"><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div>';
			}
		}

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
		if ( 'load_more' === $attributes['pagination'] ) {
			$data = $attributes;
			$data['page'] = $current + 1;
			return '<div class="atomic-social-pagination"><button type="button" class="atomic-social-pagination__button" data-atomic-social-load-more data-attributes="' . esc_attr( wp_json_encode( $data ) ) . '" data-max-pages="' . esc_attr( (string) $query->max_num_pages ) . '">' . esc_html__( 'Load More', 'atomic-wp-social-sync' ) . '</button><noscript><a href="' . esc_url( add_query_arg( $key, $current + 1 ) ) . '">' . esc_html__( 'Next page', 'atomic-wp-social-sync' ) . '</a></noscript><p class="screen-reader-text" aria-live="polite"></p></div>';
		}

		$links = paginate_links(
			array(
				'base'      => esc_url_raw( add_query_arg( $key, '%#%' ) ),
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
		$page_provided = array_key_exists( 'page', $input );
		$defaults = array(
			'postsPerPage' => 12, 'page' => 1, 'paginationKey' => '', 'order' => 'newest', 'pinnedFirst' => true,
			'homepageOnly' => false, 'providers' => array(), 'connections' => array(), 'desktopColumns' => 3, 'tabletColumns' => 2,
			'mobileColumns' => 1, 'gap' => 'medium', 'showImage' => true, 'imageRatio' => 'auto', 'showDate' => true,
			'showExcerpt' => true, 'excerptLength' => 30, 'showSource' => true, 'showCta' => true,
			'ctaLabel' => __( 'View post', 'atomic-wp-social-sync' ), 'cardLink' => 'original', 'pagination' => 'none',
			'presentation' => 'auto',
			'showFullNewsCta' => false,
			'fullNewsCtaLabel' => __( 'View full news', 'atomic-wp-social-sync' ),
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
		$attributes['showFullNewsCta'] = (bool) $attributes['showFullNewsCta'];
		$attributes['fullNewsCtaLabel'] = sanitize_text_field( (string) $attributes['fullNewsCtaLabel'] );
		$attributes['newsPageId'] = absint( $attributes['newsPageId'] );
		if ( 0 === $attributes['newsPageId'] ) {
			$attributes['newsPageId'] = (int) $this->settings->get( 'news_page_id', 0 );
		}

		// Pagination key: try to avoid conflicts when multiple feeds are rendered on the same page.
		// Backward compatible: still read from legacy `atomic_social_page` when the instance key is absent.
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
			$page = (int) wp_unslash( $_GET[ $key ] ?? 0 );
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
