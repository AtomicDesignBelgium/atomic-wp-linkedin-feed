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
use AtomicWPSocialSync\Support\DeveloperGuard;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
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
		add_action( 'admin_post_atomic_linkedin_toggle_status', array( $this, 'toggleStatus' ) );

		add_action( 'wp_ajax_atomic_linkedin_create_post', array( $this, 'ajaxCreatePost' ) );
		add_action( 'wp_ajax_atomic_linkedin_update_post', array( $this, 'ajaxUpdatePost' ) );
		add_action( 'wp_ajax_atomic_linkedin_get_post', array( $this, 'ajaxGetPost' ) );
		add_action( 'wp_ajax_atomic_linkedin_update_title_inline', array( $this, 'ajaxUpdateTitleInline' ) );
		add_action( 'wp_ajax_atomic_linkedin_inspect_post', array( $this, 'ajaxInspectPost' ) );

		add_action( 'admin_menu', array( $this, 'hideLegacyCptMenu' ), 999 );
		add_action( 'admin_init', array( $this, 'blockEditorAccessForEmbedPosts' ) );
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		add_thickbox();
		$asset_path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/js/linkedin-posts-admin.js';
		$asset_ver  = file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_enqueue_script(
			'atomic-linkedin-feed-admin',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/linkedin-posts-admin.js',
			array(),
			$asset_ver,
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
					'editorialTitle' => __( 'Editorial title', 'atomic-wp-social-sync' ),
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
					'method'         => __( 'Method', 'atomic-wp-social-sync' ),
					'methodOfficial' => __( 'Official LinkedIn embed', 'atomic-wp-social-sync' ),
					'methodCompat'   => __( 'Compatibility embed', 'atomic-wp-social-sync' ),
					'compatMessage'  => __( 'LinkedIn does not provide an official embed option for some post formats. Atomic will attempt to display this post using LinkedIn\'s public embed renderer. Preview the post before publishing.', 'atomic-wp-social-sync' ),
					'techDetails'    => __( 'Technical details', 'atomic-wp-social-sync' ),
					'compatPreviewTitle' => __( 'Compatibility preview', 'atomic-wp-social-sync' ),
					'editTitleInline'    => __( 'Edit title', 'atomic-wp-social-sync' ),
					'saveInline'         => __( 'Save', 'atomic-wp-social-sync' ),
					'cancelInline'       => __( 'Cancel', 'atomic-wp-social-sync' ),
					'inspectTitle'       => __( 'Inspector', 'atomic-wp-social-sync' ),
					'inspectLoading'     => __( 'Loading inspector…', 'atomic-wp-social-sync' ),
					'inspectSource'      => __( 'Source / normalized data', 'atomic-wp-social-sync' ),
					'inspectMapping'     => __( 'WordPress mapping', 'atomic-wp-social-sync' ),
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

			<?php
			$trash_q = new \WP_Query(
				array(
					'post_type'      => SocialPostType::POST_TYPE,
					'post_status'    => array( 'trash' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => array(
						array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
						array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					),
				)
			);
			$trash_count = is_countable( $trash_q->posts ) ? count( $trash_q->posts ) : 0;
			$total_count = (int) ( $query->found_posts ?? 0 ) + $trash_count;

			if ( 0 === $total_count ) :
				$import_url = add_query_arg(
					array(
						'page' => LinkedInFeedSettingsPage::SLUG,
						'tab'  => LinkedInFeedSettingsPage::TAB_IMPORT,
					),
					admin_url( 'admin.php' )
				);
				$help_url = add_query_arg(
					array(
						'page' => LinkedInFeedSettingsPage::SLUG,
						'tab'  => LinkedInFeedSettingsPage::TAB_HELP,
					),
					admin_url( 'admin.php' )
				);
				?>
				<div class="notice notice-info" style="padding:12px 16px">
					<p style="margin:0 0 8px 0"><strong><?php esc_html_e( 'No LinkedIn News have been imported yet.', 'atomic-wp-social-sync' ); ?></strong></p>
					<p style="margin:0">
						<a class="button button-primary" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Import your first post', 'atomic-wp-social-sync' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $help_url ); ?>"><?php esc_html_e( 'Read the guide', 'atomic-wp-social-sync' ); ?></a>
					</p>
				</div>
			<?php endif;

			if ( $trash_count > 0 ) :
				$settings_url = add_query_arg(
					array(
						'page' => LinkedInFeedSettingsPage::SLUG,
						'tab'  => LinkedInFeedSettingsPage::TAB_ADVANCED,
					),
					admin_url( 'admin.php' )
				);
				$trash_help_url = add_query_arg(
					array(
						'page' => LinkedInFeedSettingsPage::SLUG,
						'tab'  => LinkedInFeedSettingsPage::TAB_HELP,
					),
					admin_url( 'admin.php' )
				) . '#ermn-help-manage';
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of trashed LinkedIn posts */
								_n(
									'%d LinkedIn post is in the Trash and not shown here. Reimporting it will skip it. To make it importable again, restore it or permanently delete it.',
									'%d LinkedIn posts are in the Trash and not shown here. Reimporting them will skip them. To make them importable again, restore them or permanently delete them.',
									$trash_count,
									'atomic-wp-social-sync'
								),
								$trash_count
							)
						);
						?>
						<br>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings → Advanced', 'atomic-wp-social-sync' ); ?></a>
						&nbsp;|&nbsp;
						<a href="<?php echo esc_url( $trash_help_url ); ?>"><?php esc_html_e( 'How does Trash work?', 'atomic-wp-social-sync' ); ?></a>
					</p>
				</div>
			<?php endif;
			?>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Publication date', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Source / Method', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Preview', 'atomic-wp-social-sync' ); ?></th>
						<th><?php esc_html_e( 'Status / actions', 'atomic-wp-social-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! $query->posts ) :
						?>
						<tr><td colspan="5"><?php esc_html_e( 'No LinkedIn posts found.', 'atomic-wp-social-sync' ); ?></td></tr>
						<?php
					else :
						foreach ( $query->posts as $post ) :
							if ( ! $post instanceof \WP_Post ) {
								continue;
							}
							$urn = (string) get_post_meta( $post->ID, MetaKeys::EMBED_URN, true );
							$published = (string) get_post_meta( $post->ID, MetaKeys::REMOTE_PUBLISHED_AT, true );
							$published_label = '' !== $published ? $this->formatAdminDate( $published ) : wp_date( get_option( 'date_format' ), (int) get_post_timestamp( $post ) );
							$title = trim( (string) $post->post_title );
							$title = '' !== $title ? $title : __( 'Untitled LinkedIn post', 'atomic-wp-social-sync' );
							$source_label = $this->sourceLabel( (string) get_post_meta( $post->ID, MetaKeys::LINKEDIN_SOURCE_ID, true ) );
							$method_label = $this->methodLabel( (string) get_post_meta( $post->ID, MetaKeys::EMBED_STRATEGY, true ) );

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
							$toggle_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'atomic_linkedin_toggle_status',
										'post_id' => (int) $post->ID,
										'status'  => 'publish' === $post->post_status ? 'draft' : 'publish',
									),
									admin_url( 'admin-post.php' )
								),
								self::NONCE_ACTION
							);
							?>
							<tr>
								<td>
									<strong class="atomic-linkedin-title-text" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php echo esc_html( $title ); ?></strong><br>
									<button
										type="button"
										class="button-link atomic-linkedin-edit-title"
										data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
										data-current-title="<?php echo esc_attr( $title ); ?>"
									><?php esc_html_e( 'Edit title', 'atomic-wp-social-sync' ); ?></button><br>
									<code style="opacity:.75;"><?php echo esc_html( $urn ?: '—' ); ?></code>
									<div class="atomic-linkedin-inline-title-error" style="margin-top:6px;color:#b32d2e;display:none;"></div>
								</td>
								<td><?php echo esc_html( $published_label ); ?></td>
								<td>
									<?php echo esc_html( $source_label ); ?><br>
									<span style="opacity:.75;"><?php echo esc_html( $method_label ); ?></span>
								</td>
								<td>
									<button type="button" class="button atomic-linkedin-preview" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Preview', 'atomic-wp-social-sync' ); ?></button>
								</td>
								<td>
									<span class="atomic-linkedin-status"><?php echo esc_html( ucfirst( (string) $post->post_status ) ); ?></span>
									<span class="atomic-linkedin-actions">
										<a class="button-link" href="<?php echo esc_url( $toggle_url ); ?>"><?php echo esc_html( 'publish' === $post->post_status ? __( 'Unpublish', 'atomic-wp-social-sync' ) : __( 'Publish', 'atomic-wp-social-sync' ) ); ?></a>
										<span class="sep">|</span>
										<button type="button" class="button-link atomic-linkedin-edit" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Edit', 'atomic-wp-social-sync' ); ?></button>
										<?php if ( DeveloperGuard::areDeveloperToolsEnabled() ) : ?>
											<span class="sep">|</span>
											<button type="button" class="button-link atomic-linkedin-inspect" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Inspect', 'atomic-wp-social-sync' ); ?></button>
										<?php endif; ?>
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
					<label for="atomic-linkedin-title"><strong><?php esc_html_e( 'Editorial title', 'atomic-wp-social-sync' ); ?></strong></label>
					<span class="description" style="display:block;"><?php esc_html_e( 'Optional title displayed by Atomic outside the LinkedIn embed.', 'atomic-wp-social-sync' ); ?></span>
					<input type="text" id="atomic-linkedin-title" name="editorial_title" class="widefat" value="">
				</p>

				<p>
					<label for="atomic-linkedin-embed"><strong><?php esc_html_e( 'LinkedIn post', 'atomic-wp-social-sync' ); ?></strong></label>
					<span class="description" style="display:block;"><?php esc_html_e( 'Paste the LinkedIn embed code or the LinkedIn post link.', 'atomic-wp-social-sync' ); ?></span>
					<textarea class="widefat" rows="4" id="atomic-linkedin-embed" name="embed_input" placeholder="urn:li:share:7500553868422897664"></textarea>
				</p>

				<p>
					<label for="atomic-linkedin-published"><strong><?php esc_html_e( 'Original publication date', 'atomic-wp-social-sync' ); ?></strong></label>
					<span class="description" style="display:block;"><?php esc_html_e( 'Used for feed ordering.', 'atomic-wp-social-sync' ); ?></span>
					<input type="datetime-local" id="atomic-linkedin-published" name="published_at" class="regular-text">
				</p>

				<div id="atomic-linkedin-result" style="margin-top:10px;"></div>

				<details id="atomic-linkedin-advanced" style="margin-top:12px;">
					<summary><strong><?php esc_html_e( 'Advanced', 'atomic-wp-social-sync' ); ?></strong></summary>
					<div style="margin-top:10px;">
						<p style="margin-top:0;">
							<label for="atomic-linkedin-compat-height-mode"><strong><?php esc_html_e( 'Embed height', 'atomic-wp-social-sync' ); ?></strong></label>
							<span class="description" style="display:block;"><?php esc_html_e( 'For Compatibility embeds only (activity fallback).', 'atomic-wp-social-sync' ); ?></span>
							<select id="atomic-linkedin-compat-height-mode" class="regular-text">
								<option value="default"><?php esc_html_e( 'Default', 'atomic-wp-social-sync' ); ?></option>
								<option value="custom"><?php esc_html_e( 'Custom height', 'atomic-wp-social-sync' ); ?></option>
							</select>
							<input type="number" id="atomic-linkedin-compat-height" class="small-text" min="<?php echo esc_attr( (string) LinkedInEmbed::MIN_HEIGHT ); ?>" max="<?php echo esc_attr( (string) LinkedInEmbed::MAX_HEIGHT ); ?>" step="10" value="" style="margin-left:8px;">
							<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
						</p>
					</div>
				</details>

				<p style="margin-top:16px;">
					<button type="submit" class="button button-primary" id="atomic-linkedin-save"><?php esc_html_e( 'Add post', 'atomic-wp-social-sync' ); ?></button>
					<button type="button" class="button" id="atomic-linkedin-preview"><?php esc_html_e( 'Preview', 'atomic-wp-social-sync' ); ?></button>
					<button type="button" class="button" id="atomic-linkedin-cancel"><?php esc_html_e( 'Cancel', 'atomic-wp-social-sync' ); ?></button>
				</p>
			</form>
		</div>

		<div id="atomic-linkedin-preview-modal" style="display:none;">
			<div id="atomic-linkedin-preview-container"></div>
		</div>

		<?php if ( DeveloperGuard::areDeveloperToolsEnabled() ) : ?>
			<div id="atomic-linkedin-inspector-modal" style="display:none;max-width:900px;padding:20px;">
				<div id="atomic-linkedin-inspector-container">
					<p class="description"><?php esc_html_e( 'Loading inspector…', 'atomic-wp-social-sync' ); ?></p>
				</div>
				<p style="margin-top:20px;text-align:right;">
					<button type="button" class="button" id="atomic-linkedin-inspector-close"><?php esc_html_e( 'Close', 'atomic-wp-social-sync' ); ?></button>
				</p>
			</div>
			<script>
			(function(){
				var i18n = (window.atomicLinkedInFeedAdmin || {}).i18n || {};
				var ajaxUrl = (window.atomicLinkedInFeedAdmin || {}).ajaxUrl || '';
				var nonce = (window.atomicLinkedInFeedAdmin || {}).nonce || '';
				function openInspector() {
					var overlay = document.createElement('div');
					overlay.id = 'atomic-linkedin-inspector-overlay';
					overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:40px 20px;';
					var panel = document.getElementById('atomic-linkedin-inspector-modal').cloneNode(true);
					panel.style.cssText = 'display:block;background:#fff;max-width:900px;width:100%;padding:24px;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,0.3);';
					overlay.appendChild(panel);
					document.body.appendChild(overlay);
					panel.querySelector('#atomic-linkedin-inspector-close').addEventListener('click', function(){
						if ( overlay && overlay.parentNode ) { overlay.parentNode.removeChild(overlay); }
					});
					overlay.addEventListener('click', function(e){
						if ( e.target === overlay && overlay.parentNode ) { overlay.parentNode.removeChild(overlay); }
					});
					panel.querySelector('#atomic-linkedin-inspector-container').innerHTML = '<p class="description">' + (i18n.inspectLoading || 'Loading inspector…') + '</p>';
					var postId = this.getAttribute('data-post-id') || '';
					var form = new FormData();
					form.append('action', 'atomic_linkedin_inspect_post');
					form.append('nonce', nonce);
					form.append('post_id', postId);
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form })
						.then(function(r){ return r.json(); })
						.then(function(resp){
							if ( resp && resp.success && resp.data && resp.data.html ) {
								panel.querySelector('#atomic-linkedin-inspector-container').innerHTML = resp.data.html;
							} else {
								var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Inspection failed.';
								panel.querySelector('#atomic-linkedin-inspector-container').innerHTML = '<div class="notice notice-error"><p>' + msg + '</p></div>';
							}
						})
						.catch(function(err){
							panel.querySelector('#atomic-linkedin-inspector-container').innerHTML = '<div class="notice notice-error"><p>' + String(err) + '</p></div>';
						});
				}
				document.addEventListener('click', function(e){
					var btn = e.target.closest('.atomic-linkedin-inspect');
					if ( btn ) {
						e.preventDefault();
						openInspector.call(btn);
					}
				});
			})();
			</script>
		<?php endif; ?>
		<?php
	}

	public function ajaxCreatePost(): void {
		$this->assertAjaxAuth();
		try {
			$input          = trim( (string) wp_unslash( $_POST['embed_input'] ?? '' ) );
			$title          = sanitize_text_field( (string) wp_unslash( $_POST['editorial_title'] ?? '' ) );
			$published_local = trim( (string) wp_unslash( $_POST['published_at'] ?? '' ) );
			$override       = $this->readCompatibilityHeightOverride();
			$post_id        = $this->createOrUpdateEmbedPost( null, $input, $published_local, $override, $title );
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
			$title          = sanitize_text_field( (string) wp_unslash( $_POST['editorial_title'] ?? '' ) );
			$published_local = trim( (string) wp_unslash( $_POST['published_at'] ?? '' ) );
			if ( $post_id <= 0 ) {
				throw new RuntimeException( __( 'Invalid post ID.', 'atomic-wp-social-sync' ) );
			}
			$override = $this->readCompatibilityHeightOverride();
			$this->createOrUpdateEmbedPost( $post_id, $input, $published_local, $override, $title );
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
		$strategy = (string) get_post_meta( $post_id, MetaKeys::EMBED_STRATEGY, true );
		$strategy = '' !== $strategy ? $strategy : LinkedInEmbed::STRATEGY_OFFICIAL;
		$height_override = (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE, true );
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
		$preview_presentation = LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy ? 'full' : 'compact';
		$preview_src = LinkedInEmbed::embedUrl( $urn, $preview_presentation, $strategy );
		if ( LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy ) {
			$height = $height_override;
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, true );
			}
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, true );
			}
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = LinkedInEmbed::DEFAULT_HEIGHT_ACTIVITY;
			}
		} else {
			$height = (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, true );
			if ( $height < LinkedInEmbed::MIN_HEIGHT || $height > LinkedInEmbed::MAX_HEIGHT ) {
				$height = LinkedInEmbed::DEFAULT_HEIGHT_COMPACT;
			}
		}
		wp_send_json_success(
			array(
				'post_id'        => $post_id,
				'title'          => (string) get_post_field( 'post_title', $post_id ),
				'urn'            => $urn,
				'strategy'       => $strategy,
				'height_override' => $height_override,
				'published_local' => $published_local,
				'preview_src'    => $preview_src,
				'preview_height' => $height,
			)
		);
	}

	public function ajaxUpdateTitleInline(): void {
		$this->assertAjaxAuth();
		try {
			$post_id = absint( $_POST['post_id'] ?? 0 );
			$title   = sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) );

			if ( $post_id <= 0 ) {
				throw new RuntimeException( __( 'Invalid post ID.', 'atomic-wp-social-sync' ) );
			}
			if ( ! $this->isEmbedLinkedInPost( $post_id ) ) {
				throw new RuntimeException( __( 'Post was not found.', 'atomic-wp-social-sync' ) );
			}

			$updated = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				throw new RuntimeException( $updated->get_error_message() );
			}

			$this->applyTitleLockedMeta( $post_id, $title );

			wp_send_json_success(
				array(
					'post_id' => $post_id,
					'title'   => (string) get_post_field( 'post_title', $post_id ),
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	public function ajaxInspectPost(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		$this->assertAjaxAuth();
		try {
			$post_id = absint( $_POST['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				throw new RuntimeException( __( 'Invalid post ID.', 'atomic-wp-social-sync' ) );
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || SocialPostType::POST_TYPE !== $post->post_type ) {
				throw new RuntimeException( __( 'Post not found.', 'atomic-wp-social-sync' ) );
			}

			$source_payload_raw = get_post_meta( $post_id, MetaKeys::SOURCE_PAYLOAD, true );
			$source_data = null;
			if ( is_string( $source_payload_raw ) && '' !== trim( $source_payload_raw ) ) {
				$decoded = json_decode( $source_payload_raw, true );
				if ( is_array( $decoded ) ) {
					$source_data = $decoded;
				}
			}
			if ( null === $source_data ) {
				$source_data = array(
					'external_id'  => (string) get_post_meta( $post_id, MetaKeys::EXTERNAL_ID, true ),
					'title'        => (string) get_post_meta( $post_id, MetaKeys::GENERATED_TITLE, true ),
					'text'         => (string) get_post_meta( $post_id, MetaKeys::REMOTE_TEXT, true ),
					'published_at' => (string) get_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, true ),
					'external_url' => (string) get_post_meta( $post_id, MetaKeys::EXTERNAL_URL, true ),
					'provider'     => (string) get_post_meta( $post_id, MetaKeys::PROVIDER, true ),
					'embed_urn'    => (string) get_post_meta( $post_id, MetaKeys::EMBED_URN, true ),
					'author_id'    => (string) get_post_meta( $post_id, MetaKeys::REMOTE_AUTHOR_ID, true ),
					'author_name'  => (string) get_post_meta( $post_id, MetaKeys::REMOTE_AUTHOR_NAME, true ),
				);
			}

			$mapping = array(
				__( 'LinkedIn / source field', 'atomic-wp-social-sync' ) => __( 'WordPress', 'atomic-wp-social-sync' ),
				'external_id / embed_urn' => MetaKeys::EXTERNAL_ID . ' / ' . MetaKeys::EMBED_URN . ' = ' . (string) get_post_meta( $post_id, MetaKeys::EXTERNAL_ID, true ),
				'title'                   => 'post_title / ' . MetaKeys::GENERATED_TITLE . ' = ' . (string) $post->post_title,
				'text'                    => 'post_content / ' . MetaKeys::REMOTE_TEXT,
				'published_at'            => 'post_date / ' . MetaKeys::REMOTE_PUBLISHED_AT . ' = ' . (string) get_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, true ),
				'external_url'            => MetaKeys::EXTERNAL_URL . ' = ' . (string) get_post_meta( $post_id, MetaKeys::EXTERNAL_URL, true ),
				'author_id, author_name'  => MetaKeys::REMOTE_AUTHOR_ID . ' / ' . MetaKeys::REMOTE_AUTHOR_NAME,
				'media_source_ids'        => MetaKeys::MEDIA_SOURCE_ID . ' / featured image',
				'provider'                => 'taxonomy ' . SocialPostType::PROVIDER_TAXONOMY . ' / ' . MetaKeys::PROVIDER . ' = ' . (string) get_post_meta( $post_id, MetaKeys::PROVIDER, true ),
				'integration_mode'        => MetaKeys::INTEGRATION_MODE . ' = ' . (string) get_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, true ),
				'embed_strategy'          => MetaKeys::EMBED_STRATEGY . ' = ' . (string) get_post_meta( $post_id, MetaKeys::EMBED_STRATEGY, true ),
			);

			$html  = '<h2>' . esc_html__( 'Inspector — read-only', 'atomic-wp-social-sync' ) . '</h2>';
			$html .= '<h3>' . esc_html__( 'Source / normalized data', 'atomic-wp-social-sync' ) . '</h3>';
			$html .= '<table class="widefat fixed striped"><thead><tr><th style="width:30%;">' . esc_html__( 'Field', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Value', 'atomic-wp-social-sync' ) . '</th></tr></thead><tbody>';
			foreach ( $source_data as $k => $v ) {
				$val = is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$html .= '<tr><td><code>' . esc_html( (string) $k ) . '</code></td><td><div style="max-width:100%;overflow:auto;"><code>' . esc_html( $val ) . '</code></div></td></tr>';
			}
			$html .= '</tbody></table>';
			$html .= '<h3 style="margin-top:20px;">' . esc_html__( 'WordPress mapping', 'atomic-wp-social-sync' ) . '</h3>';
			$html .= '<table class="widefat fixed striped"><thead><tr><th style="width:30%;">' . esc_html( (string) array_key_first( $mapping ) ) . '</th><th>' . esc_html( (string) reset( $mapping ) ) . '</th></tr></thead><tbody>';
			$skip_first = true;
			foreach ( $mapping as $k => $v ) {
				if ( $skip_first ) { $skip_first = false; continue; }
				$html .= '<tr><td><code>' . esc_html( (string) $k ) . '</code></td><td><code>' . esc_html( (string) $v ) . '</code></td></tr>';
			}
			$html .= '</tbody></table>';
			$html .= '<p style="margin-top:16px;">' . esc_html( sprintf( __( 'Post ID: %d · Post status: %s', 'atomic-wp-social-sync' ), (int) $post->ID, (string) $post->post_status ) ) . '</p>';

			wp_send_json_success( array( 'html' => $html ) );
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
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

	public function toggleStatus(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		$status  = sanitize_key( (string) ( $_GET['status'] ?? '' ) );
		if ( $post_id > 0 && in_array( $status, array( 'publish', 'draft' ), true ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);
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

	private function createOrUpdateEmbedPost( ?int $post_id, string $input, string $published_local, ?int $compat_height_override, string $title ): int {
		$parsed = LinkedInEmbed::parseInput( $input );
		$strategy = (string) ( $parsed['strategy'] ?? LinkedInEmbed::STRATEGY_OFFICIAL );
		$urn    = $parsed['urn'];

		// Duplicate handling: allow unlimited different Share IDs, but reject duplicates of the same Share URN.
		$existing_id = $this->findExistingEmbedPostIdByStrategyUrn( $strategy, $urn, $post_id );
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
			$post_id = wp_insert_post(
				array(
					'post_type'     => SocialPostType::POST_TYPE,
					'post_status'   => 'publish',
					'post_title'    => sanitize_text_field( $title ),
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
					'post_title'     => sanitize_text_field( $title ),
				)
			);
		}

		$this->applyTitleLockedMeta( (int) $post_id, (string) $title );

		if ( '' !== $iso ) {
			update_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, $iso );
		}

		update_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, IntegrationMode::EMBED );
		update_post_meta( $post_id, MetaKeys::PROVIDER, 'linkedin' );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_ID, $urn );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_URL, esc_url_raw( 'https://www.linkedin.com/feed/update/' . $urn . '/' ) );
		update_post_meta( $post_id, MetaKeys::EMBED_STRATEGY, $strategy );
		update_post_meta( $post_id, MetaKeys::EMBED_URN, $urn );
		update_post_meta( $post_id, MetaKeys::DETACHED, '1' );
		update_post_meta( $post_id, MetaKeys::REMOTE_STATUS, 'embedded' );

		// Optional compatibility height override (activity fallback only).
		if ( LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy ) {
			if ( null !== $compat_height_override ) {
				if ( $compat_height_override < LinkedInEmbed::MIN_HEIGHT || $compat_height_override > LinkedInEmbed::MAX_HEIGHT ) {
					throw new RuntimeException( __( 'Compatibility embed height is out of allowed bounds.', 'atomic-wp-social-sync' ) );
				}
				update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE, (string) $compat_height_override );
			} else {
				delete_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE );
			}
		} else {
			delete_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE );
		}

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

	private function applyTitleLockedMeta( int $post_id, string $title ): void {
		$title = trim( (string) $title );
		if ( $post_id <= 0 ) {
			return;
		}
		if ( '' === $title ) {
			delete_post_meta( $post_id, MetaKeys::TITLE_LOCKED );
			return;
		}
		update_post_meta( $post_id, MetaKeys::TITLE_LOCKED, '1' );
	}

	private function readCompatibilityHeightOverride(): ?int {
		$mode = sanitize_key( (string) wp_unslash( $_POST['compat_height_mode'] ?? 'default' ) );
		if ( 'custom' !== $mode ) {
			return null;
		}
		$value = absint( wp_unslash( $_POST['compat_height'] ?? 0 ) );
		return $value > 0 ? $value : null;
	}

	private function findExistingEmbedPostIdByStrategyUrn( string $strategy, string $urn, ?int $exclude_post_id ): ?int {
		$args = array(
			'post_type'      => SocialPostType::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => array( 'ID' => 'DESC' ),
			'meta_query'     => array(
				array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
				array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
				array( 'key' => MetaKeys::EMBED_STRATEGY, 'value' => $strategy ),
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

	private function formatAdminDate( string $iso ): string {
		try {
			$dt = new DateTimeImmutable( $iso );
			$dt = $dt->setTimezone( wp_timezone() );
			return wp_date( get_option( 'date_format' ), $dt->getTimestamp() );
		} catch ( \Throwable ) {
			return $iso;
		}
	}

	private function sourceLabel( string $source_id ): string {
		if ( '' === $source_id ) {
			return '—';
		}
		$settings = get_option( PluginSettings::OPTION_NAME, array() );
		$sources = is_array( $settings ) && is_array( $settings['linkedin_sources'] ?? null ) ? $settings['linkedin_sources'] : array();
		foreach ( $sources as $source ) {
			if ( is_array( $source ) && (string) ( $source['id'] ?? '' ) === $source_id ) {
				$label = trim( (string) ( $source['label'] ?? '' ) );
				return '' !== $label ? $label : '—';
			}
		}
		return '—';
	}

	private function methodLabel( string $strategy ): string {
		return LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK === $strategy
			? __( 'Compatibility embed', 'atomic-wp-social-sync' )
			: __( 'Official embed', 'atomic-wp-social-sync' );
	}
}
