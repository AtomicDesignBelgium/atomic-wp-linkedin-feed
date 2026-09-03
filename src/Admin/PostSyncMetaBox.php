<?php
/**
 * Per-post source status and local editorial controls.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Media\MediaImporter;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Sync\ReconciliationService;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use AtomicWPSocialSync\WordPress\SocialPostType;
use WP_Post;

final class PostSyncMetaBox {
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly ReconciliationService $reconciliation
	) {}

	public function register(): void {
		add_meta_box(
			'atomic-social-sync',
			__( 'Social Sync', 'atomic-wp-social-sync' ),
			array( $this, 'render' ),
			SocialPostType::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'atomic_social_post_meta', 'atomic_social_post_nonce' );
		$connection_id = (string) get_post_meta( $post->ID, MetaKeys::CONNECTION_ID, true );
		$connection    = $this->connections->find( $connection_id );
		$provider      = (string) get_post_meta( $post->ID, MetaKeys::PROVIDER, true );
		$external_url  = (string) get_post_meta( $post->ID, MetaKeys::EXTERNAL_URL, true );
		$rows = array(
			__( 'Source', 'atomic-wp-social-sync' )          => 'linkedin' === $provider ? __( 'LinkedIn', 'atomic-wp-social-sync' ) : ucfirst( $provider ),
			__( 'Account', 'atomic-wp-social-sync' )         => $connection ? $connection->account_name : $connection_id,
			__( 'Status', 'atomic-wp-social-sync' )          => (string) get_post_meta( $post->ID, MetaKeys::REMOTE_STATUS, true ),
			__( 'Published', 'atomic-wp-social-sync' )       => (string) get_post_meta( $post->ID, MetaKeys::REMOTE_PUBLISHED_AT, true ),
			__( 'Remote modified', 'atomic-wp-social-sync' ) => (string) get_post_meta( $post->ID, MetaKeys::REMOTE_MODIFIED_AT, true ),
			__( 'Last verified', 'atomic-wp-social-sync' )   => (string) get_post_meta( $post->ID, MetaKeys::LAST_VERIFIED_AT, true ),
			__( 'Last synced', 'atomic-wp-social-sync' )     => (string) get_post_meta( $post->ID, MetaKeys::LAST_SYNCED_AT, true ),
		);
		foreach ( $rows as $label => $value ) {
			echo '<p><strong>' . esc_html( $label ) . ':</strong><br>' . esc_html( $value ?: '—' ) . '</p>';
		}
		if ( $external_url ) {
			echo '<p><a href="' . esc_url( $external_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View original ↗', 'atomic-wp-social-sync' ) . '</a></p>';
		}

		echo '<hr><p><strong>' . esc_html__( 'Editorial', 'atomic-wp-social-sync' ) . '</strong></p>';
		$this->checkbox( $post->ID, 'show_on_homepage', MetaKeys::SHOW_ON_HOMEPAGE, __( 'Show on homepage', 'atomic-wp-social-sync' ), true );
		$this->checkbox( $post->ID, 'hidden_from_feed', MetaKeys::HIDDEN_FROM_FEED, __( 'Hide from feeds', 'atomic-wp-social-sync' ) );
		$this->checkbox( $post->ID, 'pinned', MetaKeys::PINNED, __( 'Pin post', 'atomic-wp-social-sync' ) );
		$this->checkbox( $post->ID, 'detached', MetaKeys::DETACHED, __( 'Detach from source sync', 'atomic-wp-social-sync' ) );
		$excerpt = (string) get_post_meta( $post->ID, MetaKeys::EXCERPT_OVERRIDE, true );
		echo '<p><label for="atomic-social-excerpt"><strong>' . esc_html__( 'Website Excerpt', 'atomic-wp-social-sync' ) . '</strong></label><textarea class="widefat" rows="4" id="atomic-social-excerpt" name="atomic_social_excerpt_override">' . esc_textarea( $excerpt ) . '</textarea></p>';

		$conflict = (string) get_post_meta( $post->ID, MetaKeys::SYNC_CONFLICT, true );
		if ( $conflict ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Remote source and WordPress content require a decision.', 'atomic-wp-social-sync' ) . '</strong></p>';
			echo '<p><button class="button button-primary" name="atomic_social_conflict_action" value="use_remote">' . esc_html__( 'Use Remote Version', 'atomic-wp-social-sync' ) . '</button> <button class="button" name="atomic_social_conflict_action" value="keep_local">' . esc_html__( 'Keep WordPress Version', 'atomic-wp-social-sync' ) . '</button></p></div>';
		}
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( SocialPostType::POST_TYPE !== $post->post_type || SocialPostRepository::isSynchronizing() || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['atomic_social_post_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'atomic_social_post_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array( 'show_on_homepage' => MetaKeys::SHOW_ON_HOMEPAGE, 'hidden_from_feed' => MetaKeys::HIDDEN_FROM_FEED, 'pinned' => MetaKeys::PINNED, 'detached' => MetaKeys::DETACHED ) as $field => $meta_key ) {
			update_post_meta( $post_id, $meta_key, isset( $_POST[ 'atomic_social_' . $field ] ) ? '1' : '0' );
		}
		$excerpt = sanitize_textarea_field( (string) wp_unslash( $_POST['atomic_social_excerpt_override'] ?? '' ) );
		update_post_meta( $post_id, MetaKeys::EXCERPT_OVERRIDE, $excerpt );

		$generated_title = (string) get_post_meta( $post_id, MetaKeys::GENERATED_TITLE, true );
		if ( '' !== $generated_title && $post->post_title !== $generated_title ) {
			update_post_meta( $post_id, MetaKeys::TITLE_LOCKED, '1' );
		}

		$conflict_action = sanitize_key( (string) wp_unslash( $_POST['atomic_social_conflict_action'] ?? '' ) );
		if ( 'use_remote' === $conflict_action ) {
			$this->reconciliation->useRemoteVersion( $post_id );
		} elseif ( 'keep_local' === $conflict_action ) {
			$this->reconciliation->keepWordPressVersion( $post_id );
		}
	}

	public function lockFeaturedImage( int|array $meta_id, int $object_id, string $meta_key ): void {
		unset( $meta_id );
		if ( '_thumbnail_id' !== $meta_key || MediaImporter::isImporting() || SocialPostType::POST_TYPE !== get_post_type( $object_id ) ) {
			return;
		}
		update_post_meta( $object_id, MetaKeys::FEATURED_IMAGE_LOCKED, '1' );
	}

	private function checkbox( int $post_id, string $field, string $meta_key, string $label, bool $default = false ): void {
		$value   = get_post_meta( $post_id, $meta_key, true );
		$checked = '' === $value ? $default : '1' === $value;
		echo '<p><label><input type="checkbox" name="atomic_social_' . esc_attr( $field ) . '" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}
}
