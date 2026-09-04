<?php
/**
 * Design / appearance settings for Atomic LinkedIn Feed (V1).
 *
 * This page configures Atomic-controlled outer styling only (wrappers, spacing, pagination).
 * It does not attempt to preview or represent LinkedIn iframe internal rendering.
 *
 * @package AtomicWPSocialSync
 */
namespace AtomicWPSocialSync\Admin;

use AtomicWPSocialSync\Support\PluginSettings;

final class DesignSettingsPage {
	public const SLUG = 'atomic-linkedin-feed-design';

	public function __construct( private readonly PluginSettings $settings ) {}

	public function registerMenu(): void {
		add_submenu_page(
			LinkedInPostsPage::MENU_SLUG,
			__( 'Design', 'atomic-wp-social-sync' ),
			__( 'Design', 'atomic-wp-social-sync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
		// The V1 IA is "LinkedIn Posts" + "Settings". Keep the legacy Design URL working
		// but remove it from the visible submenu.
		remove_submenu_page( LinkedInPostsPage::MENU_SLUG, self::SLUG );
	}

	public function enqueueAssets( string $hook ): void {
		// Hook suffix for submenu page: {parent}_page_{slug}.
		$design_hook    = 'atomic-linkedin-feed_page_' . self::SLUG;
		$settings_hook  = 'atomic-linkedin-feed_page_atomic-linkedin-feed-settings';
		$is_design_tab  = isset( $_GET['tab'] ) && 'design' === sanitize_key( (string) wp_unslash( $_GET['tab'] ) );
		if ( $design_hook !== $hook && ( $settings_hook !== $hook || ! $is_design_tab ) ) {
			return;
		}
		// Reuse the frontend stylesheet for consistent wrapper styling preview (selectors are scoped).
		wp_enqueue_style( 'atomic-wp-social-sync-frontend' );

		$css_path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/css/admin-design.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_enqueue_style(
			'atomic-linkedin-feed-admin-design',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/css/admin-design.css',
			array(),
			$css_ver
		);

		// Theme-aware color controls.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		$color_path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/js/design-settings-colors.js';
		$color_ver  = file_exists( $color_path ) ? (string) filemtime( $color_path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_enqueue_script(
			'atomic-linkedin-feed-design-colors',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/design-settings-colors.js',
			array( 'wp-color-picker', 'jquery' ),
			$color_ver,
			true
		);

		$path = ATOMIC_WP_SOCIAL_SYNC_PATH . 'assets/js/design-settings-preview.js';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : ATOMIC_WP_SOCIAL_SYNC_VERSION;
		wp_enqueue_script(
			'atomic-linkedin-feed-design-preview',
			ATOMIC_WP_SOCIAL_SYNC_URL . 'assets/js/design-settings-preview.js',
			array(),
			$ver,
			true
		);
		wp_localize_script(
			'atomic-linkedin-feed-design-preview',
			'atomicLinkedInFeedDesign',
			array(
				'defaults' => $this->settings->all(),
				'themePalette' => $this->themePaletteMap(),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap atomic-linkedin-admin atomic-linkedin-design">
			<header class="atomic-linkedin-design__header">
				<h1><?php esc_html_e( 'Atomic LinkedIn Feed — Design', 'atomic-wp-social-sync' ); ?></h1>
				<p class="atomic-linkedin-design__intro">
					<?php esc_html_e( 'Customize the default appearance of Atomic LinkedIn Posts. These settings control layout wrappers, spacing and navigation. LinkedIn controls the content displayed inside its embeds.', 'atomic-wp-social-sync' ); ?>
				</p>
			</header>

			<?php $this->renderTab(); ?>
		</div>
		<?php
	}

	/**
	 * Render the Design screen contents (used both on the legacy Design page and
	 * as the "Design" tab inside the Settings screen).
	 */
	public function renderTab(): void {
		$s = $this->settings->all();
		$palette = $this->themePalette();
		?>
		<div class="atomic-linkedin-admin atomic-linkedin-design" id="atomic-linkedin-design">
			<form id="atomic-linkedin-design-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="atomic_social_save_settings">
				<?php wp_nonce_field( 'atomic_social_save_settings' ); ?>

				<div class="atomic-linkedin-design__layout">
					<main class="atomic-linkedin-design__settings">
						<section class="atomic-linkedin-design__section">
							<header class="atomic-linkedin-design__section-header">
								<h2 class="atomic-linkedin-design__section-title"><?php esc_html_e( 'Post appearance', 'atomic-wp-social-sync' ); ?></h2>
								<p class="atomic-linkedin-design__section-description"><?php esc_html_e( 'Set the default appearance of the container around each LinkedIn post.', 'atomic-wp-social-sync' ); ?></p>
							</header>

							<div class="atomic-linkedin-design__fields">
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-radius"><?php esc_html_e( 'Corner radius', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Controls the rounding of the Atomic post container.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-design-radius" type="number" class="small-text" name="settings[design_radius]" min="0" max="40" value="<?php echo esc_attr( (string) $s['design_radius'] ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-border-style"><?php esc_html_e( 'Border', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Add or remove the outer Atomic border.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-design-border-style" name="settings[design_border_style]">
											<option value="none" <?php selected( 'none', (string) $s['design_border_style'] ); ?>><?php esc_html_e( 'None', 'atomic-wp-social-sync' ); ?></option>
											<option value="solid" <?php selected( 'solid', (string) $s['design_border_style'] ); ?>><?php esc_html_e( 'Solid', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>

								<div id="atomic-design-border-width-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-border-width"><?php esc_html_e( 'Border width', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Controls the width of the outer Atomic border.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-design-border-width" type="number" class="small-text" name="settings[design_border_width]" min="0" max="12" value="<?php echo esc_attr( (string) $s['design_border_width'] ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>

								<div id="atomic-design-border-color-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-border-color"><?php esc_html_e( 'Border color', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Color used for the outer Atomic border.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<?php
										$this->renderColorControl(
											'atomic-design-border-color',
											'settings[design_border_color]',
											(string) $s['design_border_color'],
											$palette,
											__( 'Default', 'atomic-wp-social-sync' )
										);
										?>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-background"><?php esc_html_e( 'Background', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Background applied around the LinkedIn embed.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<?php
										$this->renderColorControl(
											'atomic-design-background',
											'settings[design_background]',
											(string) $s['design_background'],
											$palette,
											__( 'Transparent (default)', 'atomic-wp-social-sync' )
										);
										?>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-shadow"><?php esc_html_e( 'Shadow', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Adds depth to the Atomic container without changing the LinkedIn embed.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-design-shadow" name="settings[design_shadow]">
											<option value="none" <?php selected( 'none', (string) $s['design_shadow'] ); ?>><?php esc_html_e( 'None', 'atomic-wp-social-sync' ); ?></option>
											<option value="subtle" <?php selected( 'subtle', (string) $s['design_shadow'] ); ?>><?php esc_html_e( 'Subtle', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-hover"><?php esc_html_e( 'Hover effect', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Optional interaction applied to the outer post container.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-design-hover" name="settings[design_hover]">
											<option value="none" <?php selected( 'none', (string) $s['design_hover'] ); ?>><?php esc_html_e( 'None', 'atomic-wp-social-sync' ); ?></option>
											<option value="lift" <?php selected( 'lift', (string) $s['design_hover'] ); ?>><?php esc_html_e( 'Lift', 'atomic-wp-social-sync' ); ?></option>
											<option value="scale" <?php selected( 'scale', (string) $s['design_hover'] ); ?>><?php esc_html_e( 'Scale subtle', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-design-transition"><?php esc_html_e( 'Transition duration', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Animation duration for hover and visual transitions.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-design-transition" type="number" class="small-text" name="settings[design_transition_ms]" min="0" max="2000" value="<?php echo esc_attr( (string) $s['design_transition_ms'] ); ?>">
										<span class="description"><?php esc_html_e( 'ms', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>
							</div>
						</section>

						<section class="atomic-linkedin-design__section">
							<header class="atomic-linkedin-design__section-header">
								<h2 class="atomic-linkedin-design__section-title"><?php esc_html_e( 'Layout & spacing', 'atomic-wp-social-sync' ); ?></h2>
								<p class="atomic-linkedin-design__section-description"><?php esc_html_e( 'Define the default spacing used by Atomic LinkedIn Posts layouts.', 'atomic-wp-social-sync' ); ?></p>
							</header>

							<div class="atomic-linkedin-design__fields">
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-gap"><?php esc_html_e( 'Post gap', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Space between posts in Grid and Carousel layouts.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-layout-gap" name="settings[layout_gap]">
											<option value="small" <?php selected( 'small', (string) ( $s['layout_gap'] ?? 'medium' ) ); ?>><?php esc_html_e( 'Small', 'atomic-wp-social-sync' ); ?></option>
											<option value="medium" <?php selected( 'medium', (string) ( $s['layout_gap'] ?? 'medium' ) ); ?>><?php esc_html_e( 'Medium', 'atomic-wp-social-sync' ); ?></option>
											<option value="large" <?php selected( 'large', (string) ( $s['layout_gap'] ?? 'medium' ) ); ?>><?php esc_html_e( 'Large', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-min-width"><?php esc_html_e( 'Minimum post width', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Minimum preferred width before the layout reduces the number of visible columns.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-layout-min-width" type="number" class="small-text" name="settings[layout_min_width]" min="280" max="600" value="<?php echo esc_attr( (string) ( $s['layout_min_width'] ?? 340 ) ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>

								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-separator-enable"><?php esc_html_e( 'Stacked separator', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Display a divider between posts in Stacked layouts.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<label>
											<input id="atomic-layout-separator-enable" type="checkbox" name="settings[layout_separator_enabled]" value="1" <?php checked( ! empty( $s['layout_separator_enabled'] ) ); ?>>
											<?php esc_html_e( 'Show separator', 'atomic-wp-social-sync' ); ?>
										</label>
									</div>
								</div>

								<div id="atomic-layout-separator-thickness-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-separator-thickness"><?php esc_html_e( 'Separator thickness', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-layout-separator-thickness" type="number" class="small-text" name="settings[layout_separator_thickness]" min="0" max="12" value="<?php echo esc_attr( (string) ( $s['layout_separator_thickness'] ?? 1 ) ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>

								<div id="atomic-layout-separator-color-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-separator-color"><?php esc_html_e( 'Separator color', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<?php
										$this->renderColorControl(
											'atomic-layout-separator-color',
											'settings[layout_separator_color]',
											(string) ( $s['layout_separator_color'] ?? '' ),
											$palette,
											__( 'Default', 'atomic-wp-social-sync' )
										);
										?>
									</div>
								</div>

								<div id="atomic-layout-separator-spacing-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-layout-separator-spacing"><?php esc_html_e( 'Separator spacing', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Vertical breathing room around each divider.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-layout-separator-spacing" type="number" class="small-text" name="settings[layout_separator_spacing]" min="0" max="120" value="<?php echo esc_attr( (string) ( $s['layout_separator_spacing'] ?? 40 ) ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>
							</div>
						</section>

						<section class="atomic-linkedin-design__section">
							<header class="atomic-linkedin-design__section-header">
								<h2 class="atomic-linkedin-design__section-title"><?php esc_html_e( 'Pagination', 'atomic-wp-social-sync' ); ?></h2>
								<p class="atomic-linkedin-design__section-description"><?php esc_html_e( 'Customize numbered pagination used by LinkedIn feed blocks.', 'atomic-wp-social-sync' ); ?></p>
							</header>

							<div class="atomic-linkedin-design__fields">
								<h3 class="atomic-linkedin-design__subheading"><?php esc_html_e( 'Typography', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-font-size"><?php esc_html_e( 'Font size', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Text size used for page numbers and Previous / Next links.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-pg-font-size" type="number" class="small-text" name="settings[pagination_font_size]" min="10" max="26" value="<?php echo esc_attr( (string) $s['pagination_font_size'] ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-font-weight"><?php esc_html_e( 'Font weight', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Font weight used by pagination controls.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-pg-font-weight" type="number" class="small-text" name="settings[pagination_font_weight]" min="200" max="900" step="50" value="<?php echo esc_attr( (string) $s['pagination_font_weight'] ); ?>">
									</div>
								</div>

								<h3 class="atomic-linkedin-design__subheading"><?php esc_html_e( 'Button', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-min-width"><?php esc_html_e( 'Minimum button size', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Minimum clickable width and height for pagination controls.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-pg-min-width" type="number" class="small-text" name="settings[pagination_min_width]" min="28" max="120" value="<?php echo esc_attr( (string) $s['pagination_min_width'] ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>
								<div class="atomic-linkedin-design__field atomic-linkedin-design__field--inline">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-padding-x"><?php esc_html_e( 'Padding', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<div class="atomic-linkedin-design__inline">
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-padding-x"><?php esc_html_e( 'Horizontal', 'atomic-wp-social-sync' ); ?></label>
												<input id="atomic-pg-padding-x" type="number" class="small-text" name="settings[pagination_padding_x]" min="0" max="40" value="<?php echo esc_attr( (string) $s['pagination_padding_x'] ); ?>">
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-padding-y"><?php esc_html_e( 'Vertical', 'atomic-wp-social-sync' ); ?></label>
												<input id="atomic-pg-padding-y" type="number" class="small-text" name="settings[pagination_padding_y]" min="0" max="30" value="<?php echo esc_attr( (string) $s['pagination_padding_y'] ); ?>">
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-gap"><?php esc_html_e( 'Gap', 'atomic-wp-social-sync' ); ?></label>
												<input id="atomic-pg-gap" type="number" class="small-text" name="settings[pagination_gap]" min="0" max="30" value="<?php echo esc_attr( (string) $s['pagination_gap'] ); ?>">
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-radius"><?php esc_html_e( 'Radius', 'atomic-wp-social-sync' ); ?></label>
												<input id="atomic-pg-radius" type="number" class="small-text" name="settings[pagination_radius]" min="0" max="30" value="<?php echo esc_attr( (string) $s['pagination_radius'] ); ?>">
											</div>
										</div>
									</div>
								</div>

								<h3 class="atomic-linkedin-design__subheading"><?php esc_html_e( 'Border', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-border-style"><?php esc_html_e( 'Border', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-pg-border-style" name="settings[pagination_border_style]">
											<option value="none" <?php selected( 'none', (string) $s['pagination_border_style'] ); ?>><?php esc_html_e( 'None', 'atomic-wp-social-sync' ); ?></option>
											<option value="solid" <?php selected( 'solid', (string) $s['pagination_border_style'] ); ?>><?php esc_html_e( 'Solid', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>

								<div id="atomic-pg-border-width-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-border-width"><?php esc_html_e( 'Border width', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-pg-border-width" type="number" class="small-text" name="settings[pagination_border_width]" min="0" max="12" value="<?php echo esc_attr( (string) $s['pagination_border_width'] ); ?>">
										<span class="description"><?php esc_html_e( 'px', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>

								<div id="atomic-pg-border-color-field" class="atomic-linkedin-design__field atomic-linkedin-design__field--conditional">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-border-color"><?php esc_html_e( 'Border color', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<?php
										$this->renderColorControl(
											'atomic-pg-border-color',
											'settings[pagination_border_color]',
											(string) $s['pagination_border_color'],
											$palette,
											__( 'Default', 'atomic-wp-social-sync' )
										);
										?>
									</div>
								</div>

								<h3 class="atomic-linkedin-design__subheading"><?php esc_html_e( 'Colors', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-design__field atomic-linkedin-design__field--inline">
									<div class="atomic-linkedin-design__field-label">
										<label><?php esc_html_e( 'Default state', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<div class="atomic-linkedin-design__inline">
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-color"><?php esc_html_e( 'Text', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-color',
													'settings[pagination_color]',
													(string) $s['pagination_color'],
													$palette,
													__( 'Inherit (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-bg"><?php esc_html_e( 'Background', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-bg',
													'settings[pagination_background]',
													(string) $s['pagination_background'],
													$palette,
													__( 'Transparent (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
										</div>
									</div>
								</div>
								<div class="atomic-linkedin-design__field atomic-linkedin-design__field--inline">
									<div class="atomic-linkedin-design__field-label">
										<label><?php esc_html_e( 'Active page', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<div class="atomic-linkedin-design__inline">
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-active-color"><?php esc_html_e( 'Text', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-active-color',
													'settings[pagination_active_color]',
													(string) $s['pagination_active_color'],
													$palette,
													__( 'Inherit (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-active-bg"><?php esc_html_e( 'Background', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-active-bg',
													'settings[pagination_active_background]',
													(string) $s['pagination_active_background'],
													$palette,
													__( 'Default', 'atomic-wp-social-sync' )
												);
												?>
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-active-border"><?php esc_html_e( 'Border', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-active-border',
													'settings[pagination_active_border_color]',
													(string) $s['pagination_active_border_color'],
													$palette,
													__( 'Inherit (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
										</div>
									</div>
								</div>
								<div class="atomic-linkedin-design__field atomic-linkedin-design__field--inline">
									<div class="atomic-linkedin-design__field-label">
										<label><?php esc_html_e( 'Hover', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<div class="atomic-linkedin-design__inline">
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-hover-color"><?php esc_html_e( 'Text', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-hover-color',
													'settings[pagination_hover_color]',
													(string) $s['pagination_hover_color'],
													$palette,
													__( 'Inherit (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-hover-bg"><?php esc_html_e( 'Background', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-hover-bg',
													'settings[pagination_hover_background]',
													(string) $s['pagination_hover_background'],
													$palette,
													__( 'Default', 'atomic-wp-social-sync' )
												);
												?>
											</div>
											<div>
												<label class="atomic-linkedin-design__mini-label" for="atomic-pg-hover-border"><?php esc_html_e( 'Border', 'atomic-wp-social-sync' ); ?></label>
												<?php
												$this->renderColorControl(
													'atomic-pg-hover-border',
													'settings[pagination_hover_border_color]',
													(string) $s['pagination_hover_border_color'],
													$palette,
													__( 'Inherit (default)', 'atomic-wp-social-sync' )
												);
												?>
											</div>
										</div>
									</div>
								</div>

								<h3 class="atomic-linkedin-design__subheading"><?php esc_html_e( 'Shadow and transitions', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-shadow"><?php esc_html_e( 'Shadow', 'atomic-wp-social-sync' ); ?></label>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<select id="atomic-pg-shadow" name="settings[pagination_shadow]">
											<option value="none" <?php selected( 'none', (string) $s['pagination_shadow'] ); ?>><?php esc_html_e( 'None', 'atomic-wp-social-sync' ); ?></option>
											<option value="subtle" <?php selected( 'subtle', (string) $s['pagination_shadow'] ); ?>><?php esc_html_e( 'Subtle', 'atomic-wp-social-sync' ); ?></option>
										</select>
									</div>
								</div>
								<div class="atomic-linkedin-design__field">
									<div class="atomic-linkedin-design__field-label">
										<label for="atomic-pg-transition"><?php esc_html_e( 'Transition duration', 'atomic-wp-social-sync' ); ?></label>
										<p class="atomic-linkedin-design__field-help"><?php esc_html_e( 'Animation duration between pagination states.', 'atomic-wp-social-sync' ); ?></p>
									</div>
									<div class="atomic-linkedin-design__field-control">
										<input id="atomic-pg-transition" type="number" class="small-text" name="settings[pagination_transition_ms]" min="0" max="2000" value="<?php echo esc_attr( (string) $s['pagination_transition_ms'] ); ?>">
										<span class="description"><?php esc_html_e( 'ms', 'atomic-wp-social-sync' ); ?></span>
									</div>
								</div>
							</div>
						</section>

						<div class="atomic-linkedin-design__actions">
							<?php submit_button( __( 'Save changes', 'atomic-wp-social-sync' ), 'primary', 'submit', false ); ?>
						</div>
					</main>

					<aside class="atomic-linkedin-design__preview-column">
						<div
							id="atomic-linkedin-design-preview"
							class="atomic-linkedin-design__preview-sticky atomic-linkedin-preview"
						>
							<header class="atomic-linkedin-preview__header">
								<h2 class="atomic-linkedin-preview__title"><?php esc_html_e( 'Live preview', 'atomic-wp-social-sync' ); ?></h2>
								<p class="atomic-linkedin-preview__description"><?php esc_html_e( 'Preview Atomic-controlled styles before saving.', 'atomic-wp-social-sync' ); ?></p>
								<p class="atomic-linkedin-preview__note"><?php esc_html_e( 'LinkedIn controls the content inside its embeds.', 'atomic-wp-social-sync' ); ?></p>
								<p class="atomic-linkedin-preview__note"><?php esc_html_e( 'This preview represents the Atomic container and navigation styles only.', 'atomic-wp-social-sync' ); ?></p>
							</header>

							<div class="atomic-linkedin-preview__group">
								<h3 class="atomic-linkedin-preview__group-title"><?php esc_html_e( 'Post card', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-preview__canvas atomic-linkedin-preview__card-demo">
									<article class="atomic-social-card">
										<div class="atomic-social-card__body">
											<div class="atomic-linkedin-preview__card-header">
												<strong><?php esc_html_e( 'LinkedIn Post', 'atomic-wp-social-sync' ); ?></strong>
												<span class="atomic-linkedin-preview__muted"><?php esc_html_e( 'Just now', 'atomic-wp-social-sync' ); ?></span>
											</div>
											<div class="atomic-linkedin-preview__embed-placeholder">
												<?php esc_html_e( 'LinkedIn embed area', 'atomic-wp-social-sync' ); ?>
											</div>
										</div>
									</article>
								</div>
							</div>

							<div class="atomic-linkedin-preview__group">
								<h3 class="atomic-linkedin-preview__group-title"><?php esc_html_e( 'Stacked layout', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-preview__canvas atomic-linkedin-preview__stacked-demo">
									<div class="atomic-linkedin-preview__stacked-demo-inner" data-preview="stacked-demo">
										<article class="atomic-social-card atomic-linkedin-preview__stacked-item">
											<div class="atomic-social-card__body">
												<div class="atomic-linkedin-preview__embed-placeholder atomic-linkedin-preview__embed-placeholder--small"><?php esc_html_e( 'Post preview', 'atomic-wp-social-sync' ); ?></div>
											</div>
										</article>
										<div class="atomic-linkedin-preview__separator" aria-hidden="true"></div>
										<article class="atomic-social-card atomic-linkedin-preview__stacked-item">
											<div class="atomic-social-card__body">
												<div class="atomic-linkedin-preview__embed-placeholder atomic-linkedin-preview__embed-placeholder--small"><?php esc_html_e( 'Post preview', 'atomic-wp-social-sync' ); ?></div>
											</div>
										</article>
									</div>
								</div>
							</div>

							<div class="atomic-linkedin-preview__group">
								<h3 class="atomic-linkedin-preview__group-title"><?php esc_html_e( 'Pagination', 'atomic-wp-social-sync' ); ?></h3>
								<div class="atomic-linkedin-preview__canvas atomic-linkedin-preview__pagination-demo">
									<nav class="atomic-social-pagination" aria-label="<?php echo esc_attr__( 'Pagination', 'atomic-wp-social-sync' ); ?>">
										<ul>
											<li><a href="#"><?php esc_html_e( 'Previous', 'atomic-wp-social-sync' ); ?></a></li>
											<li><span class="current" aria-current="page">1</span></li>
											<li><a href="#">2</a></li>
											<li><a href="#"><?php esc_html_e( 'Next', 'atomic-wp-social-sync' ); ?></a></li>
										</ul>
									</nav>
								</div>
							</div>

							<p class="atomic-linkedin-preview__note atomic-linkedin-preview__note--save"><?php esc_html_e( 'Preview updates instantly. Changes are saved only when you click Save changes.', 'atomic-wp-social-sync' ); ?></p>
						</div>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * @return array<int,array{slug:string,name:string,color:string}>
	 */
	private function themePalette(): array {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return array();
		}
		// WP_Theme_JSON_Resolver is the supported API to get the merged active theme settings.
		$data = \WP_Theme_JSON_Resolver::get_merged_data();
		if ( ! is_object( $data ) || ! method_exists( $data, 'get_settings' ) ) {
			return array();
		}
		$settings = $data->get_settings();
		$palette = is_array( $settings['color']['palette'] ?? null ) ? $settings['color']['palette'] : array();

		// Include theme-defined palette. If WP exposes other palettes (e.g. default), include them too.
		$merged = array();
		foreach ( array( 'theme', 'default' ) as $bucket ) {
			$items = $palette[ $bucket ] ?? array();
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$slug = sanitize_key( (string) ( $item['slug'] ?? '' ) );
				$name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
				$color = sanitize_hex_color( (string) ( $item['color'] ?? '' ) );
				if ( '' === $slug || '' === $name || ! $color ) {
					continue;
				}
				$merged[ $slug ] = array(
					'slug'  => $slug,
					'name'  => $name,
					'color' => $color,
				);
			}
		}

		return array_values( $merged );
	}

	/** @return array<string,string> */
	private function themePaletteMap(): array {
		$map = array();
		foreach ( $this->themePalette() as $c ) {
			$map[ $c['slug'] ] = $c['color'];
		}
		return $map;
	}

	/**
	 * Render a compact theme-aware color control.
	 *
	 * Stored value is a single string:
	 * - '' (inherit/default/transparent depending on the property)
	 * - literal HEX/RGB(A)
	 * - `var(--wp--preset--color--{slug})` (theme preset reference)
	 *
	 * JS enhances this into an accessible popover; without JS the hidden input
	 * is still submitted and server-side sanitize remains authoritative.
	 *
	 * @param string $id HTML id for the hidden input (must remain stable for preview JS).
	 * @param string $name Input name (e.g. settings[pagination_color]).
	 * @param string $value Stored value.
	 * @param array<int,array{slug:string,name:string,color:string}> $palette Theme palette.
	 * @param string $default_label Label for the empty/default option.
	 */
	private function renderColorControl( string $id, string $name, string $value, array $palette, string $default_label ): void {
		$value = (string) $value;

		$mode = 'default';
		$preset_slug = '';
		$preset_name = '';
		$preset_hex  = '';
		$custom_hex  = '';

		if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9-]+)\)$/', $value, $m ) ) {
			$mode = 'preset';
			$preset_slug = (string) $m[1];
			foreach ( $palette as $c ) {
				if ( is_array( $c ) && (string) $c['slug'] === $preset_slug ) {
					$preset_name = (string) $c['name'];
					$preset_hex  = (string) $c['color'];
					break;
				}
			}
		} elseif ( '' !== $value ) {
			$mode = 'custom';
			$hex = sanitize_hex_color( $value );
			$custom_hex = $hex ? $hex : '';
		}

		$toggle_label = $default_label;
		$toggle_swatch = '';
		if ( 'preset' === $mode ) {
			$toggle_label = sprintf(
				/* translators: %s is the theme color name */
				__( 'Theme: %s', 'atomic-wp-social-sync' ),
				$preset_name ? $preset_name : $preset_slug
			);
			$toggle_swatch = $preset_hex;
		} elseif ( 'custom' === $mode ) {
			$toggle_label = $custom_hex ? $custom_hex : $value;
			$toggle_swatch = $custom_hex;
		}

		$popover_id = $id . '__popover';
		?>
		<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
		<div
			class="atomic-linkedin-color-control atomic-color-control"
			data-target="<?php echo esc_attr( $id ); ?>"
			data-default-label="<?php echo esc_attr( $default_label ); ?>"
		>
			<button
				type="button"
				class="atomic-linkedin-color-control__trigger atomic-color-control__toggle"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $popover_id ); ?>"
			>
				<span class="atomic-linkedin-color-control__swatch atomic-color-control__toggle-chip" aria-hidden="true" style="<?php echo $toggle_swatch ? 'background:' . esc_attr( $toggle_swatch ) . ';' : ''; ?>"></span>
				<span class="atomic-linkedin-color-control__label atomic-color-control__toggle-label"><?php echo esc_html( $toggle_label ); ?></span>
				<span class="atomic-linkedin-color-control__caret atomic-color-control__toggle-caret" aria-hidden="true">▾</span>
			</button>

			<div id="<?php echo esc_attr( $popover_id ); ?>" class="atomic-linkedin-color-control__popover atomic-color-control__popover" hidden>
				<?php if ( $palette ) : ?>
					<div class="atomic-linkedin-color-control__palette atomic-color-control__section">
						<div class="atomic-color-control__section-title"><?php esc_html_e( 'Theme colors', 'atomic-wp-social-sync' ); ?></div>
						<div class="atomic-color-control__swatches" role="listbox" aria-label="<?php echo esc_attr__( 'Theme colors', 'atomic-wp-social-sync' ); ?>">
							<?php foreach ( $palette as $c ) : ?>
								<?php
								if ( ! is_array( $c ) ) { continue; }
								$slug = (string) $c['slug'];
								$name_label = (string) $c['name'];
								$color = (string) $c['color'];
								$var = 'var(--wp--preset--color--' . $slug . ')';
								$is_selected = ( 'preset' === $mode && $preset_slug === $slug );
								$aria = $name_label . ' — ' . $color;
								?>
								<button
									type="button"
									class="atomic-linkedin-color-control__preset atomic-color-control__swatch <?php echo $is_selected ? 'is-selected' : ''; ?>"
									data-value="<?php echo esc_attr( $var ); ?>"
									data-label="<?php echo esc_attr( sprintf( __( 'Theme: %s', 'atomic-wp-social-sync' ), $name_label ) ); ?>"
									data-color="<?php echo esc_attr( $color ); ?>"
									role="option"
									aria-selected="<?php echo esc_attr( $is_selected ? 'true' : 'false' ); ?>"
									aria-label="<?php echo esc_attr( $aria ); ?>"
								>
									<span class="atomic-color-control__swatch-chip" aria-hidden="true" style="background:<?php echo esc_attr( $color ); ?>"></span>
									<span class="atomic-color-control__swatch-name"><?php echo esc_html( $name_label ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="atomic-linkedin-color-control__options atomic-color-control__section">
					<div class="atomic-color-control__section-title"><?php esc_html_e( 'Options', 'atomic-wp-social-sync' ); ?></div>
					<button
						type="button"
						class="atomic-linkedin-color-control__preset atomic-color-control__option <?php echo 'default' === $mode ? 'is-selected' : ''; ?>"
						data-value=""
						data-label="<?php echo esc_attr( $default_label ); ?>"
					>
						<?php echo esc_html( $default_label ); ?>
					</button>
				</div>

				<div class="atomic-linkedin-color-control__custom atomic-color-control__section">
					<div class="atomic-color-control__section-title"><?php esc_html_e( 'Custom', 'atomic-wp-social-sync' ); ?></div>
					<div class="atomic-color-control__custom">
						<input
							type="text"
							class="atomic-linkedin-color-control__hex atomic-color-control__hex atomic-color-picker"
							value="<?php echo esc_attr( $custom_hex ); ?>"
							placeholder="#006b61"
							inputmode="text"
							autocomplete="off"
							spellcheck="false"
							data-custom="1"
						/>
						<span class="description"><?php esc_html_e( 'Type a HEX value or use the picker.', 'atomic-wp-social-sync' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
