<?php
/**
 * Dedicated LinkedIn Posts admin screen (embed-mode).
 *
 * V1 UX goal: administrators manage LinkedIn items without using the Gutenberg editor.
 * Storage remains `atomic_social_post` with normalized embed metadata.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\WordPress\SocialPostType;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class LinkedInPostsPage {
	public const MENU_SLUG = 'atomic-linkedin-feed';

	private const NONCE_ACTION = 'atomic_linkedin_feed_admin';

	public function registerMenu(): void {
		add_menu_page(
			__( 'Atomic LinkedIn Feed', 'atomic-wp-social-sync' ),
			__( 'Atomic LinkedIn Feed', 'atomic-wp-social-sync' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-share',
			56
		);
	}

	public function registerHooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_post_atomic_linkedin_delete_post', array( $this, 'deletePost' ) );

		add_action( 'wp_ajax_atomic_linkedin_create_post', array( $this, 'ajaxCreatePost' ) );
		add_action( 'wp_ajax_atomic_linkedin_update_post', array( $this, 'ajaxUpdatePost' ) );
		add_action( 'wp_ajax_atomic_linkedin_get_post', array( $this, 'ajaxGetPost' ) );

		add_action( 'admin_menu', array( $this, 'hideLegacyCptMenu' ), 999 );
		add_action( 'admin_init', array( $this, 'blockEditorAccessForEmbedPosts' ) );
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		add_thickbox();
		wp_enqueue_script(
			'atomic-linkedin-feed-admin',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/linkedin-posts-admin.js',
			array(),
			ATOMIC_WP_SOCIAL_SYNC_VERSION,
			true
		);
		wp_localize_script(
			'atomic-linkedin-feed-admin',
			'atomicLinkedInFeedAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'wpTimeZone' => wp_timezone_string(),
				'i18n'    => array(
					'createTitle'    => __( 'Add LinkedIn Post', 'atomic-wp-social-sync' ),
					'editTitle'      => __( 'Edit LinkedIn Post', 'atomic-wp-social-sync' ),
					'previewTitle'   => __( 'LinkedIn preview', 'atomic-wp-social-sync' ),
					'saving'         => __( 'Saving…', 'atomic-wp-social-sync' ),
					'addPost'        => __( 'Add post', 'atomic-wp-social-sync' ),
					'saveChanges'    => __( 'Save changes', 'atomic-wp-social-sync' ),
					'cancel'         => __( 'Cancel', 'atomic-wp-social-sync' ),
					'preview'        => __( 'Preview', 'atomic-wp-social-sync' ),
					'embedValidated' => __( 'LinkedIn embed validated', 'atomic-wp-social-sync' ),
					'notDetectedYet' => __( 'Not detected yet', 'atomic-wp-social-sync' ),
					'postDetected'   => __( 'LinkedIn post detected', 'atomic-wp-social-sync' ),
					'shareId'        => __( 'Share ID', 'atomic-wp-social-sync' ),
					'normalizedUrn'  => __( 'Normalized URN', 'atomic-wp-social-sync' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page     = max( 1, (int) wp_unslash( $_GET['paged'] ?? 1 ) );
		$per_page = 20;

		$query = new \WP_Query(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'meta_query'     => array(
					array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
				),
			)
		);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'LinkedIn Posts', 'atomic-wp-social-sync' ); ?></h1>
			<a href="#TB_inline?width=600&height=420&inlineId=atomic-linkedin-feed-modal" class="page-title-action thickbox" id="atomic-linkedin-feed-add"><?php esc_html_e( '+ Add LinkedIn Post', 'atomic-wp-social-sync' ); ?></a>
			<hr class="wp-header-end">

			<p><?php esc_html_e( 'Manage a local LinkedIn feed based on manually selected official LinkedIn embeds.', 'atomic-wp-social-sync' ); ?></p>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Share URN', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Publication date', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Preview', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Status / actions', 'atomic-wp-social-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! $query->posts ) :
						?>
						<tr><td colspan="4"><?php esc_html_e( 'No LinkedIn posts found.', 'atomic-wp-social-sync' ); ?></td></tr>
						<?php
					else :
						foreach ( $query->posts as $post ) :
							if ( ! $post instanceof \WP_Post ) {
								continue;
							}
							$urn = (string) get_post_meta( $post->ID, MetaKeys::EMBED_URN, true );
							$published = (string) get_post_meta( $post->ID, MetaKeys::REMOTE_PUBLISHED_AT, true );
							$published_label = '' !== $published ? $this->formatAdminDate( $published ) : get_the_date( '', $post );

							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'atomic_linkedin_delete_post',
										'post_id' => (int) $post->ID,
									),
									admin_url( 'admin-post.php' )
								),
								self::NONCE_ACTION
							);
							?>
							<tr>
								<td>
									<code><?php echo esc_html( $urn ?: '—' ); ?></code>
								</td>
								<td><?php echo esc_html( $published_label ); ?></td>
								<td>
									<button type="button" class="button atomic-linkedin-preview" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Preview', 'atomic-wp-social-sync' ); ?></button>
								</td>
								<td>
									<span class="atomic-linkedin-status"><?php echo esc_html( ucfirst( (string) $post->post_status ) ); ?></span>
									<span class="atomic-linkedin-actions">
										<button type="button" class="button-link atomic-linkedin-edit" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Edit', 'atomic-wp-social-sync' ); ?></button>
										<span class="sep">|</span>
										<a class="button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Trash this LinkedIn post?', 'atomic-wp-social-sync' ) ); ?>');"><?php esc_html_e( 'Trash', 'atomic-wp-social-sync' ); ?></a>
									</span>
								</td>
							</tr>
							<?php
						endforeach;
					endif;
					?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) $query->max_num_pages;
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => esc_url_raw( add_query_arg( 'paged', '%#%' ) ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'type'      => 'plain',
							'prev_text' => __( 'Previous', 'atomic-wp-social-sync' ),
							'next_text' => __( 'Next', 'atomic-wp-social-sync' ),
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>

		<div id="atomic-linkedin-feed-modal" style="display:none;">
			<form id="atomic-linkedin-feed-form">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
				<input type="hidden" name="post_id" id="atomic-linkedin-post-id" value="">

				<p>
					<label for="atomic-linkedin-embed"><strong><?php esc_html_e( 'LinkedIn Embed / Share ID', 'atomic-wp-social-sync' ); ?></strong></label>
					<span class="description" style="display:block;"><?php esc_html_e( 'Paste the LinkedIn embed code, embed URL, Share URN, or Share ID.', 'atomic-wp-social-sync' ); ?></span>
					<textarea class="widefat" rows="4" id="atomic-linkedin-embed" name="embed_input" placeholder="urn:li:share:7500553868422897664"></textarea>
				</p>

				<p>
					<label for="atomic-linkedin-published"><strong><?php esc_html_e( 'Original publication date', 'atomic-wp-social-sync' ); ?></strong></label>
					<span class="description" style="display:block;"><?php esc_html_e( 'Used for feed ordering.', 'atomic-wp-social-sync' ); ?></span>
					<input type="datetime-local" id="atomic-linkedin-published" name="published_at" class="regular-text">
				</p>

				<div id="atomic-linkedin-result" style="margin-top:10px;"></div>

				<p style="margin-top:16px;">
					<button type="submit" class="button button-primary" id="atomic-linkedin-save"><?php esc_html_e( 'Add post', 'atomic-wp-social-sync' ); ?></button>
					<button type="button" class="button" id="atomic-linkedin-cancel"><?php esc_html_e( 'Cancel', 'atomic-wp-social-sync' ); ?></button>
				</p>
			</form>
		</div>

		<div id="atomic-linkedin-preview-modal" style="display:none;">
			<div id="atomic-linkedin-preview-container"></div>
		</div>
		<?php
	}

	public function ajaxCreatePost(): void {
		$this->assertAjaxAuth();
		try {
			$input          = trim( (string) wp_unslash( $_POST['embed_input'] ?? '' ) );
			$published_local = trim( (string) wp_unslash( $_POST['published_at'] ?? '' ) );
			$post_id        = $this->createOrUpdateEmbedPost( null, $input, $published_local );
			wp_send_json_success(
				array(
					'post_id' => $post_id,
					'urn'     => (string) get_post_meta( $post_id, MetaKeys::EMBED_URN, true ),
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	public function ajaxUpdatePost(): void {
		$this->assertAjaxAuth();
		try {
			$post_id        = absint( $_POST['post_id'] ?? 0 );
			$input          = trim( (string) wp_unslash( $_POST['embed_input'] ?? '' ) );
			$published_local = trim( (string) wp_unslash( $_POST['published_at'] ?? '' ) );
			if ( $post_id <= 0 ) {
				throw new RuntimeException( __( 'Invalid post ID.', 'atomic-wp-social-sync' ) );
			}
			$this->createOrUpdateEmbedPost( $post_id, $input, $published_local );
			wp_send_json_success(
				array(
					'post_id' => $post_id,
					'urn'     => (string) get_post_meta( $post_id, MetaKeys::EMBED_URN, true ),
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	public function ajaxGetPost(): void {
		$this->assertAjaxAuth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'atomic-wp-social-sync' ) ), 400 );
		}
		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) || SocialPostType::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Post was not found.', 'atomic-wp-social-sync' ) ), 404 );
		}
		$urn = (string) get_post_meta( $post_id, MetaKeys::EMBED_URN, true );
		$published = (string) get_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, true );
		$published_local = '';
		if ( '' !== $published ) {
			try {
				$dt = new DateTimeImmutable( $published );
				$dt = $dt->setTimezone( wp_timezone() );
				$published_local = $dt->format( 'Y-m-d\TH:i' );
			} catch ( \Throwable ) {
				$published_local = '';
			}
		}
		$preview_src = LinkedInEmbed::embedUrl( $urn, 'compact' );
		$height = (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, true );
		if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
			$height = LinkedInEmbed::DEFAULT_HEIGHT_COMPACT;
		}
		wp_send_json_success(
			array(
				'post_id'        => $post_id,
				'urn'            => $urn,
				'published_local' => $published_local,
				'preview_src'    => $preview_src,
				'preview_height' => $height,
			)
		);
	}

	public function deletePost(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			wp_trash_post( $post_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function hideLegacyCptMenu(): void {
		if ( $this->debugAllowsEditor() ) {
			return;
		}
		remove_menu_page( 'edit.php?post_type=' . SocialPostType::POST_TYPE );
	}

	public function blockEditorAccessForEmbedPosts(): void {
		if ( $this->debugAllowsEditor() ) {
			return;
		}

		global $pagenow;
		$pagenow = (string) $pagenow;

		// Prevent "Add New" for the underlying CPT.
		if ( 'post-new.php' === $pagenow && SocialPostType::POST_TYPE === (string) ( $_GET['post_type'] ?? '' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		if ( 'post.php' === $pagenow && isset( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] );
			if ( $post_id > 0 && $this->isEmbedLinkedInPost( $post_id ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				exit;
			}
		}
	}

	private function assertAjaxAuth(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'atomic-wp-social-sync' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	private function debugAllowsEditor(): bool {
		if ( defined( 'ATOMIC_LINKEDIN_FEED_DEBUG' ) && true === ATOMIC_LINKEDIN_FEED_DEBUG ) {
			return true;
		}
		return (bool) apply_filters( 'atomic_linkedin_feed_allow_editor', false );
	}

	private function isEmbedLinkedInPost( int $post_id ): bool {
		$mode = (string) get_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, true );
		$provider = (string) get_post_meta( $post_id, MetaKeys::PROVIDER, true );
		return IntegrationMode::EMBED === $mode && 'linkedin' === $provider;
	}

	private function createOrUpdateEmbedPost( ?int $post_id, string $input, string $published_local ): int {
		$parsed = LinkedInEmbed::parseInput( $input );
		$urn    = $parsed['urn'];

		// Duplicate handling: allow unlimited different Share IDs, but reject duplicates of the same Share URN.
		$existing_id = $this->findExistingEmbedPostIdByUrn( $urn, $post_id );
		if ( null !== $existing_id ) {
			throw new RuntimeException( __( 'This LinkedIn post has already been added.', 'atomic-wp-social-sync' ) );
		}

		$iso = '';
		if ( '' !== $published_local ) {
			$local = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $published_local, wp_timezone() );
			if ( ! $local instanceof DateTimeImmutable ) {
				throw new RuntimeException( __( 'Invalid publication date.', 'atomic-wp-social-sync' ) );
			}
			$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );
			$iso = $utc->format( DATE_ATOM );
			$gmt = $utc->format( 'Y-m-d H:i:s' );
			$local_date = get_date_from_gmt( $gmt );
		} else {
			$gmt = current_time( 'mysql', true );
			$local_date = current_time( 'mysql', false );
		}

		if ( null === $post_id ) {
			$title = $this->titleFromUrn( $urn );
			$post_id = wp_insert_post(
				array(
					'post_type'     => SocialPostType::POST_TYPE,
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_content'  => '',
					'post_excerpt'  => '',
					'post_date_gmt' => $gmt,
					'post_date'     => $local_date,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				throw new RuntimeException( $post_id->get_error_message() );
			}
		} else {
			$post = get_post( $post_id );
			if ( ! ( $post instanceof \WP_Post ) || SocialPostType::POST_TYPE !== $post->post_type ) {
				throw new RuntimeException( __( 'Post was not found.', 'atomic-wp-social-sync' ) );
			}
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_date_gmt'  => $gmt,
					'post_date'      => $local_date,
					'post_title'     => $this->titleFromUrn( $urn ),
				)
			);
		}

		if ( '' !== $iso ) {
			update_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, $iso );
		}

		update_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, IntegrationMode::EMBED );
		update_post_meta( $post_id, MetaKeys::PROVIDER, 'linkedin' );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_ID, $urn );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_URL, esc_url_raw( 'https://www.linkedin.com/feed/update/' . $urn . '/' ) );
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

		// Optional height extraction (iframe paste).
		$height = $parsed['height'];
		if ( null !== $height ) {
			if ( $parsed['is_compact'] ) {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, (string) $height );
			} else {
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, (string) $height );
			}
		}

		wp_set_object_terms( $post_id, 'linkedin', SocialPostType::PROVIDER_TAXONOMY );
		return (int) $post_id;
	}

	private function findExistingEmbedPostIdByUrn( string $urn, ?int $exclude_post_id ): ?int {
		$args = array(
			'post_type'      => SocialPostType::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => array( 'ID' => 'DESC' ),
			'meta_query'     => array(
				array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
				array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
				array( 'key' => MetaKeys::EMBED_URN, 'value' => $urn ),
			),
		);
		if ( null !== $exclude_post_id && $exclude_post_id > 0 ) {
			$args['post__not_in'] = array( $exclude_post_id );
		}
		$query = new \WP_Query( $args );
		$ids = is_array( $query->posts ) ? $query->posts : array();
		$id  = $ids ? (int) $ids[0] : 0;
		return $id > 0 ? $id : null;
	}

	private function titleFromUrn( string $urn ): string {
		$id = preg_replace( '/^urn:li:share:/', '', $urn );
		$id = is_string( $id ) ? $id : '';
		$id = ctype_digit( $id ) ? $id : '';
		return $id ? sprintf( __( 'LinkedIn post %s', 'atomic-wp-social-sync' ), $id ) : __( 'LinkedIn post', 'atomic-wp-social-sync' );
	}

	private function formatAdminDate( string $iso ): string {
		try {
			$dt = new DateTimeImmutable( $iso );
			$dt = $dt->setTimezone( wp_timezone() );
			return $dt->format( 'Y-m-d H:i' );
		} catch ( \Throwable ) {
			return $iso;
		}
	}
}
