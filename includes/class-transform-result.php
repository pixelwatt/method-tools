<?php
/**
 * Outcome of running a content transform over one post.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Transform_Result {

	/** Something an editor should look at after the run. */
	const WARNING = 'warning';

	/** Expected, lossy detail (a setting the target block can't hold). */
	const NOTICE = 'notice';

	/** The transform refused to touch the post. */
	const ERROR = 'error';

	/** @var string Transformed content (the original when unchanged). */
	public $content;

	/** @var bool */
	public $changed = false;

	/** @var array<string,int> Tool-defined counters, e.g. accordions => 2. */
	public $counts = array();

	/** @var array[] Each: [ 'level' => WARNING|NOTICE|ERROR, 'text' => string ]. */
	public $messages = array();

	/**
	 * @param string $content Original content.
	 */
	public function __construct( $content ) {
		$this->content = (string) $content;
	}

	/**
	 * @param string $level WARNING|NOTICE|ERROR.
	 * @param string $text  Human-readable message.
	 * @return $this
	 */
	public function add( $level, $text ) {
		$this->messages[] = array(
			'level' => $level,
			'text'  => $text,
		);
		return $this;
	}

	/**
	 * @param string $key Counter name.
	 * @param int    $by  Increment.
	 * @return $this
	 */
	public function bump( $key, $by = 1 ) {
		$this->counts[ $key ] = ( isset( $this->counts[ $key ] ) ? $this->counts[ $key ] : 0 ) + $by;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function has_errors() {
		foreach ( $this->messages as $m ) {
			if ( self::ERROR === $m['level'] ) {
				return true;
			}
		}
		return false;
	}
}
