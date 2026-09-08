<?php
/**
 * Plugin Name: Atomic LinkedIn Feed
 * Plugin URI: https://atomic-design.be/
 * Description: Manage a local LinkedIn feed based on manually selected official LinkedIn embeds.
 * Version: 0.10.0
 * Author: Bernard Coubeaux
 * Author URI: https://atomic-design.be/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atomic-wp-social-sync
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package AtomicWPSocialSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ATOMIC_WP_SOCIAL_SYNC_VERSION', '0.9.0' );
define( 'ATOMIC_WP_SOCIAL_SYNC_SCHEMA_VERSION', '1' );
define( 'ATOMIC_WP_SOCIAL_SYNC_FILE', __FILE__ );
define( 'ATOMIC_WP_SOCIAL_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ATOMIC_WP_SOCIAL_SYNC_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$namespace_prefix = 'AtomicWPSocialSync\\';
		if ( ! str_starts_with( $class_name, $namespace_prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $namespace_prefix ) );
		$file_path      = ATOMIC_WP_SOCIAL_SYNC_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file_path ) ) {
			require_once $file_path;
		}
	}
);

register_activation_hook( __FILE__, array( AtomicWPSocialSync\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( AtomicWPSocialSync\Plugin::class, 'deactivate' ) );

AtomicWPSocialSync\Plugin::instance()->boot();
