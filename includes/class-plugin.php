<?php
/**
 * Plugin bootstrap and tool registry.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance;

	/** @var Tool[] Keyed by tool ID, in registration order. */
	private $tools = array();

	/** @var bool */
	private $tools_loaded = false;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooked to plugins_loaded.
	 */
	public static function boot() {
		$plugin = self::instance();
		add_action( 'rest_api_init', array( REST_Controller::class, 'register_routes' ) );
		add_action( 'admin_menu', array( Admin::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( Admin::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( METHOD_TOOLS_FILE ), array( Admin::class, 'action_links' ) );
		return $plugin;
	}

	/**
	 * Register a tool. Call from the method_tools_register action.
	 *
	 * @param Tool $tool Tool instance.
	 */
	public function register( Tool $tool ) {
		$id = $tool->id();
		if ( ! preg_match( '/^[a-z0-9-]+$/', $id ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Invalid Method Tools tool ID "%s".', $id ) ), '0.1.0' );
			return;
		}
		$this->tools[ $id ] = $tool;
	}

	/**
	 * Registered tools. Built-ins load first, then the method_tools_register
	 * action lets themes and plugins add their own.
	 *
	 * @return Tool[]
	 */
	public function tools() {
		if ( ! $this->tools_loaded ) {
			$this->tools_loaded = true;
			$this->register( new Tools\Accordion_Converter_Tool() );

			/**
			 * Register additional tools.
			 *
			 * @param Plugin $plugin Call $plugin->register( $tool ).
			 */
			do_action( 'method_tools_register', $this );
		}
		return $this->tools;
	}

	/**
	 * @param string $id Tool ID.
	 * @return Tool|null
	 */
	public function tool( $id ) {
		$tools = $this->tools();
		return isset( $tools[ $id ] ) ? $tools[ $id ] : null;
	}

	/**
	 * Default capability for the plugin and its tools.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'method_tools_capability', 'manage_options' );
	}

	/**
	 * Posts per apply/restore request.
	 *
	 * @return int
	 */
	public static function batch_size() {
		return max( 1, min( REST_Controller::MAX_BATCH, (int) apply_filters( 'method_tools_batch_size', 10 ) ) );
	}
}
