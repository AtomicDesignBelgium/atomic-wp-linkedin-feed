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
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInOAuth;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInProvider;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInTokenManager;
use AtomicWPSocialSync\Support\CredentialVault;
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
$connections_backup = get_option( PluginSettings::CONNECTIONS, null );
$log_backup         = get_option( PluginSettings::SYNC_LOG, null );
$credentials_backup = get_option( PluginSettings::CREDENTIALS, null );
$created_post_ids   = array();
$checks             = array();

try {
	atomic_social_assert( post_type_exists( SocialPostType::POST_TYPE ), 'Social Posts CPT is not registered.' );
	atomic_social_assert( taxonomy_exists( SocialPostType::PROVIDER_TAXONOMY ), 'Provider taxonomy is not registered.' );
	atomic_social_assert( WP_Block_Type_Registry::get_instance()->is_registered( 'atomic-wp-social-sync/feed' ), 'Feed block is not registered.' );
	atomic_social_assert( shortcode_exists( 'atomic_social_feed' ), 'Feed shortcode is not registered.' );
	$checks[] = 'bootstrap/CPT/taxonomies/block/shortcode';

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

	$test_settings = PluginSettings::defaults();
	$test_settings['import_images'] = false;
	update_option( PluginSettings::OPTION_NAME, $test_settings, false );
	update_option( PluginSettings::CONNECTIONS, array(), false );

	$connection_id = 'atomic-social-test-' . wp_generate_password( 8, false, false );
	$connection = new Connection( $connection_id, 'fixture', 'account-1', 'Fixture Account', Connection::STATUS_CONNECTED, array(), array(), null, null, PluginSettings::FREQUENCY_OFF );
	$connections = new ConnectionRepository();
	$connections->save( $connection );
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
	add_option( 'atomic_social_sync_lock_' . sanitize_key( $connection_id ), time() + 60, '', false );
	$locked = $sync->syncConnection( $connection_id );
	atomic_social_assert( 1 === count( $locked->errors ) && str_contains( $locked->errors[0], 'already running' ), 'Concurrent sync lock was not enforced.' );
	delete_option( 'atomic_social_sync_lock_' . sanitize_key( $connection_id ) );
	$checks[] = 'expiring concurrent-sync lock';

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
	atomic_social_assert( 'draft' === get_post_status( $remote_three->ID ), 'Second missing verification did not apply default Draft policy.' );
	$checks[] = 'two-stage authoritative missing handling';

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
		$policy_provider->posts = array();
		$policy_provider->verification[ $policy_external_id ] = 'missing';
		$policy_sync->syncConnection( $policy_connection_id );
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

	Plugin::deactivate();
	atomic_social_assert( false === wp_next_scheduled( Scheduler::HOOK ), 'Deactivation did not clear the scheduler.' );
	Plugin::activate();
	atomic_social_assert( false !== wp_next_scheduled( Scheduler::HOOK ), 'Activation did not restore the scheduler.' );
	$checks[] = 'activation/deactivation scheduler lifecycle';

	echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
} finally {
	foreach ( array_unique( $created_post_ids ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	$test_posts = get_posts( array( 'post_type' => SocialPostType::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => MetaKeys::CONNECTION_ID, 'meta_value' => $connection_id ?? '' ) );
	foreach ( $test_posts as $test_post ) { wp_delete_post( $test_post->ID, true ); }
	if ( null === $settings_backup ) { delete_option( PluginSettings::OPTION_NAME ); } else { update_option( PluginSettings::OPTION_NAME, $settings_backup, false ); }
	if ( null === $connections_backup ) { delete_option( PluginSettings::CONNECTIONS ); } else { update_option( PluginSettings::CONNECTIONS, $connections_backup, false ); }
	if ( null === $log_backup ) { delete_option( PluginSettings::SYNC_LOG ); } else { update_option( PluginSettings::SYNC_LOG, $log_backup, false ); }
	if ( null === $credentials_backup ) { delete_option( PluginSettings::CREDENTIALS ); } else { update_option( PluginSettings::CREDENTIALS, $credentials_backup, false ); }
}
