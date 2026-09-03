<?php
/**
 * Settings, OAuth onboarding, and connection administration.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Connections\Connection;
use AtomicWPSocialSync\Connections\ConnectionRepository;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInClient;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInOAuth;
use AtomicWPSocialSync\Providers\ProviderRegistry;
use AtomicWPSocialSync\Support\CredentialVault;
use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\Sync\SyncService;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsPage {
	public const SLUG = 'atomic-wp-social-sync';
	public const PENDING_CLEANUP_HOOK = 'atomic_social_cleanup_pending_credentials';

	public function __construct(
		private readonly PluginSettings $settings,
		private readonly CredentialVault $vault,
		private readonly LinkedInOAuth $oauth,
		private readonly LinkedInClient $linkedin_client,
		private readonly ConnectionRepository $connections,
		private readonly ProviderRegistry $providers,
		private readonly SyncService $sync_service
	) {}

	public function registerMenu(): void {
		add_options_page(
			__( 'Atomic WP Social Sync', 'atomic-wp-social-sync' ),
			__( 'Atomic WP Social Sync', 'atomic-wp-social-sync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function registerRoutes(): void {
		register_rest_route(
			'atomic-wp-social-sync/v1',
			'/linkedin/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'oauthCallback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function saveSettings(): void {
		$this->authorizeAction( 'atomic_social_save_settings' );
		$old_settings = $this->settings->all();
		$input        = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$new_settings = PluginSettings::sanitize( $input );
		update_option( PluginSettings::OPTION_NAME, $new_settings, false );
		$client_secret = trim( (string) wp_unslash( $_POST['linkedin_client_secret'] ?? '' ) );
		if ( '' !== $client_secret ) {
			$this->oauth->saveClientSecret( $client_secret );
		}
		if ( (bool) $old_settings['enable_single_pages'] !== (bool) $new_settings['enable_single_pages'] ) {
			update_option( 'atomic_wp_social_sync_flush_rewrite', '1', false );
		}
		$this->redirect( 'settings_saved' );
	}

	public function connect(): void {
		$this->authorizeAction( 'atomic_social_connect' );
		try {
			$connection_id = sanitize_text_field( (string) wp_unslash( $_POST['connection_id'] ?? '' ) );
			if ( '' !== $connection_id && null === $this->connections->find( $connection_id ) ) {
				throw new \RuntimeException( __( 'Connection was not found.', 'atomic-wp-social-sync' ) );
			}
			wp_redirect( $this->oauth->authorizationUrl( get_current_user_id(), $connection_id ?: null ) );
			exit;
		} catch ( Throwable $exception ) {
			$this->redirect( 'error', $exception->getMessage() );
		}
	}

	public function oauthCallback( WP_REST_Request $request ): WP_REST_Response {
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		if ( $request->get_param( 'error' ) ) {
			$this->redirect( 'error', __( 'LinkedIn authorization was cancelled or denied.', 'atomic-wp-social-sync' ) );
		}
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		try {
			$token_data = $this->oauth->exchangeCode( $code, $state );
			$user_id    = (int) $token_data['oauth_user_id'];
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				throw new \RuntimeException( __( 'The authorizing WordPress user is not allowed to manage settings.', 'atomic-wp-social-sync' ) );
			}
			$organizations = $this->linkedin_client->discoverOrganizations( (string) $token_data['access_token'] );
			if ( ! $organizations ) {
				throw new \RuntimeException( __( 'No approved LinkedIn Page roles were found for this member.', 'atomic-wp-social-sync' ) );
			}

			$pending_id = wp_generate_uuid4();
			$this->vault->put( 'linkedin_pending_access_' . $pending_id, (string) $token_data['access_token'] );
			if ( ! empty( $token_data['refresh_token'] ) ) {
				$this->vault->put( 'linkedin_pending_refresh_' . $pending_id, (string) $token_data['refresh_token'] );
			}
			set_transient(
				'atomic_social_pending_' . $pending_id,
				array(
					'user_id'                  => $user_id,
					'organizations'            => $organizations,
					'expires_in'               => (int) ( $token_data['expires_in'] ?? 0 ),
					'refresh_token_expires_in' => (int) ( $token_data['refresh_token_expires_in'] ?? 0 ),
					'scope'                    => sanitize_text_field( (string) ( $token_data['scope'] ?? '' ) ),
					'has_refresh_token'        => ! empty( $token_data['refresh_token'] ),
					'reconnect_connection_id'  => sanitize_text_field( (string) ( $token_data['reconnect_connection_id'] ?? '' ) ),
				),
				15 * MINUTE_IN_SECONDS
			);
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::PENDING_CLEANUP_HOOK, array( $pending_id ) );
			wp_safe_redirect( $this->pageUrl( array( 'pending' => $pending_id ) ) );
			exit;
		} catch ( Throwable $exception ) {
			$this->redirect( 'error', $exception->getMessage() );
		}
	}

	public function selectOrganization(): void {
		$this->authorizeAction( 'atomic_social_select_organization' );
		$pending_id = sanitize_text_field( (string) wp_unslash( $_POST['pending_id'] ?? '' ) );
		$pending    = get_transient( 'atomic_social_pending_' . $pending_id );
		if ( ! is_array( $pending ) || (int) $pending['user_id'] !== get_current_user_id() ) {
			$this->redirect( 'error', __( 'The LinkedIn setup session expired. Please connect again.', 'atomic-wp-social-sync' ) );
		}
		$organization_id = sanitize_text_field( (string) wp_unslash( $_POST['organization_id'] ?? '' ) );
		$organization    = null;
		foreach ( $pending['organizations'] as $candidate ) {
			if ( is_array( $candidate ) && hash_equals( (string) $candidate['id'], $organization_id ) ) {
				$organization = $candidate;
				break;
			}
		}
		if ( null === $organization ) {
			$this->redirect( 'error', __( 'Select a valid LinkedIn Page.', 'atomic-wp-social-sync' ) );
		}

		$existing_connection = ! empty( $pending['reconnect_connection_id'] ) ? $this->connections->find( (string) $pending['reconnect_connection_id'] ) : null;
		$connection_id = $existing_connection ? $existing_connection->id : wp_generate_uuid4();
		$access_token  = $this->vault->get( 'linkedin_pending_access_' . $pending_id );
		if ( null === $access_token ) {
			$this->redirect( 'error', __( 'The temporary LinkedIn token expired.', 'atomic-wp-social-sync' ) );
		}
		$this->vault->put( LinkedInClient::accessTokenKey( $connection_id ), $access_token );
		$refresh_token = $this->vault->get( 'linkedin_pending_refresh_' . $pending_id );
		if ( null !== $refresh_token ) {
			$this->vault->put( LinkedInClient::refreshTokenKey( $connection_id ), $refresh_token );
		} else {
			$this->vault->delete( LinkedInClient::refreshTokenKey( $connection_id ) );
		}
		$scopes = array_values( array_filter( explode( ' ', (string) $pending['scope'] ) ) );
		$capabilities = $this->providers->get( 'linkedin' )->capabilities()->toArray();
		$capabilities['can_refresh_token'] = null !== $refresh_token;
		$capabilities['can_import_media']  = in_array( 'w_organization_social', $scopes, true );
		$now = time();
		$new_connection = new Connection(
				$connection_id,
				'linkedin',
				$organization_id,
				(string) $organization['name'],
				Connection::STATUS_CONNECTED,
				$scopes,
				$capabilities,
				$now + (int) $pending['expires_in'],
				! empty( $pending['refresh_token_expires_in'] ) ? $now + (int) $pending['refresh_token_expires_in'] : null,
				$existing_connection?->sync_frequency ?? (string) $this->settings->get( 'default_sync_frequency', PluginSettings::FREQUENCY_TWICE ),
				$existing_connection?->last_sync_at,
				$now,
				null,
				$existing_connection?->diagnostics ?? array()
			);
		$this->connections->save( $new_connection );
		$this->deletePendingCredentials( $pending_id );
		wp_clear_scheduled_hook( self::PENDING_CLEANUP_HOOK, array( $pending_id ) );
		delete_transient( 'atomic_social_pending_' . $pending_id );
		$this->redirect( 'connected' );
	}

	public function connectionAction(): void {
		$this->authorizeAction( 'atomic_social_connection_action' );
		$connection_id = sanitize_text_field( (string) wp_unslash( $_POST['connection_id'] ?? '' ) );
		$operation     = sanitize_key( (string) wp_unslash( $_POST['operation'] ?? '' ) );
		$connection    = $this->connections->find( $connection_id );
		if ( null === $connection ) {
			$this->redirect( 'error', __( 'Connection was not found.', 'atomic-wp-social-sync' ) );
		}

		try {
			if ( 'disconnect' === $operation ) {
				$this->vault->delete( LinkedInClient::accessTokenKey( $connection_id ) );
				$this->vault->delete( LinkedInClient::refreshTokenKey( $connection_id ) );
				$this->connections->delete( $connection_id );
				$this->redirect( 'disconnected' );
			}
			if ( 'test' === $operation ) {
				$this->providers->get( $connection->provider )->testConnection( $connection );
				$this->redirect( 'connection_ok' );
			}
			if ( 'sync' === $operation ) {
				$result = $this->sync_service->syncConnection( $connection_id );
				$this->redirect( $result->errors ? 'sync_errors' : 'sync_complete', implode( ' ', $result->errors ) );
			}
			if ( 'frequency' === $operation ) {
				$frequency = sanitize_key( (string) wp_unslash( $_POST['sync_frequency'] ?? '' ) );
				if ( ! array_key_exists( $frequency, PluginSettings::frequencies() ) ) {
					throw new \RuntimeException( __( 'Invalid sync frequency.', 'atomic-wp-social-sync' ) );
				}
				$this->connections->save( $connection->with( array( 'sync_frequency' => $frequency, 'next_sync_at' => time() ) ) );
				$this->redirect( 'connection_updated' );
			}
		} catch ( Throwable $exception ) {
			$this->redirect( 'error', $exception->getMessage() );
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atomic WP Social Sync', 'atomic-wp-social-sync' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<p><?php esc_html_e( 'Imports provider content into local WordPress Social Posts. Frontend feeds never contact LinkedIn.', 'atomic-wp-social-sync' ); ?></p>

			<h2><?php esc_html_e( 'Global defaults', 'atomic-wp-social-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atomic_social_save_settings">
				<?php wp_nonce_field( 'atomic_social_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<?php $this->selectRow( 'default_sync_frequency', __( 'Default sync frequency', 'atomic-wp-social-sync' ), PluginSettings::frequencies(), (string) $settings['default_sync_frequency'] ); ?>
					<?php $this->selectRow( 'remote_edit_policy', __( 'Remote edit policy', 'atomic-wp-social-sync' ), PluginSettings::editPolicies(), (string) $settings['remote_edit_policy'] ); ?>
					<?php $this->selectRow( 'remote_delete_policy', __( 'Remote deletion policy', 'atomic-wp-social-sync' ), PluginSettings::deletePolicies(), (string) $settings['remote_delete_policy'] ); ?>
					<tr><th><?php esc_html_e( 'Content', 'atomic-wp-social-sync' ); ?></th><td>
						<label><input type="checkbox" name="settings[import_images]" value="1" <?php checked( $settings['import_images'] ); ?>> <?php esc_html_e( 'Import images locally', 'atomic-wp-social-sync' ); ?></label><br>
						<label><input type="checkbox" name="settings[enable_single_pages]" value="1" <?php checked( $settings['enable_single_pages'] ); ?>> <?php esc_html_e( 'Enable individual Social Post pages', 'atomic-wp-social-sync' ); ?></label><br>
						<label><input type="checkbox" name="settings[debug_logging]" value="1" <?php checked( $settings['debug_logging'] ); ?>> <?php esc_html_e( 'Enable safe debug logging', 'atomic-wp-social-sync' ); ?></label>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'LinkedIn application', 'atomic-wp-social-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="linkedin-client-id"><?php esc_html_e( 'Client ID', 'atomic-wp-social-sync' ); ?></label></th><td><input class="regular-text" id="linkedin-client-id" name="settings[linkedin_client_id]" value="<?php echo esc_attr( (string) $settings['linkedin_client_id'] ); ?>"></td></tr>
					<tr><th><label for="linkedin-client-secret"><?php esc_html_e( 'Client Secret', 'atomic-wp-social-sync' ); ?></label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="linkedin-client-secret" name="linkedin_client_secret" value="" placeholder="<?php echo esc_attr( $this->oauth->hasClientSecret() ? __( 'Saved securely — leave blank to keep', 'atomic-wp-social-sync' ) : '' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'OAuth callback URL', 'atomic-wp-social-sync' ); ?></th><td><code id="atomic-social-callback"><?php echo esc_html( $this->oauth->callbackUrl() ); ?></code> <button class="button" type="button" id="atomic-social-copy-callback"><?php esc_html_e( 'Copy', 'atomic-wp-social-sync' ); ?></button><?php if ( ! str_starts_with( $this->oauth->callbackUrl(), 'https://' ) ) : ?><p class="description notice notice-warning inline"><?php esc_html_e( 'LinkedIn requires an HTTPS callback URL. Enable Local SSL before connecting.', 'atomic-wp-social-sync' ); ?></p><?php endif; ?></td></tr>
			</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connections', 'atomic-wp-social-sync' ); ?></h2>
			<?php $this->renderPendingSelection(); ?>
			<?php $this->renderConnections(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atomic_social_connect">
				<?php wp_nonce_field( 'atomic_social_connect' ); ?>
				<?php submit_button( __( 'Connect with LinkedIn', 'atomic-wp-social-sync' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<script>document.getElementById('atomic-social-copy-callback')?.addEventListener('click',function(){navigator.clipboard.writeText(document.getElementById('atomic-social-callback').textContent);});</script>
		<?php
	}

	private function renderConnections(): void {
		$connections = $this->connections->all();
		if ( ! $connections ) {
			echo '<p>' . esc_html__( 'No sources connected yet.', 'atomic-wp-social-sync' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Account', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Status', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Frequency', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Last sync', 'atomic-wp-social-sync' ) . '</th><th>' . esc_html__( 'Actions', 'atomic-wp-social-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $connections as $connection ) {
			echo '<tr><td>' . esc_html( $this->providers->get( $connection->provider )->label() ) . '</td><td>' . esc_html( $connection->account_name ) . '</td><td>' . esc_html( $this->statusLabel( $connection->status ) ) . '</td><td>';
			$this->connectionFrequencyForm( $connection );
			echo '</td><td>' . esc_html( $connection->last_sync_at ? wp_date( 'Y-m-d H:i', $connection->last_sync_at ) : __( 'Never', 'atomic-wp-social-sync' ) ) . '</td><td>';
			foreach ( array( 'sync' => __( 'Sync Now', 'atomic-wp-social-sync' ), 'test' => __( 'Test Connection', 'atomic-wp-social-sync' ), 'disconnect' => __( 'Disconnect', 'atomic-wp-social-sync' ) ) as $operation => $label ) {
				$this->connectionButton( $connection->id, $operation, $label );
			}
			$this->reconnectButton( $connection->id );
			echo '</td></tr>';
			if ( $connection->diagnostics || $connection->last_error ) {
				echo '<tr><td colspan="6"><small>' . esc_html( $this->diagnosticSummary( $connection ) ) . '</small></td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	private function renderPendingSelection(): void {
		$pending_id = sanitize_text_field( (string) wp_unslash( $_GET['pending'] ?? '' ) );
		$pending    = $pending_id ? get_transient( 'atomic_social_pending_' . $pending_id ) : false;
		if ( ! is_array( $pending ) || (int) $pending['user_id'] !== get_current_user_id() ) {
			return;
		}
		?>
		<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Select a LinkedIn Page to connect', 'atomic-wp-social-sync' ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="atomic_social_select_organization"><input type="hidden" name="pending_id" value="<?php echo esc_attr( $pending_id ); ?>">
			<?php wp_nonce_field( 'atomic_social_select_organization' ); ?>
			<select name="organization_id"><?php foreach ( $pending['organizations'] as $organization ) : ?><option value="<?php echo esc_attr( (string) $organization['id'] ); ?>"><?php echo esc_html( (string) $organization['name'] ); ?></option><?php endforeach; ?></select>
			<?php submit_button( __( 'Add Connection', 'atomic-wp-social-sync' ), 'primary', 'submit', false ); ?>
		</form></div>
		<?php
	}

	/** @param array<string,string> $options */
	private function selectRow( string $key, string $label, array $options, string $selected ): void {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function connectionFrequencyForm( Connection $connection ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="atomic_social_connection_action"><input type="hidden" name="operation" value="frequency"><input type="hidden" name="connection_id" value="' . esc_attr( $connection->id ) . '">';
		wp_nonce_field( 'atomic_social_connection_action' );
		echo '<select name="sync_frequency" onchange="this.form.submit()">';
		foreach ( PluginSettings::frequencies() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $connection->sync_frequency, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></form>';
	}

	private function connectionButton( string $connection_id, string $operation, string $label ): void {
		echo '<form style="display:inline-block;margin-right:4px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="atomic_social_connection_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="connection_id" value="' . esc_attr( $connection_id ) . '">';
		wp_nonce_field( 'atomic_social_connection_action' );
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	private function reconnectButton( string $connection_id ): void {
		echo '<form style="display:inline-block" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="atomic_social_connect"><input type="hidden" name="connection_id" value="' . esc_attr( $connection_id ) . '">';
		wp_nonce_field( 'atomic_social_connect' );
		echo '<button class="button" type="submit">' . esc_html__( 'Reconnect', 'atomic-wp-social-sync' ) . '</button></form>';
	}

	private function diagnosticSummary( Connection $connection ): string {
		$diagnostics = $connection->diagnostics;
		$parts = array();
		if ( ! empty( $diagnostics['last_request_at'] ) ) {
			$parts[] = __( 'Last request', 'atomic-wp-social-sync' ) . ': ' . wp_date( 'Y-m-d H:i', (int) $diagnostics['last_request_at'] );
		}
		$labels = array(
			'fetched' => __( 'Fetched', 'atomic-wp-social-sync' ), 'created' => __( 'Created', 'atomic-wp-social-sync' ),
			'updated' => __( 'Updated', 'atomic-wp-social-sync' ), 'unchanged' => __( 'Unchanged', 'atomic-wp-social-sync' ),
			'missing' => __( 'Missing', 'atomic-wp-social-sync' ), 'drafted' => __( 'Drafted', 'atomic-wp-social-sync' ),
			'conflicts' => __( 'Conflicts', 'atomic-wp-social-sync' ), 'skipped' => __( 'Skipped', 'atomic-wp-social-sync' ),
		);
		foreach ( $labels as $key => $label ) {
			$parts[] = $label . ': ' . (int) ( $diagnostics[ $key ] ?? 0 );
		}
		$parts[] = __( 'Duration', 'atomic-wp-social-sync' ) . ': ' . (float) ( $diagnostics['duration'] ?? 0 ) . 's';
		$parts[] = __( 'Errors', 'atomic-wp-social-sync' ) . ': ' . count( is_array( $diagnostics['errors'] ?? null ) ? $diagnostics['errors'] : array() );
		if ( $connection->last_error ) {
			$parts[] = __( 'Error', 'atomic-wp-social-sync' ) . ': ' . $connection->last_error;
		}
		return implode( ' · ', $parts );
	}

	private function statusLabel( string $status ): string {
		return array(
			Connection::STATUS_CONNECTED => __( 'Connected', 'atomic-wp-social-sync' ),
			Connection::STATUS_RENEWAL   => __( 'Renewal required', 'atomic-wp-social-sync' ),
			Connection::STATUS_ERROR     => __( 'Error', 'atomic-wp-social-sync' ),
			Connection::STATUS_DISABLED  => __( 'Disabled', 'atomic-wp-social-sync' ),
		)[ $status ] ?? $status;
	}

	private function renderNotice(): void {
		$status = sanitize_key( (string) wp_unslash( $_GET['atomic_social_status'] ?? '' ) );
		$message = sanitize_text_field( (string) wp_unslash( $_GET['atomic_social_message'] ?? '' ) );
		if ( '' === $status ) {
			return;
		}
		$class = in_array( $status, array( 'error', 'sync_errors' ), true ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ?: ucwords( str_replace( '_', ' ', $status ) ) ) . '</p></div>';
	}

	private function authorizeAction( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage social connections.', 'atomic-wp-social-sync' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function deletePendingCredentials( string $pending_id ): void {
		$this->vault->delete( 'linkedin_pending_access_' . $pending_id );
		$this->vault->delete( 'linkedin_pending_refresh_' . $pending_id );
	}

	public function cleanupPendingCredentials( string $pending_id ): void {
		$pending_id = sanitize_text_field( $pending_id );
		$this->deletePendingCredentials( $pending_id );
		delete_transient( 'atomic_social_pending_' . $pending_id );
	}

	/** @param array<string,string> $args */
	private function pageUrl( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	private function redirect( string $status, string $message = '' ): never {
		wp_safe_redirect( $this->pageUrl( array( 'atomic_social_status' => $status, 'atomic_social_message' => $message ) ) );
		exit;
	}
}
