<?php
/**
 * Helpers for writing block markup the way the block editor's serializer
 * does, and for reading small pieces of saved block HTML without depending
 * on WP_HTML_Tag_Processor (unavailable before WordPress 6.2).
 *
 * @package Method_Tools
 */

namespace Method_Tools\Blocks;

defined( 'ABSPATH' ) || exit;

final class Block_Markup {

	/**
	 * Serialize a block the way @wordpress/blocks does:
	 *
	 *   <!-- wp:name {"attr":1} -->
	 *   {saved html}
	 *   <!-- /wp:name -->
	 *
	 * @param string $name  Fully-qualified block name.
	 * @param array  $attrs Attributes to put in the delimiter (empty = none).
	 * @param string $html  Saved HTML, with inner blocks already in place.
	 * @return string
	 */
	public static function block( $name, array $attrs, $html ) {
		$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$json  = $attrs ? self::serialize_attributes( $attrs ) . ' ' : '';
		return '<!-- wp:' . $short . ' ' . $json . "-->\n" . $html . "\n<!-- /wp:" . $short . ' -->';
	}

	/**
	 * Same output as core's serialize_block_attributes() (WP 5.3.1+), kept
	 * local so older installs produce identical delimiters.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function serialize_attributes( array $attrs ) {
		if ( function_exists( 'serialize_block_attributes' ) ) {
			return serialize_block_attributes( $attrs );
		}
		$json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = preg_replace( '/--/', '\\u002d\\u002d', $json );
		$json = preg_replace( '/</', '\\u003c', $json );
		$json = preg_replace( '/>/', '\\u003e', $json );
		$json = preg_replace( '/&/', '\\u0026', $json );
		$json = preg_replace( '/\\\\"/', '\\u0022', $json );
		return $json;
	}

	/**
	 * Join class names, dropping empties and duplicates, preserving order.
	 *
	 * @param array $classes Class names (strings may contain several).
	 * @return string
	 */
	public static function classes( array $classes ) {
		$out = array();
		foreach ( $classes as $class ) {
			foreach ( preg_split( '/\s+/', trim( (string) $class, " \t\n\r\0\x0B" ) ) as $c ) {
				if ( '' !== $c && ! in_array( $c, $out, true ) ) {
					$out[] = $c;
				}
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Inner HTML of the first element carrying $class, honoring nesting of
	 * the same tag name. Returns null when no such element exists.
	 *
	 * @param string $html  HTML fragment.
	 * @param string $class Class name to look for.
	 * @return string|null
	 */
	public static function inner_html_by_class( $html, $class ) {
		$span = self::inner_html_span_by_class( $html, $class );
		return null === $span ? null : (string) substr( $html, $span[0], $span[1] );
	}

	/**
	 * Offset and length of the inner HTML of the first element carrying
	 * $class (see inner_html_by_class()).
	 *
	 * @param string $html  HTML fragment.
	 * @param string $class Class name to look for.
	 * @return array{0:int,1:int}|null
	 */
	public static function inner_html_span_by_class( $html, $class ) {
		$pattern = '/<([a-zA-Z][a-zA-Z0-9-]*)\b(?=[^>]*\bclass\s*=\s*(["\'])(?:(?!\2).)*?(?<![\w-])' . preg_quote( $class, '/' ) . '(?![\w-])(?:(?!\2).)*\2)[^>]*>/s';
		if ( ! preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tag         = strtolower( $m[1][0] );
		$inner_start = $m[0][1] + strlen( $m[0][0] );
		$depth       = 1;
		$offset      = $inner_start;
		$tag_re      = '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';
		while ( preg_match( $tag_re, $html, $t, PREG_OFFSET_CAPTURE, $offset ) ) {
			$self_closing = '/' === substr( rtrim( $t[0][0], '>' ), -1 );
			if ( '/' === $t[1][0] ) {
				$depth--;
				if ( 0 === $depth ) {
					return array( $inner_start, $t[0][1] - $inner_start );
				}
			} elseif ( ! $self_closing ) {
				$depth++;
			}
			$offset = $t[0][1] + strlen( $t[0][0] );
		}
		return null;
	}

	/**
	 * Value of an attribute on the first tag of an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @param string $attr Attribute name.
	 * @return string|null
	 */
	public static function first_tag_attribute( $html, $attr ) {
		if ( ! preg_match( '/<[a-zA-Z][^>]*>/s', $html, $tag ) ) {
			return null;
		}
		if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag[0], $m ) ) {
			$value = '';
			foreach ( array( 1, 2, 3 ) as $i ) {
				if ( isset( $m[ $i ] ) && '' !== $m[ $i ] ) {
					$value = $m[ $i ];
					break;
				}
			}
			return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}
		return null;
	}

	/**
	 * Visible text left in a fragment once tags and whitespace are removed.
	 * Used to detect content a conversion would silently drop.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	public static function residual_text( $html ) {
		// Byte-oriented on purpose: a /u pattern returns null on invalid UTF-8,
		// which would make any content look empty.
		$text = (string) preg_replace( '/<[^>]*>/', '', (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ), " \t\n\r\0\x0B" );
	}

	/**
	 * Lowercase names of every element used in a fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string[]
	 */
	public static function tag_names( $html ) {
		preg_match_all( '/<\s*([a-zA-Z][a-zA-Z0-9-]*)/', (string) $html, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	}
}
