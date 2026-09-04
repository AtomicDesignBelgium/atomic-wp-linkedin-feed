<?php
/**
 * Disposable Local-site integration checks.
 *
 * Run only from the command line with ATOMIC_SOCIAL_RUN_TESTS=1. The test
 * creates and then removes its own Social Posts and restores plugin options.
 */

if ( '1' !== getenv( 'ATOMIC_SOCIAL_RUN_TESTS' ) ) {
	exit( 1 );
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_plugin_active( 'atomic-wp-social-sync/atomic-wp-social-sync.php' ) ) {
	$result = activate_plugin( 'atomic-wp-social-sync/atomic-wp-social-sync.php' );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	echo "Plugin activated. Run the integration test once more.\n";
	exit( 0 );
}

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Admin\LinkedInEmbedMetaBox;
use AtomicWPSocialSync\Plugin;
use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Display\FeedQuery;
use AtomicWPSocialSync\Display\FeedRenderer;
use AtomicWPSocialSync\Media\MediaImporter;
use AtomicWPSocialSync\Model\NormalizedSocialPost;
use AtomicWPSocialSync\Providers\ProviderCapabilities;
use AtomicWPSocialSync\Providers\ProviderException;
use AtomicWPSocialSync\Providers\ProviderRegistry;
use AtomicWPSocialSync\Providers\SocialProviderInterface;
use AtomicWPSocialSync\Providers\VerificationResult;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInClient;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInOAuth;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInProvider;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInTokenManager;
use AtomicWPSocialSync\Support\CredentialVault;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\Logger;
use AtomicWPSocialSync\Support\MetaKeys;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\Sync\ReconciliationService;
use AtomicWPSocialSync\Sync\SyncResult;
use AtomicWPSocialSync\Sync\SyncService;
use AtomicWPSocialSync\Sync\Scheduler;
use AtomicWPSocialSync\WordPress\SocialPostRepository;
use AtomicWPSocialSync\WordPress\SocialPostType;

final class AtomicSocialFixtureProvider implements SocialProviderInterface {
	/** @var NormalizedSocialPost[] */
	public array $posts = array();
	/** @var array<string,string> */
	public array $verification = array();

	public function slug(): string { return 'fixture'; }
	public function label(): string { return 'Fixture'; }
	public function capabilities(): ProviderCapabilities { return new ProviderCapabilities( true, false, false, false, true, false, false ); }
	public function testConnection( Connection $connection ): array { return array( 'ok' => true ); }
	public function getAccount( Connection $connection ): array { return array( 'id' => $connection->account_external_id, 'name' => $connection->account_name ); }
	public function fetchPosts( Connection $connection, int $limit = 100, int $start = 0 ): array { return array_slice( $this->posts, $start, $limit ); }
	public function fetchPost( Connection $connection, string $external_id ): ?NormalizedSocialPost {
		foreach ( $this->posts as $post ) { if ( $post->external_id === $external_id ) { return $post; } }
		return null;
	}
	public function verifyPostExists( Connection $connection, string $external_id ): VerificationResult {
		$state = $this->verification[ $external_id ] ?? 'exists';
		if ( 'authorization' === $state ) { throw new ProviderException( 'Fixture permission denied.', ProviderException::AUTHORIZATION, 403 ); }
		if ( 'missing' === $state ) { return VerificationResult::missing(); }
		$post = $this->fetchPost( $connection, $external_id );
		return null === $post ? VerificationResult::missing() : VerificationResult::exists( $post );
	}
}

/** @throws RuntimeException */
function atomic_social_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function atomic_social_fixture_post( string $connection_id, string $id, string $text, string $modified = '2026-01-01T00:00:00+00:00' ): NormalizedSocialPost {
	return new NormalizedSocialPost(
		'fixture', $connection_id, $id, 'https://example.test/' . $id, '', $text, $text,
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ), new DateTimeImmutable( $modified ),
		'fixture-author', 'Fixture Account', 'published', 'text', array()
	);
}

$settings_backup    = get_option( PluginSettings::OPTION_NAME, null );
$schema_backup      = get_option( PluginSettings::SCHEMA_OPTION, null );
$connections_backup = get_option( PluginSettings::CONNECTIONS, null );
$log_backup         = get_option( PluginSettings::SYNC_LOG, null );
$credentials_backup = get_option( PluginSettings::CREDENTIALS, null );
$flush_rewrite_backup = get_option( 'atomic_wp_social_sync_flush_rewrite', null );
$cron_backup        = wp_next_scheduled( Scheduler::HOOK );
$provider_terms_backup   = array();
$connection_terms_backup = array();
$created_lock_keys  = array();
$created_connection_ids = array();
$created_post_ids   = array();
$created_page_ids   = array();
$created_user_ids   = array();
$checks             = array();

try {
	atomic_social_assert( post_type_exists( SocialPostType::POST_TYPE ), 'Social Posts CPT is not registered.' );
	atomic_social_assert( taxonomy_exists( SocialPostType::PROVIDER_TAXONOMY ), 'Provider taxonomy is not registered.' );
	atomic_social_assert( WP_Block_Type_Registry::get_instance()->is_registered( 'atomic-wp-social-sync/feed' ), 'Feed block is not registered.' );
	atomic_social_assert( shortcode_exists( 'atomic_social_feed' ), 'Feed shortcode is not registered.' );
	$checks[] = 'bootstrap/CPT/taxonomies/block/shortcode';

	$provider_terms_backup   = get_terms( array( 'taxonomy' => SocialPostType::PROVIDER_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );
	$connection_terms_backup = get_terms( array( 'taxonomy' => SocialPostType::CONNECTION_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );
	$provider_terms_backup   = is_array( $provider_terms_backup ) ? $provider_terms_backup : array();
	$connection_terms_backup = is_array( $connection_terms_backup ) ? $connection_terms_backup : array();

	$linkedin_connection = new Connection( 'linkedin-fixture', 'linkedin', '5515715', 'Fixture Page' );
	$linkedin_settings = new PluginSettings();
	$linkedin_vault = new CredentialVault();
	$linkedin_vault->put( 'integration_test_secret', 'not-a-real-secret' );
	atomic_social_assert( 'not-a-real-secret' === $linkedin_vault->get( 'integration_test_secret' ), 'Credential encryption round trip failed.' );
	atomic_social_assert( false === str_contains( wp_json_encode( get_option( PluginSettings::CREDENTIALS ) ), 'not-a-real-secret' ), 'Credential was stored in plaintext.' );
	$linkedin_vault->delete( 'integration_test_secret' );
	$linkedin_client = new LinkedInClient( $linkedin_vault, new Logger( $linkedin_settings ) );
	$linkedin_provider = new LinkedInProvider( $linkedin_client, new LinkedInTokenManager( new LinkedInOAuth( $linkedin_settings, $linkedin_vault ), $linkedin_vault, new ConnectionRepository() ) );
	$linkedin_payload = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/linkedin-post.json' ), true );
	$linkedin_post = $linkedin_provider->normalizePost( $linkedin_connection, $linkedin_payload );
	atomic_social_assert( 'urn:li:share:6876614985750310912' === $linkedin_post->external_id, 'LinkedIn fixture ID was not normalized.' );
	atomic_social_assert( 'image' === $linkedin_post->media[0]['type'] && 'Accessible fixture image' === $linkedin_post->media[0]['alt'], 'LinkedIn image metadata was not normalized.' );
	$checks[] = 'encrypted credential round trip and current LinkedIn Posts API fixture normalization';

	$hash_media_a = new NormalizedSocialPost(
		'fixture',
		'hash-connection',
		'hash-post',
		'https://example.test/hash-post',
		'Title',
		'Body',
		'Excerpt',
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
		'fixture-author',
		'Fixture Account',
		'published',
		'image',
		array(
			array(
				'type'      => 'image',
				'source_id' => 'urn:li:image:1',
				'alt'       => 'Alt',
				'url'       => 'https://example.test/signed-a.jpg?sig=aaa',
				'thumbnail' => 'https://example.test/thumb-a.jpg?sig=aaa',
			),
		)
	);
	$hash_media_b = new NormalizedSocialPost(
		'fixture',
		'hash-connection',
		'hash-post',
		'https://example.test/hash-post',
		'Title',
		'Body',
		'Excerpt',
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
		'fixture-author',
		'Fixture Account',
		'published',
		'image',
		array(
			array(
				'type'      => 'image',
				'source_id' => 'urn:li:image:1',
				'alt'       => 'Alt',
				'url'       => 'https://example.test/signed-b.jpg?sig=bbb',
				'thumbnail' => 'https://example.test/thumb-b.jpg?sig=bbb',
			),
		)
	);
	atomic_social_assert( $hash_media_a->contentHash() === $hash_media_b->contentHash(), 'Signed media URLs should not change the remote content hash.' );
	$checks[] = 'stable content hashes exclude signed media URLs';

	$test_settings = PluginSettings::defaults();
	$test_settings['import_images'] = false;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	update_option( PluginSettings::CONNECTIONS, array(), false );

	$connection_id = 'atomic-social-test-' . wp_generate_password( 8, false, false );
	$connection = new Connection( $connection_id, 'fixture', 'account-1', 'Fixture Account', Connection::STATUS_CONNECTED, array(), array(), null, null, PluginSettings::FREQUENCY_OFF );
	$connections = new ConnectionRepository();
	$connections->save( $connection );
	$created_connection_ids[] = $connection_id;
	$provider = new AtomicSocialFixtureProvider();
	$provider->posts = array(
		atomic_social_fixture_post( $connection_id, 'remote-1', 'First fixture post' ),
		atomic_social_fixture_post( $connection_id, 'remote-2', 'Second fixture post' ),
		atomic_social_fixture_post( $connection_id, 'remote-3', 'Missing fixture post' ),
	);
	$registry = new ProviderRegistry();
	$registry->register( $provider );
	$settings = new PluginSettings();
	$post_repository = new SocialPostRepository();
	$reconciliation = new ReconciliationService( $post_repository, $settings, new MediaImporter() );
	$sync = new SyncService( $registry, $connections, $post_repository, $reconciliation, $settings, new Logger( $settings ) );
	$lock_key = 'atomic_social_sync_lock_' . sanitize_key( $connection_id );
	add_option( $lock_key, time() + 60, '', false );
	$created_lock_keys[] = $lock_key;
	$locked = $sync->syncConnection( $connection_id );
	atomic_social_assert( 1 === count( $locked->errors ) && str_contains( $locked->errors[0], 'already running' ), 'Concurrent sync lock was not enforced.' );
	delete_option( $lock_key );
	$checks[] = 'expiring concurrent-sync lock';

	$sync_reflection = new ReflectionClass( SyncService::class );
	$acquire_method  = $sync_reflection->getMethod( 'acquireLock' );
	$release_method  = $sync_reflection->getMethod( 'releaseLock' );
	$acquire_method->setAccessible( true );
	$release_method->setAccessible( true );
	$lock_test_id = 'atomic-social-lock-test-' . wp_generate_password( 8, false, false );
	$lock_test_key = 'atomic_social_sync_lock_' . sanitize_key( $lock_test_id );
	atomic_social_assert( true === $acquire_method->invoke( $sync, $lock_test_id, 'owner-a' ), 'Owner A could not acquire the lock.' );
	update_option( $lock_test_key, array( 'owner' => 'owner-a', 'expires_at' => time() - 1 ), false );
	atomic_social_assert( true === $acquire_method->invoke( $sync, $lock_test_id, 'owner-b' ), 'Owner B could not take over an expired lock.' );
	$release_method->invoke( $sync, $lock_test_id, 'owner-a' );
	$lock_after_wrong_release = get_option( $lock_test_key, null );
	atomic_social_assert( is_array( $lock_after_wrong_release ) && 'owner-b' === (string) ( $lock_after_wrong_release['owner'] ?? '' ), 'A stale owner released another owner lock.' );
	$release_method->invoke( $sync, $lock_test_id, 'owner-b' );
	atomic_social_assert( false === get_option( $lock_test_key, false ), 'Owner B could not release its own lock.' );
	$checks[] = 'owner-aware sync locks';

	$first = $sync->syncConnection( $connection_id );
	atomic_social_assert( 3 === $first->created, 'First sync did not create exactly three posts.' );
	$second = $sync->syncConnection( $connection_id );
	atomic_social_assert( 0 === $second->created && 3 === $second->unchanged, 'Identical sync was not idempotent.' );
	$checks[] = 'first import and zero-duplicate repeat sync';

	foreach ( array( 'remote-1', 'remote-2', 'remote-3' ) as $external_id ) {
		$created_post_ids[] = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, $external_id )->ID;
	}
	$media_attachment_id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Atomic Social media fixture', 'post_mime_type' => 'image/jpeg' ) );
	$created_post_ids[] = $media_attachment_id;
	update_post_meta( $media_attachment_id, MetaKeys::PROVIDER, 'fixture' );
	update_post_meta( $media_attachment_id, MetaKeys::MEDIA_SOURCE_ID, 'fixture-media-1' );
	$media_post = new NormalizedSocialPost(
		'fixture', $connection_id, 'remote-1', 'https://example.test/remote-1', '', 'First fixture post', 'First fixture post',
		new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ), new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
		'fixture-author', 'Fixture Account', 'published', 'image', array( array( 'type' => 'image', 'source_id' => 'fixture-media-1', 'url' => 'https://example.test/not-downloaded.jpg', 'alt' => 'Fixture' ) )
	);
	$media_importer = new MediaImporter();
	atomic_social_assert( $media_attachment_id === $media_importer->importFeaturedImage( $created_post_ids[0], $media_post ), 'Existing source media was not reused.' );
	update_post_meta( $created_post_ids[0], MetaKeys::FEATURED_IMAGE_LOCKED, '1' );
	atomic_social_assert( null === $media_importer->importFeaturedImage( $created_post_ids[0], $media_post ), 'Featured-image editorial lock was ignored.' );
	delete_post_meta( $created_post_ids[0], MetaKeys::FEATURED_IMAGE_LOCKED );
	$checks[] = 'media source deduplication and featured-image lock';
	$provider->posts[] = atomic_social_fixture_post( $connection_id, 'remote-trashed', 'Locally trashed fixture post' );
	$sync->syncConnection( $connection_id );
	$trashed_post = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, 'remote-trashed' );
	$created_post_ids[] = $trashed_post->ID;
	wp_trash_post( $trashed_post->ID );
	$trash_repeat = $sync->syncConnection( $connection_id );
	atomic_social_assert( 0 === $trash_repeat->created && 'trash' === get_post_status( $trashed_post->ID ), 'A locally trashed remote identity was duplicated or restored.' );
	array_pop( $provider->posts );
	$checks[] = 'trashed identity remains deduplicated';

	$provider->posts[0] = atomic_social_fixture_post( $connection_id, 'remote-1', 'First fixture post remotely edited', '2026-01-02T00:00:00+00:00' );
	$edited = $sync->syncConnection( $connection_id );
	$remote_one = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, 'remote-1' );
	atomic_social_assert( $edited->updated >= 1 && str_contains( $remote_one->post_content, 'remotely edited' ), 'Safe remote edit was not applied.' );

	wp_update_post( array( 'ID' => $remote_one->ID, 'post_content' => 'Local editorial change' ) );
	$provider->posts[0] = atomic_social_fixture_post( $connection_id, 'remote-1', 'Another remote edit', '2026-01-03T00:00:00+00:00' );
	$conflicted = $sync->syncConnection( $connection_id );
	atomic_social_assert( $conflicted->conflicts >= 1 && 'conflict' === get_post_meta( $remote_one->ID, MetaKeys::SYNC_CONFLICT, true ), 'Concurrent local and remote edits did not create a conflict.' );
	atomic_social_assert( 'Local editorial change' === get_post( $remote_one->ID )->post_content, 'Conflict silently overwrote local content.' );
	$checks[] = 'safe remote edits and local/remote conflict protection';

	$remote_two = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, 'remote-2' );
	$test_settings['remote_edit_policy'] = PluginSettings::EDIT_REVIEW;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	$review_result = new SyncResult();
	$review_remote = atomic_social_fixture_post( $connection_id, 'remote-2', 'Review policy remote edit', '2026-01-02T00:00:00+00:00' );
	$reconciliation->reconcile( $review_remote, $connection, $review_result );
	atomic_social_assert( 1 === $review_result->conflicts && 'review' === get_post_meta( $remote_two->ID, MetaKeys::SYNC_CONFLICT, true ), 'Require Review policy did not hold the remote version.' );
	$reconciliation->keepWordPressVersion( $remote_two->ID );
	$test_settings['remote_edit_policy'] = PluginSettings::EDIT_IGNORE;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	$ignore_result = new SyncResult();
	$ignore_remote = atomic_social_fixture_post( $connection_id, 'remote-2', 'Ignored remote edit', '2026-01-03T00:00:00+00:00' );
	$reconciliation->reconcile( $ignore_remote, $connection, $ignore_result );
	atomic_social_assert( 1 === $ignore_result->skipped && ! str_contains( get_post( $remote_two->ID )->post_content, 'Ignored remote edit' ), 'Ignore policy changed local content.' );
	$provider->posts[1] = $ignore_remote;
	$test_settings['remote_edit_policy'] = PluginSettings::EDIT_AUTOMATIC;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	$checks[] = 'Require Review and Ignore remote-edit policies';

	update_post_meta( $remote_two->ID, MetaKeys::DETACHED, '1' );
	$provider->posts[1] = atomic_social_fixture_post( $connection_id, 'remote-2', 'Detached remote edit', '2026-01-03T00:00:00+00:00' );
	$sync->syncConnection( $connection_id );
	atomic_social_assert( ! str_contains( get_post( $remote_two->ID )->post_content, 'Detached remote edit' ), 'Detached post was changed.' );
	$checks[] = 'detach invariant';

	$remote_three = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, 'remote-3' );
	$provider->posts = array_slice( $provider->posts, 0, 2 );
	$provider->verification['remote-3'] = 'missing';
	$sync->syncConnection( $connection_id );
	atomic_social_assert( 'publish' === get_post_status( $remote_three->ID ), 'First missing verification changed post status.' );
	$sync->syncConnection( $connection_id );
	atomic_social_assert( 'publish' === get_post_status( $remote_three->ID ), 'Immediate second missing verification should not apply the deletion policy.' );
	update_post_meta( $remote_three->ID, MetaKeys::REMOTE_MISSING_LAST_CONFIRMED_AT, gmdate( 'c', time() - 901 ) );
	$sync->syncConnection( $connection_id );
	atomic_social_assert( 'draft' === get_post_status( $remote_three->ID ), 'Second independent missing verification did not apply default Draft policy.' );
	$checks[] = 'independent missing confirmations';

	$provider->posts[] = atomic_social_fixture_post( $connection_id, 'remote-4', 'Permission fixture post' );
	$sync->syncConnection( $connection_id );
	$remote_four = $post_repository->findByRemoteIdentity( 'fixture', $connection_id, 'remote-4' );
	$created_post_ids[] = $remote_four->ID;
	$provider->posts = array_slice( $provider->posts, 0, 2 );
	$provider->verification['remote-4'] = 'authorization';
	$permission_result = $sync->syncConnection( $connection_id );
	atomic_social_assert( $permission_result->errors && 'publish' === get_post_status( $remote_four->ID ), 'Permission failure triggered deletion handling.' );
	$checks[] = 'permission failure cannot delete/draft content';

	foreach ( array( PluginSettings::DELETE_KEEP => 'publish', PluginSettings::DELETE_TRASH => 'trash' ) as $delete_policy => $expected_status ) {
		$test_settings['remote_delete_policy'] = $delete_policy;
		update_option( PluginSettings::OPTION_NAME, $test_settings, false );
		$policy_connection_id = 'atomic-policy-' . $delete_policy . '-' . wp_generate_password( 5, false, false );
		$policy_connection = new Connection( $policy_connection_id, 'fixture', 'policy-account', 'Policy Account', Connection::STATUS_CONNECTED, array(), array(), null, null, PluginSettings::FREQUENCY_OFF );
		$connections->save( $policy_connection );
		$policy_provider = new AtomicSocialFixtureProvider();
		$policy_external_id = 'remote-' . $delete_policy;
		$policy_provider->posts = array( atomic_social_fixture_post( $policy_connection_id, $policy_external_id, 'Deletion policy fixture' ) );
		$policy_registry = new ProviderRegistry();
		$policy_registry->register( $policy_provider );
		$policy_sync = new SyncService( $policy_registry, $connections, $post_repository, $reconciliation, $settings, new Logger( $settings ) );
		$policy_sync->syncConnection( $policy_connection_id );
		$policy_post = $post_repository->findByRemoteIdentity( 'fixture', $policy_connection_id, $policy_external_id );
		$created_post_ids[] = $policy_post->ID;
		$created_connection_ids[] = $policy_connection_id;
		$policy_provider->posts = array();
		$policy_provider->verification[ $policy_external_id ] = 'missing';
		$policy_sync->syncConnection( $policy_connection_id );
		$policy_sync->syncConnection( $policy_connection_id );
		update_post_meta( $policy_post->ID, MetaKeys::REMOTE_MISSING_LAST_CONFIRMED_AT, gmdate( 'c', time() - 901 ) );
		$policy_sync->syncConnection( $policy_connection_id );
		atomic_social_assert( $expected_status === get_post_status( $policy_post->ID ), ucfirst( $delete_policy ) . ' deletion policy produced the wrong local status.' );
	}
	$test_settings['remote_delete_policy'] = PluginSettings::DELETE_DRAFT;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	$checks[] = 'Keep and Trash confirmed-missing policies';

	update_post_meta( $remote_two->ID, MetaKeys::HIDDEN_FROM_FEED, '1' );
	$feed_query = new FeedQuery();
	$feed_renderer = new FeedRenderer( $settings );
	$attributes = $feed_renderer->attributes( array( 'providers' => array( 'fixture' ), 'postsPerPage' => 20, 'pagination' => 'numbers' ) );
	$query = $feed_query->query( $attributes );
	$ids = wp_list_pluck( $query->posts, 'ID' );
	atomic_social_assert( ! in_array( $remote_two->ID, $ids, true ), 'Hidden post appeared in local feed.' );
	atomic_social_assert( str_contains( $feed_renderer->render( $query, $attributes ), 'atomic-social-card--fixture' ), 'Renderer omitted provider modifier class.' );
	atomic_social_assert( str_contains( do_shortcode( '[atomic_social_feed posts="20" providers="fixture"]' ), 'atomic-social-feed' ), 'Shortcode did not use the feed renderer.' );
	$routes = rest_get_server()->get_routes();
	atomic_social_assert( isset( $routes['/atomic-wp-social-sync/v1/feed'], $routes['/atomic-wp-social-sync/v1/linkedin/callback'] ), 'Expected REST routes are not registered.' );
	$rest_request = new WP_REST_Request( 'POST', '/atomic-wp-social-sync/v1/feed' );
	$rest_request->set_header( 'content-type', 'application/json' );
	$rest_request->set_body( wp_json_encode( array( 'providers' => array( 'fixture' ), 'postsPerPage' => 1, 'page' => 1 ) ) );
	$rest_data = rest_get_server()->dispatch( $rest_request )->get_data();
	atomic_social_assert( is_array( $rest_data ) && str_contains( (string) $rest_data['html'], 'atomic-social-card' ), 'Load More REST route did not render local posts.' );
	$checks[] = 'local feed, shortcode, and REST Load More render paths';

	// LinkedIn embed parsing allowlist.
	$raw_urn = LinkedInEmbed::parseInput( 'urn:li:share:7500553868422897664' );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === $raw_urn['urn'] && false === $raw_urn['is_compact'], 'Raw Share URN was not accepted.' );
	$url_urn = LinkedInEmbed::parseInput( 'https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664' );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === $url_urn['urn'] && false === $url_urn['is_compact'], 'Embed URL was not accepted.' );

	// Regression: exact official iframe example (including Markdown-style backticks) must parse.
	$official_iframe = '<iframe src="`https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664?collapsed=1`" height="603" width="504" frameborder="0" allowfullscreen="" title="Post intégré"></iframe>';
	$official_parsed = LinkedInEmbed::parseInput( $official_iframe );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === $official_parsed['urn'], 'Official iframe did not normalize to the Share URN.' );
	atomic_social_assert( true === $official_parsed['is_compact'] && 603 === $official_parsed['height'], 'Official iframe did not parse compact/height.' );

	// HTML entity decoding must work (common when copying from rich editors).
	$entity_iframe = htmlspecialchars( $official_iframe, ENT_QUOTES | ENT_HTML5 );
	$entity_parsed = LinkedInEmbed::parseInput( $entity_iframe );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === $entity_parsed['urn'], 'HTML-entity iframe did not normalize.' );

	// Numeric Share ID only must normalize.
	$id_only = LinkedInEmbed::parseInput( '7500553868422897664' );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === $id_only['urn'], 'Numeric Share ID did not normalize to the Share URN.' );

	$compact_iframe = LinkedInEmbed::parseInput( '<iframe src="https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664?collapsed=1" height="603" width="504" frameborder="0" title="Post intégré"></iframe>' );
	atomic_social_assert( true === $compact_iframe['is_compact'] && 603 === $compact_iframe['height'], 'Compact iframe was not parsed correctly.' );

	try {
		LinkedInEmbed::parseInput( 'urn:li:activity:123' );
		atomic_social_assert( false, 'Activity URNs must be rejected.' );
	} catch ( Throwable ) {
	}
	try {
		LinkedInEmbed::parseInput( '<iframe src="https://evil.example/embed/feed/update/urn:li:share:7500553868422897664" height="603"></iframe>' );
		atomic_social_assert( false, 'Arbitrary iframe domains must be rejected.' );
	} catch ( Throwable ) {
	}
	try {
		LinkedInEmbed::parseInput( '<iframe src="javascript:alert(1)" height="603"></iframe>' );
		atomic_social_assert( false, 'JavaScript iframe URLs must be rejected.' );
	} catch ( Throwable ) {
	}
	$checks[] = 'LinkedIn embed input allowlist parsing';

	// LinkedIn embed admin page workflow: allow multiple different Share IDs, reject true duplicates.
	$posts_page = new \AtomicWPSocialSync\Admin\LinkedInPostsPage();
	$ref = new ReflectionClass( $posts_page );
	$method = $ref->getMethod( 'createOrUpdateEmbedPost' );
	$method->setAccessible( true );
	$test_id_one = '9990000000000000001';
	$test_id_two = '9990000000000000002';
	$post_one = (int) $method->invoke( $posts_page, null, $test_id_one, '2026-01-03T10:00' );
	$post_two = (int) $method->invoke( $posts_page, null, $test_id_two, '2026-01-03T10:00' );
	atomic_social_assert( $post_one > 0 && $post_two > 0 && $post_one !== $post_two, 'Creating two different Share IDs must succeed.' );
	$created_post_ids[] = $post_one;
	$created_post_ids[] = $post_two;
	try {
		$method->invoke( $posts_page, null, $test_id_one, '2026-01-03T10:00' );
		atomic_social_assert( false, 'Duplicate Share ID should be rejected.' );
	} catch ( Throwable ) {
	}
	$checks[] = 'LinkedIn embed duplicates: allow different IDs, reject duplicates';

	// LinkedIn embed admin normalization (store only normalized URN/height, never raw HTML).
	$admin_user_id = wp_insert_user(
		array(
			'user_login' => 'atomic_social_test_' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'atomic-social-test-' . wp_generate_password( 8, false, false ) . '@example.test',
			'role'       => 'administrator',
		)
	);
	atomic_social_assert( is_int( $admin_user_id ) && $admin_user_id > 0, 'Admin test user could not be created.' );
	$created_user_ids[] = $admin_user_id;
	wp_set_current_user( $admin_user_id );

	$embed_post_c = wp_insert_post(
		array(
			'post_type'   => SocialPostType::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'LinkedIn Embed C',
		)
	);
	atomic_social_assert( is_int( $embed_post_c ) && $embed_post_c > 0, 'Embed post C could not be created.' );
	$created_post_ids[] = $embed_post_c;

	$_POST['atomic_social_linkedin_embed_nonce'] = wp_create_nonce( 'atomic_social_linkedin_embed_meta' );
	$_POST['atomic_social_linkedin_embed_input'] = '<iframe src="https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664?collapsed=1" height="603" width="504" frameborder="0" title="Post intégré"></iframe>';
	$_POST['atomic_social_linkedin_published_at'] = '';
	$_POST['atomic_social_linkedin_height_compact'] = '';
	$_POST['atomic_social_linkedin_height_full'] = '';

	( new LinkedInEmbedMetaBox() )->save( $embed_post_c, get_post( $embed_post_c ) );
	atomic_social_assert( IntegrationMode::EMBED === (string) get_post_meta( $embed_post_c, MetaKeys::INTEGRATION_MODE, true ), 'Embed integration_mode was not persisted.' );
	atomic_social_assert( 'urn:li:share:7500553868422897664' === (string) get_post_meta( $embed_post_c, MetaKeys::EMBED_URN, true ), 'Normalized embed URN was not persisted.' );
	atomic_social_assert( '603' === (string) get_post_meta( $embed_post_c, MetaKeys::EMBED_HEIGHT_COMPACT, true ), 'Compact iframe height was not extracted.' );

	$all_meta = get_post_meta( $embed_post_c );
	$found_iframe = false;
	foreach ( $all_meta as $values ) {
		$values = is_array( $values ) ? $values : array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && str_contains( $value, '<iframe' ) ) {
				$found_iframe = true;
			}
		}
	}
	atomic_social_assert( false === $found_iframe, 'Raw iframe HTML should not be persisted in post meta.' );
	unset(
		$_POST['atomic_social_linkedin_embed_nonce'],
		$_POST['atomic_social_linkedin_embed_input'],
		$_POST['atomic_social_linkedin_published_at'],
		$_POST['atomic_social_linkedin_height_compact'],
		$_POST['atomic_social_linkedin_height_full']
	);
	$checks[] = 'LinkedIn embed admin normalization (no raw HTML persistence)';

	// LinkedIn embed-mode rendering + anchor + News Page CTA.
	$news_page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'News',
		)
	);
	atomic_social_assert( is_int( $news_page_id ) && $news_page_id > 0, 'News page could not be created.' );
	$created_page_ids[] = $news_page_id;

	$test_settings['news_page_id'] = $news_page_id;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );

	$published_gmt = '2026-01-02 00:00:00';
	$published_iso = gmdate( DATE_ATOM, strtotime( $published_gmt ) ?: time() );

	$embed_post_a = wp_insert_post(
		array(
			'post_type'     => SocialPostType::POST_TYPE,
			'post_status'   => 'publish',
			'post_title'    => 'LinkedIn Embed A',
			'post_date_gmt' => $published_gmt,
			'post_date'     => get_date_from_gmt( $published_gmt ),
		)
	);
	$embed_post_b = wp_insert_post(
		array(
			'post_type'     => SocialPostType::POST_TYPE,
			'post_status'   => 'publish',
			'post_title'    => 'LinkedIn Embed B',
			'post_date_gmt' => $published_gmt,
			'post_date'     => get_date_from_gmt( $published_gmt ),
		)
	);
	atomic_social_assert( is_int( $embed_post_a ) && $embed_post_a > 0 && is_int( $embed_post_b ) && $embed_post_b > 0, 'Embed posts could not be created.' );
	$created_post_ids[] = $embed_post_a;
	$created_post_ids[] = $embed_post_b;

	foreach ( array( $embed_post_a, $embed_post_b ) as $pid ) {
		update_post_meta( $pid, MetaKeys::PROVIDER, 'linkedin' );
		update_post_meta( $pid, MetaKeys::INTEGRATION_MODE, IntegrationMode::EMBED );
		update_post_meta( $pid, MetaKeys::REMOTE_PUBLISHED_AT, $published_iso );
		update_post_meta( $pid, MetaKeys::SHOW_ON_HOMEPAGE, '1' );
		wp_set_object_terms( $pid, 'linkedin', SocialPostType::PROVIDER_TAXONOMY );
	}
	update_post_meta( $embed_post_a, MetaKeys::EMBED_URN, 'urn:li:share:7500553868422897664' );
	update_post_meta( $embed_post_a, MetaKeys::EMBED_HEIGHT_COMPACT, '603' );
	update_post_meta( $embed_post_a, MetaKeys::EMBED_HEIGHT_FULL, '1370' );
	update_post_meta( $embed_post_b, MetaKeys::EMBED_URN, 'urn:li:share:7500553868422897665' );

	$embed_attrs = $feed_renderer->attributes(
		array(
			'providers'        => array( 'linkedin' ),
			'postsPerPage'     => 10,
			'homepageOnly'     => true,
			'pagination'       => 'none',
			'presentation'     => 'compact',
			'showFullNewsCta'  => true,
			'fullNewsCtaLabel' => 'View full news',
		)
	);
	$embed_query = $feed_query->query( $embed_attrs );
	$embed_ids = wp_list_pluck( $embed_query->posts, 'ID' );
	atomic_social_assert( count( $embed_ids ) >= 2, 'Embed posts did not appear in the feed query.' );
	atomic_social_assert( max( $embed_post_a, $embed_post_b ) === (int) $embed_ids[0], 'Deterministic ID tie-breaker for same-date posts is not applied.' );

	$embed_html = $feed_renderer->render( $embed_query, $embed_attrs );
	atomic_social_assert( str_contains( $embed_html, 'id="atomic-linkedin-post-' . $embed_post_a . '"' ), 'Embed card anchor id is missing.' );
	atomic_social_assert( str_contains( $embed_html, 'https://www.linkedin.com/embed/feed/update/urn:li:share:7500553868422897664?collapsed=1' ), 'Compact LinkedIn embed URL was not generated.' );
	atomic_social_assert( str_contains( $embed_html, '#atomic-linkedin-post-' . $embed_post_a ), 'News CTA anchor fragment is missing.' );
	atomic_social_assert( str_contains( $embed_html, get_permalink( $news_page_id ) ), 'News CTA permalink was not used.' );
	$checks[] = 'LinkedIn embed-mode rendering + anchor + News CTA';

	Plugin::deactivate();
	atomic_social_assert( false === wp_next_scheduled( Scheduler::HOOK ), 'Deactivation did not clear the scheduler.' );
	Plugin::activate();
	atomic_social_assert( false !== wp_next_scheduled( Scheduler::HOOK ), 'Activation did not restore the scheduler.' );
	$checks[] = 'activation/deactivation scheduler lifecycle';

	echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
} finally {
	foreach ( $created_lock_keys as $lock_key ) {
		delete_option( $lock_key );
	}
	foreach ( array_unique( $created_post_ids ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	foreach ( array_unique( $created_page_ids ) as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}
	foreach ( array_unique( $created_user_ids ) as $user_id ) {
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( (int) $user_id );
		}
	}
	foreach ( array_unique( $created_connection_ids ) as $test_connection_id ) {
		$test_posts = get_posts(
			array(
				'post_type'      => SocialPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => MetaKeys::CONNECTION_ID,
				'meta_value'     => $test_connection_id,
			)
		);
		foreach ( $test_posts as $test_post ) {
			wp_delete_post( $test_post->ID, true );
		}
	}

	$provider_terms_after   = get_terms( array( 'taxonomy' => SocialPostType::PROVIDER_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );
	$connection_terms_after = get_terms( array( 'taxonomy' => SocialPostType::CONNECTION_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );
	$provider_terms_after   = is_array( $provider_terms_after ) ? $provider_terms_after : array();
	$connection_terms_after = is_array( $connection_terms_after ) ? $connection_terms_after : array();
	foreach ( array_diff( array_map( 'intval', $provider_terms_after ), array_map( 'intval', $provider_terms_backup ) ) as $term_id ) {
		wp_delete_term( $term_id, SocialPostType::PROVIDER_TAXONOMY );
	}
	foreach ( array_diff( array_map( 'intval', $connection_terms_after ), array_map( 'intval', $connection_terms_backup ) ) as $term_id ) {
		wp_delete_term( $term_id, SocialPostType::CONNECTION_TAXONOMY );
	}

	$was_scheduled = false !== $cron_backup;
	$is_scheduled  = false !== wp_next_scheduled( Scheduler::HOOK );
	if ( ! $was_scheduled && $is_scheduled ) {
		wp_clear_scheduled_hook( Scheduler::HOOK );
	}
	if ( $was_scheduled && ! $is_scheduled ) {
		wp_schedule_event( (int) $cron_backup, 'atomic_social_15_minutes', Scheduler::HOOK );
	}

	if ( null === $settings_backup ) { delete_option( PluginSettings::OPTION_NAME ); } else { update_option( PluginSettings::OPTION_NAME, $settings_backup, false ); }
	if ( null === $schema_backup ) { delete_option( PluginSettings::SCHEMA_OPTION ); } else { update_option( PluginSettings::SCHEMA_OPTION, $schema_backup, false ); }
	if ( null === $connections_backup ) { delete_option( PluginSettings::CONNECTIONS ); } else { update_option( PluginSettings::CONNECTIONS, $connections_backup, false ); }
	if ( null === $log_backup ) { delete_option( PluginSettings::SYNC_LOG ); } else { update_option( PluginSettings::SYNC_LOG, $log_backup, false ); }
	if ( null === $credentials_backup ) { delete_option( PluginSettings::CREDENTIALS ); } else { update_option( PluginSettings::CREDENTIALS, $credentials_backup, false ); }
	if ( null === $flush_rewrite_backup ) { delete_option( 'atomic_wp_social_sync_flush_rewrite' ); } else { update_option( 'atomic_wp_social_sync_flush_rewrite', $flush_rewrite_backup, false ); }
}
