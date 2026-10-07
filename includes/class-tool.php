<?php
/**
 * Base class for every Method Tools tool.
 *
 * A tool gets a tab on Tools → Method Tools. Extend Post_Tool instead when
 * the tool rewrites post content: it inherits the targeting form, dry run,
 * batched apply, backups/restore, REST routes and WP-CLI command.
 *
 * Register a tool:
 *
 *   add_action( 'method_tools_register', function ( \Method_Tools\Plugin $plugin ) {
 *       $plugin->register( new My_Tool() );
 *   } );
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

abstract class Tool {

	/**
	 * Unique slug: lowercase letters, digits, hyphens.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * One or two sentences shown above the tool.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Capability required to see and run the tool.
	 *
	 * @return string
	 */
	public function capability() {
		return Plugin::capability();
	}

	/**
	 * Output the tool's admin panel. Post_Tool provides this.
	 */
	abstract public function render();
}
