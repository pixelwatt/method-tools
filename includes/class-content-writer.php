<?php
/**
 * The only place Method Tools writes post content.
 *
 * Saves go through wp_update_post(), so revisions, save_post hooks and
 * cache invalidation behave exactly as an editor save would. Details:
 *
 * - Content is slashed first; wp_update_post() unslashes its input.
 * - page_template is passed empty. wp_update_post() otherwise re-submits the
 *   post's stored _wp_page_template, and wp_insert_post() rejects a template
 *   the active theme doesn't offer (common right after a theme switch) — but
 *   only after the content row is already written, and before revisions and
 *   save hooks run. An empty value skips that check and leaves the stored
 *   template untouched.
 * - The kses content filters are lifted for this one write. Tools rewrite a
 *   few blocks and copy everything else verbatim; running the whole post
 *   through kses as the current user would strip unrelated markup (iframes,
 *   scripts, unusual attributes) that a user with unfiltered_html added.
 *   Tool output itself is sanitized by the tool, and restores only write
 *   backups whose signature proves this plugin created them.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Content_Writer {

	/**
	 * @param int    $post_id Post ID.
	 * @param string $content New post_content.
	 * @return array{stored:?string,error:?\WP_Error}
	 *         stored: post_content as stored afterwards, or null if the row
	 *         was not changed. error: set when WordPress reported a problem
	 *         (with stored non-null, the content changed anyway).
	 */
	public static function write( $post_id, $content ) {
		$post_id = (int) $post_id;
		$before  = (string) get_post_field( 'post_content', $post_id, 'raw' );

		$kses_active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_active ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'            => $post_id,
					'post_content'  => $content,
					'page_template' => '',
				)
			),
			true
		);

		if ( $kses_active ) {
			kses_init_filters();
		}

		clean_post_cache( $post_id );
		$stored = (string) get_post_field( 'post_content', $post_id, 'raw' );

		$error = null;
		if ( is_wp_error( $result ) ) {
			$error = $result;
		} elseif ( ! $result ) {
			$error = new \WP_Error( 'method_tools_write_failed', __( 'wp_update_post() returned 0.', 'method-tools' ) );
		}

		return array(
			'stored' => ( null === $error || $stored !== $before ) ? $stored : null,
			'error'  => $error,
		);
	}
}
