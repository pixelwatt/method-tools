<?php
/**
 * Plugin Name: Method Tools
 * Description: Maintenance tools for Method-powered WordPress sites. Ships with an accordion converter (core/accordion ⇄ method/accordion).
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Rob Clark
 * Author URI: https://robclark.io
 * License: GPLv2 or later
 * Text Domain: method-tools
 * GitHub Plugin URI: https://github.com/pixelwatt/method-tools
 * Primary Branch: main
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'METHOD_TOOLS_VERSION' ) ) {
	return;
}

define( 'METHOD_TOOLS_VERSION', '0.1.1' );
define( 'METHOD_TOOLS_FILE', __FILE__ );
define( 'METHOD_TOOLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'METHOD_TOOLS_URL', plugin_dir_url( __FILE__ ) );

// Framework.
require_once METHOD_TOOLS_DIR . 'includes/blocks/class-block-node.php';
require_once METHOD_TOOLS_DIR . 'includes/blocks/class-block-document.php';
require_once METHOD_TOOLS_DIR . 'includes/blocks/class-block-markup.php';
require_once METHOD_TOOLS_DIR . 'includes/class-transform-result.php';
require_once METHOD_TOOLS_DIR . 'includes/class-tool.php';
require_once METHOD_TOOLS_DIR . 'includes/class-post-tool.php';
require_once METHOD_TOOLS_DIR . 'includes/class-environment.php';
require_once METHOD_TOOLS_DIR . 'includes/class-post-scanner.php';
require_once METHOD_TOOLS_DIR . 'includes/class-content-writer.php';
require_once METHOD_TOOLS_DIR . 'includes/class-runs.php';
require_once METHOD_TOOLS_DIR . 'includes/class-post-tool-runner.php';
require_once METHOD_TOOLS_DIR . 'includes/class-rest-controller.php';
require_once METHOD_TOOLS_DIR . 'includes/class-admin.php';
require_once METHOD_TOOLS_DIR . 'includes/class-plugin.php';

// Tools.
require_once METHOD_TOOLS_DIR . 'tools/accordion-converter/class-accordion-converter.php';
require_once METHOD_TOOLS_DIR . 'tools/accordion-converter/class-accordion-converter-tool.php';

add_action( 'plugins_loaded', array( 'Method_Tools\\Plugin', 'boot' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once METHOD_TOOLS_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'method-tools', 'Method_Tools\\CLI' );
}
