<?php
/**
 * WP-CLI: wp method-tools …
 *
 * @package Method_Tools
 */

namespace Method_Tools;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Run Method Tools from the command line.
 */
final class CLI {

	/**
	 * List registered tools and their options.
	 *
	 * ## EXAMPLES
	 *
	 *     wp method-tools list
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( Plugin::instance()->tools() as $id => $tool ) {
			$options = array();
			if ( $tool instanceof Post_Tool ) {
				foreach ( $tool->option_fields() as $key => $field ) {
					$choices   = isset( $field['choices'] ) ? implode( '|', array_keys( $field['choices'] ) ) : 'bool';
					$options[] = '--' . $key . '=<' . $choices . '>';
				}
			}
			$rows[] = array(
				'tool'    => $id,
				'label'   => $tool->label(),
				'options' => implode( ' ', $options ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'tool', 'label', 'options' ) );
	}

	/**
	 * Run a content tool over posts.
	 *
	 * ## OPTIONS
	 *
	 * <tool>
	 * : Tool ID (see `wp method-tools list`).
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Default: every block-editor post type.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated statuses. Default: publish, future, draft, pending, private.
	 *
	 * [--include=<ids>]
	 * : Only these post IDs.
	 *
	 * [--exclude=<ids>]
	 * : Skip these post IDs.
	 *
	 * [--dry-run]
	 * : Report what would change without saving.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--<field>=<value>]
	 * : Tool options, e.g. --direction=core-to-method.
	 *
	 * ## EXAMPLES
	 *
	 *     wp method-tools run accordion-converter --direction=core-to-method --post_type=page --dry-run
	 *     wp method-tools run accordion-converter --direction=method-to-core --include=12,48
	 */
	public function run( $args, $assoc ) {
		$tool = $this->post_tool( $args[0] );

		$dry      = WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		$format   = WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' );
		$criteria = Post_Scanner::sanitize_criteria(
			array_filter(
				array(
					'post_types' => isset( $assoc['post_type'] ) ? $assoc['post_type'] : null,
					'statuses'   => isset( $assoc['status'] ) ? $assoc['status'] : null,
					'include'    => isset( $assoc['include'] ) ? $assoc['include'] : null,
					'exclude'    => isset( $assoc['exclude'] ) ? $assoc['exclude'] : null,
				),
				function ( $v ) {
					return null !== $v;
				}
			)
		);

		$raw_options = array_intersect_key( $assoc, $tool->option_fields() );
		$options     = $tool->sanitize_options( $raw_options );
		foreach ( $raw_options as $key => $value ) {
			if ( (string) $options[ $key ] !== (string) $value && 'checkbox' !== ( isset( $tool->option_fields()[ $key ]['type'] ) ? $tool->option_fields()[ $key ]['type'] : '' ) ) {
				WP_CLI::error( sprintf( 'Invalid value "%s" for --%s.', $value, $key ) );
			}
		}

		foreach ( $tool->environment_notices( $options ) as $notice ) {
			WP_CLI::warning( $notice['text'] );
		}

		$ids = Post_Tool_Runner::candidates( $tool, $options, $criteria );
		if ( ! $ids ) {
			WP_CLI::success( 'No candidate posts.' );
			return;
		}

		$run  = $dry ? null : Runs::create( $tool, $options, $criteria );
		$mode = $dry ? Post_Tool_Runner::PREVIEW : Post_Tool_Runner::APPLY;
		$rows = array();
		foreach ( array_chunk( $ids, 25 ) as $batch ) {
			$rows = array_merge( $rows, Post_Tool_Runner::process( $tool, $options, $batch, $mode, $run ? $run['id'] : null ) );
		}
		if ( $run ) {
			Runs::update( $run['id'], array( 'finished' => gmdate( 'Y-m-d H:i:s' ) ) );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array( 'run' => $run ? $run['id'] : null, 'results' => $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$labels = $tool->count_labels();
		$table  = array();
		$notes  = array();
		foreach ( $rows as $row ) {
			if ( ! $row['changed'] && ! $row['messages'] && ! $row['error'] ) {
				continue;
			}
			$line = array(
				'ID'     => $row['id'],
				'Title'  => $row['title'],
				'Type'   => $row['type'],
				'Status' => $row['status'],
			);
			foreach ( $labels as $key => $label ) {
				$line[ $label ] = isset( $row['counts'][ $key ] ) ? $row['counts'][ $key ] : 0;
			}
			$line[ $dry ? 'Would change' : 'Saved' ] = ( $dry ? $row['changed'] : $row['written'] ) ? 'yes' : 'no';
			$table[]                                 = $line;

			if ( $row['error'] ) {
				$notes[] = sprintf( '#%d [error] %s', $row['id'], $row['error'] );
			}
			foreach ( $row['messages'] as $m ) {
				$notes[] = sprintf( '#%d [%s] %s', $row['id'], $m['level'], $m['text'] );
			}
		}

		if ( $table ) {
			WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );
		}
		foreach ( $notes as $note ) {
			WP_CLI::line( $note );
		}

		$changed = count(
			array_filter(
				$rows,
				function ( $r ) use ( $dry ) {
					return $dry ? $r['changed'] : $r['written'];
				}
			)
		);
		if ( $dry ) {
			WP_CLI::success( sprintf( 'Dry run: %d of %d candidate posts would change. Nothing saved.', $changed, count( $rows ) ) );
		} else {
			WP_CLI::success( sprintf( 'Saved %d of %d candidate posts. Run %s (restore with: wp method-tools restore %s).', $changed, count( $rows ), $run['id'], $run['id'] ) );
		}
	}

	/**
	 * List runs.
	 *
	 * ## OPTIONS
	 *
	 * [--tool=<tool>]
	 * : Only runs of this tool.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 */
	public function runs( $args, $assoc ) {
		$rows = array();
		foreach ( Runs::all( isset( $assoc['tool'] ) ? $assoc['tool'] : null ) as $run ) {
			$rows[] = array(
				'id'       => $run['id'],
				'label'    => $run['label'],
				'user'     => $run['user'],
				'started'  => $run['started'],
				'changed'  => $run['changed'],
				'failed'   => $run['failed'],
				'backups'  => Runs::backups_remaining( $run['id'] ),
				'restored' => $run['restored'],
			);
		}
		WP_CLI\Utils\format_items( WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ), $rows, array( 'id', 'label', 'user', 'started', 'changed', 'failed', 'backups', 'restored' ) );
	}

	/**
	 * Restore posts changed by a run to their previous content.
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run ID.
	 *
	 * [--force]
	 * : Also restore posts edited after the run (their later changes are lost; revisions keep them).
	 */
	public function restore( $args, $assoc ) {
		if ( ! Runs::get( $args[0] ) ) {
			WP_CLI::error( 'Unknown run.' );
		}
		if ( ! Runs::backups_remaining( $args[0] ) ) {
			WP_CLI::error( 'This run has no backups left to restore.' );
		}
		$force    = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'force', false );
		$after    = 0;
		$restored = 0;
		do {
			$res       = Runs::restore_batch( $args[0], $after, 25, $force );
			$restored += count( $res['restored'] );
			foreach ( $res['skipped'] as $skip ) {
				WP_CLI::warning( sprintf( '#%d skipped: %s', $skip['id'], $skip['reason'] ) );
			}
			$after = $res['next'];
		} while ( ! $res['done'] );

		WP_CLI::success( sprintf( 'Restored %d post(s).', $restored ) );
	}

	/**
	 * Delete a run's backups.
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run ID.
	 */
	public function discard( $args, $assoc ) {
		if ( ! Runs::get( $args[0] ) ) {
			WP_CLI::error( 'Unknown run.' );
		}
		WP_CLI::success( sprintf( 'Deleted %d backup(s).', Runs::discard_backups( $args[0] ) ) );
	}

	/**
	 * @param string $id Tool ID.
	 * @return Post_Tool
	 */
	private function post_tool( $id ) {
		$tool = Plugin::instance()->tool( $id );
		if ( ! $tool instanceof Post_Tool ) {
			WP_CLI::error( sprintf( 'No content tool "%s". See wp method-tools list.', $id ) );
		}
		return $tool;
	}
}
