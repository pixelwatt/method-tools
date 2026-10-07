<?php
/**
 * Base class for tools that rewrite post content.
 *
 * A subclass supplies the transform; the framework supplies everything
 * around it: which posts to look at (post types that use the block editor,
 * statuses, ID lists), a candidate query prefiltered in SQL, a dry run, a
 * batched apply that saves through wp_update_post() with a revision and a
 * per-run backup, run history with restore, REST routes and WP-CLI.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

abstract class Post_Tool extends Tool {

	/**
	 * Tool options shown above the targeting form and accepted by REST/CLI.
	 *
	 *   [
	 *     'direction' => [
	 *       'type'    => 'radio',               // radio | select | checkbox
	 *       'label'   => 'Direction',
	 *       'default' => 'a',
	 *       'choices' => [ 'a' => [ 'label' => '…', 'description' => '…' ] ],
	 *     ],
	 *   ]
	 *
	 * @return array
	 */
	abstract public function option_fields();

	/**
	 * Strings a post's content must contain (any of them) to be considered.
	 * Used as a SQL LIKE prefilter so large sites aren't loaded post by post.
	 *
	 * @param array $options Sanitized options.
	 * @return string[]
	 */
	abstract public function content_needles( array $options );

	/**
	 * Transform one post's content. Must be pure: no writes.
	 *
	 * @param string    $content Raw post_content.
	 * @param array     $options Sanitized options.
	 * @param \WP_Post  $post    The post (for context only).
	 * @return Transform_Result
	 */
	abstract public function transform( $content, array $options, $post );

	/**
	 * Site-level notices for these options (e.g. "target block isn't
	 * registered here"). Each: [ 'level' => …, 'text' => … ].
	 *
	 * @param array $options Sanitized options.
	 * @return array[]
	 */
	public function environment_notices( array $options ) {
		return array();
	}

	/**
	 * Short description of a run with these options, for run history.
	 *
	 * @param array $options Sanitized options.
	 * @return string
	 */
	public function run_label( array $options ) {
		return $this->label();
	}

	/**
	 * Column labels for Transform_Result::$counts keys, in display order.
	 *
	 * @return array<string,string>
	 */
	public function count_labels() {
		return array();
	}

	/**
	 * Validate raw options against option_fields(); unknown keys dropped,
	 * invalid values replaced with defaults.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public function sanitize_options( array $raw ) {
		$clean = array();
		foreach ( $this->option_fields() as $key => $field ) {
			$default = isset( $field['default'] ) ? $field['default'] : null;
			$value   = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;
			$type    = isset( $field['type'] ) ? $field['type'] : 'radio';

			if ( 'checkbox' === $type ) {
				$clean[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				continue;
			}
			$choices       = isset( $field['choices'] ) ? array_keys( $field['choices'] ) : array();
			$clean[ $key ] = in_array( (string) $value, array_map( 'strval', $choices ), true ) ? (string) $value : $default;
		}
		return $clean;
	}

	/**
	 * Admin panel.
	 */
	public function render() {
		Admin::render_post_tool( $this );
	}
}
