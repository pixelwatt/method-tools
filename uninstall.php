<?php
/**
 * Removes Method Tools data when the plugin is deleted from the Plugins
 * screen: the run history and every per-run content backup, on every site of
 * a network. Post revisions are not touched.
 *
 * @package Method_Tools
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this plugin's data from the current site.
 */
function method_tools_uninstall_site() {
	global $wpdb;

	delete_option( 'method_tools_runs' );

	// Direct delete: one query instead of loading every backup row. Object-cached
	// copies of these rows are harmless once the plugin is gone.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_method_tools_backup_' ) . '%' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $method_tools_site_id ) {
		switch_to_blog( $method_tools_site_id );
		method_tools_uninstall_site();
		restore_current_blog();
	}
} else {
	method_tools_uninstall_site();
}
