<?php
/**
 * Plugin composition root and traceable WordPress hook registration.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync;

use AtomicWPSocialSync\Admin\LinkedInEmbedMetaBox;
use AtomicWPSocialSync\Admin\LinkedInPostsPage;
use AtomicWPSocialSync\Admin\PostSyncMetaBox;
use AtomicWPSocialSync\Admin\DesignSettingsPage;
use AtomicWPSocialSync\Admin\SettingsPage;
use AtomicWPSocialSync\Admin\LinkedInFeedSettingsPage;
use AtomicWPSocialSync\Blocks\FeedBlock;
use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Display\FeedQuery;
use AtomicWPSocialSync\Display\FeedRenderer;
use AtomicWPSocialSync\Display\Shortcode;
use AtomicWPSocialSync\Media\MediaImporter;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInClient;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInOAuth;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInProvider;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInTokenManager;
use AtomicWPSocialSync\Providers\ProviderRegistry;
use AtomicWPSocialSync\Support\CredentialVault;
use AtomicWPSocialSync\Support\Logger;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\Sync\ReconciliationService;
use AtomicWPSocialSync\Sync\Scheduler;
use AtomicWPSocialSync\Sync\SyncService;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use AtomicWPSocialSync\WordPress\SocialPostType;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;
	private SocialPostType $post_type;
	private Scheduler $scheduler;
	private Shortcode $shortcode;
	private ProviderRegistry $registry;
	private ConnectionRepository $connections;
	private PluginSettings $settings;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$settings         = new PluginSettings();
		$this->settings   = $settings;
		$vault            = new CredentialVault();
		$logger           = new Logger( $settings );
		$connections      = new ConnectionRepository();
		$this->connections = $connections;
		$post_repository  = new SocialPostRepository();
		$media_importer   = new MediaImporter();
		$registry         = new ProviderRegistry();
		$this->registry   = $registry;
		$linkedin_client  = new LinkedInClient( $vault, $logger );
		$linkedin_oauth   = new LinkedInOAuth( $settings, $vault );
		$linkedin_tokens  = new LinkedInTokenManager( $linkedin_oauth, $vault, $connections );
		$registry->register( new LinkedInProvider( $linkedin_client, $linkedin_tokens ) );
		add_filter(
			'atomic_social_provider_label',
			static function ( string $label, string $slug ) use ( $registry ): string {
				$slug = sanitize_key( $slug );
				try {
					return $registry->get( $slug )->label();
				} catch ( \InvalidArgumentException ) {
					// Allow unregistered providers (e.g. tests/fixtures) to keep the caller label.
					return $label;
				}
			},
			10,
			2
		);
		$reconciliation   = new ReconciliationService( $post_repository, $settings, $media_importer );
		$sync_service     = new SyncService( $registry, $connections, $post_repository, $reconciliation, $settings, $logger );
		$this->scheduler  = new Scheduler( $connections, $sync_service );
		$this->post_type  = new SocialPostType( $settings );
		$feed_query       = new FeedQuery();
		$feed_renderer    = new FeedRenderer( $settings );
		$feed_block       = new FeedBlock( $feed_query, $feed_renderer );
		$this->shortcode  = new Shortcode( $feed_query, $feed_renderer );
		$settings_page    = new SettingsPage( $settings, $vault, $linkedin_oauth, $linkedin_client, $connections, $registry, $sync_service );
		$design_settings  = new DesignSettingsPage( $settings );
		$linkedin_settings = new LinkedInFeedSettingsPage( $settings, $design_settings );
		$post_meta_box    = new PostSyncMetaBox( $connections, $reconciliation );
		$linkedin_embed   = new LinkedInEmbedMetaBox();
		$linkedin_posts   = new LinkedInPostsPage();
		$linkedin_posts->registerHooks();

		add_action( 'init', array( $this->post_type, 'register' ), 5 );
		add_action( 'init', array( $this, 'registerAssets' ), 8 );
		add_action( 'init', array( $feed_block, 'register' ), 10 );
		add_action( 'init', array( $this, 'registerShortcode' ), 10 );
		add_action( 'rest_api_init', array( $feed_block, 'registerRoutes' ) );
		add_action( 'rest_api_init', array( $settings_page, 'registerRoutes' ) );

		add_action( 'admin_menu', array( $linkedin_posts, 'registerMenu' ) );
		add_action( 'admin_menu', array( $linkedin_settings, 'registerMenu' ) );
		add_action( 'admin_menu', array( $settings_page, 'registerMenu' ) );
		add_action( 'admin_menu', array( $design_settings, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $linkedin_settings, 'enqueueAssets' ) );
		add_action( 'admin_enqueue_scripts', array( $design_settings, 'enqueueAssets' ) );
		add_action( 'admin_post_atomic_social_save_settings', array( $settings_page, 'saveSettings' ) );
		add_action( 'admin_post_atomic_linkedin_save_sources', array( $linkedin_settings, 'saveSources' ) );
		add_action( 'admin_post_atomic_linkedin_analyze_import', array( $linkedin_settings, 'analyzeImport' ) );
		add_action( 'admin_post_atomic_linkedin_run_import', array( $linkedin_settings, 'runImport' ) );
		add_action( 'admin_post_atomic_social_connect', array( $settings_page, 'connect' ) );
		add_action( 'admin_post_atomic_social_select_organization', array( $settings_page, 'selectOrganization' ) );
		add_action( 'admin_post_atomic_social_connection_action', array( $settings_page, 'connectionAction' ) );
		add_action( SettingsPage::PENDING_CLEANUP_HOOK, array( $settings_page, 'cleanupPendingCredentials' ) );
		add_action( 'add_meta_boxes', array( $post_meta_box, 'register' ) );
		add_action( 'add_meta_boxes', array( $linkedin_embed, 'register' ) );
		add_action( 'save_post_' . SocialPostType::POST_TYPE, array( $post_meta_box, 'save' ), 20, 2 );
		add_action( 'save_post_' . SocialPostType::POST_TYPE, array( $linkedin_embed, 'save' ), 20, 2 );
		add_action( 'added_post_meta', array( $post_meta_box, 'lockFeaturedImage' ), 10, 3 );
		add_action( 'updated_post_meta', array( $post_meta_box, 'lockFeaturedImage' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $post_meta_box, 'lockFeaturedImage' ), 10, 3 );

		add_filter( 'cron_schedules', array( $this->scheduler, 'registerSchedule' ) );
		add_action( Scheduler::HOOK, array( $this->scheduler, 'run' ) );
		add_action( 'init', array( Scheduler::class, 'ensureScheduled' ), 20 );
		add_action( 'init', array( $this, 'maybeFlushRewriteRules' ), 30 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueueEditorData' ) );
	}

	public function registerAssets(): void {
		$frontend_css_path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/css/frontend.css';
		$frontend_css_ver  = file_exists( $frontend_css_path ) ? (string) filemtime( $frontend_css_path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_register_style(
			'atomic-wp-social-sync-frontend',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/css/frontend.css',
			array(),
			$frontend_css_ver
		);
		wp_register_script(
			'atomic-wp-social-sync-carousel',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/carousel.js',
			array(),
			ATOMIC_WP_SOCIAL_SYNC_VERSION,
			true
		);
		wp_register_script(
			'atomic-wp-social-sync-load-more',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/load-more.js',
			array(),
			ATOMIC_WP_SOCIAL_SYNC_VERSION,
			true
		);
		wp_localize_script(
			'atomic-wp-social-sync-load-more',
			'atomicSocialFeed',
			array(
				'restUrl'       => rest_url( 'atomic-wp-social-sync/v1/feed' ),
				'loadedMessage' => __( 'More social posts loaded.', 'atomic-wp-social-sync' ),
				'errorMessage'  => __( 'More posts could not be loaded. Please try again.', 'atomic-wp-social-sync' ),
			)
		);
	}

	public function registerShortcode(): void {
		add_shortcode( 'atomic_social_feed', array( $this->shortcode, 'render' ) );
	}

	public function maybeFlushRewriteRules(): void {
		if ( '1' === get_option( 'atomic_wp_social_sync_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'atomic_wp_social_sync_flush_rewrite' );
		}
	}

	public function enqueueEditorData(): void {
		$providers = array();
		foreach ( $this->registry->all() as $provider ) {
			$providers[] = array( 'slug' => $provider->slug(), 'label' => $provider->label() );
		}
		$connections = array();
		foreach ( $this->connections->all() as $connection ) {
			$connections[] = array( 'id' => $connection->id, 'label' => $connection->account_name, 'provider' => $connection->provider );
		}
		$pages = array();
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
			if ( $page instanceof \WP_Post ) {
				$pages[] = array( 'id' => (int) $page->ID, 'title' => (string) $page->post_title );
			}
		}
		wp_localize_script(
			'atomic-wp-social-sync-feed-editor-script',
			'atomicSocialEditor',
			array(
				'providers'          => $providers,
				'connections'        => $connections,
				'singlePagesEnabled' => (bool) $this->settings->get( 'enable_single_pages', false ),
				'pages'              => $pages,
			)
		);
	}

	public static function activate(): void {
		update_option( PluginSettings::SCHEMA_OPTION, ATOMIC_WP_SOCIAL_SYNC_SCHEMA_VERSION, false );
		if ( false === get_option( PluginSettings::OPTION_NAME, false ) ) {
			add_option( PluginSettings::OPTION_NAME, PluginSettings::defaults(), '', false );
		}
		self::instance()->post_type->register();
		Scheduler::ensureScheduled();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Scheduler::clear();
		flush_rewrite_rules();
	}

	private function __construct() {}
}
