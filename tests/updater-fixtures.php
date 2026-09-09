<?php
/**
 * Fixture-driven updater unit tests for GitHubReleaseUpdater.
 *
 * These tests do NOT perform any live network calls, do NOT modify the
 * database, and do NOT rely on a running WordPress installation.
 *
 * They exercise the pure-logic surface of the updater:
 *   - tag/version normalization
 *   - strict canonical ZIP asset name matching
 *   - version_compare semantics (equal, newer, older)
 *   - draft + prerelease filtering
 *   - safe handling of HTTP errors and malformed JSON
 *
 * Usage (no phpunit required):
 *   php tests/updater-fixtures.php
 *
 * The script exits with a non-zero code if any assertion fails.
 *
 * @package AtomicWPSocialSync
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/*  Global state + WordPress shims.                                    */
/* ------------------------------------------------------------------ */

$GLOBALS['__atomic_updater_wp_queue']    = array();
$GLOBALS['__atomic_updater_transients']  = array();
$GLOBALS['__atomic_updater_fail']        = 0;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return 'atomic-wp-linkedin-feed/atomic-wp-linkedin-feed.php';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		$parsed = parse_url( $url );
		if ( -1 === $component ) {
			return $parsed;
		}
		$map = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);
		$key = $map[ $component ] ?? null;
		return ( null === $key || ! is_array( $parsed ) || ! isset( $parsed[ $key ] ) )
			? null
			: $parsed[ $key ];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration ): bool {
		$GLOBALS['__atomic_updater_transients'][ $transient ] = array(
			'value'   => $value,
			'expires' => time() + $expiration,
		);
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		$stored = $GLOBALS['__atomic_updater_transients'][ $transient ] ?? null;
		if ( ! is_array( $stored ) ) {
			return false;
		}
		if ( (int) ( $stored['expires'] ?? 0 ) < time() ) {
			unset( $GLOBALS['__atomic_updater_transients'][ $transient ] );
			return false;
		}
		return $stored['value'];
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['__atomic_updater_transients'][ $transient ] );
		return true;
	}
}

final class AtomicWPFixtureError {
	private string $message;
	public function __construct( string $message = '' ) { $this->message = $message; }
	public function get_error_message(): string { return $this->message; }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$queue = $GLOBALS['__atomic_updater_wp_queue'];
		if ( empty( $queue ) ) {
			return new AtomicWPFixtureError( 'no fixture response for ' . $url );
		}
		$next = array_shift( $GLOBALS['__atomic_updater_wp_queue'] );
		return is_callable( $next ) ? $next( $url, $args ) : $next;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof AtomicWPFixtureError;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, $timestamp ?? time() );
	}
}

/* ------------------------------------------------------------------ */
/*  Load the updater class (its own namespace) *after* the shims.     */
/* ------------------------------------------------------------------ */

use AtomicWPSocialSync\Update\GitHubReleaseUpdater;

require_once __DIR__ . '/../src/Update/GitHubReleaseUpdater.php';

/* ------------------------------------------------------------------ */
/*  Fixture helpers + harness.                                         */
/* ------------------------------------------------------------------ */

function atomic_fixture_release(
	string $tag,
	string $asset_version,
	bool $draft = false,
	bool $prerelease = false,
	bool $include_asset = true
): array {
	$asset_name = 'atomic-wp-linkedin-feed-v' . $asset_version . '.zip';
	$body       = array(
		'tag_name'     => $tag,
		'html_url'     => 'https://github.com/AtomicDesignBelgium/atomic-wp-linkedin-feed/releases/tag/' . $tag,
		'published_at' => '2025-01-01T00:00:00Z',
		'body'         => "Release $tag",
		'draft'        => $draft,
		'prerelease'   => $prerelease,
		'assets'       => array(),
	);
	if ( $include_asset ) {
		$body['assets'][] = array(
			'name'                 => $asset_name,
			'browser_download_url' => 'https://github.com/AtomicDesignBelgium/atomic-wp-linkedin-feed/releases/download/' . $tag . '/' . $asset_name,
		);
	}
	return $body;
}

function atomic_queue_response( array $body, int $code = 200 ): void {
	$GLOBALS['__atomic_updater_wp_queue'][] = array(
		'response' => array( 'code' => $code ),
		'body'     => json_encode( $body ),
	);
}

function atomic_queue_raw( string $body, int $code = 200 ): void {
	$GLOBALS['__atomic_updater_wp_queue'][] = array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
	);
}

function atomic_queue_error( string $message = 'network down' ): void {
	$GLOBALS['__atomic_updater_wp_queue'][] = new AtomicWPFixtureError( $message );
}

function atomic_fresh_updater( string $installed ): GitHubReleaseUpdater {
	$GLOBALS['__atomic_updater_transients'] = array();
	$GLOBALS['__atomic_updater_wp_queue']   = array();
	return new GitHubReleaseUpdater(
		'/var/www/wp-content/plugins/atomic-wp-linkedin-feed/atomic-wp-linkedin-feed.php',
		$installed,
		'https://github.com/AtomicDesignBelgium/atomic-wp-linkedin-feed'
	);
}

function atomic_assert( $expected, $actual, string $message ): void {
	if ( $expected === $actual ) {
		echo "  [PASS] $message\n";
		return;
	}
	++$GLOBALS['__atomic_updater_fail'];
	echo "  [FAIL] $message\n";
	echo '         expected: ' . var_export( $expected, true ) . "\n";
	echo '         actual:   ' . var_export( $actual, true ) . "\n";
}

/* ------------------------------------------------------------------ */
/*  RUN                                                                */
/* ------------------------------------------------------------------ */

echo "=== GitHubReleaseUpdater fixture tests ===\n\n";

// --- normalizeVersion -----------------------------------------------------
echo "[normalizeVersion]\n";
$up = atomic_fresh_updater( '0.10.0' );
atomic_assert( '0.11.0',  $up->normalizeVersion( 'v0.11.0' ),   'strips leading v' );
atomic_assert( '0.11.0',  $up->normalizeVersion( '0.11.0' ),    'no-op for bare semver' );
atomic_assert( '',        $up->normalizeVersion( 'garbage' ),   'rejects garbage' );
atomic_assert( '',        $up->normalizeVersion( '' ),          'rejects empty' );
atomic_assert( '0.11.1',  $up->normalizeVersion( " v0.11.1 " ), 'trims whitespace' );
echo "\n";

// --- 1. installed == remote → NO UPDATE ----------------------------------
echo "[case 1] installed 0.11.0 == remote 0.11.0 → NO UPDATE\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.0', '0.11.0' ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( '0.11.0', is_array( $info ) ? $info['version'] : null, 'remote version parsed' );
atomic_assert( true, is_array( $info ) ? $info['asset_found'] : null, 'exact asset found' );
atomic_assert( false, version_compare( (string) $info['version'], '0.11.0', '>' ), 'remote not newer' );
echo "\n";

// --- 2. 0.10.0 → 0.11.0 → UPDATE ------------------------------------------
echo "[case 2] installed 0.10.0 < remote 0.11.0 → UPDATE\n";
$up = atomic_fresh_updater( '0.10.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.0', '0.11.0' ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( true, is_array( $info ) ? $info['asset_found'] : null, 'exact asset found' );
atomic_assert( true, version_compare( (string) $info['version'], '0.10.0', '>' ), 'remote is newer' );
atomic_assert(
	'atomic-wp-linkedin-feed-v0.11.0.zip',
	basename( (string) $info['package'] ),
	'package URL ends with exact canonical asset'
);
echo "\n";

// --- 3. 0.11.0 → 0.11.1 → UPDATE ------------------------------------------
echo "[case 3] installed 0.11.0 < remote 0.11.1 → UPDATE\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.1', '0.11.1' ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( true, is_array( $info ) ? $info['asset_found'] : null, 'exact 0.11.1 asset found' );
atomic_assert( true, version_compare( (string) $info['version'], '0.11.0', '>' ), '0.11.1 > 0.11.0' );
echo "\n";

// --- 4. newer remote but exact ZIP missing → NO UPDATE --------------------
echo "[case 4] newer remote but exact ZIP missing → NO UPDATE\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.1', '0.11.1', false, false, false ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( '0.11.1', is_array( $info ) ? $info['version'] : null, 'tag still parsed' );
atomic_assert( false, is_array( $info ) ? $info['asset_found'] : null, 'asset_found=false' );
atomic_assert( '', is_array( $info ) ? $info['package'] : null, 'package empty' );
echo "\n";

// --- 5. HTTP error → safe null --------------------------------------------
echo "[case 5] GitHub HTTP error → safe no-op (null)\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_error( 'TLS handshake timeout' );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( null, $info, 'returns null safely' );
echo "\n";

// --- 6. draft → ignored ---------------------------------------------------
echo "[case 6] draft release → ignored\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.1', '0.11.1', true, false ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( null, $info, 'draft returns null' );
echo "\n";

// --- 7. prerelease → ignored ----------------------------------------------
echo "[case 7] prerelease release → ignored\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.1-rc.1', '0.11.1-rc.1', false, true ) );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( null, $info, 'prerelease returns null' );
echo "\n";

// --- bonus A. malformed JSON → null ---------------------------------------
echo "[bonus A] malformed JSON → null\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_raw( '{not json at all !!!', 200 );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( null, $info, 'invalid JSON returns null' );
echo "\n";

// --- bonus B. 404 → null --------------------------------------------------
echo "[bonus B] 404 response → null\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( array( 'message' => 'Not Found' ), 404 );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( null, $info, 'non-200 returns null' );
echo "\n";

// --- bonus C. wrong asset names → asset_found=false -----------------------
echo "[bonus C] wrong asset name (not exact) → asset_found=false\n";
$up = atomic_fresh_updater( '0.11.0' );
$payload = atomic_fixture_release( 'v0.11.1', '0.11.1', false, false, false );
$payload['assets'][] = array(
	'name'                 => 'atomic-wp-linkedin-feed-0.11.1.zip',
	'browser_download_url' => 'https://example.com/almost.zip',
);
$payload['assets'][] = array(
	'name'                 => 'Source code (zip)',
	'browser_download_url' => 'https://example.com/source.zip',
);
atomic_queue_response( $payload );
$info = $up->fetchLatestReleaseInfo();
atomic_assert( false, is_array( $info ) ? $info['asset_found'] : null, 'non-exact names rejected' );
atomic_assert( '', is_array( $info ) ? $info['package'] : null, 'package stays empty' );
echo "\n";

// --- bonus D. transient cache prevents 2nd HTTP ---------------------------
echo "[bonus D] transient cache → second fetch issues no HTTP\n";
$up = atomic_fresh_updater( '0.11.0' );
atomic_queue_response( atomic_fixture_release( 'v0.11.0', '0.11.0' ) );
$first  = $up->fetchLatestReleaseInfo();
$second = $up->fetchLatestReleaseInfo();
atomic_assert( true, is_array( $first ) && is_array( $second ), 'both calls return data' );
atomic_assert( (string) $first['version'], (string) $second['version'], 'cache matches' );
atomic_assert( 0, count( $GLOBALS['__atomic_updater_wp_queue'] ), 'queue empty after 1 call' );
echo "\n";

/* ---------- Summary ---------- */

$fails = (int) ( $GLOBALS['__atomic_updater_fail'] ?? 0 );
echo '=== Result: ' . ( 0 === $fails ? 'ALL PASS' : "$fails FAIL(S)" ) . " ===\n";
exit( $fails > 0 ? 1 : 0 );
