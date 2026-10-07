<?php
/**
 * Run history and per-post backups.
 *
 * Every apply is a "run". Before a post is rewritten, its original content
 * is stored in post meta under a key unique to the run, together with a
 * hash of what the run wrote. Restoring a run puts the original back only
 * where the post still holds exactly what the run wrote, so later edits are
 * never overwritten unless forced.
 *
 * Revisions are created as well (when the post type supports them); the
 * backups exist because not every post type keeps revisions and because a
 * whole run can be undone in one action.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Runs {

	const OPTION      = 'method_tools_runs';
	const META_PREFIX = '_method_tools_backup_';

	/**
	 * All runs, newest first.
	 *
	 * @param string|null $tool_id Limit to one tool.
	 * @return array[]
	 */
	public static function all( $tool_id = null ) {
		$runs = get_option( self::OPTION, array() );
		$runs = is_array( $runs ) ? $runs : array();
		if ( $tool_id ) {
			$runs = array_filter(
				$runs,
				function ( $run ) use ( $tool_id ) {
					return isset( $run['tool'] ) && $run['tool'] === $tool_id;
				}
			);
		}
		usort(
			$runs,
			function ( $a, $b ) {
				return strcmp( $b['started'], $a['started'] );
			}
		);
		return array_values( $runs );
	}

	/**
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public static function get( $run_id ) {
		$runs = get_option( self::OPTION, array() );
		return isset( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
	}

	/**
	 * @param Post_Tool $tool     Tool.
	 * @param array     $options  Sanitized options.
	 * @param array     $criteria Sanitized criteria.
	 * @return array The run.
	 */
	public static function create( Post_Tool $tool, array $options, array $criteria ) {
		$user = wp_get_current_user();
		$id   = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false ) );
		$run  = array(
			'id'       => $id,
			'tool'     => $tool->id(),
			'label'    => $tool->run_label( $options ),
			'options'  => $options,
			'criteria' => $criteria,
			'user'     => $user && $user->exists() ? $user->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : '' ),
			'started'  => gmdate( 'Y-m-d H:i:s' ),
			'finished' => null,
			'changed'  => 0,
			'failed'   => 0,
			'posts'    => array(),
			'restored' => null,
		);
		self::save( $run );
		return $run;
	}

	/**
	 * Merge fields into a stored run.
	 *
	 * @param string $run_id Run ID.
	 * @param array  $fields Fields to set.
	 * @return array|null
	 */
	public static function update( $run_id, array $fields ) {
		$run = self::get( $run_id );
		if ( ! $run ) {
			return null;
		}
		$run = array_merge( $run, $fields );
		self::save( $run );
		return $run;
	}

	/**
	 * Store a post's pre-run content. Called before the write, so a failed
	 * or interrupted save never leaves a post changed without a backup.
	 * The first backup within a run wins.
	 *
	 * @param string $run_id   Run ID.
	 * @param int    $post_id  Post ID.
	 * @param string $original Content before the run.
	 * @return bool
	 */
	public static function backup( $run_id, $post_id, $original ) {
		$key = self::META_PREFIX . $run_id;
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			return true;
		}
		return (bool) add_post_meta(
			$post_id,
			$key,
			wp_slash(
				array(
					'content' => $original,
					'sig'     => self::signature( $run_id, $post_id, $original ),
					'hash'    => '',
					'time'    => time(),
				)
			),
			true
		);
	}

	/**
	 * After a successful write, remember what the run stored so a restore
	 * can tell whether the post was edited since.
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $post_id Post ID.
	 * @param string $written Content as stored.
	 */
	public static function seal( $run_id, $post_id, $written ) {
		$key    = self::META_PREFIX . $run_id;
		$backup = get_post_meta( $post_id, $key, true );
		if ( is_array( $backup ) ) {
			$backup['hash'] = md5( $written );
			update_post_meta( $post_id, $key, wp_slash( $backup ) );
		}
	}

	/**
	 * Remove a backup taken for a write that then failed.
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $post_id Post ID.
	 */
	public static function drop( $run_id, $post_id ) {
		delete_post_meta( $post_id, self::META_PREFIX . $run_id );
	}

	/**
	 * Record the outcome of one post within a run.
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $post_id Post ID.
	 * @param bool   $ok      Written successfully.
	 */
	public static function record( $run_id, $post_id, $ok ) {
		$run = self::get( $run_id );
		if ( ! $run ) {
			return;
		}
		if ( $ok ) {
			$run['changed']++;
			$run['posts'][] = (int) $post_id;
		} else {
			$run['failed']++;
		}
		self::save( $run );
	}

	/**
	 * Number of posts still holding a backup for the run.
	 *
	 * @param string $run_id Run ID.
	 * @return int
	 */
	public static function backups_remaining( $run_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_PREFIX . $run_id ) );
	}

	/**
	 * Restore the next batch of posts from a run's backups.
	 *
	 * @param string $run_id Run ID.
	 * @param int    $after  Only posts with ID greater than this (cursor).
	 * @param int    $limit  Batch size.
	 * @param bool   $force  Restore even if the post changed after the run.
	 * @return array{restored:int[],skipped:array[],next:int,done:bool}
	 */
	public static function restore_batch( $run_id, $after, $limit, $force = false ) {
		$out = array(
			'restored' => array(),
			'skipped'  => array(),
			'next'     => (int) $after,
			'done'     => false,
		);

		foreach ( self::backup_post_ids( $run_id, $after, $limit ) as $post_id ) {
			$out['next'] = $post_id;
			$backup      = get_post_meta( $post_id, self::META_PREFIX . $run_id, true );
			$current     = (string) get_post_field( 'post_content', $post_id, 'raw' );

			if ( ! is_array( $backup ) || ! isset( $backup['content'], $backup['hash'] ) ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => __( 'Backup is unreadable.', 'method-tools' ) );
				continue;
			}
			// Restores bypass kses, so only content this plugin itself backed up
			// may be written back — never a meta row planted by other means.
			if ( ! isset( $backup['sig'] ) || ! hash_equals( self::signature( $run_id, $post_id, (string) $backup['content'] ), (string) $backup['sig'] ) ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => __( 'Backup failed its integrity check and was not used.', 'method-tools' ) );
				continue;
			}
			if ( ! $force && '' === $backup['hash'] ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => __( 'The run never confirmed its write to this post; check it by hand or force the restore.', 'method-tools' ) );
				continue;
			}
			if ( ! $force && md5( $current ) !== $backup['hash'] ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => __( 'Edited after the run; left as is.', 'method-tools' ) );
				continue;
			}
			if ( function_exists( 'current_user_can' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'edit_post', $post_id ) ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => __( 'You cannot edit this post.', 'method-tools' ) );
				continue;
			}

			$write = Content_Writer::write( $post_id, $backup['content'] );
			if ( null === $write['stored'] ) {
				$out['skipped'][] = array( 'id' => $post_id, 'reason' => $write['error'] ? $write['error']->get_error_message() : __( 'Not saved.', 'method-tools' ) );
				continue;
			}
			delete_post_meta( $post_id, self::META_PREFIX . $run_id );
			$out['restored'][] = $post_id;
		}

		$out['done'] = ! self::backup_post_ids( $run_id, $out['next'], 1 );
		if ( $out['restored'] ) {
			self::update( $run_id, array( 'restored' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		return $out;
	}

	/**
	 * Delete a run's backups (the run itself stays in history).
	 *
	 * @param string $run_id Run ID.
	 * @return int Rows deleted.
	 */
	public static function discard_backups( $run_id ) {
		return (int) delete_metadata( 'post', 0, self::META_PREFIX . $run_id, '', true );
	}

	/**
	 * Keyed hash binding backup content to its run and post (uses the site's
	 * auth salts, so it can't be produced without access to wp-config.php).
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $post_id Post ID.
	 * @param string $content Backed-up content.
	 * @return string
	 */
	private static function signature( $run_id, $post_id, $content ) {
		return wp_hash( 'method-tools-backup|' . $run_id . '|' . (int) $post_id . '|' . $content );
	}

	/**
	 * Post IDs holding a backup for the run, ascending, after a cursor.
	 *
	 * @param string $run_id Run ID.
	 * @param int    $after  Cursor.
	 * @param int    $limit  Max rows.
	 * @return int[]
	 */
	private static function backup_post_ids( $run_id, $after, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					self::META_PREFIX . $run_id,
					(int) $after,
					max( 1, (int) $limit )
				)
			)
		);
	}

	/**
	 * @param array $run Run.
	 */
	private static function save( array $run ) {
		$runs = get_option( self::OPTION, array() );
		$runs = is_array( $runs ) ? $runs : array();

		$runs[ $run['id'] ] = $run;
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $runs, '', 'no' );
		} else {
			update_option( self::OPTION, $runs, false );
		}
	}
}
