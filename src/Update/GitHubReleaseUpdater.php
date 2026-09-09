<?php
/**
 * Native WordPress plugin updater backed by public GitHub Releases.
 *
 * No credentials, tokens, or external infrastructure required.
 * Uses only the anonymous, public GitHub Releases REST API.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Update;

/**
 * GitHub Release updater for Atomic LinkedIn Feed.
 *
 * Design invariants:
 *   - Anonymous public GitHub API only.
 *   - Canonical asset name MUST match `atomic-wp-linkedin-feed-v{VERSION}.zip`.
 *   - Draft and prerelease releases are silently ignored.
 *   - API responses are cached in a WordPress transient for 6 hours.
 *   - Any failure (HTTP, JSON, missing asset, bad version) → safe no-op.
 *   - Native `update_plugins_{host}` + `plugins_api` + auto-update compatible.
 */
final class GitHubReleaseUpdater {

	private const GITHUB_OWNER = 'AtomicDesignBelgium';
	private const GITHUB_REPO  = 'atomic-wp-linkedin-feed';
	private const ASSET_PREFIX = 'atomic-wp-linkedin-feed-v';
	private const ASSET_SUFFIX = '.zip';
	private const TRANSIENT_KEY = 'atomic_social_github_release_meta';
	private const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;
	private const HTTP_TIMEOUT  = 15;

	private string $plugin_file;
	private string $plugin_basename;
	private string $installed_version;
	private string $update_host;

	/**
	 * @param string $plugin_file       Absolute path to the main plugin file.
	 * @param string $installed_version Currently installed plugin version.
	 * @param string $update_uri        Value of the `Update URI` plugin header.
	 */
	public function __construct( string $plugin_file, string $installed_version, string $update_uri ) {
		$this->plugin_file       = $plugin_file;
		$this->plugin_basename   = plugin_basename( $plugin_file );
		$this->installed_version = $installed_version;
		$parsed_host             = wp_parse_url( $update_uri, PHP_URL_HOST );
		$this->update_host       = is_string( $parsed_host ) ? $parsed_host : 'github.com';
	}

	/**
	 * Wire the updater into the WordPress native plugin update mechanism.
	 *
	 * Hooks are only meaningful on the admin side; registration is guarded by
	 * the caller via is_admin().
	 */
	public function registerHooks(): void {
		add_filter( 'update_plugins_' . $this->update_host, array( $this, 'filterPluginUpdate' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filterPluginsApi' ), 10, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'backfillSiteTransient' ) );
	}

	/**
	 * Handle WordPress 5.8+ `Update URI` update check.
	 *
	 * @param false|object $update      Update data produced by WordPress core.
	 * @param array        $plugin_data Plugin header data.
	 * @param string       $plugin_file Absolute path to the main plugin file.
	 * @param array        $locales     Installed locales.
	 * @return false|object Update object on match; false otherwise (allow core default).
	 */
	public function filterPluginUpdate( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== $this->plugin_basename ) {
			return $update;
		}
		return $this->buildUpdatePayload() ?: $update;
	}

	/**
	 * Populate the "View version details" thickbox via `plugins_api`.
	 *
	 * @param false|object $result Original result (false = delegate to dot org).
	 * @param string       $action `query_plugins`, `plugin_information`, ...
	 * @param object       $args   Query arguments.
	 * @return false|object Plugin info object, or original false on mismatch.
	 */
	public function filterPluginsApi( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( $args->slug !== $this->pluginSlug() ) {
			return $result;
		}
		$info = $this->fetchLatestReleaseInfo();
		if ( ! is_array( $info ) ) {
			return $result;
		}
		return (object) array(
			'name'           => 'Atomic LinkedIn Feed',
			'slug'           => $this->pluginSlug(),
			'version'        => $info['version'],
			'author'         => '<a href="https://atomic-design.be/">Bernard Coubeaux</a>',
			'author_profile' => 'https://atomic-design.be/',
			'homepage'       => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'requires'       => '6.0',
			'requires_php'   => '8.1',
			'downloaded'     => 0,
			'last_updated'   => $info['published_at'] ?? '',
			'sections'       => array(
				'description'  => 'Manage a local LinkedIn feed based on manually selected official LinkedIn embeds.',
				'changelog'    => $this->formatChangelog( $info['body'] ?? '' ),
			),
			'download_link'  => $info['package'] ?? '',
			'banners'        => array(),
		);
	}

	/**
	 * Backfill `site_transient_update_plugins` for older WordPress installs
	 * or flows that bypass `update_plugins_{host}`.
	 *
	 * @param mixed $transient Current update_plugins transient value.
	 * @return mixed
	 */
	public function backfillSiteTransient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		if ( isset( $transient->response[ $this->plugin_basename ] ) || isset( $transient->no_update[ $this->plugin_basename ] ) ) {
			return $transient;
		}
		$payload = $this->buildUpdatePayload();
		if ( is_object( $payload ) && isset( $payload->new_version ) && version_compare( (string) $payload->new_version, $this->installed_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = $payload;
		} else {
			$no_update = (object) array(
				'id'            => 'github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
				'slug'          => $this->pluginSlug(),
				'plugin'        => $this->plugin_basename,
				'new_version'   => $this->installed_version,
				'url'           => $this->repoUrl(),
				'package'       => '',
				'requires'      => '6.0',
				'requires_php'  => '8.1',
			);
			$transient->no_update[ $this->plugin_basename ] = $no_update;
		}
		return $transient;
	}

	/**
	 * Low-level helper: fetch the latest *stable* release with the exact asset.
	 *
	 * Results are cached in a transient for TRANSIENT_TTL seconds.
	 *
	 * @return array{version:string,tag:string,package:string,html_url:string,published_at:string,body:string,asset_found:bool,last_check:int}|null
	 */
	public function fetchLatestReleaseInfo(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_url = 'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/releases/latest';
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => 'AtomicWPSocialSync-Updater/' . $this->installed_version . '; +' . $this->repoUrl(),
				'headers'    => array(
					'Accept' => 'application/vnd.github+json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}
		/** @var mixed $data */
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$tag     = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
		$version = $this->normalizeVersion( $tag );
		if ( '' === $version ) {
			return null;
		}

		$expected_asset = self::ASSET_PREFIX . $version . self::ASSET_SUFFIX;
		$package        = '';
		$asset_found    = false;
		$assets         = is_array( $data['assets'] ?? null ) ? $data['assets'] : array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( $name === $expected_asset && ! empty( $asset['browser_download_url'] ) ) {
				$package     = (string) $asset['browser_download_url'];
				$asset_found = true;
				break;
			}
		}

		$normalized = array(
			'version'      => $version,
			'tag'          => $tag,
			'package'      => $package,
			'html_url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : $this->repoUrl() . '/releases/tag/' . $tag,
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
			'asset_found'  => $asset_found,
			'last_check'   => time(),
		);

		set_transient( self::TRANSIENT_KEY, $normalized, self::TRANSIENT_TTL );
		return $normalized;
	}

	/**
	 * Explicit transient bypass + refresh (used by developer diagnostics / forced check).
	 *
	 * @return array|null See {@see fetchLatestReleaseInfo()}.
	 */
	public function forceRefreshReleaseInfo(): ?array {
		delete_transient( self::TRANSIENT_KEY );
		return $this->fetchLatestReleaseInfo();
	}

	/**
	 * Build the payload injected into the WordPress update system.
	 *
	 * Returns null when the remote is not strictly newer or the asset is missing.
	 *
	 * @return object|null
	 */
	private function buildUpdatePayload(): ?object {
		$info = $this->fetchLatestReleaseInfo();
		if ( ! is_array( $info ) ) {
			return null;
		}
		if ( empty( $info['asset_found'] ) || '' === $info['package'] ) {
			return null;
		}
		if ( version_compare( $info['version'], $this->installed_version, '<=' ) ) {
			return null;
		}
		return (object) array(
			'id'           => 'github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'slug'         => $this->pluginSlug(),
			'plugin'       => $this->plugin_basename,
			'new_version'  => $info['version'],
			'url'          => $info['html_url'],
			'package'      => $info['package'],
			'requires'     => '6.0',
			'requires_php' => '8.1',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Strip the optional leading `v` from tags so version_compare works.
	 */
	public function normalizeVersion( string $tag ): string {
		$trimmed = ltrim( trim( $tag ), "v \t\n\r\0\x0B" );
		return preg_match( '/^\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9.-]+)?$/', $trimmed ) ? $trimmed : '';
	}

	/**
	 * Plugin slug (directory name) used by WordPress update/info arrays.
	 */
	private function pluginSlug(): string {
		return dirname( $this->plugin_basename );
	}

	/**
	 * Public repository URL.
	 */
	private function repoUrl(): string {
		return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
	}

	/**
	 * Very light-weight GitHub release-note → HTML renderer for the details modal.
	 */
	private function formatChangelog( string $body ): string {
		if ( '' === trim( $body ) ) {
			return '<p><em>No release notes provided.</em></p>';
		}
		$escaped = esc_html( $body );
		return '<pre style="white-space:pre-wrap;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.5">' . $escaped . '</pre>';
	}
}
