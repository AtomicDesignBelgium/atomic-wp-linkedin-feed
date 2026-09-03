<?php
/**
 * Dynamic Gutenberg block and public local-only Load More endpoint.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Blocks;

use AtomicWPSocialSync\Display\FeedQuery;
use AtomicWPSocialSync\Display\FeedRenderer;
use WP_REST_Request;
use WP_REST_Response;

final class FeedBlock {
	public function __construct(
		private readonly FeedQuery $query,
		private readonly FeedRenderer $renderer
	) {}

	public function register(): void {
		register_block_type(
			ATOMIC_WP_SOCIAL_SYNC_PATH . 'blocks/feed',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/** @param array<string,mixed> $attributes */
	public function render( array $attributes ): string {
		$attributes = $this->renderer->attributes( $attributes );
		return $this->renderer->render( $this->query->query( $attributes ), $attributes );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'atomic-wp-social-sync/v1',
			'/feed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'loadMore' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function loadMore( WP_REST_Request $request ): WP_REST_Response {
		$input      = $request->get_json_params();
		$attributes = $this->renderer->attributes( is_array( $input ) ? $input : array() );
		$query      = $this->query->query( $attributes );
		return new WP_REST_Response(
			array(
				'html'        => $this->renderer->renderCards( $query->posts, $attributes ),
				'page'        => $attributes['page'],
				'max_pages'   => (int) $query->max_num_pages,
				'has_more'    => $attributes['page'] < $query->max_num_pages,
			),
			200
		);
	}
}
