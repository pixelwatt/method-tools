<?php
/**
 * Converts every fixture in both directions and writes the results for the
 * Gutenberg validator (tests/gutenberg-harness.cjs). Pure: no DB writes.
 *
 *   wp eval-file tests/convert-samples.php tests/fixtures/core-6.9-samples.json /tmp/out.json
 *
 * Runs the converter over every sample in both directions and writes:
 *   c2m.<name>     core → Method
 *   m2c.<name>     Method → core (for method_* samples)
 *   rt.<name>      core → Method → core round trip
 * plus a report of messages/counts, and PHP-side checks.
 */

use Method_Tools\Tools\Accordion_Converter;
use Method_Tools\Blocks\Block_Document;

$in  = $args[0];
$out = $args[1];

$samples = json_decode( file_get_contents( $in ), true );
$n       = 0;
$ids     = function () use ( &$n ) {
	$n++;
	return sprintf( '00000000-0000-4000-8000-%012d', $n );
};

$results = array();
$report  = array();
$fail    = 0;

function check( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		$fail++;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}

foreach ( $samples as $name => $content ) {
	if ( 0 === strpos( $name, 'method_' ) ) {
		$r = ( new Accordion_Converter( Accordion_Converter::METHOD_TO_CORE, $ids ) )->convert( $content );
		$results[ 'm2c.' . $name ] = $r->content;
		$report[ 'm2c.' . $name ]  = array( 'changed' => $r->changed, 'counts' => $r->counts, 'messages' => $r->messages );
		check( $r->changed, "m2c $name changed" );

		$back = ( new Accordion_Converter( Accordion_Converter::CORE_TO_METHOD, $ids, Accordion_Converter::PANEL_IDS_SCOPED ) )->convert( $r->content );
		$results[ 'rt.' . $name ] = $back->content;
		continue;
	}

	// Method ≤ beta27 panel ids (validated against beta27, and beta28's deprecation).
	$legacy = ( new Accordion_Converter( Accordion_Converter::CORE_TO_METHOD, $ids, Accordion_Converter::PANEL_IDS_LEGACY ) )->convert( $content );
	$results[ 'c2mL.' . $name ] = $legacy->content;

	$r = ( new Accordion_Converter( Accordion_Converter::CORE_TO_METHOD, $ids, Accordion_Converter::PANEL_IDS_SCOPED ) )->convert( $content );
	$results[ 'c2m.' . $name ] = $r->content;
	$report[ 'c2m.' . $name ]  = array( 'changed' => $r->changed, 'counts' => $r->counts, 'messages' => $r->messages );

	// Idempotent: converting the result again changes nothing.
	$again = ( new Accordion_Converter( Accordion_Converter::CORE_TO_METHOD, $ids, Accordion_Converter::PANEL_IDS_SCOPED ) )->convert( $r->content );
	check( ! $again->changed, "c2m $name idempotent" );

	// Nothing core-accordion left (unless intentionally skipped).
	$doc = Block_Document::parse( $r->content );
	if ( empty( $r->counts['skipped'] ) ) {
		check( 0 === $doc->count( array( 'core/accordion', 'core/accordion-item', 'core/accordion-heading', 'core/accordion-panel' ) ), "c2m $name leaves no core accordion blocks" );
	}

	// Core's own parser agrees on structure.
	$wp = parse_blocks( $r->content );
	check( is_array( $wp ) && $wp, "c2m $name parse_blocks" );

	$rt = ( new Accordion_Converter( Accordion_Converter::METHOD_TO_CORE, $ids ) )->convert( $r->content );
	$results[ 'rt.' . $name ] = $rt->content;
	$report[ 'rt.' . $name ]  = array( 'changed' => $rt->changed, 'counts' => $rt->counts, 'messages' => $rt->messages );
}

file_put_contents( $out, wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
file_put_contents( preg_replace( '/\.json$/', '.report.json', $out ), wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo count( $results ) . " outputs, $fail failures\n";
