<?php
/**
 * Tools → Method Tools → Accordion Converter.
 *
 * @package Method_Tools
 */

namespace Method_Tools\Tools;

use Method_Tools\Environment;
use Method_Tools\Post_Tool;
use Method_Tools\Transform_Result;

defined( 'ABSPATH' ) || exit;

final class Accordion_Converter_Tool extends Post_Tool {

	public function id() {
		return 'accordion-converter';
	}

	public function label() {
		return __( 'Accordion Converter', 'method-tools' );
	}

	public function description() {
		return __( 'Converts core Accordion blocks to Method Accordion blocks, or back. Conversion reads and writes block markup directly, so it works on WordPress versions that predate the core Accordion block (6.9). Item headings, open-by-default state, heading level, alignment and custom classes are carried over; panel content moves unchanged. Method accordions keep one item open at a time (converting to core turns on the core block\'s matching “close others” setting); colors, spacing, borders and icon settings from core have no Method equivalent and are dropped (each run lists what was).', 'method-tools' );
	}

	public function option_fields() {
		return array(
			'direction' => array(
				'type'    => 'radio',
				'label'   => __( 'Direction', 'method-tools' ),
				'default' => Accordion_Converter::CORE_TO_METHOD,
				'choices' => array(
					Accordion_Converter::CORE_TO_METHOD => array(
						'label'       => __( 'Core → Method', 'method-tools' ),
						'description' => __( 'core/accordion becomes method/accordion. Each heading becomes the item headline; each panel\'s blocks move into the item body.', 'method-tools' ),
					),
					Accordion_Converter::METHOD_TO_CORE => array(
						'label'       => __( 'Method → Core', 'method-tools' ),
						'description' => __( 'method/accordion becomes core/accordion (accordion, item, heading, panel). Needs WordPress 6.9+ to be editable and interactive.', 'method-tools' ),
					),
				),
			),
		);
	}

	public function content_needles( array $options ) {
		// Deliberately loose: a false positive costs one no-op transform, a
		// false negative silently skips a post.
		return Accordion_Converter::METHOD_TO_CORE === $options['direction']
			? array( 'wp:method/accordion' )
			: array( 'wp:accordion', 'wp:core/accordion' );
	}

	public function transform( $content, array $options, $post ) {
		$converter = new Accordion_Converter( $options['direction'] );
		return $converter->convert( $content );
	}

	public function environment_notices( array $options ) {
		$notices = array();
		if ( Accordion_Converter::METHOD_TO_CORE === $options['direction'] ) {
			if ( ! Environment::block_registered( 'core/accordion' ) ) {
				$notices[] = array(
					'level' => Transform_Result::WARNING,
					'text'  => sprintf(
						/* translators: %s: WordPress version */
						__( 'This site runs WordPress %s, which has no core Accordion block (it arrived in 6.9). Converted accordions will render as static markup with every panel open, and the editor will show them as unsupported blocks until WordPress is updated.', 'method-tools' ),
						Environment::wp_version()
					),
				);
			}
		} elseif ( ! Environment::block_registered( 'method/accordion' ) ) {
			$notices[] = array(
				'level' => Transform_Result::WARNING,
				'text'  => __( 'The method/accordion block isn\'t registered on this site (is Method the active theme or its parent?). Converted accordions won\'t render or be editable until it is.', 'method-tools' ),
			);
		} elseif ( Accordion_Converter::PANEL_IDS_LEGACY === Accordion_Converter::installed_panel_ids() ) {
			$notices[] = array(
				'level' => Transform_Result::WARNING,
				'text'  => sprintf(
					/* translators: %s: Method version */
					__( 'Method %s predates 2.0.0-beta28, so converted accordions are saved with its collapse1, collapse2, … panel IDs, which repeat when a page has more than one accordion. Updating Method fixes those IDs at render without re-saving; the editor upgrades the markup on the next save.', 'method-tools' ),
					Environment::method_version() ? Environment::method_version() : '(unknown version)'
				),
			);
		}
		return $notices;
	}

	public function run_label( array $options ) {
		return Accordion_Converter::METHOD_TO_CORE === $options['direction']
			? __( 'Accordions: Method → Core', 'method-tools' )
			: __( 'Accordions: Core → Method', 'method-tools' );
	}

	public function count_labels() {
		return array(
			'accordions' => __( 'Accordions', 'method-tools' ),
			'items'      => __( 'Items', 'method-tools' ),
			'skipped'    => __( 'Skipped', 'method-tools' ),
		);
	}
}
