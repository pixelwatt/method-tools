<?php
/**
 * Runs a Post_Tool over posts. Shared by the REST controller and WP-CLI so
 * both paths enforce the same checks.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Post_Tool_Runner {

	const PREVIEW = 'preview';
	const APPLY   = 'apply';

	/**
	 * Candidate post IDs for a tool.
	 *
	 * @param Post_Tool $tool     Tool.
	 * @param array     $options  Sanitized options.
	 * @param array     $criteria Sanitized criteria.
	 * @return int[]
	 */
	public static function candidates( Post_Tool $tool, array $options, array $criteria ) {
		return Post_Scanner::candidates( $criteria, $tool->content_needles( $options ) );
	}

	/**
	 * Transform (and, in apply mode, save) a list of posts.
	 *
	 * @param Post_Tool   $tool    Tool.
	 * @param array       $options Sanitized options.
	 * @param int[]       $ids     Post IDs.
	 * @param string      $mode    PREVIEW or APPLY.
	 * @param string|null $run_id  Required for APPLY.
	 * @return array[] One result per post.
	 */
	public static function process( Post_Tool $tool, array $options, array $ids, $mode, $run_id = null ) {
		$types    = Post_Scanner::post_types();
		$statuses = Post_Scanner::statuses();
		$is_cli   = defined( 'WP_CLI' ) && WP_CLI;
		$results  = array();

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			$row  = array(
				'id'       => $id,
				'title'    => '',
				'type'     => '',
				'status'   => '',
				'edit_url' => '',
				'changed'  => false,
				'written'  => false,
				'counts'   => array(),
				'messages' => array(),
				'error'    => null,
			);

			if ( ! $post ) {
				$row['error'] = __( 'Post not found.', 'method-tools' );
				$results[]    = $row;
				continue;
			}

			$type_object     = get_post_type_object( $post->post_type );
			$row['title']    = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $id );
			$row['type']     = $type_object ? $type_object->labels->singular_name : $post->post_type;
			$row['status']   = $post->post_status;
			$row['edit_url'] = (string) get_edit_post_link( $id, 'raw' );

			if ( ! isset( $types[ $post->post_type ] ) || ! isset( $statuses[ $post->post_status ] ) ) {
				$row['error'] = __( 'Post type or status is not eligible.', 'method-tools' );
				$results[]    = $row;
				continue;
			}
			if ( ! $is_cli && ! current_user_can( 'edit_post', $id ) ) {
				$row['error'] = __( 'You cannot edit this post.', 'method-tools' );
				$results[]    = $row;
				continue;
			}

			$original = (string) $post->post_content;
			$result   = $tool->transform( $original, $options, $post );

			$row['changed']  = $result->changed;
			$row['counts']   = $result->counts;
			$row['messages'] = $result->messages;

			if ( self::APPLY !== $mode || ! $result->changed || $result->has_errors() ) {
				$results[] = $row;
				continue;
			}

			if ( ! Runs::backup( $run_id, $id, $original ) ) {
				$row['error'] = __( 'Could not store a backup; post left unchanged.', 'method-tools' );
				Runs::record( $run_id, $id, false );
				$results[] = $row;
				continue;
			}

			$write = Content_Writer::write( $id, $result->content );
			if ( null === $write['stored'] ) {
				Runs::drop( $run_id, $id );
				Runs::record( $run_id, $id, false );
				$row['error'] = $write['error'] ? $write['error']->get_error_message() : __( 'Not saved.', 'method-tools' );
				$results[]    = $row;
				continue;
			}

			$stored = $write['stored'];
			Runs::seal( $run_id, $id, $stored );
			Runs::record( $run_id, $id, true );
			$row['written'] = true;

			if ( $write['error'] ) {
				$row['messages'][] = array(
					'level' => Transform_Result::WARNING,
					/* translators: %s: error message */
					'text'  => sprintf( __( 'Saved, but WordPress reported: %s. Save hooks may not have run, so check the post and clear caches.', 'method-tools' ), $write['error']->get_error_message() ),
				);
			}
			if ( $stored !== $result->content ) {
				$row['messages'][] = array(
					'level' => Transform_Result::NOTICE,
					'text'  => __( 'WordPress adjusted the content while saving (content_save_pre filters). The stored version is what the backup protects.', 'method-tools' ),
				);
			}
			$results[] = $row;
		}

		return $results;
	}
}
