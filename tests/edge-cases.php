<?php
/**
 * Converter unit checks. Pure: writes nothing to the database.
 *
 *   wp eval-file tests/edge-cases.php tests/fixtures/core-6.9-samples.json
 */

use Method_Tools\Tools\Accordion_Converter;
use Method_Tools\Blocks\Block_Document;
use Method_Tools\Blocks\Block_Markup;

$samples = json_decode( file_get_contents( $args[0] ), true );
$GLOBALS["fail"] = 0;
$GLOBALS["pass"] = 0;
function t( $cond, $msg ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}
$n   = 0;
$ids = function () use ( &$n ) {
	$n++;
	return sprintf( '00000000-0000-4000-8000-%012d', $n );
};
$c2m = function ( $s, $panel_ids = Accordion_Converter::PANEL_IDS_SCOPED ) use ( $ids ) {
	return ( new Accordion_Converter( Accordion_Converter::CORE_TO_METHOD, $ids, $panel_ids ) )->convert( $s );
};
$m2c = function ( $s ) use ( $ids ) {
	return ( new Accordion_Converter( Accordion_Converter::METHOD_TO_CORE, $ids ) )->convert( $s );
};
$msgs = function ( $r ) {
	return implode( "\n", array_map( function ( $m ) { return $m['level'] . ': ' . $m['text']; }, $r->messages ) );
};

$acc = $samples['basic'];

// 1. Byte preservation around and between accordions.
$before = "Classic intro with <b>freeform</b> HTML\r\n\r\n"
	. "<!-- wp:group {} -->\n<div class=\"wp-block-group\"><!-- wp:paragraph {\"metadata\":{}} -->\n<p>Ünïcødé 🎉 &amp; &nbsp; -- </p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:html -->\n<iframe src=\"https://example.com/embed\" onload=\"x()\"></iframe><script>var a = 1 < 2;</script>\n<!-- /wp:html -->\n\n"
	. "<!-- wp:spacer {\"height\":\"20px\"} /-->\n\n";
$middle = "</div>\n<!-- /wp:group -->\n\n<!-- wp:custom/thing {\"a\":[],\"b\":{},\"c\":\"\\u003c!-- x --\\u003e\"} /-->\n\n";
$after  = "\n\n<!-- wp:paragraph -->\n<p>tail</p>\n<!-- /wp:paragraph -->";
$doc    = $before . $acc . $middle . $acc . $after;
$r      = $c2m( $doc );
t( $r->changed, 'byte: changed' );
t( 0 === strpos( $r->content, $before ), 'byte: prefix untouched' );
t( substr( $r->content, -strlen( $after ) ) === $after, 'byte: suffix untouched' );
t( false !== strpos( $r->content, $middle ), 'byte: middle untouched' );
t( 2 === $r->counts['accordions'], 'byte: 2 accordions' );
// The reverse conversion of the result restores structure; non-accordion bytes still identical.
$back = $m2c( $r->content );
t( 0 === strpos( $back->content, $before ) && false !== strpos( $back->content, $middle ) && substr( $back->content, -strlen( $after ) ) === $after, 'byte: reverse keeps surroundings' );
// The core accordion produced by the round trip equals the original (canonical 6.9 markup).
// Exactly, apart from autoclose, which Method -> core turns on to keep Method's one-open-at-a-time behavior.
t( str_replace( '<!-- wp:accordion {"autoclose":true} -->', '<!-- wp:accordion -->', $back->content ) === $doc, 'byte: core -> method -> core reproduces the original document exactly' );
t( 2 === substr_count( $back->content, '<!-- wp:accordion {"autoclose":true} -->' ), 'm2c: autoclose set' );

// 2. No accordions: untouched, no messages.
$plain = "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->";
$r     = $c2m( $plain );
t( ! $r->changed && $r->content === $plain && ! $r->messages, 'none: no-op' );

// 3. Malformed document containing an accordion: refused.
$bad = "<!-- wp:group -->\n<div>" . $acc; // never closed
$r   = $c2m( $bad );
t( ! $r->changed && $r->has_errors(), 'malformed: refused' );

// 4. Unexpected structures: skipped with a warning, rest of doc untouched.
$stray = str_replace( '<div role="group" class="wp-block-accordion">', "<div role=\"group\" class=\"wp-block-accordion\"><!-- wp:paragraph -->\n<p>stray</p>\n<!-- /wp:paragraph -->\n\n", $acc );
$r     = $c2m( $stray );
t( ! $r->changed && 1 === $r->counts['skipped'] && false !== strpos( $msgs( $r ), 'core/paragraph block where items were expected' ), 'structure: paragraph directly in accordion skipped' );

$text = str_replace( '<div role="region" class="wp-block-accordion-panel">', '<div role="region" class="wp-block-accordion-panel">Loose text', $acc );
$r    = $c2m( $text );
t( ! $r->changed && false !== strpos( $msgs( $r ), 'contains content outside its inner blocks' ), 'structure: loose text in panel skipped' );

$badjson = str_replace( '<!-- wp:accordion -->', '<!-- wp:accordion {"x":} -->', $acc );
$r       = $c2m( $badjson );
t( ! $r->changed && false !== strpos( $msgs( $r ), 'not valid JSON' ), 'structure: invalid attrs JSON skipped' );

// A skipped outer accordion still lets a valid nested one convert.
$nested_skip = str_replace( '<div role="group" class="wp-block-accordion">', "<div role=\"group\" class=\"wp-block-accordion\"><!-- wp:paragraph -->\n<p>stray</p>\n<!-- /wp:paragraph -->\n\n", $samples['nested'] );
$nested_skip = preg_replace( '/<!-- wp:paragraph -->\n<p>stray<\/p>\n<!-- \/wp:paragraph -->\n\n/', '', $nested_skip, 1, $cnt );
// (put the stray only into the OUTER accordion: first occurrence)
$nested_skip = preg_replace( '/<div role="group" class="wp-block-accordion">/', "<div role=\"group\" class=\"wp-block-accordion\"><!-- wp:paragraph -->\n<p>stray</p>\n<!-- /wp:paragraph -->\n\n", $samples['nested'], 1 );
$r           = $c2m( $nested_skip );
$d           = Block_Document::parse( $r->content );
t( $r->changed && 1 === $r->counts['skipped'] && 1 === $r->counts['accordions'] && 1 === $d->count( 'core/accordion' ) && 1 === $d->count( 'method/accordion' ), 'structure: outer skipped, inner converted' );

// Non-text content in a wrapper (iframe/img) is never dropped silently.
$iframe = str_replace( '<div role="region" class="wp-block-accordion-panel">', '<div role="region" class="wp-block-accordion-panel"><iframe src="https://example.com"></iframe>', $acc );
$r      = $c2m( $iframe );
t( ! $r->changed && 1 === $r->counts['skipped'], 'structure: iframe in panel wrapper skipped' );

// Invalid UTF-8 anywhere: refused (byte-level checks can't be trusted).
$r = $c2m( $acc . "\n\n<!-- wp:paragraph -->\n<p>Caf\xE9</p>\n<!-- /wp:paragraph -->" );
t( ! $r->changed && $r->has_errors(), 'encoding: invalid UTF-8 refused' );

// Heading with extra content: skipped before any nested conversion, so nothing is counted twice.
$broken = preg_replace( '/<span class="wp-block-accordion-heading__toggle-title">Outer 2<\/span>/', '<span class="wp-block-accordion-heading__toggle-title">Outer 2</span><img src="x.png">', $samples['nested'], 1 );
$r      = $c2m( $broken );
t( $r->changed && 1 === $r->counts['skipped'] && 1 === $r->counts['accordions'] && 2 === $r->counts['items'], 'counts: skipped outer does not double count nested: ' . wp_json_encode( $r->counts ) );

// 5. Explicit core/ namespace in delimiters.
$ns = str_replace( array( 'wp:accordion', '/wp:accordion' ), array( 'wp:core/accordion', '/wp:core/accordion' ), $acc );
$r  = $c2m( $ns );
t( $r->changed && 1 === $r->counts['accordions'], 'namespace: explicit core/ handled' );

// 6. Method -> core: headline with a link, empty closed variants, hTag invalid.
$m = $samples['method_basic'];
$m = str_replace( '"headline":"First"', substr( Block_Markup::serialize_attributes( array( 'headline' => 'See <a href="/x">this</a> & that' ) ), 1, -1 ), $m );
$r = $m2c( $m );
t( $r->changed && false !== strpos( $r->content, '<span class="wp-block-accordion-heading__toggle-title">See this &amp; that</span>' ), 'm2c: link unwrapped, ampersand encoded' );
t( false !== strpos( $msgs( $r ), '(<a>)' ), 'm2c: link removal reported' );
t( false !== strpos( $r->content, '<div class="wp-block-accordion-item is-open">' ) && false !== strpos( $r->content, '<!-- wp:accordion-item {"openByDefault":true} -->' ), 'm2c: first item open when not closed' );
t( false === strpos( $r->content, 'headingLevel' ) || false !== strpos( $r->content, '"headingLevel":2' ), 'm2c: default Method h2 → headingLevel 2' );

// 7. Method closed=true stored as string.
$ms = str_replace( '{"accordionId":"aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa"}', '{"accordionId":"aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa","closed":"true"}', $samples['method_basic'] );
$r  = $m2c( $ms );
t( $r->changed && false === strpos( $r->content, 'is-open' ), 'm2c: closed "true" honored' );

// 8. core->method: headings at mixed levels.
$mixed = preg_replace( '/<!-- wp:accordion-heading \{"level":3\} -->\n<h3 (.*?)<\/h3>/s', "<!-- wp:accordion-heading {\"level\":4} -->\n<h4 $1</h4>", $acc, 1 );
$r     = $c2m( $mixed );
t( $r->changed && false !== strpos( $msgs( $r ), 'different heading levels (h4, h3)' ) && false !== strpos( $r->content, '"hTag":"h4"' ), 'mixed levels: first level used, warned' );

// Panel ids: Method beta28+ scopes them per accordion; ≤ beta27 used collapse{n}.
$n = 0;
$r = $c2m( $samples['in_group_with_siblings'] );
t( false !== strpos( $r->content, 'id="accordion-00000000-0000-4000-8000-000000000001-collapse-1" data-bs-parent="#accordion-00000000-0000-4000-8000-000000000001"' )
	&& false !== strpos( $r->content, 'id="accordion-00000000-0000-4000-8000-000000000002-collapse-1"' )
	&& false === strpos( $r->content, 'id="collapse1"' ), 'panel ids: scoped format, unique across accordions' );
t( false === strpos( $msgs( $r ), 'Method accordions' ), 'panel ids: no duplicate-id warning in scoped mode' );
$r = $c2m( $samples['in_group_with_siblings'], Accordion_Converter::PANEL_IDS_LEGACY );
t( 2 === substr_count( $r->content, 'id="collapse1"' ) && false !== strpos( $msgs( $r ), 'before 2.0.0-beta28' ), 'panel ids: legacy format + warning when a post has 2 accordions' );
$filter = function () {
	return Accordion_Converter::PANEL_IDS_LEGACY;
};
add_filter( 'method_tools_accordion_panel_ids', $filter );
t( Accordion_Converter::PANEL_IDS_LEGACY === Accordion_Converter::installed_panel_ids(), 'panel ids: filter override' );
remove_filter( 'method_tools_accordion_panel_ids', $filter );
t( ( function_exists( 'method_accordion_collapse_id' ) ? Accordion_Converter::PANEL_IDS_SCOPED : Accordion_Converter::PANEL_IDS_LEGACY ) === Accordion_Converter::installed_panel_ids(), 'panel ids: detected from Method\'s helper' );
if ( function_exists( 'method_accordion_collapse_id' ) ) {
	$out = $c2m( $acc )->content;
	preg_match( '/"accordionId":"([^"]+)"/', $out, $acc_id );
	t( false !== strpos( $out, 'id="' . method_accordion_collapse_id( $acc_id[1], 2 ) . '" data-bs-parent="#' . method_accordion_element_id( $acc_id[1] ) . '"' ), 'panel ids: match Method\'s own helpers' );
}

// Method -> core reads both Method formats identically.
$m27 = ( new Accordion_Converter( Accordion_Converter::METHOD_TO_CORE, $ids ) )->convert( $samples['method_basic'] );
$m28 = ( new Accordion_Converter( Accordion_Converter::METHOD_TO_CORE, $ids ) )->convert( $samples['method_b28_basic'] );
t( $m27->changed && $m28->changed && $m27->content === $m28->content, 'm2c: beta27 and beta28 Method markup convert to the same core markup' );

// 9. Helpers.
t( 'a <b>b</b> c' === Block_Markup::inner_html_by_class( '<p><span class="x y">a <b>b</b> c</span><span class="z">q</span></p>', 'y' ), 'helper: inner_html_by_class' );
t( 'a <span>n</span> b' === Block_Markup::inner_html_by_class( '<span class="t">a <span>n</span> b</span>', 't' ), 'helper: nested same tag' );
t( null === Block_Markup::inner_html_by_class( '<span class="tt">a</span>', 't' ), 'helper: class token boundary' );
t( 'faq' === Block_Markup::first_tag_attribute( '<div role="group" class="a" id="faq"><p id="x">', 'id' ), 'helper: first tag attribute' );

// 10. Core serializer parity: delimiters identical to serialize_block().
if ( function_exists( 'serialize_block' ) ) {
	$attrs = array( 'headline' => 'A <b>"q"</b> -- & é', 'n' => 1 );
	$core  = serialize_block( array( 'blockName' => 'method/accordion-item', 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => "\n<div></div>\n", 'innerContent' => array( "\n<div></div>\n" ) ) );
	$mine  = Block_Markup::block( 'method/accordion-item', $attrs, '<div></div>' );
	t( $core === $mine, 'serializer: matches core serialize_block' );
}

echo $GLOBALS["pass"] . " passed, " . $GLOBALS["fail"] . " failed\n";
