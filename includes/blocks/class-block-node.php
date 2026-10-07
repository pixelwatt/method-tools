<?php
/**
 * One block in a Block_Document, with byte offsets into the source.
 *
 * @package Method_Tools
 */

namespace Method_Tools\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Offsets (all into Block_Document::$source):
 *
 *   start        first byte of the opening delimiter `<!-- wp:name ... -->`
 *   opener_end   first byte after the opening delimiter
 *   closer_start first byte of `<!-- /wp:name -->` (equals opener_end for void blocks)
 *   end          first byte after the closing delimiter
 *
 * Everything between opener_end and closer_start is the block's inner source:
 * its own saved HTML interleaved with its children's complete source.
 */
final class Block_Node {

	/** @var string Fully-qualified name, e.g. "core/paragraph". */
	public $name;

	/** @var string The opening delimiter exactly as written. */
	public $opener;

	/** @var array Decoded attributes (empty array when none). */
	public $attrs = array();

	/** @var bool False when the delimiter carried attributes that are not valid JSON. */
	public $attrs_valid = true;

	/** @var bool Self-closing `<!-- wp:name /-->`. */
	public $void = false;

	/** @var int */
	public $start;

	/** @var int */
	public $opener_end;

	/** @var int */
	public $closer_start;

	/** @var int */
	public $end;

	/** @var Block_Node[] */
	public $children = array();

	/**
	 * Complete source of the block, delimiters included.
	 *
	 * @param string $source Document source.
	 * @return string
	 */
	public function outer( $source ) {
		return (string) substr( $source, $this->start, $this->end - $this->start );
	}

	/**
	 * The block's own saved HTML with child blocks removed — the equivalent
	 * of `innerHTML` from parse_blocks().
	 *
	 * @param string $source Document source.
	 * @return string
	 */
	public function inner_html( $source ) {
		if ( $this->void ) {
			return '';
		}
		$html   = '';
		$cursor = $this->opener_end;
		foreach ( $this->children as $child ) {
			$html  .= substr( $source, $cursor, $child->start - $cursor );
			$cursor = $child->end;
		}
		$html .= substr( $source, $cursor, $this->closer_start - $cursor );
		return $html;
	}

	/**
	 * Source from the first child's opening delimiter to the last child's
	 * closing delimiter, verbatim (separators between children included).
	 *
	 * @param string $source Document source.
	 * @return string Empty string when the block has no children.
	 */
	public function children_source( $source ) {
		if ( empty( $this->children ) ) {
			return '';
		}
		$first = $this->children[0];
		$last  = $this->children[ count( $this->children ) - 1 ];
		return (string) substr( $source, $first->start, $last->end - $first->start );
	}
}
