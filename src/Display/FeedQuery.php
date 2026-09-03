<?php
/**
 * Local-only Social Post query builder shared by every frontend entry point.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Display;

use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\WordPress\SocialPostType;
use WP_Query;

final class FeedQuery {
	private bool $pinned_first = false;
	private string $order = 'DESC';

	/** @param array<string,mixed> $attributes */
	public function query( array $attributes ): WP_Query {
		$posts_per_page = max( 1, min( 100, (int) ( $attributes['postsPerPage'] ?? $attributes['posts'] ?? 12 ) ) );
		$page           = max( 1, (int) ( $attributes['page'] ?? 1 ) );
		$this->order    = 'oldest' === ( $attributes['order'] ?? 'newest' ) ? 'ASC' : 'DESC';
		$tax_query      = array();
		$providers      = $this->slugList( $attributes['providers'] ?? array() );
		$connections    = $this->slugList( $attributes['connections'] ?? array() );
		if ( $providers ) {
			$tax_query[] = array( 'taxonomy' => SocialPostType::PROVIDER_TAXONOMY, 'field' => 'slug', 'terms' => $providers );
		}
		if ( $connections ) {
			$tax_query[] = array( 'taxonomy' => SocialPostType::CONNECTION_TAXONOMY, 'field' => 'slug', 'terms' => $connections );
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array( 'key' => MetaKeys::HIDDEN_FROM_FEED, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MetaKeys::HIDDEN_FROM_FEED, 'value' => '1', 'compare' => '!=' ),
			),
		);
		if ( ! empty( $attributes['homepageOnly'] ) ) {
			$meta_query[] = array( 'key' => MetaKeys::SHOW_ON_HOMEPAGE, 'value' => '1' );
		}

		$this->pinned_first = ! empty( $attributes['pinnedFirst'] );
		if ( $this->pinned_first ) {
			add_filter( 'posts_clauses', array( $this, 'orderPinnedFirst' ), 10, 2 );
		}
		$query = new WP_Query(
			array(
				'post_type'           => SocialPostType::POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => $posts_per_page,
				'paged'               => $page,
				'orderby'             => 'date',
				'order'               => $this->order,
				'ignore_sticky_posts' => true,
				'meta_query'          => $meta_query,
				'tax_query'           => $tax_query,
				'atomic_social_feed'  => true,
			)
		);
		if ( $this->pinned_first ) {
			remove_filter( 'posts_clauses', array( $this, 'orderPinnedFirst' ), 10 );
		}
		return $query;
	}

	/** @param array<string,string> $clauses @return array<string,string> */
	public function orderPinnedFirst( array $clauses, WP_Query $query ): array {
		if ( ! $query->get( 'atomic_social_feed' ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} atomic_social_pinned ON ({$wpdb->posts}.ID = atomic_social_pinned.post_id AND atomic_social_pinned.meta_key = '" . esc_sql( MetaKeys::PINNED ) . "')";
		$clauses['orderby'] = "CAST(COALESCE(atomic_social_pinned.meta_value, '0') AS UNSIGNED) DESC, {$wpdb->posts}.post_date {$this->order}";
		return $clauses;
	}

	/** @param mixed $value @return string[] */
	private function slugList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_title', $value ) ) );
	}
}
