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

use AtomicWPSocialSync\Support\PluginSettings;
use AtomicWPSocialSync\Import\LinkedInHtmlImportParser;
use AtomicWPSocialSync\Providers\LinkedIn\LinkedInEmbed;
use AtomicWPSocialSync\Support\IntegrationMode;
use AtomicWPSocialSync\Support\MetaKeys;
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

	private const TAB_SOURCES = 'sources';
	private const TAB_IMPORT  = 'import';
	private const TAB_DESIGN  = 'design';

	public function __construct(
		private readonly PluginSettings $settings,
		private readonly DesignSettingsPage $design_page
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
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = sanitize_key( (string) wp_unslash( $_GET['tab'] ?? self::TAB_SOURCES ) );
		if ( ! in_array( $tab, array( self::TAB_SOURCES, self::TAB_IMPORT, self::TAB_DESIGN ), true ) ) {
			$tab = self::TAB_SOURCES;
		}

		$base_url = admin_url( 'admin.php?page=' . self::SLUG );
		$tabs = array(
			self::TAB_SOURCES => __( 'Sources', 'atomic-wp-social-sync' ),
			self::TAB_IMPORT  => __( 'Import / Migration', 'atomic-wp-social-sync' ),
			self::TAB_DESIGN  => __( 'Design', 'atomic-wp-social-sync' ),
		);

		?>
		<div class="wrap atomic-linkedin-settings" id="atomic-linkedin-settings">
			<h1><?php esc_html_e( 'Atomic LinkedIn Feed — Settings', 'atomic-wp-social-sync' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<h2 class="nav-tab-wrapper" role="tablist" aria-label="<?php echo esc_attr__( 'Settings tabs', 'atomic-wp-social-sync' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
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
				if ( self::TAB_DESIGN === $tab ) {
					$this->design_page->renderTab();
				} elseif ( self::TAB_IMPORT === $tab ) {
					$this->renderImportTab();
				} else {
					$this->renderSourcesTab();
				}
				?>
			</div>
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

	private function renderNotice(): void {
		$notice = sanitize_key( (string) wp_unslash( $_GET['notice'] ?? '' ) );
		if ( '' === $notice ) {
			return;
		}

		if ( 'sources_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'atomic-wp-social-sync' ) . '</p></div>';
			return;
		}

		if ( 'sources_invalid' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Some sources were not saved. Ensure each source has a label and a valid LinkedIn posts URL (https://www.linkedin.com/company/.../posts/).', 'atomic-wp-social-sync' ) . '</p></div>';
			return;
		}

		if ( 'import_analyzed' === $notice ) {
			$total = absint( $_GET['total'] ?? 0 );
			$new   = absint( $_GET['new'] ?? 0 );
			$exists = absint( $_GET['exists'] ?? 0 );
			$warn  = absint( $_GET['warn'] ?? 0 );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sprintf( __( '%1$d LinkedIn posts detected. %2$d new, %3$d already imported, %4$d warnings.', 'atomic-wp-social-sync' ), $total, $new, $exists, $warn ) ) . '</p></div>';
			return;
		}

		if ( 'import_done' === $notice ) {
			$created = absint( $_GET['created'] ?? 0 );
			$skipped = absint( $_GET['skipped'] ?? 0 );
			$failed  = absint( $_GET['failed'] ?? 0 );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%1$d LinkedIn posts imported. %2$d skipped, %3$d failed.', 'atomic-wp-social-sync' ), $created, $skipped, $failed ) ) . '</p></div>';

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

		if ( 'import_invalid' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Import failed. Provide valid LinkedIn HTML via upload or paste.', 'atomic-wp-social-sync' ) . '</p></div>';
			return;
		}
	}

	public function analyzeImport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'atomic-wp-social-sync' ), 403 );
		}
		check_admin_referer( self::ANALYZE_IMPORT_ACTION );

		$source_id = sanitize_text_field( (string) wp_unslash( $_POST['source_id'] ?? '' ) );
		$sources = $this->settings->get( 'linkedin_sources', array() );
		$sources = is_array( $sources ) ? $sources : array();

		$valid_source = null;
		foreach ( $sources as $s ) {
			if ( is_array( $s ) && (string) ( $s['id'] ?? '' ) === $source_id ) {
				$valid_source = $s;
				break;
			}
		}

		$html = '';
		$paste = trim( (string) wp_unslash( $_POST['html_paste'] ?? '' ) );
		if ( '' !== $paste ) {
			$html = $paste;
		} elseif ( ! empty( $_FILES['html_file'] ) && is_array( $_FILES['html_file'] ) ) {
			$tmp_name = (string) ( $_FILES['html_file']['tmp_name'] ?? '' );
			$name     = (string) ( $_FILES['html_file']['name'] ?? '' );
			$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'html', 'htm', 'txt' ), true ) ) {
				$this->redirectToImport( array( 'notice' => 'import_invalid' ) );
			}
			if ( '' !== $tmp_name && file_exists( $tmp_name ) ) {
				$html = (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}

		$parser = new LinkedInHtmlImportParser();
		$items = $parser->parse( $html );
		if ( ! $items ) {
			$this->redirectToImport( array( 'notice' => 'import_invalid' ) );
		}

		$results = array();
		$new = 0;
		$exists = 0;
		$warn = 0;

		foreach ( $items as $item ) {
			$urn = (string) ( $item['urn'] ?? '' );
			$activity_id = (string) ( $item['activity_id'] ?? '' );
			$permalink = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';

			$status = 'NEW';
			$message = '';
			$published_local = '';

			if ( '' === $urn || '' === $activity_id ) {
				continue;
			}

			$existing = $this->findExistingActivityEmbedPostId( $urn );
			if ( null !== $existing ) {
				$status = 'ALREADY_EXISTS';
				$exists++;
			} else {
				// Decode timestamp from the activity ID if possible (ms = id >> 22).
				$published_local = $this->activityIdToLocalDatetime( $activity_id );
				if ( '' === $published_local ) {
					$status = 'WARNING';
					$message = __( 'Publication date could not be determined. Set it manually to enable import.', 'atomic-wp-social-sync' );
					$warn++;
				} else {
					$new++;
				}
			}

			$results[] = array(
				'urn'            => $urn,
				'activity_id'     => $activity_id,
				'permalink'       => $permalink,
				'published_local' => $published_local,
				'status'          => $status,
				'message'         => $message,
			);
		}

		$token = wp_generate_uuid4();
		set_transient(
			self::IMPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $token,
			array(
				'source_id' => $valid_source ? (string) ( $valid_source['id'] ?? '' ) : '',
				'created_at' => time(),
				'results' => $results,
			),
			60 * MINUTE_IN_SECONDS
		);

		$this->redirectToImport(
			array(
				'notice'   => 'import_analyzed',
				'analysis' => $token,
				'total'    => count( $results ),
				'new'      => $new,
				'exists'   => $exists,
				'warn'     => $warn,
			)
		);
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

			// Re-check duplicates immediately before creating.
			if ( null !== $this->findExistingActivityEmbedPostId( $urn ) ) {
				$skipped++;
				continue;
			}

			$published_local = trim( (string) wp_unslash( $_POST['published_local'][ $urn ] ?? '' ) );
			if ( '' === $published_local ) {
				$failed++;
				$errors[] = array(
					'urn'    => $urn,
					'reason' => __( 'Missing publication date.', 'atomic-wp-social-sync' ),
				);
				continue;
			}

			$permalink = trim( (string) wp_unslash( $_POST['permalink'][ $urn ] ?? '' ) );
			$permalink = '' !== $permalink ? esc_url_raw( $permalink ) : '';

			try {
				$this->createActivityEmbedPost( $urn, $published_local, $permalink, $source_id );
				$created++;
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

		?>
		<div class="atomic-linkedin-design atomic-linkedin-settings__import">
			<p class="atomic-linkedin-design__intro">
				<?php esc_html_e( 'Import historical LinkedIn posts from a manually saved LinkedIn page. Existing posts are detected and skipped automatically.', 'atomic-wp-social-sync' ); ?>
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

					<p>
						<label for="atomic-import-paste"><strong><?php esc_html_e( 'Or paste HTML', 'atomic-wp-social-sync' ); ?></strong></label><br>
						<textarea id="atomic-import-paste" class="widefat" rows="6" name="html_paste" placeholder="<?php echo esc_attr__( 'Paste saved LinkedIn page HTML here', 'atomic-wp-social-sync' ); ?>"></textarea>
					</p>

					<?php submit_button( __( 'Analyze', 'atomic-wp-social-sync' ), 'secondary', 'analyze', false ); ?>
				</form>
			</div>

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
									$disabled = ( 'ALREADY_EXISTS' === $status );
									$warning_blocked = ( 'WARNING' === $status && '' === $published_local );
									$checked  = ( 'NEW' === $status );
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
											<strong><?php echo esc_html( str_replace( '_', ' ', $status ) ); ?></strong>
											<?php if ( 'WARNING' === $status ) : ?>
												<div class="description"><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<input
												type="datetime-local"
												name="published_local[<?php echo esc_attr( $urn ); ?>]"
												value="<?php echo esc_attr( $published_local ); ?>"
												<?php disabled( true, $disabled ); ?>
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

	private function createActivityEmbedPost( string $urn, string $published_local, string $permalink, string $source_id ): int {
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
				'post_title'    => 'LinkedIn Activity ' . preg_replace( '/\D+/', '', $urn ),
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

		wp_set_object_terms( $post_id, 'linkedin', SocialPostType::PROVIDER_TAXONOMY );
		return (int) $post_id;
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
}
