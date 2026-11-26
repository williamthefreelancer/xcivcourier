<?php
/**
 * Plugin Name: XCIV Courier Core
 * Description: Ops dashboard, lead-rider modes, hubs, and Mapbox / custom lead-rider map for XCIV Courier.
 * Version: 1.0.2
 * Author: XCIV
 * Text Domain: xciv-courier-core
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package XCIV_Courier_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'XCIV_CORE_VERSION', '1.0.2' );
define( 'XCIV_CORE_FILE', __FILE__ );
define( 'XCIV_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'XCIV_CORE_URI', plugin_dir_url( __FILE__ ) );
define( 'XCIV_CORE_OPTION', 'xciv_core_settings' );

require_once XCIV_CORE_DIR . 'includes/settings.php';
require_once XCIV_CORE_DIR . 'includes/map.php';
require_once XCIV_CORE_DIR . 'includes/admin.php';

/**
 * Boot hooks.
 */
function xciv_core_boot(): void {
	xciv_core_register_settings();
}
add_action( 'init', 'xciv_core_boot' );

/**
 * Activation defaults.
 */
function xciv_core_activate(): void {
	if ( get_option( XCIV_CORE_OPTION ) === false ) {
		add_option( XCIV_CORE_OPTION, xciv_core_default_settings() );
	}
}
register_activation_hook( __FILE__, 'xciv_core_activate' );
