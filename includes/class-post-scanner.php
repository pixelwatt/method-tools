<?php
/**
 * Which posts a Post_Tool may target, and the candidate query.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Post_Scanner {

	/**
	 * Post types whose content is block markup: everything that uses the
	 * block editor, plus the site-editor types (templates, template parts,
	 * patterns, navigation) that store blocks without a classic edit screen.
	 *
	 * Filter: method_tools_post_types (array slug => label).
	 *
	 * @return array<string,string> slug => label
	 */
	public static function post_types() {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$skip       = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );
		$site_types = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
		$types      = array();

		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( in_array( $type->name, $skip, true ) ) {
				continue;
			}
			$uses_blocks = in_array( $type->name, $site_types, true )
				|| ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $type->name ) );
			if ( $uses_blocks ) {
				$types[ $type->name ] = $type->labels->name;
			}
		}

		return (array) apply_filters( 'method_tools_post_types', $types );
	}

	/**
	 * Statuses that can be targeted. Trash, auto-drafts and revisions never.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function statuses() {
		$out = array();
		foreach ( get_post_stati( array( 'show_in_admin_all_list' => true ), 'objects' ) as $status ) {
			if ( in_array( $status->name, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
				continue;
			}
			$out[ $status->name ] = $status->label;
		}
		return (array) apply_filters( 'method_tools_post_statuses', $out );
	}

	/**
	 * Normalize targeting input.
	 *
	 * @param array $raw post_types, statuses (arrays or comma strings), include, exclude (IDs).
	 * @return array
	 */
	public static function sanitize_criteria( array $raw ) {
		$types    = array_keys( self::post_types() );
		$statuses = array_keys( self::statuses() );

		$pick = function ( $value, array $allowed ) {
			$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
			return array_values( array_intersect( array_map( 'sanitize_key', $list ), $allowed ) );
		};
		$ids = function ( $value ) {
			$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
			return array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) );
		};

		return array(
			'post_types' => isset( $raw['post_types'] ) ? $pick( $raw['post_types'], $types ) : $types,
			'statuses'   => isset( $raw['statuses'] ) ? $pick( $raw['statuses'], $statuses ) : $statuses,
			'include'    => isset( $raw['include'] ) ? $ids( $raw['include'] ) : array(),
			'exclude'    => isset( $raw['exclude'] ) ? $ids( $raw['exclude'] ) : array(),
		);
	}

	/**
	 * IDs of posts matching the criteria whose content contains any needle.
	 *
	 * @param array    $criteria Sanitized criteria.
	 * @param string[] $needles  Substrings (matched with LIKE, escaped).
	 * @return int[]
	 */
	public static function candidates( array $criteria, array $needles ) {
		global $wpdb;

		if ( empty( $criteria['post_types'] ) || empty( $criteria['statuses'] ) || empty( $needles ) ) {
			return array();
		}

		$where  = array();
		$params = array();

		$where[] = 'post_type IN (' . implode( ',', array_fill( 0, count( $criteria['post_types'] ), '%s' ) ) . ')';
		$params  = array_merge( $params, $criteria['post_types'] );

		$where[] = 'post_status IN (' . implode( ',', array_fill( 0, count( $criteria['statuses'] ), '%s' ) ) . ')';
		$params  = array_merge( $params, $criteria['statuses'] );

		if ( ! empty( $criteria['include'] ) ) {
			$where[] = 'ID IN (' . implode( ',', array_fill( 0, count( $criteria['include'] ), '%d' ) ) . ')';
			$params  = array_merge( $params, $criteria['include'] );
		}
		if ( ! empty( $criteria['exclude'] ) ) {
			$where[] = 'ID NOT IN (' . implode( ',', array_fill( 0, count( $criteria['exclude'] ), '%d' ) ) . ')';
			$params  = array_merge( $params, $criteria['exclude'] );
		}

		$likes = array();
		foreach ( $needles as $needle ) {
			$likes[] = 'post_content LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$where[] = '(' . implode( ' OR ', $likes ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE " . implode( ' AND ', $where ) . ' ORDER BY ID ASC', $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}
}
