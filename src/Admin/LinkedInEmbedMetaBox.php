<?php
/**
 * LinkedIn embed input and normalization for manual embed-mode posts.
 *
 * This meta box is intentionally strict:
 * - Admin pastes embed iframe / embed URL / URN
 * - We store only normalized safe values (URN + heights + publication date)
 * - We never persist arbitrary HTML
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use AtomicWPSocialSync\WordPress\SocialPostType;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_Post;

final class LinkedInEmbedMetaBox {
	private const NONCE_ACTION = 'atomic_social_linkedin_embed_meta';
	private const ERROR_META   = '_atomic_social_linkedin_embed_error';

	public function register(): void {
		add_meta_box(
			'atomic-social-linkedin-embed',
			__( 'LinkedIn Embed', 'atomic-wp-social-sync' ),
			array( $this, 'render' ),
			SocialPostType::POST_TYPE,
			'normal',
			'high'
		);
		add_action( 'admin_notices', array( $this, 'renderNotice' ) );
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'atomic_social_linkedin_embed_nonce' );

		$urn      = (string) get_post_meta( $post->ID, MetaKeys::EMBED_URN, true );
		$mode     = (string) get_post_meta( $post->ID, MetaKeys::INTEGRATION_MODE, true );
		$compact  = (string) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_COMPACT, true );
		$full     = (string) get_post_meta( $post->ID, MetaKeys::EMBED_HEIGHT_FULL, true );
		$published = (string) get_post_meta( $post->ID, MetaKeys::REMOTE_PUBLISHED_AT, true );
		if ( '' === $published ) {
			$published = get_post_time( DATE_ATOM, true, $post );
		}

		// Convert to local datetime-local for admin convenience.
		$published_local = '';
		try {
			$dt = new DateTimeImmutable( $published );
			$dt = $dt->setTimezone( wp_timezone() );
			$published_local = $dt->format( 'Y-m-d\TH:i' );
		} catch ( \Throwable ) {
			$published_local = '';
		}

		echo '<p><label for="atomic-social-linkedin-embed-input"><strong>' . esc_html__( 'LinkedIn Embed / URN', 'atomic-wp-social-sync' ) . '</strong></label></p>';
		echo '<p class="description">' . esc_html__( 'Paste the LinkedIn embed code, embed URL, or Share URN.', 'atomic-wp-social-sync' ) . '</p>';
		echo '<textarea class="widefat" rows="4" id="atomic-social-linkedin-embed-input" name="atomic_social_linkedin_embed_input" placeholder="urn:li:share:7500553868422897664"></textarea>';

		if ( IntegrationMode::EMBED === $mode && '' !== $urn ) {
			echo '<p><strong>' . esc_html__( 'Embed validated', 'atomic-wp-social-sync' ) . ':</strong><br><code>' . esc_html( $urn ) . '</code></p>';
		}

		echo '<hr>';
		echo '<p><label for="atomic-social-linkedin-published"><strong>' . esc_html__( 'LinkedIn publication date', 'atomic-wp-social-sync' ) . '</strong></label></p>';
		echo '<p class="description">' . esc_html__( 'Used for feed ordering. This should be the original LinkedIn publication date, not the date you added the item to WordPress.', 'atomic-wp-social-sync' ) . '</p>';
		echo '<input type="datetime-local" class="regular-text" id="atomic-social-linkedin-published" name="atomic_social_linkedin_published_at" value="' . esc_attr( $published_local ) . '">';

		echo '<hr>';
		echo '<details><summary><strong>' . esc_html__( 'Advanced: embed height overrides', 'atomic-wp-social-sync' ) . '</strong></summary>';
		echo '<p class="description">' . esc_html__( 'LinkedIn embeds are cross-origin. Set heights explicitly when needed. Width is always responsive (100%).', 'atomic-wp-social-sync' ) . '</p>';
		echo '<p><label for="atomic-social-linkedin-height-compact">' . esc_html__( 'Compact embed height', 'atomic-wp-social-sync' ) . '</label><br>';
		echo '<input type="number" min="' . esc_attr( (string) LinkedInEmbed::MIN_HEIGHT ) . '" max="' . esc_attr( (string) LinkedInEmbed::MAX_HEIGHT ) . '" id="atomic-social-linkedin-height-compact" name="atomic_social_linkedin_height_compact" value="' . esc_attr( $compact ) . '" class="small-text"> <span class="description">' . esc_html__( 'px', 'atomic-wp-social-sync' ) . '</span></p>';
		echo '<p><label for="atomic-social-linkedin-height-full">' . esc_html__( 'Full embed height', 'atomic-wp-social-sync' ) . '</label><br>';
		echo '<input type="number" min="' . esc_attr( (string) LinkedInEmbed::MIN_HEIGHT ) . '" max="' . esc_attr( (string) LinkedInEmbed::MAX_HEIGHT ) . '" id="atomic-social-linkedin-height-full" name="atomic_social_linkedin_height_full" value="' . esc_attr( $full ) . '" class="small-text"> <span class="description">' . esc_html__( 'px', 'atomic-wp-social-sync' ) . '</span></p>';
		echo '</details>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( SocialPostType::POST_TYPE !== $post->post_type || SocialPostRepository::isSynchronizing() || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['atomic_social_linkedin_embed_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = trim( (string) wp_unslash( $_POST['atomic_social_linkedin_embed_input'] ?? '' ) );
		$published_local = trim( (string) wp_unslash( $_POST['atomic_social_linkedin_published_at'] ?? '' ) );
		$height_compact_override = trim( (string) wp_unslash( $_POST['atomic_social_linkedin_height_compact'] ?? '' ) );
		$height_full_override    = trim( (string) wp_unslash( $_POST['atomic_social_linkedin_height_full'] ?? '' ) );
		$compact_override = $this->sanitizeHeight( $height_compact_override );
		$full_override    = $this->sanitizeHeight( $height_full_override );

		// Always validate and persist publication date when supplied (even if embed input is unchanged).
		if ( '' !== $published_local ) {
			try {
				$local = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $published_local, wp_timezone() );
				if ( ! $local instanceof DateTimeImmutable ) {
					throw new RuntimeException( __( 'Invalid publication date.', 'atomic-wp-social-sync' ) );
				}
				$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );
				$iso = $utc->format( DATE_ATOM );
				update_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, $iso );

				// Keep WordPress post_date aligned so existing FeedQuery date ordering remains correct.
				$gmt = $utc->format( 'Y-m-d H:i:s' );
				remove_action( 'save_post_' . SocialPostType::POST_TYPE, array( $this, 'save' ), 20 );
				wp_update_post(
					array(
						'ID'            => $post_id,
						'post_date_gmt'  => $gmt,
						'post_date'      => get_date_from_gmt( $gmt ),
					)
				);
				add_action( 'save_post_' . SocialPostType::POST_TYPE, array( $this, 'save' ), 20, 2 );
			} catch ( \Throwable $exception ) {
				update_post_meta( $post_id, self::ERROR_META, $exception->getMessage() );
			}
		}

		// If no embed input was submitted, do not switch the post into embed-mode.
		if ( '' === $input ) {
			return;
		}

		try {
			$parsed = LinkedInEmbed::parseInput( $input );
			$urn    = $parsed['urn'];
			$strategy = (string) ( $parsed['strategy'] ?? LinkedInEmbed::STRATEGY_OFFICIAL );

			update_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, IntegrationMode::EMBED );
			update_post_meta( $post_id, MetaKeys::PROVIDER, 'linkedin' );
			update_post_meta( $post_id, MetaKeys::EXTERNAL_ID, $urn );
			update_post_meta( $post_id, MetaKeys::EXTERNAL_URL, esc_url_raw( 'https://www.linkedin.com/feed/update/' . $urn . '/' ) );
			update_post_meta( $post_id, MetaKeys::EMBED_STRATEGY, $strategy );
			update_post_meta( $post_id, MetaKeys::EMBED_URN, $urn );
			update_post_meta( $post_id, MetaKeys::DETACHED, '1' );
			update_post_meta( $post_id, MetaKeys::REMOTE_STATUS, 'embedded' );

			// Ensure embed-mode records are editorially managed and never targeted by the import sync engine.
			delete_post_meta( $post_id, MetaKeys::CONNECTION_ID );
			delete_post_meta( $post_id, MetaKeys::CONTENT_HASH );
			delete_post_meta( $post_id, MetaKeys::REMOTE_TEXT );
			delete_post_meta( $post_id, MetaKeys::REMOTE_MODIFIED_AT );
			delete_post_meta( $post_id, MetaKeys::REMOTE_AUTHOR_ID );
			delete_post_meta( $post_id, MetaKeys::REMOTE_AUTHOR_NAME );
			delete_post_meta( $post_id, MetaKeys::MEDIA_TYPE );
			delete_post_meta( $post_id, MetaKeys::MEDIA_SOURCE_ID );
			delete_post_meta( $post_id, MetaKeys::SYNC_CONFLICT );
			delete_post_meta( $post_id, MetaKeys::PENDING_REMOTE );

			// Height extraction from pasted iframe is optional. Allow override fields to win.
			$height = $parsed['height'];
			if ( null !== $compact_override ) {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, (string) $compact_override );
			} elseif ( $parsed['is_compact'] && null !== $height ) {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, (string) $height );
			}

			if ( null !== $full_override ) {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, (string) $full_override );
			} elseif ( ! $parsed['is_compact'] && null !== $height ) {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, (string) $height );
			}

			// Provider taxonomy classification (connection taxonomy is intentionally not used for manual embeds).
			wp_set_object_terms( $post_id, 'linkedin', SocialPostType::PROVIDER_TAXONOMY );

			delete_post_meta( $post_id, self::ERROR_META );
		} catch ( \Throwable $exception ) {
			update_post_meta( $post_id, self::ERROR_META, $exception->getMessage() );
		}
	}

	private function sanitizeHeight( string $input ): ?int {
		if ( '' === $input ) {
			return null;
		}
		if ( ! ctype_digit( $input ) ) {
			return null;
		}
		$height = (int) $input;
		return ( $height >= LinkedInEmbed::MIN_HEIGHT && $height <= LinkedInEmbed::MAX_HEIGHT ) ? $height : null;
	}

	public function renderNotice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || SocialPostType::POST_TYPE !== ( $screen->post_type ?? '' ) ) {
			return;
		}
		if ( ! isset( $_GET['post'] ) ) {
			return;
		}
		$post_id = (int) $_GET['post'];
		$error   = (string) get_post_meta( $post_id, self::ERROR_META, true );
		if ( '' === $error ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'LinkedIn embed error:', 'atomic-wp-social-sync' ) . '</strong> ' . esc_html( $error ) . '</p></div>';
	}
}
