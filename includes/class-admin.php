<?php
/**
 * Tools → Method Tools.
 *
 * @package Method_Tools
 */

namespace Method_Tools;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'method-tools';

	/** @var string Page hook suffix. */
	private static $hook = '';

	public static function register_menu() {
		self::$hook = (string) add_management_page(
			__( 'Method Tools', 'method-tools' ),
			__( 'Method Tools', 'method-tools' ),
			Plugin::capability(),
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param array $links Plugin row links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( current_user_can( Plugin::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open', 'method-tools' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'method-tools-admin', METHOD_TOOLS_URL . 'assets/admin.css', array(), METHOD_TOOLS_VERSION );
		wp_enqueue_script( 'method-tools-admin', METHOD_TOOLS_URL . 'assets/admin.js', array( 'wp-api-fetch' ), METHOD_TOOLS_VERSION, true );
		wp_add_inline_script(
			'method-tools-admin',
			'window.methodTools = ' . wp_json_encode(
				array(
					'namespace'    => REST_Controller::NS,
					'previewBatch' => 25,
					'applyBatch'   => Plugin::batch_size(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Tools the current user may open.
	 *
	 * @return Tool[]
	 */
	private static function visible_tools() {
		return array_filter(
			Plugin::instance()->tools(),
			function ( Tool $tool ) {
				return current_user_can( $tool->capability() );
			}
		);
	}

	public static function render_page() {
		$tools = self::visible_tools();
		$ids   = array_keys( $tools );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$current = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : ( $ids ? $ids[0] : '' );
		if ( ! isset( $tools[ $current ] ) && $ids ) {
			$current = $ids[0];
		}

		$method = Environment::method_version();
		?>
		<div class="wrap method-tools">
			<h1><?php esc_html_e( 'Method Tools', 'method-tools' ); ?></h1>
			<p class="mt-env">
				<?php
				printf(
					/* translators: 1: plugin version, 2: WordPress version */
					esc_html__( 'Method Tools %1$s · WordPress %2$s · ', 'method-tools' ),
					esc_html( METHOD_TOOLS_VERSION ),
					esc_html( Environment::wp_version() )
				);
				if ( $method ) {
					/* translators: %s: Method version */
					printf( esc_html__( 'Method %s', 'method-tools' ), esc_html( $method ) );
				} else {
					esc_html_e( 'Method is not the active theme or its parent', 'method-tools' );
				}
				?>
			</p>

			<?php if ( count( $tools ) > 1 ) : ?>
				<nav class="nav-tab-wrapper">
					<?php foreach ( $tools as $id => $tool ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tool' => $id ), admin_url( 'tools.php' ) ) ); ?>" class="nav-tab<?php echo $id === $current ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $tool->label() ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php
			if ( isset( $tools[ $current ] ) ) {
				$tools[ $current ]->render();
			} else {
				echo '<p>' . esc_html__( 'No tools are available to you.', 'method-tools' ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Standard panel for a Post_Tool: options, targeting, scan/convert,
	 * results, run history. Behavior lives in assets/admin.js.
	 *
	 * @param Post_Tool $tool Tool.
	 */
	public static function render_post_tool( Post_Tool $tool ) {
		$types    = Post_Scanner::post_types();
		$statuses = Post_Scanner::statuses();
		$config   = array(
			'tool'        => $tool->id(),
			'countLabels' => $tool->count_labels(),
		);
		?>
		<div class="mt-tool" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<h2 class="mt-tool-title"><?php echo esc_html( $tool->label() ); ?></h2>
			<p class="mt-description"><?php echo esc_html( $tool->description() ); ?></p>

			<form class="mt-form" novalidate>
				<div class="mt-columns">
					<?php foreach ( $tool->option_fields() as $key => $field ) : ?>
						<fieldset class="mt-card">
							<legend><?php echo esc_html( $field['label'] ); ?></legend>
							<?php self::render_field( $key, $field ); ?>
						</fieldset>
					<?php endforeach; ?>

					<fieldset class="mt-card">
						<legend><?php esc_html_e( 'Content to scan', 'method-tools' ); ?></legend>

						<p class="mt-label"><?php esc_html_e( 'Post types', 'method-tools' ); ?></p>
						<div class="mt-checks">
							<?php foreach ( $types as $slug => $label ) : ?>
								<label><input type="checkbox" name="post_types" value="<?php echo esc_attr( $slug ); ?>" checked> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
							<?php endforeach; ?>
						</div>

						<p class="mt-label"><?php esc_html_e( 'Statuses', 'method-tools' ); ?></p>
						<div class="mt-checks mt-checks-inline">
							<?php foreach ( $statuses as $slug => $label ) : ?>
								<label><input type="checkbox" name="statuses" value="<?php echo esc_attr( $slug ); ?>" checked> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</div>

						<p>
							<label class="mt-label" for="mt-include-<?php echo esc_attr( $tool->id() ); ?>"><?php esc_html_e( 'Only these post IDs', 'method-tools' ); ?></label>
							<input type="text" class="regular-text" id="mt-include-<?php echo esc_attr( $tool->id() ); ?>" name="include" placeholder="<?php esc_attr_e( 'e.g. 12, 48, 301 — leave empty for all', 'method-tools' ); ?>">
						</p>
						<p>
							<label class="mt-label" for="mt-exclude-<?php echo esc_attr( $tool->id() ); ?>"><?php esc_html_e( 'Skip these post IDs', 'method-tools' ); ?></label>
							<input type="text" class="regular-text" id="mt-exclude-<?php echo esc_attr( $tool->id() ); ?>" name="exclude">
						</p>
					</fieldset>
				</div>

				<div class="mt-actions">
					<button type="submit" class="button button-primary mt-scan"><?php esc_html_e( 'Scan (dry run)', 'method-tools' ); ?></button>
					<button type="button" class="button mt-apply" disabled><?php esc_html_e( 'Convert', 'method-tools' ); ?></button>
					<span class="mt-status" role="status" aria-live="polite"></span>
				</div>
				<progress class="mt-progress" max="100" value="0" hidden></progress>
			</form>

			<div class="mt-notices"></div>
			<div class="mt-results" hidden></div>

			<section class="mt-card mt-runs">
				<h3><?php esc_html_e( 'Run history', 'method-tools' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Every conversion stores each post\'s previous content. Restoring a run puts it back on posts that haven\'t been edited since; revisions are kept too.', 'method-tools' ); ?></p>
				<div class="mt-runs-body"><?php esc_html_e( 'Loading…', 'method-tools' ); ?></div>
			</section>
		</div>
		<?php
	}

	/**
	 * @param string $key   Option key.
	 * @param array  $field Field definition.
	 */
	private static function render_field( $key, array $field ) {
		$type    = isset( $field['type'] ) ? $field['type'] : 'radio';
		$default = isset( $field['default'] ) ? $field['default'] : null;

		if ( 'checkbox' === $type ) {
			printf(
				'<label><input type="checkbox" name="option:%1$s" value="1"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( (bool) $default, true, false ),
				esc_html( isset( $field['description'] ) ? $field['description'] : $field['label'] )
			);
			return;
		}

		if ( 'select' === $type ) {
			echo '<select name="option:' . esc_attr( $key ) . '">';
			foreach ( $field['choices'] as $value => $choice ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $default, $value, false ), esc_html( $choice['label'] ) );
			}
			echo '</select>';
			return;
		}

		echo '<div class="mt-radios">';
		foreach ( $field['choices'] as $value => $choice ) {
			printf(
				'<label class="mt-radio"><input type="radio" name="option:%1$s" value="%2$s"%3$s> <strong>%4$s</strong>%5$s</label>',
				esc_attr( $key ),
				esc_attr( $value ),
				checked( $default, $value, false ),
				esc_html( $choice['label'] ),
				! empty( $choice['description'] ) ? '<span class="description">' . esc_html( $choice['description'] ) . '</span>' : ''
			);
		}
		echo '</div>';
	}
}
