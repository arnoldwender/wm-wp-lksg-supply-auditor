<?php
/**
 * Plugin Name:       WM LkSG Supply Chain Auditor
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-lksg-supply-auditor/
 * Description:       Supplier register for the German supply-chain due-diligence act (LkSG): a two-level heuristic risk score, a public self-disclosure form and a yearly summary of the register counts. It does not produce a BAFA report.
 * Version:           1.2.3
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-lksg-supply-auditor
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\LkSG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WM_LKSG_VERSION' ) ) {
	define( 'WM_LKSG_VERSION', '1.2.3' );
}
if ( ! defined( 'WM_LKSG_FILE' ) ) {
	define( 'WM_LKSG_FILE', __FILE__ );
}
if ( ! defined( 'WM_LKSG_PATH' ) ) {
	define( 'WM_LKSG_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WM_LKSG_URL' ) ) {
	define( 'WM_LKSG_URL', plugin_dir_url( __FILE__ ) );
}

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix = 'WMCyber\\LkSG\\';
	$base_dir = WM_LKSG_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Lifecycle Hooks
register_activation_hook( __FILE__, [ Database\Schema::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Database\Schema::class, 'deactivate' ] );

add_action( 'wp_initialize_site', function ( object $new_site ): void {
	if ( ! is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	$blog_id = isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;
	if ( $blog_id <= 0 ) {
		return;
	}

	switch_to_blog( $blog_id );
	try {
		Database\Schema::activate();
	} finally {
		restore_current_blog();
	}
}, 20 );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\LkSG\Adapters\Lksg_Spoke_Adapter' ) && file_exists( WM_LKSG_PATH . 'includes/Adapters/Lksg_Spoke_Adapter.php' ) ) {
		require_once WM_LKSG_PATH . 'includes/Adapters/Lksg_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\LkSG\Adapters\Lksg_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Lksg_Spoke_Adapter();
	}
	return $modules;
} );

add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

