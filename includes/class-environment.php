<?php
/**
 * Facts about the site that tools branch on.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Environment {

	/**
	 * Whether a block type is registered server-side. Safe on any version.
	 *
	 * @param string $name Block name.
	 * @return bool
	 */
	public static function block_registered( $name ) {
		return class_exists( 'WP_Block_Type_Registry' )
			&& \WP_Block_Type_Registry::get_instance()->is_registered( $name );
	}

	/**
	 * The Method theme in use (as the active theme or its parent), if any.
	 *
	 * @return \WP_Theme|null
	 */
	public static function method_theme() {
		$theme = wp_get_theme();
		if ( ! $theme->exists() ) {
			return null;
		}
		$slug = (string) apply_filters( 'method_tools_method_theme_slug', 'method' );
		if ( $theme->get_stylesheet() === $slug ) {
			return $theme;
		}
		$parent = $theme->parent();
		if ( $parent && $parent->get_stylesheet() === $slug ) {
			return $parent;
		}
		return null;
	}

	/**
	 * Installed Method version, or null when Method isn't active.
	 *
	 * @return string|null
	 */
	public static function method_version() {
		$theme = self::method_theme();
		return $theme ? (string) $theme->get( 'Version' ) : null;
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	public static function wp_version() {
		return (string) get_bloginfo( 'version' );
	}
}
