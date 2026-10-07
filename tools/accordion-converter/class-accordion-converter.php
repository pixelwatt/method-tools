<?php
/**
 * core/accordion ⇄ method/accordion conversion.
 *
 * Works on raw block markup and never asks the block registry anything, so
 * it runs the same on a site that predates the core Accordion block
 * (WordPress < 6.9) as on one that has it.
 *
 * Markup written for each side is what that block's save() produces:
 *
 * - core: the WordPress 6.9 / 7.0 save output. Later Gutenberg versions
 *   (which add has-icon classes to the heading) list this exact markup as a
 *   deprecation and migrate it silently in the editor.
 * - Method: the save() of Method's accordion, item and body blocks (static
 *   wrappers around inner blocks; the PHP render callbacks add the rest).
 *
 * Only accordion blocks are rebuilt. Panel/body content is copied verbatim
 * from the source string (nested accordions inside it are converted the same
 * way), and every byte outside the converted accordions is left untouched.
 *
 * @package Method_Tools
 */

namespace Method_Tools\Tools;

use Method_Tools\Blocks\Block_Document;
use Method_Tools\Blocks\Block_Markup;
use Method_Tools\Blocks\Block_Node;
use Method_Tools\Transform_Result;

defined( 'ABSPATH' ) || exit;

final class Accordion_Converter {

	const CORE_TO_METHOD = 'core-to-method';
	const METHOD_TO_CORE = 'method-to-core';

	const CORE_ACCORDION = 'core/accordion';
	const CORE_ITEM      = 'core/accordion-item';
	const CORE_HEADING   = 'core/accordion-heading';
	const CORE_PANEL     = 'core/accordion-panel';

	const METHOD_ACCORDION = 'method/accordion';
	const METHOD_ITEM      = 'method/accordion-item';
	const METHOD_BODY      = 'method/accordion-body';

	const FAMILY = array(
		self::CORE_ACCORDION,
		self::CORE_ITEM,
		self::CORE_HEADING,
		self::CORE_PANEL,
		self::METHOD_ACCORDION,
		self::METHOD_ITEM,
		self::METHOD_BODY,
	);

	/** Attributes every block type accepts; carried across when present. */
	const UNIVERSAL_ATTRS = array( 'lock', 'metadata' );

	/** @var string */
	private $direction;

	/** @var callable Returns a new accordionId. */
	private $id_factory;

	/** @var string Source being converted. */
	private $source = '';

	/** @var Transform_Result */
	private $result;

	/** @var int Source accordions seen, in document order (for messages). */
	private $seen = 0;

	/**
	 * @param string        $direction  CORE_TO_METHOD or METHOD_TO_CORE.
	 * @param callable|null $id_factory Generates Method accordion IDs (tests pass a deterministic one).
	 */
	public function __construct( $direction, $id_factory = null ) {
		$this->direction  = self::METHOD_TO_CORE === $direction ? self::METHOD_TO_CORE : self::CORE_TO_METHOD;
		$this->id_factory = $id_factory ? $id_factory : 'wp_generate_uuid4';
	}

	/**
	 * @param string $content Post content.
	 * @return Transform_Result
	 */
	public function convert( $content ) {
		$this->source = (string) $content;
		$this->result = new Transform_Result( $this->source );
		$this->seen   = 0;

		$root = $this->source_root();
		$doc  = Block_Document::parse( $this->source );

		$this->result->counts = array(
			'accordions' => 0,
			'items'      => 0,
			'skipped'    => 0,
		);

		if ( 0 === $doc->count( $root ) ) {
			return $this->result;
		}
		if ( ! $doc->is_well_formed() ) {
			$this->result->add( Transform_Result::ERROR, sprintf( 'Block markup in this post is malformed, so nothing was changed. %s', $doc->errors[0] ) );
			return $this->result;
		}
		if ( ! preg_match( '//u', $this->source ) ) {
			$this->result->add( Transform_Result::ERROR, 'This post contains bytes that are not valid UTF-8, so nothing was changed. Re-save it in the editor or fix its encoding first.' );
			return $this->result;
		}

		$converted = $this->rewrite_range( $doc->blocks, 0, strlen( $this->source ) );

		if ( $converted === $this->source ) {
			return $this->result;
		}

		// Self-check: the result must parse cleanly and contain exactly the
		// same non-accordion blocks, with the same attributes, in the same order.
		$after = Block_Document::parse( $converted );
		if ( ! $after->is_well_formed() || $after->fingerprint( self::FAMILY ) !== $doc->fingerprint( self::FAMILY ) ) {
			$this->result->add( Transform_Result::ERROR, 'Self-check failed: the converted markup would not preserve the other blocks in this post exactly, so nothing was changed. Please report this post.' );
			return $this->result;
		}

		$this->result->content = $converted;
		$this->result->changed = true;

		if ( self::CORE_TO_METHOD === $this->direction && $after->count( self::METHOD_ACCORDION ) > 1 ) {
			$this->result->add(
				Transform_Result::WARNING,
				sprintf(
					'This post now has %d Method accordions. Method gives item panels the IDs collapse1, collapse2, … per accordion, so on one page the toggles of a later accordion open the matching panel of the first. Check this page on the front end.',
					$after->count( self::METHOD_ACCORDION )
				)
			);
		}

		return $this->result;
	}

	/**
	 * @return string
	 */
	private function source_root() {
		return self::CORE_TO_METHOD === $this->direction ? self::CORE_ACCORDION : self::METHOD_ACCORDION;
	}

	/**
	 * Source between $from and $to with each node in $nodes rewritten.
	 *
	 * @param Block_Node[] $nodes Sibling nodes lying within [$from, $to).
	 * @param int          $from  Start offset.
	 * @param int          $to    End offset.
	 * @return string
	 */
	private function rewrite_range( array $nodes, $from, $to ) {
		$out    = '';
		$cursor = $from;
		foreach ( $nodes as $node ) {
			$out   .= substr( $this->source, $cursor, $node->start - $cursor );
			$out   .= $this->rewrite_node( $node );
			$cursor = $node->end;
		}
		return $out . substr( $this->source, $cursor, $to - $cursor );
	}

	/**
	 * @param Block_Node $node Node.
	 * @return string Replacement source for the node.
	 */
	private function rewrite_node( Block_Node $node ) {
		if ( $node->name === $this->source_root() ) {
			$this->seen++;
			$converted = self::CORE_TO_METHOD === $this->direction
				? $this->core_to_method( $node )
				: $this->method_to_core( $node );
			if ( null !== $converted ) {
				return $converted;
			}
			$this->result->bump( 'skipped' );
		}

		if ( ! $node->children ) {
			return $node->outer( $this->source );
		}

		return substr( $this->source, $node->start, $node->opener_end - $node->start )
			. $this->rewrite_range( $node->children, $node->opener_end, $node->closer_start )
			. substr( $this->source, $node->closer_start, $node->end - $node->closer_start );
	}

	/**
	 * Converted source of a container's children (nested accordions included),
	 * from the first child's delimiter to the last child's.
	 *
	 * @param Block_Node|null $container Panel or body.
	 * @return string
	 */
	private function inner_blocks( $container ) {
		if ( ! $container || ! $container->children ) {
			return '';
		}
		$first = $container->children[0];
		$last  = $container->children[ count( $container->children ) - 1 ];
		return $this->rewrite_range( $container->children, $first->start, $last->end );
	}

	// ---------------------------------------------------------------------
	// core → Method
	// ---------------------------------------------------------------------

	/**
	 * @param Block_Node $accordion core/accordion node.
	 * @return string|null Method markup, or null to leave the block as is.
	 */
	private function core_to_method( Block_Node $accordion ) {
		$label = $this->label( $accordion );
		$parts = array();

		$skip = $this->unsupported_structure( $accordion, self::CORE_ITEM, array( self::CORE_HEADING, self::CORE_PANEL ) );
		if ( $skip ) {
			$this->result->add( Transform_Result::WARNING, $label . ': left unconverted — ' . $skip );
			return null;
		}

		$dropped = $this->dropped_attributes( $accordion, array( 'headingLevel', 'iconPosition', 'showIcon', 'autoclose', 'levelOptions', 'className', 'align' ), 'accordion' );
		$anchor  = Block_Markup::first_tag_attribute( $accordion->inner_html( $this->source ), 'id' );
		if ( null !== $anchor && '' !== $anchor ) {
			$this->result->add( Transform_Result::WARNING, sprintf( '%s: its HTML anchor "#%s" has no Method equivalent and was dropped; links to it will no longer jump to the accordion.', $label, $anchor ) );
		}
		if ( $this->attr_differs( $accordion, 'iconPosition', 'right' ) || $this->attr_differs( $accordion, 'showIcon', true ) ) {
			$dropped[] = 'icon settings';
		}

		$levels = array();
		$open   = array();

		foreach ( $accordion->children as $i => $item ) {
			$n       = $i + 1;
			$heading = $this->first_child( $item, self::CORE_HEADING );
			$panel   = $this->first_child( $item, self::CORE_PANEL );

			$title = '';
			if ( $heading ) {
				// Shape already checked by unsupported_structure().
				$heading_html = $heading->inner_html( $this->source );
				$title        = (string) Block_Markup::inner_html_by_class( $heading_html, 'wp-block-accordion-heading__toggle-title' );

				$levels[] = ( isset( $heading->attrs['level'] ) && (int) $heading->attrs['level'] >= 1 && (int) $heading->attrs['level'] <= 6 ) ? (int) $heading->attrs['level'] : 3;

				$heading_anchor = Block_Markup::first_tag_attribute( $heading_html, 'id' );
				if ( null !== $heading_anchor && '' !== $heading_anchor ) {
					$this->result->add( Transform_Result::WARNING, sprintf( '%s: item %d\'s heading anchor "#%s" was dropped.', $label, $n, $heading_anchor ) );
				}
				$dropped = array_merge( $dropped, $this->dropped_attributes( $heading, array( 'level', 'title', 'iconPosition', 'showIcon', 'openByDefault' ), 'heading' ) );
			}

			$removed  = array();
			$headline = self::sanitize_inline( $title, self::method_headline_tags(), $removed );
			if ( $removed ) {
				$this->result->add( Transform_Result::WARNING, sprintf( '%s: item %d\'s heading lost formatting Method headlines can\'t hold (%s); the text was kept.', $label, $n, '<' . implode( '>, <', $removed ) . '>' ) );
			}
			if ( '' === Block_Markup::residual_text( $headline ) ) {
				$this->result->add( Transform_Result::WARNING, sprintf( '%s: item %d has no heading text; Method will show “Accordion Item”.', $label, $n ) );
			}

			if ( isset( $item->attrs['openByDefault'] ) && true === $item->attrs['openByDefault'] ) {
				$open[] = $n;
			}

			$dropped = array_merge(
				$dropped,
				$this->dropped_attributes( $item, array( 'openByDefault', 'className' ), 'item' ),
				$panel ? $this->dropped_attributes( $panel, array( 'openByDefault', 'isSelected', 'templateLock', 'className' ), 'panel' ) : array()
			);

			$parts[] = array(
				'headline'        => $headline,
				'item_class'      => isset( $item->attrs['className'] ) ? (string) $item->attrs['className'] : '',
				'body_class'      => $panel && isset( $panel->attrs['className'] ) ? (string) $panel->attrs['className'] : '',
				'item_universal'  => $this->universal_attrs( $item ),
				'body_universal'  => $panel ? $this->universal_attrs( $panel ) : array(),
				'inner'           => $this->inner_blocks( $panel ),
			);
		}

		// Heading level: Method has one tag for the whole accordion.
		$unique = array_values( array_unique( $levels ) );
		$level  = $unique ? $unique[0] : 3;
		if ( count( $unique ) > 1 ) {
			$this->result->add( Transform_Result::WARNING, sprintf( '%s: items used different heading levels (h%s); all now use h%d.', $label, implode( ', h', $unique ), $level ) );
		}

		// Open state: Method opens the first item, or none.
		$closed = ! in_array( 1, $open, true );
		$others = array_values( array_diff( $open, array( 1 ) ) );
		if ( $others ) {
			$this->result->add(
				Transform_Result::WARNING,
				sprintf(
					'%s: %s set to open by default, but a Method accordion can only start with its first item open%s.',
					$label,
					1 === count( $others ) ? 'item ' . $others[0] . ' was' : 'items ' . implode( ', ', array_slice( $others, 0, -1 ) ) . ' and ' . end( $others ) . ' were',
					$closed ? ', so all items now start closed' : ''
				)
			);
		}

		$align = isset( $accordion->attrs['align'] ) ? (string) $accordion->attrs['align'] : '';
		if ( '' !== $align && 'wide' !== $align ) {
			$dropped[] = 'align: ' . $align;
			$align     = '';
		}

		$dropped = array_values( array_unique( $dropped ) );
		if ( $dropped ) {
			$this->result->add( Transform_Result::NOTICE, sprintf( '%s: settings with no Method equivalent were dropped (%s).', $label, implode( ', ', $dropped ) ) );
		}

		// Build Method markup.
		$id    = (string) call_user_func( $this->id_factory );
		$items = array();
		foreach ( $parts as $i => $part ) {
			$n = $i + 1;

			$body_attrs = $part['body_universal'];
			if ( '' !== $part['body_class'] ) {
				$body_attrs['className'] = $part['body_class'];
			}
			$body = Block_Markup::block(
				self::METHOD_BODY,
				$body_attrs,
				'<div class="accordion-body-inner">' . $part['inner'] . '</div>'
			);

			$item_attrs = array();
			if ( '' !== $part['headline'] ) {
				$item_attrs['headline'] = $part['headline'];
			}
			$item_attrs['itemIndex']         = $n;
			$item_attrs['parentAccordionId'] = $id;
			if ( $closed ) {
				$item_attrs['closed'] = true;
			}
			$item_attrs += $part['item_universal'];
			if ( '' !== $part['item_class'] ) {
				$item_attrs['className'] = $part['item_class'];
			}

			$items[] = Block_Markup::block(
				self::METHOD_ITEM,
				$item_attrs,
				'<div class="' . esc_attr( Block_Markup::classes( array( 'accordion-collapse', 'collapse', 1 === $n && ! $closed ? 'show' : '' ) ) ) . '" id="collapse' . $n . '" data-bs-parent="#accordion-' . esc_attr( $id ) . '">' . $body . '</div>'
			);

			$this->result->bump( 'items' );
		}

		$acc_attrs = array( 'accordionId' => $id );
		if ( $closed ) {
			$acc_attrs['closed'] = true;
		}
		// Method's default tag is h2 and core's is h3, so a default core
		// accordion still gets an explicit hTag.
		if ( 2 !== $level ) {
			$acc_attrs['hTag'] = 'h' . $level;
		}
		$acc_attrs += $this->universal_attrs( $accordion );
		if ( '' !== $align ) {
			$acc_attrs['align'] = $align;
		}
		if ( isset( $accordion->attrs['className'] ) && '' !== (string) $accordion->attrs['className'] ) {
			$acc_attrs['className'] = (string) $accordion->attrs['className'];
		}

		$this->result->bump( 'accordions' );

		return Block_Markup::block(
			self::METHOD_ACCORDION,
			$acc_attrs,
			'<div class="accordion" id="accordion-' . esc_attr( $id ) . '">' . implode( "\n\n", $items ) . '</div>'
		);
	}

	// ---------------------------------------------------------------------
	// Method → core
	// ---------------------------------------------------------------------

	/**
	 * @param Block_Node $accordion method/accordion node.
	 * @return string|null Core markup, or null to leave the block as is.
	 */
	private function method_to_core( Block_Node $accordion ) {
		$label = $this->label( $accordion );

		$skip = $this->unsupported_structure( $accordion, self::METHOD_ITEM, array( self::METHOD_BODY ) );
		if ( $skip ) {
			$this->result->add( Transform_Result::WARNING, $label . ': left unconverted — ' . $skip );
			return null;
		}

		$dropped = $this->dropped_attributes( $accordion, array( 'accordionId', 'openItem', 'closed', 'hTag', 'className', 'align' ), 'accordion' );

		$tag   = isset( $accordion->attrs['hTag'] ) ? strtolower( (string) $accordion->attrs['hTag'] ) : 'h2';
		$level = preg_match( '/^h([1-6])$/', $tag, $m ) ? (int) $m[1] : 2;
		if ( 'h' . $level !== $tag ) {
			$this->result->add( Transform_Result::WARNING, sprintf( '%s: unrecognized heading tag "%s"; used h2.', $label, $tag ) );
		}
		$closed = isset( $accordion->attrs['closed'] ) && self::truthy( $accordion->attrs['closed'] );

		$items = array();
		foreach ( $accordion->children as $i => $item ) {
			$n    = $i + 1;
			$body = $this->first_child( $item, self::METHOD_BODY );
			$open = ( 1 === $n && ! $closed );

			$dropped = array_merge(
				$dropped,
				$this->dropped_attributes( $item, array( 'headline', 'itemIndex', 'parentAccordionId', 'closed', 'className' ), 'item' ),
				$body ? $this->dropped_attributes( $body, array( 'className' ), 'body' ) : array()
			);

			$removed = array();
			$title   = self::sanitize_inline( isset( $item->attrs['headline'] ) ? (string) $item->attrs['headline'] : '', self::core_title_tags(), $removed );
			if ( $removed ) {
				$this->result->add( Transform_Result::WARNING, sprintf( '%s: item %d\'s headline lost markup that can\'t sit inside the accordion toggle button (%s); the text was kept.', $label, $n, '<' . implode( '>, <', $removed ) . '>' ) );
			}
			if ( '' === Block_Markup::residual_text( $title ) ) {
				$this->result->add( Transform_Result::WARNING, sprintf( '%s: item %d has no headline; its core heading will be empty.', $label, $n ) );
			}

			$heading = Block_Markup::block(
				self::CORE_HEADING,
				array( 'level' => $level ),
				'<h' . $level . ' class="wp-block-accordion-heading"><button type="button" class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">' . $title . '</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h' . $level . '>'
			);

			$panel_attrs = array();
			if ( $open ) {
				// WordPress 6.9's editor reads this to show the panel; 7.0+ ignores it.
				$panel_attrs['openByDefault'] = true;
			}
			$panel_attrs += $body ? $this->universal_attrs( $body ) : array();
			$panel_class  = $body && isset( $body->attrs['className'] ) ? (string) $body->attrs['className'] : '';
			if ( '' !== $panel_class ) {
				$panel_attrs['className'] = $panel_class;
			}
			$panel = Block_Markup::block(
				self::CORE_PANEL,
				$panel_attrs,
				'<div role="region" class="' . esc_attr( Block_Markup::classes( array( 'wp-block-accordion-panel', $panel_class ) ) ) . '">' . $this->inner_blocks( $body ) . '</div>'
			);

			$item_attrs = array();
			if ( $open ) {
				$item_attrs['openByDefault'] = true;
			}
			$item_attrs += $this->universal_attrs( $item );
			$item_class  = isset( $item->attrs['className'] ) ? (string) $item->attrs['className'] : '';
			if ( '' !== $item_class ) {
				$item_attrs['className'] = $item_class;
			}

			$items[] = Block_Markup::block(
				self::CORE_ITEM,
				$item_attrs,
				'<div class="' . esc_attr( Block_Markup::classes( array( 'wp-block-accordion-item', $open ? 'is-open' : '', $item_class ) ) ) . '">' . $heading . "\n\n" . $panel . '</div>'
			);

			$this->result->bump( 'items' );
		}

		$align = isset( $accordion->attrs['align'] ) ? (string) $accordion->attrs['align'] : '';
		if ( '' !== $align && ! in_array( $align, array( 'wide', 'full' ), true ) ) {
			$dropped[] = 'align: ' . $align;
			$align     = '';
		}
		$class = isset( $accordion->attrs['className'] ) ? (string) $accordion->attrs['className'] : '';

		// Method (Bootstrap data-bs-parent) keeps one panel open at a time;
		// core's equivalent is autoclose, which defaults to off.
		$acc_attrs = array( 'autoclose' => true );
		if ( 3 !== $level ) {
			$acc_attrs['headingLevel'] = $level;
		}
		$acc_attrs += $this->universal_attrs( $accordion );
		if ( '' !== $align ) {
			$acc_attrs['align'] = $align;
		}
		if ( '' !== $class ) {
			$acc_attrs['className'] = $class;
		}

		$dropped = array_values( array_unique( $dropped ) );
		if ( $dropped ) {
			$this->result->add( Transform_Result::NOTICE, sprintf( '%s: settings with no core equivalent were dropped (%s).', $label, implode( ', ', $dropped ) ) );
		}

		$this->result->bump( 'accordions' );

		return Block_Markup::block(
			self::CORE_ACCORDION,
			$acc_attrs,
			'<div role="group" class="' . esc_attr( Block_Markup::classes( array( 'wp-block-accordion', '' !== $align ? 'align' . $align : '', $class ) ) ) . '">' . implode( "\n\n", $items ) . '</div>'
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Why an accordion can't be converted safely, or '' if it can.
	 *
	 * Checks: readable attributes; only item blocks inside the accordion; only
	 * the expected parts (each at most once) inside every item; and no text in
	 * any wrapper's own HTML that the rebuild would lose.
	 *
	 * @param Block_Node $accordion  Accordion node.
	 * @param string     $item_name  Expected item block.
	 * @param string[]   $part_names Allowed blocks inside an item.
	 * @return string
	 */
	private function unsupported_structure( Block_Node $accordion, $item_name, array $part_names ) {
		if ( ! $accordion->attrs_valid ) {
			return 'its block attributes are not valid JSON.';
		}
		if ( ! $accordion->children ) {
			return 'it has no items.';
		}
		if ( ! $this->wrapper_only( $accordion ) ) {
			return 'its own markup contains content besides its items.';
		}
		foreach ( $accordion->children as $i => $item ) {
			$n = $i + 1;
			if ( $item->name !== $item_name ) {
				return sprintf( 'it contains a %s block where items were expected.', $item->name );
			}
			if ( ! $item->attrs_valid ) {
				return sprintf( 'item %d has invalid block attributes.', $n );
			}
			$seen = array();
			foreach ( $item->children as $part ) {
				if ( ! in_array( $part->name, $part_names, true ) ) {
					return sprintf( 'item %d contains a %s block.', $n, $part->name );
				}
				if ( isset( $seen[ $part->name ] ) ) {
					return sprintf( 'item %d contains more than one %s block.', $n, $part->name );
				}
				if ( ! $part->attrs_valid ) {
					return sprintf( 'item %d has a %s block with invalid attributes.', $n, $part->name );
				}
				$seen[ $part->name ] = true;

				if ( self::CORE_HEADING === $part->name ) {
					$problem = $this->heading_problem( $part );
					if ( $problem ) {
						return sprintf( 'item %d\'s heading %s', $n, $problem );
					}
				} elseif ( ! $this->wrapper_only( $part ) ) {
					return sprintf( 'item %d\'s %s contains content outside its inner blocks.', $n, $part->name );
				}
			}
			if ( ! $this->wrapper_only( $item ) ) {
				return sprintf( 'item %d contains content outside its blocks.', $n );
			}
		}
		return '';
	}

	/**
	 * Whether a container's own saved HTML is nothing but one empty wrapper
	 * element (or nothing at all), i.e. rebuilding it loses no content.
	 * Deliberately strict: text, images, iframes or extra elements in a
	 * wrapper make the accordion ineligible rather than silently dropped.
	 *
	 * @param Block_Node $node Container node.
	 * @return bool
	 */
	private function wrapper_only( Block_Node $node ) {
		$html = $node->inner_html( $this->source );
		return '' === trim( $html, " \t\n\r\0\x0B" )
			|| 1 === preg_match( '/^\s*<([a-zA-Z][a-zA-Z0-9-]*)\b[^<>]*>\s*<\/\1\s*>\s*$/', $html );
	}

	/**
	 * Why a core heading's markup can't be read safely, or ''.
	 * Expected: <hN><button><span icon>+</span>?<span title>…</span><span icon>+</span>?</button></hN>.
	 *
	 * @param Block_Node $heading core/accordion-heading node.
	 * @return string
	 */
	private function heading_problem( Block_Node $heading ) {
		if ( $heading->children ) {
			return 'contains blocks.';
		}
		$html = $heading->inner_html( $this->source );
		$span = Block_Markup::inner_html_span_by_class( $html, 'wp-block-accordion-heading__toggle-title' );
		if ( null === $span ) {
			return 'doesn\'t have the markup the core Accordion block saves.';
		}
		$rest = substr_replace( $html, '', $span[0], $span[1] );
		if ( array_diff( Block_Markup::tag_names( $rest ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'span' ) ) ) {
			return 'contains elements besides its title and toggle icon.';
		}
		if ( '' !== trim( str_replace( '+', '', Block_Markup::residual_text( $rest ) ), " \t\n\r\0\x0B" ) ) {
			return 'contains text besides its title and toggle icon.';
		}
		return '';
	}

	/**
	 * Names of attributes on a node that the conversion doesn't map.
	 *
	 * @param Block_Node $node   Node.
	 * @param string[]   $mapped Attributes the conversion handles.
	 * @param string     $where  Label for the message.
	 * @return string[]
	 */
	private function dropped_attributes( Block_Node $node, array $mapped, $where ) {
		$out = array();
		foreach ( array_keys( $node->attrs ) as $key ) {
			if ( ! in_array( $key, $mapped, true ) && ! in_array( $key, self::UNIVERSAL_ATTRS, true ) ) {
				$out[] = $key . ' (' . $where . ')';
			}
		}
		return $out;
	}

	/**
	 * lock/metadata attributes present on a node.
	 *
	 * @param Block_Node $node Node.
	 * @return array
	 */
	private function universal_attrs( Block_Node $node ) {
		$out = array();
		foreach ( self::UNIVERSAL_ATTRS as $key ) {
			if ( isset( $node->attrs[ $key ] ) ) {
				$out[ $key ] = $node->attrs[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Whether an attribute is set to something other than its default.
	 *
	 * @param Block_Node $node    Node.
	 * @param string     $key     Attribute.
	 * @param mixed      $default Default value.
	 * @return bool
	 */
	private function attr_differs( Block_Node $node, $key, $default ) {
		return array_key_exists( $key, $node->attrs ) && $node->attrs[ $key ] !== $default;
	}

	/**
	 * @param Block_Node $parent Parent.
	 * @param string     $name   Child block name.
	 * @return Block_Node|null
	 */
	private function first_child( Block_Node $parent, $name ) {
		foreach ( $parent->children as $child ) {
			if ( $child->name === $name ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * "Accordion 2 (“First question”)" — for messages.
	 *
	 * @param Block_Node $accordion Accordion node.
	 * @return string
	 */
	private function label( Block_Node $accordion ) {
		$first = '';
		if ( $accordion->children ) {
			$item = $accordion->children[0];
			if ( self::METHOD_ITEM === $item->name && isset( $item->attrs['headline'] ) ) {
				$first = (string) $item->attrs['headline'];
			} else {
				$heading = $this->first_child( $item, self::CORE_HEADING );
				if ( $heading ) {
					$first = (string) Block_Markup::inner_html_by_class( $heading->inner_html( $this->source ), 'wp-block-accordion-heading__toggle-title' );
				}
			}
		}
		$first = Block_Markup::residual_text( $first );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $first ) > 40 ) {
			$first = mb_substr( $first, 0, 39 ) . '…';
		}
		return '' !== $first ? sprintf( 'Accordion %d (“%s”)', $this->seen, $first ) : sprintf( 'Accordion %d', $this->seen );
	}

	/**
	 * Inline HTML reduced to an allowed tag set (text of removed tags kept).
	 *
	 * @param string   $html    HTML.
	 * @param array    $allowed wp_kses() allowed-HTML array.
	 * @param string[] $removed Out: tag names that were removed.
	 * @return string
	 */
	private static function sanitize_inline( $html, array $allowed, &$removed ) {
		$removed = array_values( array_diff( Block_Markup::tag_names( $html ), array_keys( $allowed ) ) );
		return trim( wp_kses( (string) $html, $allowed ), " \t\n\r\0\x0B" );
	}

	/**
	 * Tags kept in a Method item headline (its editor allows bold and italic).
	 *
	 * @return array
	 */
	public static function method_headline_tags() {
		return (array) apply_filters(
			'method_tools_accordion_method_headline_tags',
			array(
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
			)
		);
	}

	/**
	 * Tags kept in a core accordion heading title (phrasing content that is
	 * valid inside a <button>; links are not).
	 *
	 * @return array
	 */
	public static function core_title_tags() {
		return (array) apply_filters(
			'method_tools_accordion_core_title_tags',
			array(
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'code'   => array(),
				'kbd'    => array(),
				'sub'    => array(),
				'sup'    => array(),
				's'      => array(),
				'mark'   => array(
					'class' => true,
					'style' => true,
				),
				'span'   => array(
					'class' => true,
					'style' => true,
					'lang'  => true,
					'dir'   => true,
				),
			)
		);
	}

	/**
	 * Method stores `closed` with a non-standard "bool" type; accept the
	 * shapes it can take.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
