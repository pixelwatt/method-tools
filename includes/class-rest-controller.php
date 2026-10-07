<?php
/**
 * REST routes behind the admin UI (namespace method-tools/v1).
 *
 *   POST /tools/{tool}/candidates   options, criteria        → ids, notices
 *   POST /tools/{tool}/process      options, ids, mode, run  → results
 *   POST /tools/{tool}/runs         options, criteria        → run
 *   GET  /tools/{tool}/runs                                  → runs
 *   POST /runs/{run}/finish                                  → run
 *   POST /runs/{run}/restore        after, force             → batch outcome
 *   POST /runs/{run}/discard                                 → deleted
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class REST_Controller {

	const NS = 'method-tools/v1';

	/** Hard cap on posts per process request. */
	const MAX_BATCH = 50;

	public static function register_routes() {
		$tool_arg = array(
			'tool' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z0-9-]+$',
				'required' => true,
			),
		);
		$run_arg  = array(
			'run' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z0-9-]+$',
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/tools/(?P<tool>[a-z0-9-]+)/candidates',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'candidates' ),
				'permission_callback' => array( __CLASS__, 'can_use_tool' ),
				'args'                => $tool_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/tools/(?P<tool>[a-z0-9-]+)/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'process' ),
				'permission_callback' => array( __CLASS__, 'can_use_tool' ),
				'args'                => $tool_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/tools/(?P<tool>[a-z0-9-]+)/runs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_runs' ),
					'permission_callback' => array( __CLASS__, 'can_use_tool' ),
					'args'                => $tool_arg,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'start_run' ),
					'permission_callback' => array( __CLASS__, 'can_use_tool' ),
					'args'                => $tool_arg,
				),
			)
		);
		foreach ( array( 'finish', 'restore', 'discard' ) as $action ) {
			register_rest_route(
				self::NS,
				'/runs/(?P<run>[a-z0-9-]+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $action . '_run' ),
					'permission_callback' => array( __CLASS__, 'can_use_run' ),
					'args'                => $run_arg,
				)
			);
		}
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_use_tool( $request ) {
		$tool = Plugin::instance()->tool( $request['tool'] );
		return $tool instanceof Post_Tool && current_user_can( $tool->capability() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_use_run( $request ) {
		$run  = Runs::get( $request['run'] );
		$tool = $run ? Plugin::instance()->tool( $run['tool'] ) : null;
		return $tool instanceof Post_Tool && current_user_can( $tool->capability() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function candidates( $request ) {
		list( $tool, $options, $criteria ) = self::read( $request );
		$ids = Post_Tool_Runner::candidates( $tool, $options, $criteria );
		return rest_ensure_response(
			array(
				'ids'     => $ids,
				'total'   => count( $ids ),
				'notices' => $tool->environment_notices( $options ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function process( $request ) {
		list( $tool, $options ) = self::read( $request );

		$ids  = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		$mode = Post_Tool_Runner::APPLY === $request->get_param( 'mode' ) ? Post_Tool_Runner::APPLY : Post_Tool_Runner::PREVIEW;
		$run  = null;

		if ( count( $ids ) > self::MAX_BATCH ) {
			return new \WP_Error( 'method_tools_batch_too_large', sprintf( 'At most %d posts per request.', self::MAX_BATCH ), array( 'status' => 400 ) );
		}
		if ( Post_Tool_Runner::APPLY === $mode ) {
			$run = Runs::get( (string) $request->get_param( 'run' ) );
			if ( ! $run || $run['tool'] !== $tool->id() || ! empty( $run['finished'] ) ) {
				return new \WP_Error( 'method_tools_bad_run', 'Apply requires an open run for this tool.', array( 'status' => 400 ) );
			}
			if ( $run['options'] !== $options ) {
				return new \WP_Error( 'method_tools_run_mismatch', 'Options differ from the run they belong to.', array( 'status' => 400 ) );
			}
		}

		return rest_ensure_response(
			array(
				'results' => Post_Tool_Runner::process( $tool, $options, $ids, $mode, $run ? $run['id'] : null ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function start_run( $request ) {
		list( $tool, $options, $criteria ) = self::read( $request );
		return rest_ensure_response( Runs::create( $tool, $options, $criteria ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_runs( $request ) {
		$runs = Runs::all( $request['tool'] );
		foreach ( $runs as &$run ) {
			$run['backups'] = Runs::backups_remaining( $run['id'] );
			unset( $run['posts'] );
		}
		return rest_ensure_response( $runs );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function finish_run( $request ) {
		return rest_ensure_response( Runs::update( $request['run'], array( 'finished' => gmdate( 'Y-m-d H:i:s' ) ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function restore_run( $request ) {
		return rest_ensure_response(
			Runs::restore_batch(
				$request['run'],
				absint( $request->get_param( 'after' ) ),
				Plugin::batch_size(),
				rest_sanitize_boolean( $request->get_param( 'force' ) )
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function discard_run( $request ) {
		return rest_ensure_response( array( 'deleted' => Runs::discard_backups( $request['run'] ) ) );
	}

	/**
	 * Tool, sanitized options, sanitized criteria from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0:Post_Tool,1:array,2:array}
	 */
	private static function read( $request ) {
		$tool     = Plugin::instance()->tool( $request['tool'] );
		$options  = $tool->sanitize_options( (array) $request->get_param( 'options' ) );
		$criteria = Post_Scanner::sanitize_criteria( (array) $request->get_param( 'criteria' ) );
		return array( $tool, $options, $criteria );
	}
}
