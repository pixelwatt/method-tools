<?php
/**
 * Offset-preserving block parser.
 *
 * parse_blocks() + serialize_blocks() round-trips are not byte-stable (an
 * empty `{}` attribute object comes back as `[]`, attribute JSON is
 * re-encoded, and so on), so tools that rewrite one kind of block must not
 * re-serialize the rest of the post. This parser records where every block
 * starts and ends in the original string so a tool can splice replacements
 * into the source and leave every other byte untouched.
 *
 * Tokenizing uses the same delimiter grammar as core's WP_Block_Parser, so
 * both parsers see the same blocks.
 *
 * @package Method_Tools
 */

namespace Method_Tools\Blocks;

defined( 'ABSPATH' ) || exit;

final class Block_Document {

	/**
	 * Block delimiter pattern, identical to WP_Block_Parser::next_token().
	 */
	const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/** @var string */
	public $source;

	/** @var Block_Node[] Top-level blocks. */
	public $blocks = array();

	/** @var string[] Structural problems (unclosed or mismatched delimiters). */
	public $errors = array();

	/**
	 * @param string $source Post content.
	 * @return self
	 */
	public static function parse( $source ) {
		$doc         = new self();
		$doc->source = (string) $source;
		$doc->tokenize();
		return $doc;
	}

	/**
	 * Whether the delimiter structure is sound enough to rewrite safely.
	 *
	 * @return bool
	 */
	public function is_well_formed() {
		return empty( $this->errors );
	}

	/**
	 * Depth-first list of every block.
	 *
	 * @return Block_Node[]
	 */
	public function all() {
		$out   = array();
		$stack = array_reverse( $this->blocks );
		while ( $stack ) {
			$node  = array_pop( $stack );
			$out[] = $node;
			for ( $i = count( $node->children ) - 1; $i >= 0; $i-- ) {
				$stack[] = $node->children[ $i ];
			}
		}
		return $out;
	}

	/**
	 * Count blocks by name.
	 *
	 * @param string|string[] $names Block name(s).
	 * @return int
	 */
	public function count( $names ) {
		$names = (array) $names;
		$n     = 0;
		foreach ( $this->all() as $node ) {
			if ( in_array( $node->name, $names, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Opening delimiters of every block not in $exclude, in document order.
	 * Two documents with equal fingerprints contain the same blocks with the
	 * same attributes, whatever happened to the excluded blocks around them.
	 *
	 * @param string[] $exclude Block names to leave out.
	 * @return string[]
	 */
	public function fingerprint( array $exclude = array() ) {
		$out = array();
		foreach ( $this->all() as $node ) {
			if ( ! in_array( $node->name, $exclude, true ) ) {
				$out[] = $node->opener;
			}
		}
		return $out;
	}

	/**
	 * Build the tree.
	 */
	private function tokenize() {
		$source = $this->source;
		$length = strlen( $source );
		$offset = 0;
		$stack  = array();

		while ( $offset < $length ) {
			if ( ! preg_match( self::DELIMITER, $source, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				if ( preg_last_error() !== PREG_NO_ERROR ) {
					$this->errors[] = 'PCRE error while scanning block delimiters (' . preg_last_error() . ').';
				}
				break;
			}

			$start  = $m[0][1];
			$end    = $start + strlen( $m[0][0] );
			$name   = ( isset( $m['namespace'] ) && -1 !== $m['namespace'][1] && '' !== $m['namespace'][0] ? $m['namespace'][0] : 'core/' ) . $m['name'][0];
			$closer = isset( $m['closer'] ) && -1 !== $m['closer'][1] && '' !== $m['closer'][0];
			$void   = isset( $m['void'] ) && -1 !== $m['void'][1] && '' !== $m['void'][0];
			$offset = $end;

			if ( $closer ) {
				if ( ! $stack ) {
					$this->errors[] = sprintf( 'Closing delimiter for %s at byte %d has no opener.', $name, $start );
					continue;
				}
				$node = array_pop( $stack );
				if ( $node->name !== $name ) {
					$this->errors[] = sprintf( 'Closing delimiter for %s at byte %d closes %s.', $name, $start, $node->name );
				}
				$node->closer_start = $start;
				$node->end          = $end;
				continue;
			}

			$node             = new Block_Node();
			$node->name       = $name;
			$node->opener     = $m[0][0];
			$node->start      = $start;
			$node->opener_end = $end;
			$node->void       = $void;

			if ( isset( $m['attrs'] ) && -1 !== $m['attrs'][1] && '' !== $m['attrs'][0] ) {
				$decoded = json_decode( $m['attrs'][0], true );
				if ( is_array( $decoded ) ) {
					$node->attrs = $decoded;
				} else {
					$node->attrs_valid = false;
				}
			}

			if ( $void ) {
				$node->closer_start = $end;
				$node->end          = $end;
			}

			if ( $stack ) {
				$stack[ count( $stack ) - 1 ]->children[] = $node;
			} else {
				$this->blocks[] = $node;
			}

			if ( ! $void ) {
				$stack[] = $node;
			}
		}

		foreach ( $stack as $unclosed ) {
			$this->errors[]         = sprintf( 'Block %s opened at byte %d is never closed.', $unclosed->name, $unclosed->start );
			$unclosed->closer_start = $length;
			$unclosed->end          = $length;
		}
	}
}
