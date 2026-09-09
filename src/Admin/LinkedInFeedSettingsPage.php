<?php
/**
 * Atomic LinkedIn Feed — Settings (V1).
 *
 * Provides a single Settings screen with internal tabs:
 * - Sources
 * - Import / Migration
 * - Design
 *
 * Important: This is UI + settings only. It must not alter feed rendering logic.
 *
 * @package AtomicWPSocialSync
 */
namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Import\LinkedInHtmlImportParser;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Providers\ProviderRegistry;
use AtomicWPSocialSync\Support\DeveloperGuard;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\Logger;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\Support\TitlePolicy;
use AtomicWPSocialSync\Sync\Scheduler;
use AtomicWPSocialSync\Sync\SyncService;
use AtomicWPSocialSync\Update\GitHubReleaseUpdater;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use AtomicWPSocialSync\WordPress\SocialPostType;
use DateTimeImmutable;
use DateTimeZone;

final class LinkedInFeedSettingsPage {
	public const SLUG = 'atomic-linkedin-feed-settings';
	private const SAVE_SOURCES_ACTION = 'atomic_linkedin_save_sources';
	private const ANALYZE_IMPORT_ACTION = 'atomic_linkedin_analyze_import';
	private const RUN_IMPORT_ACTION     = 'atomic_linkedin_run_import';
	private const IMPORT_TRANSIENT_PREFIX = 'atomic_linkedin_import_';
	private const IMPORT_REPORT_PREFIX    = 'atomic_linkedin_import_report_';

	public const SAVE_ADVANCED_ACTION   = 'atomic_social_save_advanced_settings';
	public const DELETE_POSTS_ACTION    = 'atomic_social_delete_imported_posts';
	public const DELETE_MEDIA_ACTION    = 'atomic_social_delete_imported_media';
	public const RESET_RUNTIME_ACTION   = 'atomic_social_reset_runtime';
	public const RETROFIT_MARKER_ACTION = 'atomic_social_retrofit_imported_marker';
	public const CLEAR_LOG_ACTION       = 'atomic_social_clear_debug_log';

	public const DEV_RUN_SYNC_ACTION     = 'atomic_social_dev_run_sync';
	public const DEV_FULL_RESYNC_ACTION  = 'atomic_social_dev_full_resync';
	public const DEV_DRY_RUN_ACTION      = 'atomic_social_dev_dry_run';
	public const DEV_CLEAR_CACHE_ACTION  = 'atomic_social_dev_clear_cache';
	public const DEV_RESET_STATE_ACTION  = 'atomic_social_dev_reset_sync_state';
	public const DEV_REBUILD_ACTION      = 'atomic_social_dev_rebuild_content';

	public const TAB_SOURCES  = 'sources';
	public const TAB_IMPORT   = 'import';
	public const TAB_DESIGN   = 'design';
	public const TAB_ADVANCED = 'advanced';
	public const TAB_HELP     = 'help';
	private const TAB_DEVELOPER = 'developer';

	public function __construct(
		private readonly PluginSettings $settings,
		private readonly DesignSettingsPage $design_page,
		private readonly ConnectionRepository $connections,
		private readonly SyncService $sync_service,
		private readonly ProviderRegistry $providers,
		private readonly SocialPostRepository $post_repository,
		private readonly Logger $logger
	) {}

	public function registerMenu(): void {
		add_submenu_page(
			LinkedInPostsPage::MENU_SLUG,
			__( 'Settings', 'atomic-wp-social-sync' ),
			__( 'Settings', 'atomic-wp-social-sync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueueAssets( string $hook ): void {
		// Hook suffix for submenu page: {parent}_page_{slug}.
		if ( 'atomic-linkedin-feed_page_' . self::SLUG !== $hook ) {
			return;
		}
		$css_path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/css/admin-design.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_enqueue_style(
			'atomic-linkedin-feed-admin-design',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/css/admin-design.css',
			array(),
			$css_ver
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$available_tabs = array(
			self::TAB_SOURCES => __( 'Sources', 'atomic-wp-social-sync' ),
			self::TAB_IMPORT  => __( 'Import / Migration', 'atomic-wp-social-sync' ),
			self::TAB_DESIGN  => __( 'Design', 'atomic-wp-social-sync' ),
			self::TAB_ADVANCED => __( 'Advanced', 'atomic-wp-social-sync' ),
			self::TAB_HELP     => __( 'Help / Documentation', 'atomic-wp-social-sync' ),
		);
		if ( DeveloperGuard::isDeveloperContext() ) {
			$available_tabs[ self::TAB_DEVELOPER ] = __( 'Developer', 'atomic-wp-social-sync' );
		}

		$tab = sanitize_key( (string) wp_unslash( $_GET['tab'] ?? self::TAB_SOURCES ) );
		if ( ! isset( $available_tabs[ $tab ] ) ) {
			$tab = self::TAB_SOURCES;
		}

		$base_url = admin_url( 'admin.php?page=' . self::SLUG );

		?>
		<div class="wrap atomic-linkedin-settings" id="atomic-linkedin-settings">
			<h1><?php esc_html_e( 'Atomic LinkedIn Feed — Settings', 'atomic-wp-social-sync' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<h2 class="nav-tab-wrapper" role="tablist" aria-label="<?php echo esc_attr__( 'Settings tabs', 'atomic-wp-social-sync' ); ?>">
				<?php foreach ( $available_tabs as $key => $label ) : ?>
					<?php
					$url = add_query_arg( 'tab', $key, $base_url );
					$classes = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
					?>
					<a
						href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $classes ); ?>"
						role="tab"
						aria-selected="<?php echo esc_attr( $key === $tab ? 'true' : 'false' ); ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="atomic-linkedin-settings__tab">
				<?php
				if ( self::TAB_DESIGN === $tab ) :
					$help_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_HELP ), admin_url( 'admin.php' ) ) . '#ermn-help-display';
					?>
					<p class="description" style="margin:12px 0 16px 0">
						<a href="<?php echo esc_url( $help_url ); ?>"><?php esc_html_e( 'Need help configuring the News display? Read the guide.', 'atomic-wp-social-sync' ); ?></a>
					</p>
					<?php
					$this->design_page->renderTab();
				elseif ( self::TAB_IMPORT === $tab ) :
					$this->renderImportTab();
				elseif ( self::TAB_ADVANCED === $tab ) :
					$this->renderAdvancedTab();
				elseif ( self::TAB_HELP === $tab ) :
					$this->renderHelpTab();
				elseif ( self::TAB_DEVELOPER === $tab ) :
					$this->renderDeveloperTab();
				else :
					$this->renderSourcesTab();
				endif;
				?>
			</div>
		</div>
		<?php
	}

	private function renderAdvancedTab(): void {
		$imported_post_count = $this->countImportedPosts();
		$imported_media_count = $this->countImportedMedia();
		$legacy_count = $this->countLegacyPostsWithoutMarker();
		list( $last_sync_success, $last_sync_attempt ) = $this->lastSyncDates();
		$next_cron = wp_next_scheduled( Scheduler::HOOK );
		?>
		<div class="atomic-linkedin-design atomic-linkedin-settings__advanced">
			<div class="atomic-linkedin-design__card">
				<div class="atomic-linkedin-design__card-header">
					<h2><?php esc_html_e( 'Maintenance — Status', 'atomic-wp-social-sync' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Overview of content and synchronization state.', 'atomic-wp-social-sync' ); ?></p>
					<?php
					$help_manage_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_HELP ), admin_url( 'admin.php' ) ) . '#ermn-help-manage';
					?>
					<p class="description"><a href="<?php echo esc_url( $help_manage_url ); ?>"><?php esc_html_e( 'Need help with maintenance or Trash rules? Read the guide.', 'atomic-wp-social-sync' ); ?></a></p>
				</div>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Imported LinkedIn posts', 'atomic-wp-social-sync' ); ?></th><td><strong><?php echo esc_html( (string) $imported_post_count ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Imported LinkedIn media (attachments)', 'atomic-wp-social-sync' ); ?></th><td><strong><?php echo esc_html( (string) $imported_media_count ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Last successful sync', 'atomic-wp-social-sync' ); ?></th><td><?php echo esc_html( $last_sync_success ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Last sync attempt', 'atomic-wp-social-sync' ); ?></th><td><?php echo esc_html( $last_sync_attempt ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Next scheduled sync (cron)', 'atomic-wp-social-sync' ); ?></th><td><?php echo false === $next_cron ? esc_html__( 'Not scheduled', 'atomic-wp-social-sync' ) : esc_html( wp_date( 'Y-m-d H:i:s', (int) $next_cron ) ); ?></td></tr>
					<?php if ( $legacy_count > 0 ) : ?>
					<tr><th><?php esc_html_e( 'Legacy posts without ownership marker', 'atomic-wp-social-sync' ); ?></th><td><strong style="color:#996500"><?php echo esc_html( (string) $legacy_count ); ?></strong> <span class="description"><?php esc_html_e( 'These posts are preserved by destructive bulk actions. Use the Retrofit ownership marker action below to tag them safely.', 'atomic-wp-social-sync' ); ?></span></td></tr>
					<?php endif; ?>
				</table>
			</div>

			<div class="atomic-linkedin-design__card" style="border-left:4px solid #dba617">
				<div class="atomic-linkedin-design__card-header">
					<h2><?php esc_html_e( 'Maintenance — Destructive actions', 'atomic-wp-social-sync' ); ?></h2>
					<p class="description" style="color:#996500"><strong><?php esc_html_e( 'Danger zone. These actions are irreversible.', 'atomic-wp-social-sync' ); ?></strong></p>
				</div>

				<h3 style="margin-top:0"><?php esc_html_e( 'Delete all imported LinkedIn posts', 'atomic-wp-social-sync' ); ?></h3>
				<p class="description"><?php esc_html_e( 'This action will permanently delete posts created by this plugin. Manual posts created in the same CPT without the ownership marker are NEVER deleted by this action.', 'atomic-wp-social-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_POSTS_ACTION ); ?>">
					<?php wp_nonce_field( self::DELETE_POSTS_ACTION ); ?>
					<p><?php printf( esc_html__( 'Number of posts that will be deleted: %d', 'atomic-wp-social-sync' ), esc_html( $imported_post_count ) ); ?></p>
					<p style="color:#d63638"><strong><?php esc_html_e( 'Are you sure you want to delete all LinkedIn posts imported by ERMN LinkedIn? This action cannot be undone.', 'atomic-wp-social-sync' ); ?></strong></p>
					<p><label><strong><?php esc_html_e( 'Type DELETE to confirm:', 'atomic-wp-social-sync' ); ?></strong><br><input type="text" name="confirm" autocomplete="off" required></label></p>
					<?php submit_button( __( 'Delete all imported LinkedIn posts', 'atomic-wp-social-sync' ), 'delete', 'submit', false, $imported_post_count === 0 ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>

				<hr style="margin:32px 0">

				<h3><?php esc_html_e( 'Delete imported LinkedIn media', 'atomic-wp-social-sync' ); ?></h3>
				<p class="description"><?php esc_html_e( 'This action deletes attachments positively identified as imported by this plugin (ownership marker + provider metadata). Media reused elsewhere will only be deleted if it carries the ownership marker — never merely because it is attached to a LinkedIn post.', 'atomic-wp-social-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_MEDIA_ACTION ); ?>">
					<?php wp_nonce_field( self::DELETE_MEDIA_ACTION ); ?>
					<p><?php printf( esc_html__( 'Number of attachments that will be deleted: %d', 'atomic-wp-social-sync' ), esc_html( $imported_media_count ) ); ?></p>
					<p style="color:#d63638"><strong><?php esc_html_e( 'Delete all media attachments imported by this plugin. This action cannot be undone.', 'atomic-wp-social-sync' ); ?></strong></p>
					<p><label><strong><?php esc_html_e( 'Type DELETE to confirm:', 'atomic-wp-social-sync' ); ?></strong><br><input type="text" name="confirm" autocomplete="off" required></label></p>
					<?php submit_button( __( 'Delete imported LinkedIn media', 'atomic-wp-social-sync' ), 'delete', 'submit', false, $imported_media_count === 0 ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>

				<hr style="margin:32px 0">

				<h3><?php esc_html_e( 'Reset plugin runtime data', 'atomic-wp-social-sync' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Clears sync cursors, cache transients, sync locks, and debug logs. Does NOT delete content, settings, or credentials.', 'atomic-wp-social-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_RUNTIME_ACTION ); ?>">
					<?php wp_nonce_field( self::RESET_RUNTIME_ACTION ); ?>
					<?php submit_button( __( 'Reset runtime data', 'atomic-wp-social-sync' ), 'secondary' ); ?>
				</form>

				<?php if ( $legacy_count > 0 ) : ?>
				<hr style="margin:32px 0">
				<h3><?php esc_html_e( 'Retrofit ownership marker to legacy posts', 'atomic-wp-social-sync' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Non-destructive action: adds the canonical ownership marker to posts currently identifiable as LinkedIn-imported via legacy metadata patterns (provider + urn/external_id). Posts already tagged are left untouched.', 'atomic-wp-social-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RETROFIT_MARKER_ACTION ); ?>">
					<?php wp_nonce_field( self::RETROFIT_MARKER_ACTION ); ?>
					<p><?php printf( esc_html__( 'Estimated legacy posts that will be tagged: %d', 'atomic-wp-social-sync' ), esc_html( $legacy_count ) ); ?></p>
					<?php submit_button( __( 'Retrofit ownership marker', 'atomic-wp-social-sync' ), 'secondary' ); ?>
				</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function renderDeveloperTab(): void {
		DeveloperGuard::requireAdminOrDie();
		$settings = $this->settings->all();
		$tools_enabled = DeveloperGuard::areDeveloperToolsEnabled();
		$diagnostics = $this->collectDiagnostics();
		$rebuild_info = $this->rebuildAvailability();
		$log_entries = get_option( PluginSettings::SYNC_LOG, array() );
		$log_entries = is_array( $log_entries ) ? array_reverse( $log_entries ) : array();
		?>
		<div class="atomic-linkedin-design atomic-linkedin-settings__developer">
			<div class="atomic-linkedin-design__card">
				<div class="atomic-linkedin-design__card-header">
					<h2><?php esc_html_e( 'Developer Tools', 'atomic-wp-social-sync' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Advanced controls for diagnosis, cache management, and rebuild. Available only when WP_DEBUG is true AND current user is admin AND the toggle below is enabled.', 'atomic-wp-social-sync' ); ?></p>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'Enable developer tools', 'atomic-wp-social-sync' ); ?></label></th>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ADVANCED_ACTION ); ?>">
								<?php wp_nonce_field( self::SAVE_ADVANCED_ACTION ); ?>
								<label>
									<input type="checkbox" name="settings[developer_tools]" value="1" <?php checked( ! empty( $settings['developer_tools'] ) ); ?>>
									<?php esc_html_e( 'Enable developer tools (enables all actions below)', 'atomic-wp-social-sync' ); ?>
								</label>
								<p><strong>WP_DEBUG:</strong> <?php echo ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '<span style="color:#008a20">ON</span>' : '<span style="color:#d63638">OFF</span>'; ?></p>
								<?php submit_button( __( 'Save developer setting', 'atomic-wp-social-sync' ), 'primary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				</table>
			</div>

			<?php if ( $tools_enabled ) : ?>
				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Diagnostics', 'atomic-wp-social-sync' ); ?></h2>
					</div>
					<table class="widefat striped">
						<tbody>
							<?php foreach ( $diagnostics as $label => $value ) : ?>
							<tr><th style="width:36%"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Sync actions', 'atomic-wp-social-sync' ); ?></h2>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Run sync now', 'atomic-wp-social-sync' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_RUN_SYNC_ACTION ); ?>">
									<?php wp_nonce_field( self::DEV_RUN_SYNC_ACTION ); ?>
									<?php submit_button( __( 'Run sync now', 'atomic-wp-social-sync' ), 'primary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Runs the normal production sync logic for every connected LinkedIn account.', 'atomic-wp-social-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Force full resync', 'atomic-wp-social-sync' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_FULL_RESYNC_ACTION ); ?>">
									<?php wp_nonce_field( self::DEV_FULL_RESYNC_ACTION ); ?>
									<?php submit_button( __( 'Force full resync', 'atomic-wp-social-sync' ), 'primary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Clears sync checkpoints and missing-confirmation state, then runs normal sync. Does NOT delete existing content first.', 'atomic-wp-social-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Dry run (fetch without saving)', 'atomic-wp-social-sync' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_DRY_RUN_ACTION ); ?>">
									<?php wp_nonce_field( self::DEV_DRY_RUN_ACTION ); ?>
									<?php submit_button( __( 'Dry run latest fetch', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Architecture hook in V1. Retrieves LinkedIn data for connected accounts and reports would-be actions without writing posts, meta, attachments, or sync state.', 'atomic-wp-social-sync' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Cache & state', 'atomic-wp-social-sync' ); ?></h2>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Clear plugin cache', 'atomic-wp-social-sync' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_CLEAR_CACHE_ACTION ); ?>">
									<?php wp_nonce_field( self::DEV_CLEAR_CACHE_ACTION ); ?>
									<?php submit_button( __( 'Clear plugin cache', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Deletes only plugin-owned transients (atomic_social_ prefix), sync locks, and debug logs. Settings and credentials are preserved.', 'atomic-wp-social-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Reset sync state', 'atomic-wp-social-sync' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_RESET_STATE_ACTION ); ?>">
									<?php wp_nonce_field( self::DEV_RESET_STATE_ACTION ); ?>
									<?php submit_button( __( 'Reset sync state', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Resets incremental cursors (last_sync_at, checkpoints, verification stamps, missing state, conflict markers) for all connections and imported posts. No content is deleted.', 'atomic-wp-social-sync' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Content rebuild', 'atomic-wp-social-sync' ); ?></h2>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:8px">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::DEV_REBUILD_ACTION ); ?>">
						<?php wp_nonce_field( self::DEV_REBUILD_ACTION ); ?>
						<?php submit_button( __( 'Rebuild imported content (architecture report)', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description">
						<?php
						printf(
							esc_html__( 'Rebuild architecture is in place. Source payload is currently stored only when developer tools OR debug logging are enabled at import time. %1$d of %2$d imported posts have a stored source snapshot available.', 'atomic-wp-social-sync' ),
							(int) $rebuild_info['with_snapshot'],
							(int) $rebuild_info['total_imported']
						);
						?>
					</p>
				</div>

				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Debug logs', 'atomic-wp-social-sync' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="float:right">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>">
							<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
							<?php submit_button( __( 'Clear log', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>
					<?php if ( ! $log_entries ) : ?>
						<p class="description"><?php esc_html_e( 'No log entries. Enable debug logging or run developer actions to populate the log.', 'atomic-wp-social-sync' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead><tr><th style="width:20%"><?php esc_html_e( 'Time (UTC)', 'atomic-wp-social-sync' ); ?></th><th style="width:10%"><?php esc_html_e( 'Level', 'atomic-wp-social-sync' ); ?></th><th><?php esc_html_e( 'Message', 'atomic-wp-social-sync' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $log_entries as $entry ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
										<td><strong><?php echo esc_html( strtoupper( (string) ( $entry['level'] ?? 'info' ) ) ); ?></strong></td>
										<td>
											<?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?>
											<?php if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) : ?>
												<span class="description">
													<?php
													$ctx_parts = array();
													foreach ( $entry['context'] as $ck => $cv ) {
														$ctx_parts[] = esc_html( $ck ) . '=' . esc_html( (string) $cv );
													}
													echo ' {' . esc_html( implode( ', ', $ctx_parts ) ) . '}';
													?>
												</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function saveSources(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'atomic-wp-social-sync' ), 403 );
		}
		check_admin_referer( self::SAVE_SOURCES_ACTION );

		$posted = isset( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
		$default_token = sanitize_text_field( (string) wp_unslash( $_POST['default_source'] ?? '' ) );

		$rows = is_array( $posted ) ? $posted : array();
		$normalized_rows = array();
		$attempted = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label_raw = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$url_raw   = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' !== $label_raw || '' !== $url_raw ) {
				$attempted++;
			}
			$normalized_rows[] = array(
				'id'         => sanitize_text_field( (string) ( $row['id'] ?? '' ) ),
				'label'      => $label_raw,
				'url'        => $url_raw,
				'is_default' => (string) ( $row['id'] ?? '' ) === $default_token || (string) ( $row['tmp'] ?? '' ) === $default_token,
				'remove'     => ! empty( $row['remove'] ),
			);
		}

		// Drop removed rows pre-sanitize.
		$normalized_rows = array_values(
			array_filter(
				$normalized_rows,
				static fn( array $r ): bool => empty( $r['remove'] )
			)
		);

		// Ensure one default at most (use first match only).
		$default_seen = false;
		foreach ( $normalized_rows as &$r ) {
			if ( ! empty( $r['is_default'] ) ) {
				if ( $default_seen ) {
					$r['is_default'] = false;
				}
				$default_seen = true;
			}
		}
		unset( $r );

		$settings = $this->settings->all();
		$saved_sources = PluginSettings::sanitizeLinkedInSources( $normalized_rows );
		$settings['linkedin_sources'] = $saved_sources;
		update_option( PluginSettings::OPTION_NAME, $settings, false );

		$notice = 'sources_saved';
		if ( $attempted > 0 && count( $saved_sources ) < $attempted ) {
			$notice = 'sources_invalid';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'tab'    => self::TAB_SOURCES,
					'notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function analyzeImport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'atomic-wp-social-sync' ), 403 );
		}
		check_admin_referer( self::ANALYZE_IMPORT_ACTION );

		$source_id = sanitize_text_field( (string) wp_unslash( $_POST['source_id'] ?? '' ) );
		$source = $this->findSourceById( $source_id );
		$html = $this->readUploadedOrPastedHtml();
		if ( '' === $html ) {
			$this->redirectToImport( array( 'notice' => 'import_invalid' ) );
		}
		$this->analyzeParsedHtml( $html, $source );
	}

	/** @return array<string,mixed>|null */
	private function findSourceById( string $source_id ): ?array {
		$sources = $this->settings->get( 'linkedin_sources', array() );
		$sources = is_array( $sources ) ? $sources : array();
		foreach ( $sources as $source ) {
			if ( is_array( $source ) && (string) ( $source['id'] ?? '' ) === $source_id ) {
				return $source;
			}
		}
		return null;
	}

	private function readUploadedOrPastedHtml(): string {
		$paste = trim( (string) wp_unslash( $_POST['html_paste'] ?? '' ) );
		if ( '' !== $paste ) {
			return $paste;
		}
		if ( ! empty( $_FILES['html_file'] ) && is_array( $_FILES['html_file'] ) ) {
			$tmp_name = (string) ( $_FILES['html_file']['tmp_name'] ?? '' );
			$name     = (string) ( $_FILES['html_file']['name'] ?? '' );
			$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'html', 'htm', 'txt' ), true ) ) {
				return '';
			}
			if ( '' !== $tmp_name && file_exists( $tmp_name ) ) {
				return (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		return '';
	}

	/** @param array<string,mixed>|null $source */
	private function analyzeParsedHtml( string $html, ?array $source ): void {
		$parser = new LinkedInHtmlImportParser();
		$items = $parser->parse( $html );
		if ( ! $items ) {
			$this->redirectToImport( array( 'notice' => 'import_invalid' ) );
		}

		$results = array();
		$new = 0;
		$exists = 0;
		$updates = 0;
		$warn = 0;
		$trashed = 0;

		foreach ( $items as $item ) {
			$row = $this->analyzeImportItem( $item );
			if ( null === $row ) {
				continue;
			}
			$results[] = $row;
			$status = (string) ( $row['status'] ?? '' );
			if ( 'NEW' === $status ) {
				$new++;
			} elseif ( 'EXISTING_UPDATE_AVAILABLE' === $status ) {
				$exists++;
				$updates++;
			} elseif ( 'EXISTING_NO_CHANGES' === $status || 'REVIEW' === $status ) {
				$exists++;
			} elseif ( 'WARNING' === $status ) {
				$warn++;
			} elseif ( 'SKIPPED_TRASHED' === $status ) {
				$trashed++;
				$warn++;
			}
		}

		$total = count( $results );
		$unchanged = max( 0, $exists - $updates );

		$token = wp_generate_uuid4();
		set_transient(
			self::IMPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $token,
			array(
				'source_id'   => is_array( $source ) ? (string) ( $source['id'] ?? '' ) : '',
				'created_at'  => time(),
				'results'     => $results,
				'summary'     => array(
					'source_label'          => is_array( $source ) ? (string) ( $source['label'] ?? '' ) : '',
					'total'                 => $total,
					'new'                   => $new,
					'updates'               => $updates,
					'unchanged'             => $unchanged,
					'trashed'               => $trashed,
				),
			),
			60 * MINUTE_IN_SECONDS
		);

		$this->redirectToImport(
			array(
				'notice'   => 'import_analyzed',
				'analysis' => $token,
				'total'    => $total,
				'new'      => $new,
				'exists'   => $exists,
				'updates'  => $updates,
				'warn'     => $warn,
			)
		);
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>|null
	 */
	private function analyzeImportItem( array $item ): ?array {
		$urn = (string) ( $item['urn'] ?? '' );
		$activity_id = (string) ( $item['activity_id'] ?? '' );
		$permalink = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
		$suggested_title = sanitize_text_field( (string) ( $item['suggested_title'] ?? '' ) );
		$import_metadata = $this->sanitizeImportMetadata( $item );

		if ( '' === $urn || '' === $activity_id ) {
			return null;
		}

		$status = 'NEW';
		$message = '';
		$derived_published_local = $this->activityIdToLocalDatetime( $activity_id );
		$published_local = '';
		$existing_id = 0;
		$current_title = '';
		$current_permalink = '';
		$title_review = false;
		$title_state = 'empty';

		$existing = $this->findExistingActivityEmbedPostId( $urn );
		if ( null !== $existing ) {
			$existing_id = (int) $existing;
			$existing_post_status = get_post_status( $existing_id );
			if ( 'trash' === $existing_post_status ) {
				$status = 'SKIPPED_TRASHED';
				$message = __( 'Skipped — this LinkedIn post was previously moved to Trash. Use Settings → Advanced → Permanently delete imported posts to wipe it, or restore it manually before reimporting.', 'atomic-wp-social-sync' );
				$existing_post = get_post( $existing_id );
				if ( $existing_post instanceof \WP_Post ) {
					$current_title = (string) $existing_post->post_title;
					$published_local = mysql2date( 'Y-m-d\TH:i', (string) $existing_post->post_date, false );
				}
				return array(
					'urn'               => $urn,
					'activity_id'       => $activity_id,
					'permalink'         => $permalink,
					'published_local'   => $published_local,
					'suggested_title'   => $suggested_title,
					'existing_id'       => $existing_id,
					'current_title'     => $current_title,
					'current_permalink' => (string) get_post_meta( $existing_id, MetaKeys::EXTERNAL_URL, true ),
					'title_state'       => 'manual',
					'import_metadata'   => $import_metadata,
					'title_review'      => false,
					'status'            => $status,
					'message'           => $message,
				);
			}
			$existing_post = get_post( $existing_id );
			if ( $existing_post instanceof \WP_Post ) {
				$current_title = (string) $existing_post->post_title;
				$published_local = mysql2date( 'Y-m-d\TH:i', (string) $existing_post->post_date, false );
			}
			$current_permalink = (string) get_post_meta( $existing_id, MetaKeys::EXTERNAL_URL, true );

			$title_locked = '1' === (string) get_post_meta( $existing_id, MetaKeys::TITLE_LOCKED, true );
			$is_placeholder = TitlePolicy::isGeneratedPlaceholderTitle( $current_title );
			// IMPORTANT:
			// The title lock meta is meant to protect manual/editorial edits.
			// If a title still matches a strict generated-placeholder pattern, it is not editorial content,
			// even if the lock meta is present due to historical data/bugs. In that case, allow replacement.
			$effective_locked = $title_locked && ! $is_placeholder;

			if ( $effective_locked ) {
				$title_state = 'manual';
			} elseif ( '' === trim( $current_title ) ) {
				$title_state = 'empty';
			} elseif ( $is_placeholder ) {
				$title_state = 'generated_placeholder';
			} else {
				$title_state = 'manual';
			}

			$can_auto_update_title = ! $effective_locked && ( '' === trim( $current_title ) || $is_placeholder );
			$has_title_update = $can_auto_update_title && '' !== $suggested_title;
			$has_permalink_update = $this->hasSafePermalinkUpdate( $permalink, $urn, $current_permalink );
			$has_published_update = $this->hasMissingPublishedMetaUpdate( $existing_id, $derived_published_local );
			$has_metadata_update = $this->hasSafeMetadataUpdate( $existing_id, $import_metadata );
			$title_review = ( ! $can_auto_update_title ) && '' !== trim( $current_title ) && '' !== $suggested_title;

			// Status policy:
			// - If a suggested title exists but the current title is manual/editorial, surface REVIEW even when there are no other safe changes.
			// - If any safe update exists (title/permalink/published/metadata), surface EXISTING_UPDATE_AVAILABLE (title may still remain preserved).
			if ( $title_review && ! $has_title_update && ! $has_permalink_update && ! $has_published_update && ! $has_metadata_update ) {
				$status = 'REVIEW';
			} elseif ( $has_title_update || $has_permalink_update || $has_published_update || $has_metadata_update ) {
				$status = 'EXISTING_UPDATE_AVAILABLE';
			} else {
				$status = 'EXISTING_NO_CHANGES';
			}
		} else {
			$published_local = $derived_published_local;
			if ( '' === $published_local ) {
				$status = 'WARNING';
				$message = __( 'Publication date could not be determined. Set it manually to enable import.', 'atomic-wp-social-sync' );
			}
		}

		return array(
			'urn'               => $urn,
			'activity_id'       => $activity_id,
			'permalink'         => $permalink,
			'published_local'   => $published_local,
			'suggested_title'   => $suggested_title,
			'existing_id'       => $existing_id,
			'current_title'     => $current_title,
			'current_permalink' => $current_permalink,
			'title_state'       => $title_state,
			'import_metadata'   => $import_metadata,
			'title_review'      => $title_review,
			'status'            => $status,
			'message'           => $message,
		);
	}

	private function hasSafePermalinkUpdate( string $permalink, string $urn, string $current_permalink ): bool {
		$safe_permalink = $this->validatePermalinkForUrn( $permalink, $urn ) ?? '';
		$default_permalink = 'https://www.linkedin.com/feed/update/' . $urn . '/';
		return '' !== $safe_permalink
			&& ( '' === $current_permalink || $default_permalink === $current_permalink )
			&& $safe_permalink !== $current_permalink;
	}

	private function hasMissingPublishedMetaUpdate( int $post_id, string $published_local ): bool {
		if ( $post_id <= 0 || '' === $published_local ) {
			return false;
		}
		return '' === trim( (string) get_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, true ) );
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function sanitizeImportMetadata( array $item ): array {
		$source_width = max( 0, absint( $item['source_width'] ?? 0 ) );
		$source_height = max( 0, absint( $item['source_height'] ?? 0 ) );
		$text_length = max( 0, absint( $item['text_length'] ?? 0 ) );
		$media_count = max( 0, absint( $item['media_count'] ?? 0 ) );
		$media_type = is_array( $item['media_type'] ?? null ) ? $item['media_type'] : array();
		$media_type = array_values(
			array_filter(
				array_unique(
					array_map(
						static fn( mixed $value ): string => sanitize_key( (string) $value ),
						$media_type
					)
				)
			)
		);
		$content_profile = sanitize_key( (string) ( $item['content_profile'] ?? '' ) );
		if ( ! in_array( $content_profile, array( 'text_only', 'image_single', 'image_multi', 'video_single', 'video_multi', 'mixed_media' ), true ) ) {
			$content_profile = '';
		}

		$aspect_data = array();
		if ( is_array( $item['aspect_data'] ?? null ) ) {
			$aspect_width = max( 0, absint( $item['aspect_data']['width'] ?? 0 ) );
			$aspect_height = max( 0, absint( $item['aspect_data']['height'] ?? 0 ) );
			$ratio = isset( $item['aspect_data']['ratio'] ) ? (float) $item['aspect_data']['ratio'] : 0.0;
			if ( $aspect_width > 0 && $aspect_height > 0 ) {
				$aspect_data = array(
					'width'  => $aspect_width,
					'height' => $aspect_height,
					'ratio'  => $ratio > 0 ? round( $ratio, 4 ) : round( $aspect_width / $aspect_height, 4 ),
				);
			}
		}
		if ( ! $aspect_data && $source_width > 0 && $source_height > 0 ) {
			$aspect_data = array(
				'width'  => $source_width,
				'height' => $source_height,
				'ratio'  => round( $source_width / $source_height, 4 ),
			);
		}

		return array(
			'source_width'    => $source_width,
			'source_height'   => $source_height,
			'text_length'     => $text_length,
			'media_type'      => $media_type,
			'media_count'     => $media_count,
			'aspect_data'     => $aspect_data,
			'content_profile' => $content_profile,
		);
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	private function hasSafeMetadataUpdate( int $post_id, array $metadata ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( (int) ( $metadata['source_width'] ?? 0 ) > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_WIDTH, true ) <= 0 ) {
			return true;
		}
		if ( (int) ( $metadata['source_height'] ?? 0 ) > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_HEIGHT, true ) <= 0 ) {
			return true;
		}
		if ( (int) ( $metadata['text_length'] ?? 0 ) > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_TEXT_LENGTH, true ) <= 0 ) {
			return true;
		}
		if ( (int) ( $metadata['media_count'] ?? 0 ) > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_MEDIA_COUNT, true ) <= 0 ) {
			return true;
		}
		if ( ! empty( $metadata['media_type'] ) && empty( get_post_meta( $post_id, MetaKeys::MEDIA_TYPE, true ) ) ) {
			return true;
		}
		if ( ! empty( $metadata['aspect_data'] ) && empty( get_post_meta( $post_id, MetaKeys::LINKEDIN_ASPECT_DATA, true ) ) ) {
			return true;
		}
		if ( '' !== (string) ( $metadata['content_profile'] ?? '' ) && '' === trim( (string) get_post_meta( $post_id, MetaKeys::LINKEDIN_CONTENT_PROFILE, true ) ) ) {
			return true;
		}

		$estimated_heights = $this->estimatedActivityHeights( $metadata );
		if ( ! empty( $estimated_heights ) && (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE, true ) <= 0 ) {
			if ( (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, true ) <= 0 && (int) ( $estimated_heights['full'] ?? 0 ) > 0 ) {
				return true;
			}
			if ( (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, true ) <= 0 && (int) ( $estimated_heights['compact'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	public function runImport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'atomic-wp-social-sync' ), 403 );
		}
		check_admin_referer( self::RUN_IMPORT_ACTION );

		$token = sanitize_text_field( (string) wp_unslash( $_POST['analysis'] ?? '' ) );
		$key = self::IMPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$this->redirectToImport( array( 'notice' => 'sources_invalid' ) );
		}

		$source_id = sanitize_text_field( (string) ( $data['source_id'] ?? '' ) );
		$results   = is_array( $data['results'] ?? null ) ? $data['results'] : array();

		$selected = isset( $_POST['select'] ) ? (array) wp_unslash( $_POST['select'] ) : array();
		$selected = array_values( array_filter( array_map( 'sanitize_text_field', $selected ) ) );

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$urn = (string) ( $row['urn'] ?? '' );
			if ( '' === $urn || ! in_array( $urn, $selected, true ) ) {
				continue;
			}

			$title = isset( $_POST['title'][ $urn ] )
				? sanitize_text_field( (string) wp_unslash( $_POST['title'][ $urn ] ) )
				: '';

			$published_local = trim( (string) wp_unslash( $_POST['published_local'][ $urn ] ?? '' ) );
			$permalink = trim( (string) wp_unslash( $_POST['permalink'][ $urn ] ?? '' ) );
			$permalink = '' !== $permalink ? esc_url_raw( $permalink ) : '';

			try {
					$existing_id = $this->findExistingActivityEmbedPostId( $urn );
					$import_metadata = is_array( $row['import_metadata'] ?? null ) ? $row['import_metadata'] : array();
				if ( null !== $existing_id && 'trash' === get_post_status( (int) $existing_id ) ) {
					$skipped++;
					continue;
				}
				if ( null !== $existing_id ) {
						$this->updateActivityEmbedPost( (int) $existing_id, $urn, $title, $permalink, $source_id, $published_local, $import_metadata );
					$updated++;
				} else {
					if ( '' === $published_local ) {
						throw new \RuntimeException( __( 'Missing publication date.', 'atomic-wp-social-sync' ) );
					}
						$this->createActivityEmbedPost( $urn, $published_local, $permalink, $source_id, $title, $import_metadata );
					$created++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				$errors[] = array(
					'urn'    => $urn,
					'reason' => $e->getMessage() ? (string) $e->getMessage() : __( 'Unknown error.', 'atomic-wp-social-sync' ),
				);
			}
		}

		delete_transient( $key );
		$report = '';
		if ( $errors ) {
			$report = wp_generate_uuid4();
			set_transient(
				self::IMPORT_REPORT_PREFIX . get_current_user_id() . '_' . $report,
				array( 'errors' => $errors ),
				10 * MINUTE_IN_SECONDS
			);
		}

		$args = array(
			'notice'  => 'import_done',
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
		if ( '' !== $report ) {
			$args['report'] = $report;
		}
		$this->redirectToImport( $args );
	}

	private function renderImportTab(): void {
		$settings = $this->settings->all();
		$sources  = $settings['linkedin_sources'] ?? array();
		$sources  = is_array( $sources ) ? $sources : array();

		$analysis = sanitize_text_field( (string) wp_unslash( $_GET['analysis'] ?? '' ) );
		$key = $analysis ? self::IMPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $analysis : '';
		$data = $analysis ? get_transient( $key ) : null;

		$results = ( is_array( $data ) && is_array( $data['results'] ?? null ) ) ? $data['results'] : null;
		$summary = ( is_array( $data ) && is_array( $data['summary'] ?? null ) ) ? $data['summary'] : array();
		$selected_source = ( is_array( $data ) ? (string) ( $data['source_id'] ?? '' ) : '' );
		$default_source = '';
		foreach ( $sources as $s ) {
			if ( is_array( $s ) && ! empty( $s['is_default'] ) ) {
				$default_source = (string) ( $s['id'] ?? '' );
				break;
			}
		}
		if ( '' === $selected_source ) {
			$count = count( $sources );
			if ( 1 === $count && is_array( $sources[0] ?? null ) ) {
				$selected_source = (string) ( $sources[0]['id'] ?? '' );
			} elseif ( $count > 1 && '' !== $default_source ) {
				$selected_source = $default_source;
			}
		}
		$selected_source_label = '';
		foreach ( $sources as $s ) {
			if ( is_array( $s ) && (string) ( $s['id'] ?? '' ) === $selected_source ) {
				$selected_source_label = (string) ( $s['label'] ?? '' );
				break;
			}
		}

		$import_help_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_HELP ), admin_url( 'admin.php' ) ) . '#ermn-help-bulk';
		?>
		<div class="atomic-linkedin-design atomic-linkedin-settings__import">
			<p class="atomic-linkedin-design__intro">
				<?php esc_html_e( 'Upload a saved LinkedIn HTML file or paste HTML.', 'atomic-wp-social-sync' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Before saving the LinkedIn page, scroll through all posts you want to capture so LinkedIn loads them into the page.', 'atomic-wp-social-sync' ); ?>
				<a href="<?php echo esc_url( $import_help_url ); ?>"><?php esc_html_e( 'Need help importing posts?', 'atomic-wp-social-sync' ); ?></a>
			</p>

			<div class="atomic-linkedin-design__card">
				<div class="atomic-linkedin-design__card-header">
					<h2><?php esc_html_e( 'LinkedIn HTML Import', 'atomic-wp-social-sync' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Upload a saved LinkedIn HTML page or paste its HTML below.', 'atomic-wp-social-sync' ); ?></p>
					<p class="description"><?php esc_html_e( 'Atomic analyzes the supplied file locally in WordPress and does not automatically fetch LinkedIn.', 'atomic-wp-social-sync' ); ?></p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ANALYZE_IMPORT_ACTION ); ?>">
					<?php wp_nonce_field( self::ANALYZE_IMPORT_ACTION ); ?>

					<?php if ( ! $sources ) : ?>
						<?php
						$sources_url = add_query_arg(
							array( 'page' => self::SLUG, 'tab' => self::TAB_SOURCES ),
							admin_url( 'admin.php' )
						);
						?>
						<p class="description">
							<?php esc_html_e( 'No LinkedIn source has been configured yet.', 'atomic-wp-social-sync' ); ?>
							<a href="<?php echo esc_url( $sources_url ); ?>"><?php esc_html_e( 'Go to Sources.', 'atomic-wp-social-sync' ); ?></a>
						</p>
					<?php else : ?>
						<p>
							<label for="atomic-import-source"><strong><?php esc_html_e( 'Source', 'atomic-wp-social-sync' ); ?></strong></label><br>
							<select id="atomic-import-source" name="source_id">
								<option value=""><?php esc_html_e( '— Select —', 'atomic-wp-social-sync' ); ?></option>
								<?php foreach ( $sources as $s ) : ?>
									<?php if ( ! is_array( $s ) ) { continue; } ?>
									<option value="<?php echo esc_attr( (string) ( $s['id'] ?? '' ) ); ?>" <?php selected( $selected_source, (string) ( $s['id'] ?? '' ) ); ?>>
										<?php echo esc_html( (string) ( $s['label'] ?? '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>

					<p>
						<label for="atomic-import-file"><strong><?php esc_html_e( 'Upload HTML file', 'atomic-wp-social-sync' ); ?></strong></label><br>
						<input id="atomic-import-file" type="file" name="html_file" accept=".html,.htm,.txt">
					</p>
					<?php
					$bulk_help_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_HELP ), admin_url( 'admin.php' ) ) . '#ermn-help-bulk';
					?>
					<div style="background:#f6f7f7;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:10px 14px;margin:8px 0 16px 0">
						<p style="margin:0 0 6px 0"><strong><?php esc_html_e( 'How to export the LinkedIn page', 'atomic-wp-social-sync' ); ?></strong></p>
						<ol style="margin:0 0 4px 20px;padding:0">
							<li><?php esc_html_e( 'Open the organisation page in your browser.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Make sure the posts you want are loaded.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Save the page as HTML.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Upload the saved HTML file here.', 'atomic-wp-social-sync' ); ?></li>
						</ol>
						<p style="margin:0"><a href="<?php echo esc_url( $bulk_help_url ); ?>"><?php esc_html_e( 'Learn more', 'atomic-wp-social-sync' ); ?></a></p>
					</div>

					<p>
						<label for="atomic-import-paste"><strong><?php esc_html_e( 'Or paste HTML', 'atomic-wp-social-sync' ); ?></strong></label><br>
						<textarea id="atomic-import-paste" class="widefat" rows="6" name="html_paste" placeholder="<?php echo esc_attr__( 'Paste saved LinkedIn page HTML here', 'atomic-wp-social-sync' ); ?>"></textarea>
					</p>

					<?php submit_button( __( 'Analyze', 'atomic-wp-social-sync' ), 'secondary', 'analyze', false ); ?>
				</form>
			</div>

			<?php if ( is_array( $summary ) && $summary ) : ?>
				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Analyze summary', 'atomic-wp-social-sync' ); ?></h2>
					</div>
					<p><strong><?php esc_html_e( 'Source:', 'atomic-wp-social-sync' ); ?></strong> <?php echo esc_html( (string) ( $summary['source_label'] ?? '—' ) ); ?></p>
					<p><strong><?php esc_html_e( 'Posts detected:', 'atomic-wp-social-sync' ); ?></strong> <?php echo esc_html( (string) ( $summary['total'] ?? 0 ) ); ?></p>
					<p><strong><?php esc_html_e( 'New:', 'atomic-wp-social-sync' ); ?></strong> <?php echo esc_html( (string) ( $summary['new'] ?? 0 ) ); ?></p>
					<p><strong><?php esc_html_e( 'Existing with updates:', 'atomic-wp-social-sync' ); ?></strong> <?php echo esc_html( (string) ( $summary['updates'] ?? 0 ) ); ?></p>
					<p><strong><?php esc_html_e( 'Unchanged:', 'atomic-wp-social-sync' ); ?></strong> <?php echo esc_html( (string) ( $summary['unchanged'] ?? 0 ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_array( $results ) ) : ?>
				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'Analyze results', 'atomic-wp-social-sync' ); ?></h2>
						<p class="description"><?php esc_html_e( 'No posts are created during Analyze. Select posts to import, adjust any warnings, then import.', 'atomic-wp-social-sync' ); ?></p>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_IMPORT_ACTION ); ?>">
						<input type="hidden" name="analysis" value="<?php echo esc_attr( $analysis ); ?>">
						<?php wp_nonce_field( self::RUN_IMPORT_ACTION ); ?>

						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Select', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Status', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Title', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Publication date', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Activity ID', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'LinkedIn URL', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Source', 'atomic-wp-social-sync' ); ?></th>
									<th><?php esc_html_e( 'Method', 'atomic-wp-social-sync' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $results as $row ) : ?>
									<?php
									if ( ! is_array( $row ) ) { continue; }
									$urn = (string) ( $row['urn'] ?? '' );
									$activity_id = (string) ( $row['activity_id'] ?? '' );
									$status = (string) ( $row['status'] ?? '' );
									$published_local = (string) ( $row['published_local'] ?? '' );
									$permalink = (string) ( $row['permalink'] ?? '' );
									$suggested_title = (string) ( $row['suggested_title'] ?? '' );
									$current_title = (string) ( $row['current_title'] ?? '' );
									$title_review = ! empty( $row['title_review'] );
									$title_state = (string) ( $row['title_state'] ?? '' );
									$is_existing = str_starts_with( $status, 'EXISTING_' ) || 'REVIEW' === $status || 'SKIPPED_TRASHED' === $status;
									$disabled = ( 'EXISTING_NO_CHANGES' === $status ) || ( 'SKIPPED_TRASHED' === $status );
									$warning_blocked = ( 'WARNING' === $status && '' === $published_local );
									$checked  = in_array( $status, array( 'NEW', 'EXISTING_UPDATE_AVAILABLE' ), true );
									$status_label = match ( $status ) {
										'EXISTING_UPDATE_AVAILABLE' => __( 'EXISTING — UPDATE AVAILABLE', 'atomic-wp-social-sync' ),
										'EXISTING_NO_CHANGES' => __( 'EXISTING — NO CHANGES', 'atomic-wp-social-sync' ),
										'REVIEW' => __( 'REVIEW', 'atomic-wp-social-sync' ),
										'SKIPPED_TRASHED' => __( 'SKIPPED — TRASHED', 'atomic-wp-social-sync' ),
										default => strtoupper( str_replace( '_', ' ', $status ) ),
									};
									?>
									<tr>
										<td>
											<input
												type="checkbox"
												name="select[]"
												value="<?php echo esc_attr( $urn ); ?>"
												<?php checked( true, $checked ); ?>
												<?php disabled( true, $disabled || $warning_blocked ); ?>
												data-urn="<?php echo esc_attr( $urn ); ?>"
												class="atomic-import-select"
											/>
										</td>
										<td>
											<strong><?php echo esc_html( $status_label ); ?></strong>
											<?php if ( 'WARNING' === $status ) : ?>
												<div class="description"><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></div>
											<?php endif; ?>
											<?php if ( 'SKIPPED_TRASHED' === $status ) : ?>
												<div class="description" style="color:#996500"><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></div>
											<?php endif; ?>
											<?php if ( 'EXISTING_NO_CHANGES' === $status ) : ?>
												<div class="description"><?php esc_html_e( 'No safe update detected for this post.', 'atomic-wp-social-sync' ); ?></div>
											<?php endif; ?>
											<?php if ( 'REVIEW' === $status ) : ?>
												<div class="description"><?php esc_html_e( 'A suggested title was detected, but the current editorial title is already populated. Review manually if needed.', 'atomic-wp-social-sync' ); ?></div>
											<?php endif; ?>
											<?php if ( 'EXISTING_UPDATE_AVAILABLE' === $status && $title_review ) : ?>
												<div class="description"><?php esc_html_e( 'Editorial title is preserved. Safe metadata/permalink enrichment can still be applied.', 'atomic-wp-social-sync' ); ?></div>
											<?php endif; ?>
											<?php if ( 'EXISTING_UPDATE_AVAILABLE' === $status && 'generated_placeholder' === $title_state ) : ?>
												<div class="description"><?php esc_html_e( 'Title status: Generated placeholder — suggested title can be applied.', 'atomic-wp-social-sync' ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $is_existing ) : ?>
												<div class="description">
													<?php esc_html_e( 'Current:', 'atomic-wp-social-sync' ); ?>
													<strong><?php echo esc_html( '' !== trim( $current_title ) ? $current_title : '—' ); ?></strong>
												</div>
											<?php endif; ?>
											<input
												type="text"
												class="large-text"
												name="title[<?php echo esc_attr( $urn ); ?>]"
												value="<?php echo esc_attr( $suggested_title ); ?>"
												placeholder="<?php echo esc_attr__( 'Suggested title', 'atomic-wp-social-sync' ); ?>"
												<?php disabled( true, $disabled ); ?>
											/>
											<?php if ( $is_existing ) : ?>
												<?php if ( 'empty' === $title_state ) : ?>
													<div class="description"><?php esc_html_e( 'Title status: Empty — can be replaced.', 'atomic-wp-social-sync' ); ?></div>
												<?php elseif ( 'generated_placeholder' === $title_state ) : ?>
													<div class="description"><?php esc_html_e( 'Title status: Generated placeholder — can be replaced.', 'atomic-wp-social-sync' ); ?></div>
												<?php elseif ( '' !== trim( $current_title ) ) : ?>
													<div class="description"><?php esc_html_e( 'Title status: Manual/editorial — preserved.', 'atomic-wp-social-sync' ); ?></div>
												<?php endif; ?>
											<?php endif; ?>
										</td>
										<td>
											<input
												type="datetime-local"
												name="published_local[<?php echo esc_attr( $urn ); ?>]"
												value="<?php echo esc_attr( $published_local ); ?>"
												<?php disabled( true, $disabled || $is_existing ); ?>
												data-urn="<?php echo esc_attr( $urn ); ?>"
												class="atomic-import-date"
											/>
										</td>
										<td><code><?php echo esc_html( $activity_id ); ?></code></td>
										<td>
											<input type="url" class="large-text" name="permalink[<?php echo esc_attr( $urn ); ?>]" value="<?php echo esc_attr( $permalink ); ?>" placeholder="https://www.linkedin.com/feed/update/<?php echo esc_attr( $urn ); ?>/" <?php disabled( true, $disabled ); ?> />
										</td>
										<td><?php echo esc_html( $selected_source_label ?: '—' ); ?></td>
										<td><?php esc_html_e( 'Compatibility embed', 'atomic-wp-social-sync' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<script>
							(function(){
								function qs(sel, root){ return (root || document).querySelector(sel); }
								function onChangeDate(e){
									var input = e.target;
									if(!input || !input.classList.contains('atomic-import-date')){ return; }
									var urn = input.getAttribute('data-urn');
									if(!urn){ return; }
									var checkbox = qs('.atomic-import-select[data-urn="' + urn.replace(/"/g, '\\"') + '"]');
									if(!checkbox){ return; }
									if(input.value){
										checkbox.disabled = false;
									}
								}
								document.addEventListener('input', onChangeDate);
							})();
						</script>

						<?php submit_button( __( 'Import selected posts', 'atomic-wp-social-sync' ) ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function redirectToImport( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page' => self::SLUG,
						'tab'  => self::TAB_IMPORT,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function findExistingActivityEmbedPostId( string $urn ): ?int {
		$q = new \WP_Query(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					array( 'key' => MetaKeys::EMBED_STRATEGY, 'value' => LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK ),
					array( 'key' => MetaKeys::EMBED_URN, 'value' => $urn ),
				),
			)
		);
		$id = $q->posts ? (int) $q->posts[0] : 0;
		return $id > 0 ? $id : null;
	}

	private function activityIdToLocalDatetime( string $activity_id ): string {
		if ( ! ctype_digit( $activity_id ) ) {
			return '';
		}
		// Activity IDs are 64-bit values. Prefer safe integer handling.
		// If the environment cannot represent the ID safely as an int, return empty (warning).
		$id_int = (int) $activity_id;
		if ( (string) $id_int !== $activity_id ) {
			return '';
		}
		$ms = $id_int >> 22;
		if ( $ms <= 0 ) {
			return '';
		}
		$sec = intdiv( $ms, 1000 );
		$micro = ( $ms % 1000 ) * 1000;
		$utc = DateTimeImmutable::createFromFormat( 'U.u', sprintf( '%d.%06d', $sec, $micro ), new DateTimeZone( 'UTC' ) );
		if ( ! $utc instanceof DateTimeImmutable ) {
			return '';
		}
		$local = $utc->setTimezone( wp_timezone() );
		return $local->format( 'Y-m-d\TH:i' );
	}

	private function createActivityEmbedPost( string $urn, string $published_local, string $permalink, string $source_id, string $title = '', array $import_metadata = array() ): int {
		// Ensure the URN is valid and force activity fallback semantics.
		if ( ! preg_match( '/^urn:li:activity:\d+$/', $urn ) ) {
			throw new \RuntimeException( 'Invalid URN.' );
		}

		$existing = $this->findExistingActivityEmbedPostId( $urn );
		if ( null !== $existing ) {
			throw new \RuntimeException( 'Duplicate.' );
		}

		$local = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $published_local, wp_timezone() );
		if ( ! $local instanceof DateTimeImmutable ) {
			throw new \RuntimeException( 'Invalid date.' );
		}
		$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );
		$iso = $utc->format( DATE_ATOM );
		$gmt = $utc->format( 'Y-m-d H:i:s' );
		$local_date = get_date_from_gmt( $gmt );

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
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		$external_url = $this->validatePermalinkForUrn( $permalink, $urn ) ?? '';
		if ( '' === $external_url ) {
			$external_url = 'https://www.linkedin.com/feed/update/' . $urn . '/';
		}

		update_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, $iso );
		update_post_meta( $post_id, MetaKeys::INTEGRATION_MODE, IntegrationMode::EMBED );
		update_post_meta( $post_id, MetaKeys::PROVIDER, 'linkedin' );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_ID, $urn );
		update_post_meta( $post_id, MetaKeys::EXTERNAL_URL, esc_url_raw( $external_url ) );
		update_post_meta( $post_id, MetaKeys::IMPORTED, '1' );
		update_post_meta( $post_id, MetaKeys::EMBED_STRATEGY, LinkedInEmbed::STRATEGY_ACTIVITY_FALLBACK );
		update_post_meta( $post_id, MetaKeys::EMBED_URN, $urn );
		update_post_meta( $post_id, MetaKeys::DETACHED, '1' );
		update_post_meta( $post_id, MetaKeys::REMOTE_STATUS, 'embedded' );

		if ( '' !== $source_id ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_ID, $source_id );
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

		$this->applyImportedMetadata( $post_id, $import_metadata );

		wp_set_object_terms( $post_id, 'linkedin', SocialPostType::PROVIDER_TAXONOMY );
		return (int) $post_id;
	}

	private function updateActivityEmbedPost( int $post_id, string $urn, string $title, string $permalink, string $source_id, string $published_local = '', array $import_metadata = array() ): void {
		if ( $post_id <= 0 ) {
			throw new \RuntimeException( 'Invalid post.' );
		}
		if ( ! preg_match( '/^urn:li:activity:\d+$/', $urn ) ) {
			throw new \RuntimeException( 'Invalid URN.' );
		}
		if ( 'trash' === get_post_status( $post_id ) ) {
			throw new \RuntimeException( __( 'Cannot update a trashed LinkedIn post. Restore it from trash or permanently delete it first.', 'atomic-wp-social-sync' ) );
		}

		$current_urn = (string) get_post_meta( $post_id, MetaKeys::EMBED_URN, true );
		if ( $current_urn !== $urn ) {
			throw new \RuntimeException( 'URN mismatch.' );
		}

		$current_title = (string) get_post_field( 'post_title', $post_id );
		$title = sanitize_text_field( $title );
		$title_locked = '1' === (string) get_post_meta( $post_id, MetaKeys::TITLE_LOCKED, true );
		$is_placeholder = TitlePolicy::isGeneratedPlaceholderTitle( $current_title );
		// Same rule as Analyze: if the current title is a strict generated placeholder, treat it as replaceable,
		// even if a historical lock flag exists.
		$effective_locked = $title_locked && ! $is_placeholder;
		$can_update_title = ! $effective_locked && ( '' === trim( $current_title ) || $is_placeholder );

		if ( $can_update_title && '' !== trim( $title ) ) {
			$result = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
		}

		$safe_permalink = $this->validatePermalinkForUrn( $permalink, $urn ) ?? '';
		$current_url = (string) get_post_meta( $post_id, MetaKeys::EXTERNAL_URL, true );
		$default_url = 'https://www.linkedin.com/feed/update/' . $urn . '/';
		if ( '' !== $safe_permalink && ( '' === $current_url || $default_url === $current_url ) && $safe_permalink !== $current_url ) {
			update_post_meta( $post_id, MetaKeys::EXTERNAL_URL, esc_url_raw( $safe_permalink ) );
		}

		if ( '' !== $source_id ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_ID, $source_id );
		}

		$this->applyPublishedMetaIfMissing( $post_id, $published_local );
		$this->applyImportedMetadata( $post_id, $import_metadata );
	}

	private function applyPublishedMetaIfMissing( int $post_id, string $published_local ): void {
		if ( $post_id <= 0 || '' === $published_local ) {
			return;
		}
		if ( '' !== trim( (string) get_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, true ) ) ) {
			return;
		}
		$local = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $published_local, wp_timezone() );
		if ( ! $local instanceof DateTimeImmutable ) {
			return;
		}
		$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );
		update_post_meta( $post_id, MetaKeys::REMOTE_PUBLISHED_AT, $utc->format( DATE_ATOM ) );
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	private function applyImportedMetadata( int $post_id, array $metadata ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$source_width = (int) ( $metadata['source_width'] ?? 0 );
		$source_height = (int) ( $metadata['source_height'] ?? 0 );
		$text_length = (int) ( $metadata['text_length'] ?? 0 );
		$media_count = (int) ( $metadata['media_count'] ?? 0 );
		$media_type = is_array( $metadata['media_type'] ?? null ) ? $metadata['media_type'] : array();
		$aspect_data = is_array( $metadata['aspect_data'] ?? null ) ? $metadata['aspect_data'] : array();
		$content_profile = sanitize_key( (string) ( $metadata['content_profile'] ?? '' ) );

		if ( $source_width > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_WIDTH, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_WIDTH, (string) $source_width );
		}
		if ( $source_height > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_HEIGHT, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_SOURCE_HEIGHT, (string) $source_height );
		}
		if ( $text_length > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_TEXT_LENGTH, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_TEXT_LENGTH, (string) $text_length );
		}
		if ( $media_count > 0 && (int) get_post_meta( $post_id, MetaKeys::LINKEDIN_MEDIA_COUNT, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_MEDIA_COUNT, (string) $media_count );
		}
		if ( ! empty( $media_type ) && empty( get_post_meta( $post_id, MetaKeys::MEDIA_TYPE, true ) ) ) {
			update_post_meta( $post_id, MetaKeys::MEDIA_TYPE, array_values( $media_type ) );
		}
		if ( ! empty( $aspect_data ) && empty( get_post_meta( $post_id, MetaKeys::LINKEDIN_ASPECT_DATA, true ) ) ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_ASPECT_DATA, $aspect_data );
		}
		if ( '' !== $content_profile && '' === trim( (string) get_post_meta( $post_id, MetaKeys::LINKEDIN_CONTENT_PROFILE, true ) ) ) {
			update_post_meta( $post_id, MetaKeys::LINKEDIN_CONTENT_PROFILE, $content_profile );
		}

		if ( (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_OVERRIDE, true ) > 0 ) {
			return;
		}

		$estimated_heights = $this->estimatedActivityHeights( $metadata );
		$compact = (int) ( $estimated_heights['compact'] ?? 0 );
		$full = (int) ( $estimated_heights['full'] ?? 0 );
		if ( $compact > 0 && (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_COMPACT, (string) $compact );
		}
		if ( $full > 0 && (int) get_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, true ) <= 0 ) {
			update_post_meta( $post_id, MetaKeys::EMBED_HEIGHT_FULL, (string) $full );
		}
	}

	/**
	 * @param array<string,mixed> $metadata
	 * @return array{compact:int,full:int}
	 */
	private function estimatedActivityHeights( array $metadata ): array {
		$content_profile = (string) ( $metadata['content_profile'] ?? '' );
		$text_length = (int) ( $metadata['text_length'] ?? 0 );
		$media_count = (int) ( $metadata['media_count'] ?? 0 );

		$full = match ( $content_profile ) {
			'text_only'    => 620,
			'image_single' => 780,
			'image_multi'  => 940,
			'video_single' => 840,
			'video_multi'  => 980,
			'mixed_media'  => 1020,
			default        => 0,
		};

		if ( $full <= 0 ) {
			return array( 'compact' => 0, 'full' => 0 );
		}
		if ( $text_length > 280 ) {
			$full += 80;
		}
		if ( $text_length > 600 ) {
			$full += 80;
		}
		if ( $media_count > 1 ) {
			$full += 40;
		}
		$full = max( LinkedInEmbed::MIN_HEIGHT, min( 1400, $full ) );
		$compact = max( LinkedInEmbed::MIN_HEIGHT, min( $full, LinkedInEmbed::DEFAULT_HEIGHT_COMPACT ) );

		return array(
			'compact' => $compact,
			'full'    => $full,
		);
	}

	private function validatePermalinkForUrn( string $url, string $urn ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $host, array( 'linkedin.com', 'www.linkedin.com' ), true ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path ) {
			return null;
		}
		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		$normalized = 'https://www.linkedin.com' . $path;

		// If the URL contains an activity id, it must match the candidate URN.
		if ( preg_match( '/activity-(\d+)/i', $normalized, $m ) ) {
			$id = (string) $m[1];
			return ( 'urn:li:activity:' . $id ) === $urn ? $normalized : null;
		}
		if ( preg_match( '/urn:li:activity:(\d+)/i', $normalized, $m ) ) {
			$id = (string) $m[1];
			return ( 'urn:li:activity:' . $id ) === $urn ? $normalized : null;
		}

		return $normalized;
	}

	private function renderSourcesTab(): void {
		$settings = $this->settings->all();
		$sources  = $settings['linkedin_sources'] ?? array();
		$sources  = is_array( $sources ) ? $sources : array();
		$has_sources = ! empty( $sources );

		$default_id = '';
		foreach ( $sources as $source ) {
			if ( is_array( $source ) && ! empty( $source['is_default'] ) ) {
				$default_id = (string) ( $source['id'] ?? '' );
				break;
			}
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="atomic-linkedin-design atomic-linkedin-settings__sources">
			<p class="atomic-linkedin-design__intro">
				<?php esc_html_e( 'Define the LinkedIn Pages associated with this website. Sources are used for administration and manual import/migration.', 'atomic-wp-social-sync' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_SOURCES_ACTION ); ?>">
				<?php wp_nonce_field( self::SAVE_SOURCES_ACTION ); ?>

				<div class="atomic-linkedin-design__card">
					<div class="atomic-linkedin-design__card-header">
						<h2><?php esc_html_e( 'LinkedIn Sources', 'atomic-wp-social-sync' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Add one or more LinkedIn company or Page sources. One source can be set as the default.', 'atomic-wp-social-sync' ); ?></p>
					</div>

					<?php if ( ! $has_sources ) : ?>
						<p class="description"><?php esc_html_e( 'No LinkedIn sources configured.', 'atomic-wp-social-sync' ); ?></p>
					<?php endif; ?>

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Default', 'atomic-wp-social-sync' ); ?></th>
								<th><?php esc_html_e( 'Label', 'atomic-wp-social-sync' ); ?></th>
								<th><?php esc_html_e( 'LinkedIn Page URL', 'atomic-wp-social-sync' ); ?></th>
								<th><?php esc_html_e( 'Remove', 'atomic-wp-social-sync' ); ?></th>
							</tr>
						</thead>
						<tbody id="atomic-linkedin-sources-rows">
							<?php
							if ( ! $sources ) {
								$sources = array(
									array(
										'id'         => '',
										'label'      => '',
										'url'        => '',
										'is_default' => true,
									),
								);
							}

							foreach ( array_values( $sources ) as $i => $source ) :
								$source = is_array( $source ) ? $source : array();
								$id    = (string) ( $source['id'] ?? '' );
								$label = (string) ( $source['label'] ?? '' );
								$url   = (string) ( $source['url'] ?? '' );
								$tmp   = '' === $id ? 'new_' . $i : '';
								$is_default = ( '' !== $default_id && $id === $default_id ) || ( '' === $default_id && 0 === $i );
								?>
								<tr class="atomic-linkedin-source-row">
									<td style="width:90px;">
										<label class="screen-reader-text" for="atomic-source-default-<?php echo esc_attr( (string) $i ); ?>">
											<?php esc_html_e( 'Default source', 'atomic-wp-social-sync' ); ?>
										</label>
										<input
											id="atomic-source-default-<?php echo esc_attr( (string) $i ); ?>"
											type="radio"
											name="default_source"
											value="<?php echo esc_attr( '' !== $id ? $id : $tmp ); ?>"
											<?php checked( true, $is_default ); ?>
										/>
									</td>
									<td>
										<input type="hidden" name="sources[<?php echo esc_attr( (string) $i ); ?>][id]" value="<?php echo esc_attr( $id ); ?>">
										<input type="hidden" name="sources[<?php echo esc_attr( (string) $i ); ?>][tmp]" value="<?php echo esc_attr( $tmp ); ?>">
										<label class="screen-reader-text" for="atomic-source-label-<?php echo esc_attr( (string) $i ); ?>">
											<?php esc_html_e( 'Label', 'atomic-wp-social-sync' ); ?>
										</label>
										<input id="atomic-source-label-<?php echo esc_attr( (string) $i ); ?>" type="text" class="regular-text" name="sources[<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'My LinkedIn page', 'atomic-wp-social-sync' ); ?>">
									</td>
									<td>
										<label class="screen-reader-text" for="atomic-source-url-<?php echo esc_attr( (string) $i ); ?>">
											<?php esc_html_e( 'LinkedIn page URL', 'atomic-wp-social-sync' ); ?>
										</label>
										<input id="atomic-source-url-<?php echo esc_attr( (string) $i ); ?>" type="url" class="large-text" name="sources[<?php echo esc_attr( (string) $i ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.linkedin.com/company/example/posts/">
									</td>
									<td style="width:90px;">
										<label class="screen-reader-text" for="atomic-source-remove-<?php echo esc_attr( (string) $i ); ?>">
											<?php
											$remove_label = '' !== trim( $label ) ? $label : __( 'Source', 'atomic-wp-social-sync' );
											echo esc_html( sprintf( __( 'Remove "%s"', 'atomic-wp-social-sync' ), $remove_label ) );
											?>
										</label>
										<input id="atomic-source-remove-<?php echo esc_attr( (string) $i ); ?>" type="checkbox" name="sources[<?php echo esc_attr( (string) $i ); ?>][remove]" value="1">
									</td>
								</tr>
								<?php
							endforeach;
							?>
						</tbody>
					</table>

					<p style="margin-top:12px;">
						<button type="button" class="button" id="atomic-linkedin-add-source"><?php esc_html_e( '+ Add source', 'atomic-wp-social-sync' ); ?></button>
					</p>
				</div>

				<?php submit_button( __( 'Save changes', 'atomic-wp-social-sync' ) ); ?>
			</form>

			<script>
				(function(){
					var addBtn = document.getElementById('atomic-linkedin-add-source');
					var tbody = document.getElementById('atomic-linkedin-sources-rows');
					if(!addBtn || !tbody){ return; }
					addBtn.addEventListener('click', function(){
						var i = tbody.querySelectorAll('tr.atomic-linkedin-source-row').length;
						var tmp = 'new_' + i;
						var tr = document.createElement('tr');
						tr.className = 'atomic-linkedin-source-row';
						tr.innerHTML =
							'<td style="width:90px;">' +
								'<input id="atomic-source-default-' + i + '" type="radio" name="default_source" value="' + tmp + '"/>' +
							'</td>' +
							'<td>' +
								'<input type="hidden" name="sources[' + i + '][id]" value=""/>' +
								'<input type="hidden" name="sources[' + i + '][tmp]" value="' + tmp + '"/>' +
								'<input id="atomic-source-label-' + i + '" type="text" class="regular-text" name="sources[' + i + '][label]" value="" placeholder="<?php echo esc_js( __( 'My LinkedIn page', 'atomic-wp-social-sync' ) ); ?>"/>' +
							'</td>' +
							'<td>' +
								'<input id="atomic-source-url-' + i + '" type="url" class="large-text" name="sources[' + i + '][url]" value="" placeholder="https://www.linkedin.com/company/example/posts/"/>' +
							'</td>' +
							'<td style="width:90px;">' +
								'<input id="atomic-source-remove-' + i + '" type="checkbox" name="sources[' + i + '][remove]" value="1"/>' +
							'</td>';
						tbody.appendChild(tr);
					});
				})();
			</script>
		</div>
		<?php
	}

	public function saveAdvancedSettings(): void {
		DeveloperGuard::requireAdminOrDie();
		check_admin_referer( self::SAVE_ADVANCED_ACTION );

		$posted = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$posted = is_array( $posted ) ? $posted : array();
		$current = get_option( PluginSettings::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		$current['developer_tools'] = ! empty( $posted['developer_tools'] );
		$sanitized = PluginSettings::sanitize( $current );

		update_option( PluginSettings::OPTION_NAME, $sanitized, false );
		$this->logger->debug( 'Advanced settings saved.', array( 'developer_tools' => $sanitized['developer_tools'] ? 'on' : 'off' ) );
		$this->redirectToAdvanced( 'settings_saved_advanced' );
	}

	public function deleteImportedPosts(): void {
		DeveloperGuard::requireAdminOrDie();
		check_admin_referer( self::DELETE_POSTS_ACTION );
		$confirm = strtoupper( trim( (string) wp_unslash( $_POST['confirm'] ?? '' ) ) );
		if ( 'DELETE' !== $confirm ) {
			$this->redirectToAdvanced( 'delete_error', 0, __( 'You must type DELETE exactly to confirm.', 'atomic-wp-social-sync' ) );
		}

		$owned_ids = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => MetaKeys::IMPORTED, 'value' => '1' ),
				),
			)
		);
		$legacy_ids = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'compare' => 'NOT EXISTS' ),
					array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					array(
						'relation' => 'OR',
						array( 'key' => MetaKeys::EXTERNAL_ID, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
						array( 'key' => MetaKeys::EMBED_URN, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
					),
				),
			)
		);
		$ids = array_values( array_unique( array_map( 'intval', array_merge( is_array( $owned_ids ) ? $owned_ids : array(), is_array( $legacy_ids ) ? $legacy_ids : array() ) ) ) );

		$total = is_array( $ids ) ? count( $ids ) : 0;
		$done = 0;
		$failed = 0;
		if ( is_array( $ids ) ) {
			foreach ( array_chunk( $ids, 50 ) as $chunk ) {
				set_time_limit( 30 );
				foreach ( $chunk as $id ) {
					$result = wp_delete_post( (int) $id, true );
					if ( false === $result ) {
						$failed++;
					} else {
						$done++;
					}
				}
			}
		}

		$this->logger->debug( 'Maintenance: imported posts deleted.', array( 'total' => $total, 'done' => $done, 'failed' => $failed ) );
		if ( $failed > 0 ) {
			$this->redirectToAdvanced( 'posts_deleted_partial', $done, sprintf( __( '%d posts deleted, %d failed.', 'atomic-wp-social-sync' ), $done, $failed ) );
		}
		$this->redirectToAdvanced( 'posts_deleted', $done, sprintf( __( '%d imported LinkedIn posts deleted.', 'atomic-wp-social-sync' ), $done ) );
	}

	public function deleteImportedMedia(): void {
		DeveloperGuard::requireAdminOrDie();
		check_admin_referer( self::DELETE_MEDIA_ACTION );
		$confirm = strtoupper( trim( (string) wp_unslash( $_POST['confirm'] ?? '' ) ) );
		if ( 'DELETE' !== $confirm ) {
			$this->redirectToAdvanced( 'delete_error', 0, __( 'You must type DELETE exactly to confirm.', 'atomic-wp-social-sync' ) );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'value' => '1' ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
				),
			)
		);

		$total = is_array( $ids ) ? count( $ids ) : 0;
		$done = 0;
		$failed = 0;
		if ( is_array( $ids ) ) {
			foreach ( array_chunk( $ids, 50 ) as $chunk ) {
				set_time_limit( 30 );
				foreach ( $chunk as $id ) {
					$result = wp_delete_attachment( (int) $id, true );
					if ( false === $result ) {
						$failed++;
					} else {
						$done++;
					}
				}
			}
		}

		$this->logger->debug( 'Maintenance: imported media deleted.', array( 'total' => $total, 'done' => $done, 'failed' => $failed ) );
		if ( $failed > 0 ) {
			$this->redirectToAdvanced( 'media_deleted_partial', $done, sprintf( __( '%d attachments deleted, %d failed.', 'atomic-wp-social-sync' ), $done, $failed ) );
		}
		$this->redirectToAdvanced( 'media_deleted', $done, sprintf( __( '%d imported LinkedIn media deleted.', 'atomic-wp-social-sync' ), $done ) );
	}

	public function resetRuntimeData(): void {
		DeveloperGuard::requireAdminOrDie();
		check_admin_referer( self::RESET_RUNTIME_ACTION );

		global $wpdb;
		$cleared_transients = 0;
		$prefix = '_transient_atomic_social_';
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $opt ) {
				$transient_name = substr( (string) $opt, strlen( '_transient_' ) );
				delete_transient( $transient_name );
				$cleared_transients++;
			}
		}

		$site_prefix = '_site_transient_atomic_social_';
		$site_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $site_prefix ) . '%' ) );
		if ( is_array( $site_rows ) ) {
			foreach ( $site_rows as $opt ) {
				$transient_name = substr( (string) $opt, strlen( '_site_transient_' ) );
				delete_site_transient( $transient_name );
				$cleared_transients++;
			}
		}

		delete_option( PluginSettings::SYNC_LOG );

		$lock_prefix = 'atomic_social_sync_lock_';
		$lock_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $lock_prefix ) . '%' ) );
		if ( is_array( $lock_rows ) ) {
			foreach ( $lock_rows as $opt ) {
				delete_option( (string) $opt );
			}
		}

		$this->logger->debug( 'Maintenance: runtime data cleared.', array( 'transients_cleared' => $cleared_transients, 'locks_cleared' => is_array( $lock_rows ) ? count( $lock_rows ) : 0 ) );
		$this->redirectToAdvanced( 'runtime_reset', $cleared_transients, __( 'Plugin runtime data cleared (transients, locks, debug log).', 'atomic-wp-social-sync' ) );
	}

	public function retrofitMarker(): void {
		DeveloperGuard::requireAdminOrDie();
		check_admin_referer( self::RETROFIT_MARKER_ACTION );

		$candidates = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'compare' => 'NOT EXISTS' ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					array(
						'relation' => 'OR',
						array( 'key' => MetaKeys::EXTERNAL_ID, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
						array( 'key' => MetaKeys::EMBED_URN, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
					),
				),
			)
		);

		$marked = 0;
		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $pid ) {
				if ( add_post_meta( (int) $pid, MetaKeys::IMPORTED, '1', true ) ) {
					$marked++;
				}
			}
		}

		$this->logger->debug( 'Maintenance: ownership marker retrofitted.', array( 'marked' => $marked ) );
		$this->redirectToAdvanced( 'marker_retrofitted', $marked, sprintf( __( '%d legacy posts were tagged with the ownership marker.', 'atomic-wp-social-sync' ), $marked ) );
	}

	public function clearDebugLog(): void {
		DeveloperGuard::requireAdminOrDie();
		if ( DeveloperGuard::isDeveloperContext() ) {
			DeveloperGuard::requireDeveloperToolsOrDie();
		}
		check_admin_referer( self::CLEAR_LOG_ACTION );
		delete_option( PluginSettings::SYNC_LOG );
		if ( DeveloperGuard::isDeveloperContext() ) {
			$this->redirectToDeveloper( 'log_cleared' );
		}
		$this->redirectToAdvanced( 'log_cleared' );
	}

	public function devRunSync(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_RUN_SYNC_ACTION );

		$connections = $this->connections->all();
		$total = new \AtomicWPSocialSync\Sync\SyncResult();
		$ran = 0;
		foreach ( $connections as $connection ) {
			if ( 'connected' !== $connection->status ) {
				continue;
			}
			$result = $this->sync_service->syncConnection( $connection->id );
			$ran++;
			$total->fetched   += $result->fetched;
			$total->created   += $result->created;
			$total->updated   += $result->updated;
			$total->unchanged += $result->unchanged;
			$total->missing   += $result->missing;
			$total->drafted   += $result->drafted;
			$total->conflicts += $result->conflicts;
			$total->skipped   += $result->skipped;
			$total->duration  += $result->duration;
			foreach ( $result->errors as $e ) {
				$total->addError( $e );
			}
		}

		$msg = 0 === $ran
			? __( 'No active LinkedIn connection available for sync.', 'atomic-wp-social-sync' )
			: sprintf(
				/* translators: 1: fetched 2: created 3: updated 4: unchanged 5: errors 6: duration */
				__( 'Sync completed. Fetched: %1$d · Created: %2$d · Updated: %3$d · Unchanged: %4$d · Errors: %5$d · Duration: %6$ss', 'atomic-wp-social-sync' ),
				$total->fetched,
				$total->created,
				$total->updated,
				$total->unchanged,
				count( $total->errors ),
				round( $total->duration, 2 )
			);

		$this->logger->debug( 'Developer: run sync now.', array( 'connections' => $ran, 'fetched' => $total->fetched, 'created' => $total->created, 'updated' => $total->updated ) );
		$has_errors = count( $total->errors ) > 0;
		$this->redirectToDeveloper( $has_errors ? 'sync_errors' : 'sync_completed', $ran, $msg . ( $has_errors ? ' ' . implode( ' ', $total->errors ) : '' ) );
	}

	public function devFullResync(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_FULL_RESYNC_ACTION );

		global $wpdb;
		$lock_prefix = 'atomic_social_sync_lock_';
		$lock_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $lock_prefix ) . '%' ) );
		if ( is_array( $lock_rows ) ) {
			foreach ( $lock_rows as $opt ) {
				delete_option( (string) $opt );
			}
		}

		$connections = $this->connections->all();
		foreach ( $connections as $connection ) {
			$posts = $this->post_repository->postsForConnection( $connection->id );
			foreach ( $posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				delete_post_meta( $post->ID, MetaKeys::LAST_VERIFIED_AT );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_SINCE );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_CONFIRMATIONS );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_LAST_CONFIRMED_AT );
			}
		}

		$this->logger->debug( 'Developer: full resync initiated.' );
		$this->devRunSync();
	}

	public function devDryRun(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_DRY_RUN_ACTION );

		$connections = $this->connections->all();
		$fetched = 0;
		$would_create = 0;
		$would_update = 0;
		$unchanged = 0;
		$skipped = 0;
		$ran = 0;

		foreach ( $connections as $connection ) {
			if ( 'connected' !== $connection->status ) {
				continue;
			}
			try {
				$provider = $this->providers->get( $connection->provider );
			} catch ( \InvalidArgumentException ) {
				continue;
			}
			$ran++;
			try {
				$posts = $provider->fetchPosts( $connection, 25 );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Developer: dry-run fetch failed.', array( 'connection_id' => $connection->id, 'error' => $e->getMessage() ) );
				$this->redirectToDeveloper( 'dry_run_error', 0, $e->getMessage() );
			}
			$fetched += count( $posts );
			foreach ( $posts as $normalized ) {
				$existing = $this->post_repository->findByRemoteIdentity(
					$normalized->provider,
					$normalized->connection_id,
					$normalized->external_id
				);
				if ( null === $existing ) {
					$would_create++;
				} else {
					$hash_match = get_post_meta( $existing->ID, MetaKeys::CONTENT_HASH, true ) === $normalized->contentHash();
					if ( $hash_match ) {
						$unchanged++;
					} else {
						$would_update++;
					}
				}
			}
		}

		$msg = 0 === $ran
			? __( 'No active LinkedIn connection available for dry run.', 'atomic-wp-social-sync' )
			: sprintf(
				/* translators: 1: fetched 2: would create 3: would update 4: unchanged 5: skipped */
				__( 'Dry run completed. Fetched: %1$d · Would create: %2$d · Would update: %3$d · Unchanged: %4$d · Skipped: %5$d', 'atomic-wp-social-sync' ),
				$fetched,
				$would_create,
				$would_update,
				$unchanged,
				$skipped
			);

		$this->logger->debug( 'Developer: dry run completed.', compact( 'fetched', 'would_create', 'would_update', 'unchanged' ) );
		$this->redirectToDeveloper( 'dry_run_completed', $ran, $msg );
	}

	public function devClearCache(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_CLEAR_CACHE_ACTION );

		global $wpdb;
		$cleared = 0;
		$prefix = '_transient_atomic_social_';
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $opt ) {
				$transient_name = substr( (string) $opt, strlen( '_transient_' ) );
				delete_transient( $transient_name );
				$cleared++;
			}
		}

		delete_option( PluginSettings::SYNC_LOG );

		$lock_prefix = 'atomic_social_sync_lock_';
		$lock_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $lock_prefix ) . '%' ) );
		if ( is_array( $lock_rows ) ) {
			foreach ( $lock_rows as $opt ) {
				delete_option( (string) $opt );
				$cleared++;
			}
		}

		$this->logger->debug( 'Developer: plugin cache cleared.', array( 'items_cleared' => $cleared ) );
		$this->redirectToDeveloper( 'cache_cleared', $cleared, sprintf( __( 'Plugin cache cleared. %d transient/lock/log entries removed.', 'atomic-wp-social-sync' ), $cleared ) );
	}

	public function devResetSyncState(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_RESET_STATE_ACTION );

		$connections = $this->connections->all();
		$now = time();
		foreach ( $connections as $connection ) {
			$updated = $connection->with(
				array(
					'last_sync_at' => null,
					'next_sync_at' => $now,
					'diagnostics'  => array(),
					'last_error'   => null,
				)
			);
			$this->connections->save( $updated );

			$posts = $this->post_repository->postsForConnection( $connection->id );
			foreach ( $posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				delete_post_meta( $post->ID, MetaKeys::LAST_SYNCED_AT );
				delete_post_meta( $post->ID, MetaKeys::LAST_VERIFIED_AT );
				delete_post_meta( $post->ID, MetaKeys::CONTENT_HASH );
				delete_post_meta( $post->ID, MetaKeys::LOCAL_CONTENT_HASH );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_SINCE );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_CONFIRMATIONS );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_MISSING_LAST_CONFIRMED_AT );
				delete_post_meta( $post->ID, MetaKeys::REMOTE_STATUS );
				delete_post_meta( $post->ID, MetaKeys::SYNC_CONFLICT );
				delete_post_meta( $post->ID, MetaKeys::PENDING_REMOTE );
			}
		}

		$this->logger->debug( 'Developer: sync state reset.', array( 'connections' => count( $connections ) ) );
		$this->redirectToDeveloper( 'sync_state_reset', count( $connections ), sprintf( __( 'Sync state reset for %d connections. No content was modified.', 'atomic-wp-social-sync' ), count( $connections ) ) );
	}

	public function devRebuildContent(): void {
		DeveloperGuard::requireDeveloperToolsOrDie();
		check_admin_referer( self::DEV_REBUILD_ACTION );

		$info = $this->rebuildAvailability();
		$msg = sprintf(
			/* translators: 1: posts with snapshot 2: total imported posts */
			__( 'Rebuild architecture is in place. Source payload is currently stored only when developer tools OR debug logging are enabled at import time. %1$d of %2$d imported posts have a stored source snapshot available. No changes were made to content.', 'atomic-wp-social-sync' ),
			(int) $info['with_snapshot'],
			(int) $info['total_imported']
		);
		$this->logger->debug( 'Developer: rebuild report.', $info );
		$this->redirectToDeveloper( 'rebuild_report', (int) $info['with_snapshot'], $msg );
	}

	private function renderNotice(): void {
		$status = sanitize_key( (string) wp_unslash( $_GET['notice'] ?? '' ) );
		$count  = absint( $_GET['count'] ?? 0 );
		$message = sanitize_text_field( (string) wp_unslash( $_GET['msg'] ?? '' ) );

		// Preserve original import notices (unchanged).
		if ( in_array( $status, array( 'sources_saved', 'sources_invalid', 'import_analyzed', 'import_done', 'import_invalid' ), true ) ) {
			$this->renderLegacyImportNotice( $status );
			return;
		}

		if ( '' === $status ) {
			return;
		}

		$class = 'notice-success';
		$defaults = array();

		// Success conditions.
		if ( in_array( $status, array( 'posts_deleted', 'media_deleted', 'runtime_reset', 'marker_retrofitted', 'settings_saved_advanced', 'sync_completed', 'full_resync_completed', 'dry_run_completed', 'cache_cleared', 'sync_state_reset', 'log_cleared', 'rebuild_report' ), true ) ) {
			$class = 'notice-success';
		}
		// Error / warning conditions.
		if ( in_array( $status, array( 'delete_error', 'posts_deleted_partial', 'media_deleted_partial', 'sync_errors', 'dry_run_error' ), true ) ) {
			$class = in_array( $status, array( 'delete_error', 'sync_errors', 'dry_run_error' ), true ) ? 'notice-error' : 'notice-warning';
		}

		$msg = '' !== $message ? $message : ( $defaults[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) ) );
		$html  = '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
		if ( $count > 0 ) {
			$html .= '<strong>' . esc_html( (string) $count ) . '</strong> · ';
		}
		$html .= esc_html( $msg );
		$html .= '</p></div>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** @param string $status Original import/source statuses (pre-existing behavior). */
	private function renderLegacyImportNotice( string $status ): void {
		if ( 'sources_saved' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'atomic-wp-social-sync' ) . '</p></div>';
			return;
		}
		if ( 'sources_invalid' === $status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Some sources were not saved. Ensure each source has a label and a valid LinkedIn posts URL (https://www.linkedin.com/company/.../posts/).', 'atomic-wp-social-sync' ) . '</p></div>';
			return;
		}
		if ( 'import_analyzed' === $status ) {
			$total = absint( $_GET['total'] ?? 0 );
			$new   = absint( $_GET['new'] ?? 0 );
			$exists = absint( $_GET['exists'] ?? 0 );
			$updates = absint( $_GET['updates'] ?? 0 );
			$warn  = absint( $_GET['warn'] ?? 0 );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sprintf( __( '%1$d LinkedIn posts detected. %2$d new, %3$d updates available, %4$d unchanged, %5$d warnings.', 'atomic-wp-social-sync' ), $total, $new, $updates, $exists - $updates, $warn ) ) . '</p></div>';
			return;
		}
		if ( 'import_done' === $status ) {
			$created = absint( $_GET['created'] ?? 0 );
			$updated = absint( $_GET['updated'] ?? 0 );
			$skipped = absint( $_GET['skipped'] ?? 0 );
			$failed  = absint( $_GET['failed'] ?? 0 );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%1$d created, %2$d updated. %3$d skipped, %4$d failed.', 'atomic-wp-social-sync' ), $created, $updated, $skipped, $failed ) ) . '</p></div>';

			$report = sanitize_text_field( (string) wp_unslash( $_GET['report'] ?? '' ) );
			if ( '' !== $report ) {
				$key = self::IMPORT_REPORT_PREFIX . get_current_user_id() . '_' . $report;
				$report_data = get_transient( $key );
				if ( is_array( $report_data ) && ! empty( $report_data['errors'] ) && is_array( $report_data['errors'] ) ) {
					echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Some items could not be imported:', 'atomic-wp-social-sync' ) . '</strong></p><ul style="margin-left:18px;list-style:disc;">';
					foreach ( $report_data['errors'] as $err ) {
						if ( ! is_array( $err ) ) { continue; }
						$urn = (string) ( $err['urn'] ?? '' );
						$reason = (string) ( $err['reason'] ?? '' );
						if ( '' === $urn ) { continue; }
						echo '<li><code>' . esc_html( $urn ) . '</code>: ' . esc_html( $reason ) . '</li>';
					}
					echo '</ul></div>';
				}
				delete_transient( $key );
			}
			return;
		}
		if ( 'import_invalid' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Import failed. Provide valid LinkedIn HTML via upload or paste.', 'atomic-wp-social-sync' ) . '</p></div>';
		}
	}

	private function countImportedPosts(): int {
		$owned_q = new \WP_Query(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => MetaKeys::IMPORTED, 'value' => '1' ),
				),
			)
		);
		$legacy_q = new \WP_Query(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'compare' => 'NOT EXISTS' ),
					array( 'key' => MetaKeys::INTEGRATION_MODE, 'value' => IntegrationMode::EMBED ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					array(
						'relation' => 'OR',
						array( 'key' => MetaKeys::EXTERNAL_ID, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
						array( 'key' => MetaKeys::EMBED_URN, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
					),
				),
			)
		);
		$owned = is_countable( $owned_q->posts ) ? array_map( 'intval', $owned_q->posts ) : array();
		$legacy = is_countable( $legacy_q->posts ) ? array_map( 'intval', $legacy_q->posts ) : array();
		return count( array_unique( array_merge( $owned, $legacy ) ) );
	}

	private function countImportedMedia(): int {
		$q = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'value' => '1' ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
				),
			)
		);
		return is_countable( $q->posts ) ? count( $q->posts ) : 0;
	}

	private function countLegacyPostsWithoutMarker(): int {
		$q = new \WP_Query(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => MetaKeys::IMPORTED, 'compare' => 'NOT EXISTS' ),
					array( 'key' => MetaKeys::PROVIDER, 'value' => 'linkedin' ),
					array(
						'relation' => 'OR',
						array( 'key' => MetaKeys::EXTERNAL_ID, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
						array( 'key' => MetaKeys::EMBED_URN, 'value' => 'urn:li:', 'compare' => 'LIKE' ),
					),
				),
			)
		);
		return is_countable( $q->posts ) ? count( $q->posts ) : 0;
	}

	/** @return array{0:string,1:string} */
	private function lastSyncDates(): array {
		$connections = $this->connections->all();
		$best_success = null;
		$best_attempt = null;
		foreach ( $connections as $c ) {
			$ls = (int) ( $c->last_sync_at ?? 0 );
			if ( $ls > 0 && ( null === $best_success || $ls > $best_success ) ) {
				$best_success = $ls;
			}
			$diag = is_array( $c->diagnostics ?? null ) ? $c->diagnostics : array();
			$lra = (int) ( $diag['last_request_at'] ?? 0 );
			if ( $lra > 0 && ( null === $best_attempt || $lra > $best_attempt ) ) {
				$best_attempt = $lra;
			}
		}
		$formatted_success = null === $best_success ? __( 'No successful sync recorded.', 'atomic-wp-social-sync' ) : wp_date( 'Y-m-d H:i:s', $best_success );
		$formatted_attempt = null === $best_attempt ? __( 'No sync attempt recorded.', 'atomic-wp-social-sync' ) : wp_date( 'Y-m-d H:i:s', $best_attempt );
		return array( $formatted_success, $formatted_attempt );
	}

	/** @return array<string,string> */
	private function collectDiagnostics(): array {
		$settings = $this->settings->all();
		list( $last_success, $last_attempt ) = $this->lastSyncDates();
		$next_cron = wp_next_scheduled( Scheduler::HOOK );
		$connections = $this->connections->all();
		$sync_interval = PluginSettings::frequencies()[ (string) ( $settings['default_sync_frequency'] ?? PluginSettings::FREQUENCY_TWICE ) ] ?? '—';

		$last_result = '—';
		$last_duration = '—';
		$last_error = '—';
		$best_diag_ts = 0;
		$best_diag = null;
		$best_error_ts = 0;
		$best_error = null;
		foreach ( $connections as $c ) {
			$diag = is_array( $c->diagnostics ?? null ) ? $c->diagnostics : array();
			$ts = (int) ( $diag['last_request_at'] ?? 0 );
			if ( $ts > $best_diag_ts ) {
				$best_diag_ts = $ts;
				$best_diag = $diag;
			}
			if ( ! empty( $c->last_error ) && (int) ( $c->last_sync_at ?? 0 ) > $best_error_ts ) {
				$best_error_ts = (int) $c->last_sync_at;
				$best_error = (string) $c->last_error;
			}
		}
		if ( is_array( $best_diag ) && $best_diag ) {
			$labels = array( 'fetched', 'created', 'updated', 'unchanged', 'missing', 'drafted', 'conflicts', 'skipped' );
			$parts = array();
			foreach ( $labels as $k ) {
				$parts[] = $k . ':' . (int) ( $best_diag[ $k ] ?? 0 );
			}
			$parts[] = 'errors:' . count( is_array( $best_diag['errors'] ?? null ) ? $best_diag['errors'] : array() );
			$last_result = implode( ' ', $parts );
			$last_duration = round( (float) ( $best_diag['duration'] ?? 0 ), 2 ) . 's';
		}
		if ( null !== $best_error ) {
			$last_error = $best_error;
		}

		return array(
			__( 'WordPress Debug', 'atomic-wp-social-sync' )         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'ON' : 'OFF',
			__( 'Developer Tools', 'atomic-wp-social-sync' )         => ! empty( $settings['developer_tools'] ) ? 'ON' : 'OFF',
			__( 'WP Cron', 'atomic-wp-social-sync' )                 => false === $next_cron ? __( 'Not scheduled', 'atomic-wp-social-sync' ) : 'OK',
			__( 'Plugin Version', 'atomic-wp-social-sync' )          => (string) ( defined( 'ATOMIC_WP_SOCIAL_SYNC_VERSION' ) ? ATOMIC_WP_SOCIAL_SYNC_VERSION : '—' ),
			__( 'Updater', 'atomic-wp-social-sync' )                 => 'GitHub Releases',
			__( 'Latest checked version', 'atomic-wp-social-sync' )  => $this->updaterDiagnostic( 'latest_version' ),
			__( 'Last release check', 'atomic-wp-social-sync' )      => $this->updaterDiagnostic( 'last_check' ),
			__( 'Release asset', 'atomic-wp-social-sync' )           => $this->updaterDiagnostic( 'asset' ),
			__( 'WordPress Version', 'atomic-wp-social-sync' )       => $GLOBALS['wp_version'] ?? '—',
			__( 'PHP Version', 'atomic-wp-social-sync' )             => PHP_VERSION,
			__( 'CPT Name', 'atomic-wp-social-sync' )                => SocialPostType::POST_TYPE,
			__( 'Import Source', 'atomic-wp-social-sync' )           => 'linkedin',
			__( 'Imported Posts', 'atomic-wp-social-sync' )          => (string) $this->countImportedPosts(),
			__( 'Imported Media', 'atomic-wp-social-sync' )          => (string) $this->countImportedMedia(),
			__( 'Last sync attempt', 'atomic-wp-social-sync' )       => $last_attempt,
			__( 'Last successful sync', 'atomic-wp-social-sync' )    => $last_success,
			__( 'Next cron event', 'atomic-wp-social-sync' )         => false === $next_cron ? '—' : wp_date( 'Y-m-d H:i:s', (int) $next_cron ),
			__( 'Current sync interval', 'atomic-wp-social-sync' )   => $sync_interval,
			__( 'Last sync result', 'atomic-wp-social-sync' )        => $last_result,
			__( 'Last sync duration', 'atomic-wp-social-sync' )      => $last_duration,
			__( 'Last sync error', 'atomic-wp-social-sync' )         => $last_error,
		);
	}

	/** @return array{total_imported:int,with_snapshot:int} */
	private function rebuildAvailability(): array {
		$all = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => MetaKeys::IMPORTED, 'value' => '1' ),
				),
			)
		);
		$total = is_array( $all ) ? count( $all ) : 0;
		$with = 0;
		if ( is_array( $all ) ) {
			foreach ( $all as $pid ) {
				$payload = get_post_meta( (int) $pid, MetaKeys::SOURCE_PAYLOAD, true );
				if ( is_string( $payload ) && '' !== trim( $payload ) ) {
					$with++;
				}
			}
		}
		return array( 'total_imported' => $total, 'with_snapshot' => $with );
	}

	/**
	 * Read-only helper for the Developer diagnostic rows: expose updater state
	 * without ever forcing a blocking network call on tab render.
	 *
	 * @param 'latest_version'|'last_check'|'asset' $key
	 */
	private function updaterDiagnostic( string $key ): string {
		$updater = new GitHubReleaseUpdater(
			defined( 'ATOMIC_WP_SOCIAL_SYNC_FILE' ) ? ATOMIC_WP_SOCIAL_SYNC_FILE : __FILE__,
			defined( 'ATOMIC_WP_SOCIAL_SYNC_VERSION' ) ? ATOMIC_WP_SOCIAL_SYNC_VERSION : '0.0.0',
			'https://github.com/AtomicDesignBelgium/atomic-wp-linkedin-feed'
		);
		$info = $updater->fetchLatestReleaseInfo();
		switch ( $key ) {
			case 'latest_version':
				return is_array( $info ) && ! empty( $info['version'] ) ? (string) $info['version'] : '—';
			case 'last_check':
				if ( is_array( $info ) && ! empty( $info['last_check'] ) ) {
					return wp_date( 'Y-m-d H:i:s', (int) $info['last_check'] );
				}
				return __( 'Not yet checked', 'atomic-wp-social-sync' );
			case 'asset':
				if ( ! is_array( $info ) ) {
					return __( 'N/A — no cached release', 'atomic-wp-social-sync' );
				}
				return ! empty( $info['asset_found'] ) ? __( 'found', 'atomic-wp-social-sync' ) : __( 'missing', 'atomic-wp-social-sync' );
		}
		return '—';
	}

	private function redirectToAdvanced( string $status, int $count = 0, string $msg = '' ): never {
		$args = array(
			'page'   => self::SLUG,
			'tab'    => self::TAB_ADVANCED,
			'notice' => $status,
		);
		if ( $count > 0 ) {
			$args['count'] = $count;
		}
		if ( '' !== $msg ) {
			$args['msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function redirectToDeveloper( string $status, int $count = 0, string $msg = '' ): never {
		$args = array(
			'page'   => self::SLUG,
			'tab'    => self::TAB_DEVELOPER,
			'notice' => $status,
		);
		if ( $count > 0 ) {
			$args['count'] = $count;
		}
		if ( '' !== $msg ) {
			$args['msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function renderHelpTab(): void {
		$base_url     = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_HELP ), admin_url( 'admin.php' ) );
		$import_url   = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_IMPORT ), admin_url( 'admin.php' ) );
		$posts_url    = add_query_arg( array( 'page' => LinkedInPostsPage::MENU_SLUG ), admin_url( 'admin.php' ) );
		$design_url   = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_DESIGN ), admin_url( 'admin.php' ) );
		$advanced_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => self::TAB_ADVANCED ), admin_url( 'admin.php' ) );
		$plugin_ver   = defined( 'ATOMIC_WP_SOCIAL_SYNC_VERSION' ) ? ATOMIC_WP_SOCIAL_SYNC_VERSION : '—';
		?>
		<div class="atomic-linkedin-admin atomic-linkedin-help">

			<!-- HEADER -->
			<div class="ermn-help__header">
				<h1 class="ermn-help__title">
					<span class="dashicons dashicons-share" style="vertical-align:middle;margin-right:8px;opacity:.75"></span>
					<?php esc_html_e( 'ERMN LinkedIn News', 'atomic-wp-social-sync' ); ?>
				</h1>
				<p class="ermn-help__subtitle">
					<?php esc_html_e( 'Import LinkedIn posts into WordPress and display them as News on the ERMN website. No LinkedIn account connection is required.', 'atomic-wp-social-sync' ); ?>
				</p>
				<div class="ermn-help__meta">
					<span class="ermn-help__meta-item">
						<strong><?php esc_html_e( 'Version', 'atomic-wp-social-sync' ); ?>:</strong>
						<?php echo esc_html( $plugin_ver ); ?>
					</span>
					<span class="ermn-help__meta-sep" aria-hidden="true">·</span>
					<span class="ermn-help__meta-item">
						<strong><?php esc_html_e( 'Import mode', 'atomic-wp-social-sync' ); ?>:</strong>
						<?php esc_html_e( 'Manual / HTML', 'atomic-wp-social-sync' ); ?>
					</span>
				</div>
			</div>

			<!-- QUICK ACTION CARDS -->
			<div class="ermn-help__actions">
				<a class="ermn-help__action-card" href="<?php echo esc_url( $import_url ); ?>#ermn-help-single">
					<span class="dashicons dashicons-admin-post ermn-help__action-icon" aria-hidden="true"></span>
					<h3 class="ermn-help__action-title"><?php esc_html_e( 'Add one post', 'atomic-wp-social-sync' ); ?></h3>
					<p class="ermn-help__action-desc"><?php esc_html_e( 'Paste a LinkedIn URN or post link.', 'atomic-wp-social-sync' ); ?></p>
					<span class="ermn-help__action-link">
						<?php esc_html_e( 'Go to Import', 'atomic-wp-social-sync' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				</a>

				<a class="ermn-help__action-card" href="<?php echo esc_url( $import_url ); ?>">
					<span class="dashicons dashicons-upload ermn-help__action-icon" aria-hidden="true"></span>
					<h3 class="ermn-help__action-title"><?php esc_html_e( 'Import several posts', 'atomic-wp-social-sync' ); ?></h3>
					<p class="ermn-help__action-desc"><?php esc_html_e( 'Upload an exported LinkedIn organisation page.', 'atomic-wp-social-sync' ); ?></p>
					<span class="ermn-help__action-link">
						<?php esc_html_e( 'Go to Import', 'atomic-wp-social-sync' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				</a>

				<a class="ermn-help__action-card" href="<?php echo esc_url( $posts_url ); ?>">
					<span class="dashicons dashicons-list-view ermn-help__action-icon" aria-hidden="true"></span>
					<h3 class="ermn-help__action-title"><?php esc_html_e( 'Manage News', 'atomic-wp-social-sync' ); ?></h3>
					<p class="ermn-help__action-desc"><?php esc_html_e( 'Edit, restore or remove imported posts.', 'atomic-wp-social-sync' ); ?></p>
					<span class="ermn-help__action-link">
						<?php esc_html_e( 'Open LinkedIn Posts', 'atomic-wp-social-sync' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				</a>

				<a class="ermn-help__action-card" href="<?php echo esc_url( $design_url ); ?>">
					<span class="dashicons dashicons-layout ermn-help__action-icon" aria-hidden="true"></span>
					<h3 class="ermn-help__action-title"><?php esc_html_e( 'Display settings', 'atomic-wp-social-sync' ); ?></h3>
					<p class="ermn-help__action-desc"><?php esc_html_e( 'Choose Grid, Carousel or Stacked display.', 'atomic-wp-social-sync' ); ?></p>
					<span class="ermn-help__action-link">
						<?php esc_html_e( 'Open Design', 'atomic-wp-social-sync' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				</a>
			</div>

			<!-- TABLE OF CONTENTS -->
			<div class="ermn-help__toc">
				<h2 class="ermn-help__toc-title"><?php esc_html_e( 'On this page', 'atomic-wp-social-sync' ); ?></h2>
				<ul class="ermn-help__toc-list">
					<li><a href="#quick-start"><?php esc_html_e( 'Quick Start', 'atomic-wp-social-sync' ); ?></a></li>
					<li><a href="#import-posts"><?php esc_html_e( 'Add posts', 'atomic-wp-social-sync' ); ?></a></li>
					<li><a href="#manage-news"><?php esc_html_e( 'Manage News', 'atomic-wp-social-sync' ); ?></a></li>
					<li><a href="#display-news"><?php esc_html_e( 'Display News', 'atomic-wp-social-sync' ); ?></a></li>
					<li><a href="#troubleshooting"><?php esc_html_e( 'Troubleshooting', 'atomic-wp-social-sync' ); ?></a></li>
					<li><a href="#linkedin-limitations"><?php esc_html_e( 'LinkedIn limitations', 'atomic-wp-social-sync' ); ?></a></li>
				</ul>
			</div>

			<!-- QUICK START -->
			<section class="ermn-help__card ermn-help__card--accent" id="quick-start">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title">
						<span class="dashicons dashicons-lightbulb" aria-hidden="true" style="color:#007cba"></span>
						<?php esc_html_e( 'Quick Start', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>
				<div class="ermn-help__two-col">
					<div class="ermn-help__col">
						<h3 class="ermn-help__sub-title">
							<span class="dashicons dashicons-admin-post" aria-hidden="true"></span>
							<?php esc_html_e( 'Add one post', 'atomic-wp-social-sync' ); ?>
						</h3>
						<ol class="ermn-help__steps">
							<li><?php esc_html_e( 'Open the LinkedIn post.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Copy its URN or supported post reference.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Open Import.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Paste it.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Preview and import.', 'atomic-wp-social-sync' ); ?></li>
						</ol>
					</div>
					<div class="ermn-help__col">
						<h3 class="ermn-help__sub-title">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<?php esc_html_e( 'Import several posts', 'atomic-wp-social-sync' ); ?>
						</h3>
						<ol class="ermn-help__steps">
							<li><?php esc_html_e( 'Open the organisation\'s LinkedIn page.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Load the posts you want.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Save the page as HTML.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Upload the HTML file.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Analyze.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Review and import.', 'atomic-wp-social-sync' ); ?></li>
						</ol>
					</div>
				</div>
			</section>

			<!-- IMPORT POSTS -->
			<section class="ermn-help__card" id="import-posts">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Add posts', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>

				<!-- Single post -->
				<div id="import-single" class="ermn-help__subsection">
					<h3 class="ermn-help__section-title" id="ermn-help-single"><?php esc_html_e( 'Add a single LinkedIn post', 'atomic-wp-social-sync' ); ?></h3>
					<p><?php esc_html_e( 'Use this when you want to add a specific LinkedIn post individually.', 'atomic-wp-social-sync' ); ?></p>

					<h4 class="ermn-help__step-label"><?php esc_html_e( 'Step 1 — Go to Import', 'atomic-wp-social-sync' ); ?></h4>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( $import_url ); ?>">
							<span class="dashicons dashicons-admin-post" style="vertical-align:middle;font-size:16px;height:16px;line-height:1"></span>
							<?php esc_html_e( 'Open Import screen', 'atomic-wp-social-sync' ); ?>
						</a>
						<?php esc_html_e( 'or go to LinkedIn Posts and click + Add LinkedIn Post.', 'atomic-wp-social-sync' ); ?>
					</p>

					<h4 class="ermn-help__step-label"><?php esc_html_e( 'Step 2 — Paste the LinkedIn reference', 'atomic-wp-social-sync' ); ?></h4>
					<p><?php esc_html_e( 'You can paste any of these:', 'atomic-wp-social-sync' ); ?></p>
					<ul class="ermn-help__bullet-list">
						<li><?php esc_html_e( 'The LinkedIn post URL (web link)', 'atomic-wp-social-sync' ); ?></li>
						<li><?php esc_html_e( 'The LinkedIn embed code (iframe)', 'atomic-wp-social-sync' ); ?></li>
						<li><?php esc_html_e( 'The post URN (e.g. urn:li:share:12345)', 'atomic-wp-social-sync' ); ?></li>
					</ul>

					<h4 class="ermn-help__step-label"><?php esc_html_e( 'Step 3 — Preview and import', 'atomic-wp-social-sync' ); ?></h4>
					<ol class="ermn-help__steps">
						<li><?php esc_html_e( 'Set an editorial title (optional).', 'atomic-wp-social-sync' ); ?></li>
						<li><?php esc_html_e( 'Set the publication date (used for ordering).', 'atomic-wp-social-sync' ); ?></li>
						<li><?php esc_html_e( 'Click Preview to confirm the post is detected.', 'atomic-wp-social-sync' ); ?></li>
						<li><?php esc_html_e( 'Click Add post.', 'atomic-wp-social-sync' ); ?></li>
					</ol>

					<div class="ermn-help__note">
						<span class="dashicons dashicons-info" aria-hidden="true"></span>
						<p style="margin:0">
							<strong><?php esc_html_e( 'Duplicate handling', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'If the post already exists, the plugin updates or reuses the existing News item instead of creating a duplicate.', 'atomic-wp-social-sync' ); ?>
						</p>
					</div>

					<div class="ermn-help__note ermn-help__note--warning">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p style="margin:0">
							<strong><?php esc_html_e( 'Post in Trash', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'If the same LinkedIn post was previously moved to Trash, the import skips it. Restore it from Trash or permanently delete it before importing again.', 'atomic-wp-social-sync' ); ?>
						</p>
					</div>
				</div>

				<!-- Multiple posts -->
				<div id="import-bulk" class="ermn-help__subsection" style="margin-top:32px;padding-top:24px;border-top:1px solid #dcdcde">
					<h3 class="ermn-help__section-title" id="ermn-help-bulk"><?php esc_html_e( 'Import several posts', 'atomic-wp-social-sync' ); ?></h3>
					<p><?php esc_html_e( 'Use this workflow to import multiple LinkedIn posts at once from an organisation page.', 'atomic-wp-social-sync' ); ?></p>

					<div class="ermn-help__bulk-steps">
						<div class="ermn-help__bulk-step">
							<div class="ermn-help__bulk-step-num">1</div>
							<h4 class="ermn-help__bulk-step-title"><?php esc_html_e( 'Export the LinkedIn page', 'atomic-wp-social-sync' ); ?></h4>
							<ol class="ermn-help__steps">
								<li><?php esc_html_e( 'Open the organisation LinkedIn page.', 'atomic-wp-social-sync' ); ?></li>
								<li><strong><?php esc_html_e( 'Scroll down', 'atomic-wp-social-sync' ); ?></strong> <?php esc_html_e( 'to load all the posts you want to import. LinkedIn loads posts as you scroll — the ones not yet loaded will not be saved.', 'atomic-wp-social-sync' ); ?></li>
								<li><?php esc_html_e( 'In your browser: File → Save Page As… → choose HTML format.', 'atomic-wp-social-sync' ); ?></li>
							</ol>
						</div>

						<div class="ermn-help__bulk-step">
							<div class="ermn-help__bulk-step-num">2</div>
							<h4 class="ermn-help__bulk-step-title"><?php esc_html_e( 'Upload and analyze', 'atomic-wp-social-sync' ); ?></h4>
							<ol class="ermn-help__steps">
								<li><a href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Open LinkedIn News → Import', 'atomic-wp-social-sync' ); ?></a>.</li>
								<li><?php esc_html_e( 'Select the Source (organisation).', 'atomic-wp-social-sync' ); ?></li>
								<li><?php esc_html_e( 'Upload the saved HTML file (or paste the page HTML).', 'atomic-wp-social-sync' ); ?></li>
								<li><?php esc_html_e( 'Click Analyze.', 'atomic-wp-social-sync' ); ?></li>
							</ol>
						</div>

						<div class="ermn-help__bulk-step">
							<div class="ermn-help__bulk-step-num">3</div>
							<h4 class="ermn-help__bulk-step-title"><?php esc_html_e( 'Review and import', 'atomic-wp-social-sync' ); ?></h4>
							<p><?php esc_html_e( 'The analysis shows each post with a status:', 'atomic-wp-social-sync' ); ?></p>
							<ul class="ermn-help__bullet-list">
								<li><strong><?php esc_html_e( 'New', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'post will be created.', 'atomic-wp-social-sync' ); ?></li>
								<li><strong><?php esc_html_e( 'Update available', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'post exists and can be updated.', 'atomic-wp-social-sync' ); ?></li>
								<li><strong><?php esc_html_e( 'SKIPPED — TRASHED', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'post is in Trash (see Manage News).', 'atomic-wp-social-sync' ); ?></li>
							</ul>
							<p><?php esc_html_e( 'Select the rows you want, then click Run Import.', 'atomic-wp-social-sync' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<!-- MANAGE NEWS -->
			<section class="ermn-help__card" id="manage-news">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title" id="ermn-help-manage">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'Manage News', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>
				<p>
					<?php
					printf(
						esc_html__( 'Imported posts are managed from the %1$sLinkedIn Posts%2$s list.', 'atomic-wp-social-sync' ),
						'<a href="' . esc_url( $posts_url ) . '">',
						'</a>'
					);
					?>
				</p>

				<p>
					<a class="button button-secondary" href="<?php echo esc_url( $posts_url ); ?>">
						<span class="dashicons dashicons-list-view" style="vertical-align:middle;font-size:16px;height:16px;line-height:1"></span>
						<?php esc_html_e( 'Open LinkedIn Posts', 'atomic-wp-social-sync' ); ?>
					</a>
				</p>

				<h3 class="ermn-help__section-title"><?php esc_html_e( 'What you can do', 'atomic-wp-social-sync' ); ?></h3>
				<div class="ermn-help__two-col">
					<div class="ermn-help__col">
						<ul class="ermn-help__feature-list">
							<li><span class="dashicons dashicons-edit" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'Edit the title', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Inline or via the Edit dialog.', 'atomic-wp-social-sync' ); ?></div></li>
							<li><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'View the item', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Preview the post on the website.', 'atomic-wp-social-sync' ); ?></div></li>
							<li><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'Publish / Unpublish', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Show or hide on the website.', 'atomic-wp-social-sync' ); ?></div></li>
						</ul>
					</div>
					<div class="ermn-help__col">
						<ul class="ermn-help__feature-list">
							<li><span class="dashicons dashicons-trash" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'Move to Trash', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Soft delete — hidden but still stored.', 'atomic-wp-social-sync' ); ?></div></li>
							<li><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'Restore it', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Bring back a trashed post.', 'atomic-wp-social-sync' ); ?></div></li>
							<li><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <div><strong><?php esc_html_e( 'Permanently delete', 'atomic-wp-social-sync' ); ?></strong><br><?php esc_html_e( 'Fully removes the WordPress copy.', 'atomic-wp-social-sync' ); ?></div></li>
						</ul>
					</div>
				</div>

				<div class="ermn-help__note ermn-help__note--warning" style="margin-top:20px">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<div>
						<p style="margin:0 0 8px 0"><strong><?php esc_html_e( 'How Trash works', 'atomic-wp-social-sync' ); ?></strong></p>
						<p style="margin:0"><?php esc_html_e( 'Moving an imported post to Trash prevents it from being automatically recreated during a normal reimport. The plugin will mark it as "SKIPPED — TRASHED" so removed content does not reappear accidentally.', 'atomic-wp-social-sync' ); ?></p>
					</div>
				</div>

				<div class="ermn-help__note" style="margin-top:12px">
					<span class="dashicons dashicons-info" aria-hidden="true"></span>
					<div>
						<p style="margin:0 0 8px 0"><strong><?php esc_html_e( 'Permanent deletion', 'atomic-wp-social-sync' ); ?></strong></p>
						<p style="margin:0">
							<?php
							printf(
								esc_html__( 'Permanently deleting removes the WordPress copy completely and allows the same LinkedIn post to be imported again later. For bulk deletion, use %1$sSettings → Advanced%2$s.', 'atomic-wp-social-sync' ),
								'<a href="' . esc_url( $advanced_url ) . '">',
								'</a>'
							);
							?>
						</p>
					</div>
				</div>
			</section>

			<!-- DISPLAY NEWS -->
			<section class="ermn-help__card" id="display-news">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title" id="ermn-help-display">
						<span class="dashicons dashicons-layout" aria-hidden="true"></span>
						<?php esc_html_e( 'Display News', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>
				<p>
					<?php
					printf(
						esc_html__( 'Display settings are configured in %1$sDesign%2$s. Three layouts are available:', 'atomic-wp-social-sync' ),
						'<a href="' . esc_url( $design_url ) . '">',
						'</a>'
					);
					?>
				</p>

				<div class="ermn-help__layouts">
					<div class="ermn-help__layout-card">
						<h3 class="ermn-help__layout-title"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span> <?php esc_html_e( 'Grid', 'atomic-wp-social-sync' ); ?></h3>
						<p class="ermn-help__layout-best"><strong><?php esc_html_e( 'Best for:', 'atomic-wp-social-sync' ); ?></strong> <?php esc_html_e( 'Showing several News items together.', 'atomic-wp-social-sync' ); ?></p>
						<p class="ermn-help__layout-desc"><?php esc_html_e( 'Responsive multi-column layout with News cards side by side.', 'atomic-wp-social-sync' ); ?></p>
					</div>

					<div class="ermn-help__layout-card">
						<h3 class="ermn-help__layout-title"><span class="dashicons dashicons-slides" aria-hidden="true"></span> <?php esc_html_e( 'Carousel', 'atomic-wp-social-sync' ); ?></h3>
						<p class="ermn-help__layout-best"><strong><?php esc_html_e( 'Best for:', 'atomic-wp-social-sync' ); ?></strong> <?php esc_html_e( 'Compact homepage sections.', 'atomic-wp-social-sync' ); ?></p>
						<ul class="ermn-help__bullet-list ermn-help__bullet-list--tight">
							<li><?php esc_html_e( 'All slides use a common configured height.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Longer content can be visually clipped.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Optional "View all news" button links to the dedicated News page.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>

					<div class="ermn-help__layout-card">
						<h3 class="ermn-help__layout-title"><span class="dashicons dashicons-menu-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Stacked', 'atomic-wp-social-sync' ); ?></h3>
						<p class="ermn-help__layout-best"><strong><?php esc_html_e( 'Best for:', 'atomic-wp-social-sync' ); ?></strong> <?php esc_html_e( 'The full News page.', 'atomic-wp-social-sync' ); ?></p>
						<ul class="ermn-help__bullet-list ermn-help__bullet-list--tight">
							<li><?php esc_html_e( 'Navigation list links to the corresponding full News items.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Smooth scrolling between posts.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>
				</div>

				<h3 class="ermn-help__section-title" style="margin-top:28px"><?php esc_html_e( 'Recommended page architecture', 'atomic-wp-social-sync' ); ?></h3>
				<div class="ermn-help__two-col">
					<div class="ermn-help__col">
						<h4 class="ermn-help__sub-title"><?php esc_html_e( 'Homepage', 'atomic-wp-social-sync' ); ?></h4>
						<p><?php esc_html_e( 'Show selected or latest News using a compact Carousel or Grid, plus a "View all news" button linking to the dedicated News page.', 'atomic-wp-social-sync' ); ?></p>
					</div>
					<div class="ermn-help__col">
						<h4 class="ermn-help__sub-title"><?php esc_html_e( 'Dedicated News page', 'atomic-wp-social-sync' ); ?></h4>
						<p><?php esc_html_e( 'Complete presentation using a Stacked layout or full Grid with all News items.', 'atomic-wp-social-sync' ); ?></p>
					</div>
				</div>
			</section>

			<!-- TROUBLESHOOTING -->
			<section class="ermn-help__card" id="troubleshooting">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title" id="ermn-help-troubleshoot">
						<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
						<?php esc_html_e( 'Troubleshooting', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'A post does not import', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><strong><?php esc_html_e( 'Most likely causes', 'atomic-wp-social-sync' ); ?>:</strong></p>
						<ul class="ermn-help__bullet-list">
							<li><?php esc_html_e( 'Invalid or missing LinkedIn URN / link (check the value you pasted).', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'The post already exists in WordPress (it was shown as Update or Unchanged).', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'The post is in Trash and deliberately skipped.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'HTML was saved before the post was loaded on the page.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
						<p><strong><?php esc_html_e( 'What to do', 'atomic-wp-social-sync' ); ?>:</strong></p>
						<ul class="ermn-help__bullet-list">
							<li><?php esc_html_e( 'Verify the link/URN in the status message.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Check the post is not in Trash (see Manage News).', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'For bulk: re-save the HTML after scrolling to load all posts.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'Fewer posts were detected than expected', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><strong><?php esc_html_e( 'Most likely cause', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'LinkedIn loads posts dynamically as you scroll. If the page was saved before scrolling, only the initially visible posts were captured.', 'atomic-wp-social-sync' ); ?>
						</p>
						<p><strong><?php esc_html_e( 'What to do', 'atomic-wp-social-sync' ); ?>:</strong></p>
						<ol class="ermn-help__steps">
							<li><?php esc_html_e( 'Open LinkedIn and scroll to load all the posts you want.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Save the page as HTML again.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Upload and Analyze the new file.', 'atomic-wp-social-sync' ); ?></li>
						</ol>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'A deleted post does not come back', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><strong><?php esc_html_e( 'Cause', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'If the post was moved to Trash (not permanently deleted), it is intentionally skipped to prevent unwanted content from reappearing.', 'atomic-wp-social-sync' ); ?>
						</p>
						<p><strong><?php esc_html_e( 'What to do', 'atomic-wp-social-sync' ); ?>:</strong></p>
						<ul class="ermn-help__bullet-list">
							<li><strong><?php esc_html_e( 'Trash', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'restore it if you want it back, or permanently delete it to allow a clean reimport.', 'atomic-wp-social-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Permanently deleted', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'a new import will create a fresh copy.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'A LinkedIn embed cannot be displayed', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><strong><?php esc_html_e( 'Cause', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'LinkedIn does not provide an official embed option for every post format (events, polls, some multi-media posts).', 'atomic-wp-social-sync' ); ?>
						</p>
						<p><strong><?php esc_html_e( 'What the plugin does', 'atomic-wp-social-sync' ); ?>:</strong>
							<?php esc_html_e( 'It falls back to a compatibility embed using LinkedIn\'s public renderer. Always preview the post before publishing.', 'atomic-wp-social-sync' ); ?>
						</p>
						<p><strong><?php esc_html_e( 'What to do', 'atomic-wp-social-sync' ); ?>:</strong></p>
						<ul class="ermn-help__bullet-list">
							<li><?php esc_html_e( 'Preview the post and check it displays correctly.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'If the display is poor, add or adjust the editorial title shown above the embed.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>
				</details>
			</section>

			<!-- LINKEDIN LIMITATIONS -->
			<section class="ermn-help__card ermn-help__card--warning" id="linkedin-limitations">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title" id="ermn-help-limits">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'LinkedIn limitations', 'atomic-wp-social-sync' ); ?>
					</h2>
				</header>

				<h3 class="ermn-help__section-title"><?php esc_html_e( 'Why LinkedIn content is imported this way', 'atomic-wp-social-sync' ); ?></h3>
				<p>
					<?php
					esc_html_e(
						'LinkedIn restricts unauthorised automated scraping, while direct programmatic access to organisation posts requires specific approved LinkedIn API permissions.',
						'atomic-wp-social-sync'
					);
					?>
				</p>
				<p>
					<?php
					esc_html_e(
						'For this reason, ERMN LinkedIn News uses manually supplied post references or exported HTML and relies on LinkedIn\'s supported embed mechanism where possible.',
						'atomic-wp-social-sync'
					);
					?>
				</p>
				<p class="ermn-help__muted">
					<em><?php esc_html_e( 'Any future direct LinkedIn integration should use officially authorised LinkedIn access and be reviewed against LinkedIn\'s current terms.', 'atomic-wp-social-sync' ); ?></em>
				</p>

				<details class="ermn-help__details" style="margin-top:16px">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'LinkedIn terms and technical references', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<ul class="ermn-help__bullet-list">
							<li><a href="https://www.linkedin.com/legal/user-agreement" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'LinkedIn User Agreement', 'atomic-wp-social-sync' ); ?> <span class="dashicons dashicons-external" style="font-size:14px;opacity:.7" aria-hidden="true"></span></a></li>
							<li><a href="https://www.linkedin.com/legal/prohibited-software" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Prohibited software and automated activity', 'atomic-wp-social-sync' ); ?> <span class="dashicons dashicons-external" style="font-size:14px;opacity:.7" aria-hidden="true"></span></a></li>
							<li><a href="https://learn.microsoft.com/en-us/linkedin/community/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'LinkedIn Community Management API documentation', 'atomic-wp-social-sync' ); ?> <span class="dashicons dashicons-external" style="font-size:14px;opacity:.7" aria-hidden="true"></span></a></li>
						</ul>
					</div>
				</details>
			</section>

			<!-- ADVANCED DETAILS -->
			<section class="ermn-help__card" id="advanced-details">
				<header class="ermn-help__card-header">
					<h2 class="ermn-help__card-title">
						<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
						<?php esc_html_e( 'Advanced details', 'atomic-wp-social-sync' ); ?>
					</h2>
					<p class="description"><?php esc_html_e( 'For administrators who need deeper technical context. Not required for day-to-day use.', 'atomic-wp-social-sync' ); ?></p>
				</header>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'Ownership markers and import reconciliation', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><?php esc_html_e( 'Every post created by this plugin is tagged with an ownership marker. This protects non-plugin posts when bulk actions (like delete all imported posts) are run.', 'atomic-wp-social-sync' ); ?></p>
						<ul class="ermn-help__bullet-list">
							<li><?php esc_html_e( 'Imported posts are matched by their LinkedIn URN / source ID, not by title.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Duplicate source IDs never generate separate WordPress posts.', 'atomic-wp-social-sync' ); ?></li>
							<li><?php esc_html_e( 'Manual titles set by editors are protected and never overwritten by reimport.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'Trash and reimport — exact behaviour', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<ul class="ermn-help__bullet-list">
							<li><strong><?php esc_html_e( 'Publish / Draft', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'reimport can update metadata and titles (unless the title is manually locked).', 'atomic-wp-social-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Trash', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'reimport marks the row as SKIPPED — TRASHED. The post is never restored or modified.', 'atomic-wp-social-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Permanently deleted', 'atomic-wp-social-sync' ); ?></strong> — <?php esc_html_e( 'all record is wiped. A new import creates a fresh WordPress post with a new ID.', 'atomic-wp-social-sync' ); ?></li>
						</ul>
						<p class="ermn-help__muted">
							<?php
							printf(
								esc_html__( 'For bulk deletion or runtime reset, use %1$sSettings → Advanced%2$s.', 'atomic-wp-social-sync' ),
								'<a href="' . esc_url( $advanced_url ) . '">',
								'</a>'
							);
							?>
						</p>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'Legacy posts and retrofitting', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p><?php esc_html_e( 'Older posts imported before the ownership marker was added may appear as "Legacy posts" in Advanced → Maintenance. Run the Retrofit ownership marker action to tag them safely so they are included in bulk actions.', 'atomic-wp-social-sync' ); ?></p>
					</div>
				</details>

				<details class="ermn-help__details">
					<summary class="ermn-help__details-summary"><?php esc_html_e( 'Developer Tools', 'atomic-wp-social-sync' ); ?></summary>
					<div class="ermn-help__details-content">
						<p>
							<?php
							printf(
								esc_html__( 'When %1$sWP_DEBUG%2$s is enabled AND the developer toggle is ON in Settings → Developer, additional tools appear: run sync manually, force full resync, dry run, cache clearing, state reset, content rebuild, and debug logs. These tools are for technical diagnosis only.', 'atomic-wp-social-sync' ),
								'<code>WP_DEBUG</code>',
								'</code>'
							);
							?>
						</p>
					</div>
				</details>
			</section>

			<!-- SUPPORT CARD -->
			<section class="ermn-help__support">
				<h2 class="ermn-help__support-title">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<?php esc_html_e( 'Need help?', 'atomic-wp-social-sync' ); ?>
				</h2>
				<p class="ermn-help__support-desc"><?php esc_html_e( 'This plugin is developed and maintained by Atomic Design.', 'atomic-wp-social-sync' ); ?></p>
				<a class="button button-secondary" href="https://atomic-design.be" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Visit atomic-design.be', 'atomic-wp-social-sync' ); ?>
					<span class="dashicons dashicons-external" style="font-size:14px;vertical-align:middle;margin-left:4px" aria-hidden="true"></span>
				</a>
			</section>

			<!-- FOOTER / CREDITS -->
			<footer class="ermn-help__footer">
				<div class="ermn-help__footer-inner">
					<p class="ermn-help__footer-top">
						<?php esc_html_e( 'ERMN LinkedIn News', 'atomic-wp-social-sync' ); ?>
						<span aria-hidden="true">·</span>
						<?php printf( esc_html__( 'Version %s', 'atomic-wp-social-sync' ), esc_html( $plugin_ver ) ); ?>
					</p>
					<p class="ermn-help__footer-bottom">
						<?php esc_html_e( 'Developed & maintained by', 'atomic-wp-social-sync' ); ?>
						<a href="https://atomic-design.be" target="_blank" rel="noopener noreferrer" class="ermn-help__footer-link">
							Atomic Design
							<span class="dashicons dashicons-external" aria-hidden="true" style="font-size:13px;opacity:.7;margin-left:2px"></span>
						</a>
					</p>
				</div>
			</footer>

		</div>
		<?php
	}
}
