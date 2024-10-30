<?php
/**
 * Plugin Name: Modula Photoblocks Gallery Migrator
 * Plugin URI: https://wp-modula.com/
 * Description: Submodule that helps migrate galleries from Photoblocks Gallery to Modula Gallery
 * Author: WPChill
 * Author URI: https://www.wpchill.com/
 * Version: 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MODULA_PBG_MIGRATOR_VERSION', '1.0.0' );
define( 'MODULA_PBG_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MODULA_PBG_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'MODULA_PBG_MIGRATOR_FILE', __FILE__ );

require_once MODULA_PBG_MIGRATOR_PATH . 'includes/class-modula-photoblocks-migrator.php';

// Load the main plugin class.
$modula_photoblocks_migrator = Modula_Photoblocks_Migrator::get_instance();
