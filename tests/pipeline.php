<?php
/**
 * End-to-end through the REST routes as an admin on a real site.
 *
 * Creates (and finally deletes) its own posts and a throwaway editor user,
 * and only targets the posts it created — but it does write to the database,
 * so run it on a disposable local site, never on staging or production.
 *
 *   wp eval-file tests/pipeline.php tests/fixtures/core-6.9-samples.json
 */

use Method_Tools\Runs;

$samples = json_decode( file_get_contents( $args[0] ), true );
$GLOBALS['p'] = 0;
$GLOBALS['f'] = 0;
function ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['p']++;
	} else {
		$GLOBALS['f']++;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}
function rest( $method, $path, $body = array() ) {
	$req = new WP_REST_Request( $method, '/method-tools/v1' . $path );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

register_post_type( 'book', array( 'public' => true, 'show_in_rest' => false, 'supports' => array( 'title', 'editor' ) ) );
register_post_type( 'event', array( 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title', 'editor' ) ) );

// Unauthenticated: routes refuse.
wp_set_current_user( 0 );
list( $status ) = rest( 'POST', '/tools/accordion-converter/candidates', array() );
ok( 401 === $status || 403 === $status, "anon refused ($status)" );

// Editor (no manage_options): refused.
$editor = wp_insert_user( array( 'user_login' => 'ed' . wp_rand(), 'user_pass' => 'x', 'role' => 'editor' ) );
wp_set_current_user( $editor );
list( $status ) = rest( 'POST', '/tools/accordion-converter/candidates', array() );
ok( 403 === $status, "editor refused ($status)" );

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );

$html_block = "<!-- wp:html -->\n<iframe src=\"https://example.com/e\" data-x=\"1\"></iframe><script>window.a=1;</script>\n<!-- /wp:html -->\n\n";
$make = function ( $type, $status, $title, $content ) {
	return wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_content' => $content ) ) );
};
$ids = array(
	'page_group'   => $make( 'page', 'publish', 'FAQ page', $samples['in_group_with_siblings'] ),
	'post_basic'   => $make( 'post', 'publish', 'Post basic', $html_block . $samples['basic'] ),
	'pattern'      => $make( 'wp_block', 'publish', 'Pattern', $samples['first_open'] ),
	'draft_styled' => $make( 'page', 'draft', 'Draft styled', $samples['styled'] ),
	'private_nest' => $make( 'page', 'private', 'Private nested', $samples['nested'] ),
	'trashed'      => $make( 'page', 'trash', 'Trashed', $samples['basic'] ),
	'classic_cpt'  => $make( 'book', 'publish', 'Book', $samples['basic'] ),
	'event'        => $make( 'event', 'publish', 'Event', $samples['basic'] ),
	'no_acc'       => $make( 'page', 'publish', 'No accordion', "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->" ),
	'method_page'  => $make( 'page', 'publish', 'Method page', $samples['method_basic'] ),
	'tpl_part'     => $make( 'wp_template_part', 'publish', 'footer-faq', $samples['basic'] ),
);
foreach ( $ids as $k => $id ) {
	ok( $id > 0 && ! is_wp_error( $id ), "insert $k" );
}
$original = array();
foreach ( $ids as $k => $id ) {
	$original[ $k ] = get_post_field( 'post_content', $id, 'raw' );
}
ok( $original['post_basic'] === $html_block . $samples['basic'], 'admin insert kept iframe/script (unfiltered_html)' );
$mine = implode( ',', $ids );

// A page template the active theme doesn't offer (left over from a previous theme).
update_post_meta( $ids['page_group'], '_wp_page_template', 'templates/old-theme-template.php' );

// Post types offered.
$types = Method_Tools\Post_Scanner::post_types();
ok( isset( $types['page'], $types['post'], $types['wp_block'], $types['wp_template_part'], $types['event'] ), 'types include block-editor and site-editor types' );
ok( ! isset( $types['book'] ), 'types exclude non-REST (classic) CPT' );
ok( ! isset( $types['attachment'] ), 'types exclude attachments' );

$opts = array( 'direction' => 'core-to-method' );

// Candidates.
list( $status, $data ) = rest( 'POST', '/tools/accordion-converter/candidates', array( 'options' => $opts, 'criteria' => array( 'include' => $mine ) ) );
ok( 200 === $status, 'candidates 200' );
$expect = array( $ids['page_group'], $ids['post_basic'], $ids['pattern'], $ids['draft_styled'], $ids['private_nest'], $ids['event'], $ids['tpl_part'] );
sort( $expect );
ok( $data['ids'] === $expect, 'candidates = expected set: ' . wp_json_encode( $data['ids'] ) . ' vs ' . wp_json_encode( $expect ) );
$method_current = WP_Block_Type_Registry::get_instance()->is_registered( 'method/accordion' ) && function_exists( 'method_accordion_collapse_id' );
ok( $method_current ? empty( $data['notices'] ) : ! empty( $data['notices'] ), 'env notice for c2m iff Method missing or older than beta28' );

// Criteria narrowing.
list( , $d2 ) = rest( 'POST', '/tools/accordion-converter/candidates', array( 'options' => $opts, 'criteria' => array( 'include' => $mine, 'post_types' => array( 'page' ), 'statuses' => array( 'publish', 'draft' ), 'exclude' => (string) $ids['draft_styled'] ) ) );
ok( $d2['ids'] === array( $ids['page_group'] ), 'criteria: type/status/exclude narrowing' );
list( , $d3 ) = rest( 'POST', '/tools/accordion-converter/candidates', array( 'options' => $opts, 'criteria' => array( 'include' => $ids['pattern'] . ', ' . $ids['no_acc'] ) ) );
ok( $d3['ids'] === array( $ids['pattern'] ), 'criteria: include list' );

// m2c env notice on WP < 6.9.
list( , $d4 ) = rest( 'POST', '/tools/accordion-converter/candidates', array( 'options' => array( 'direction' => 'method-to-core' ), 'criteria' => array( 'include' => $mine ) ) );
ok( $d4['ids'] === array( $ids['method_page'] ), 'm2c candidates' );
ok( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) ? empty( $d4['notices'] ) : ! empty( $d4['notices'] ), 'm2c env notice iff core accordion missing' );

// Preview writes nothing.
list( , $prev ) = rest( 'POST', '/tools/accordion-converter/process', array( 'options' => $opts, 'ids' => $data['ids'], 'mode' => 'preview' ) );
ok( 7 === count( $prev['results'] ), 'preview results' );
$changed = array_values( array_map( function ( $r ) { return $r['id']; }, array_filter( $prev['results'], function ( $r ) { return $r['changed']; } ) ) );
ok( 7 === count( $changed ), 'preview: all 7 would change' );
foreach ( $ids as $k => $id ) {
	ok( get_post_field( 'post_content', $id, 'raw' ) === $original[ $k ], "preview left $k untouched" );
}

// Apply without a run is refused; with mismatched options refused.
list( $st ) = rest( 'POST', '/tools/accordion-converter/process', array( 'options' => $opts, 'ids' => $changed, 'mode' => 'apply' ) );
ok( 400 === $st, 'apply without run refused' );

// Apply as a user WITHOUT unfiltered_html, to prove kses doesn't strip unrelated markup.
add_filter( 'map_meta_cap', $nofilter = function ( $caps, $cap ) {
	return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
}, 10, 2 );
wp_set_current_user( 0 );
wp_set_current_user( $admin->ID ); // re-runs kses_init for the reduced caps.
ok( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses active for this user' );

list( , $run ) = rest( 'POST', '/tools/accordion-converter/runs', array( 'options' => $opts, 'criteria' => array( 'include' => $mine ) ) );
ok( ! empty( $run['id'] ), 'run created' );
list( $st ) = rest( 'POST', '/tools/accordion-converter/process', array( 'options' => array( 'direction' => 'method-to-core' ), 'ids' => $changed, 'mode' => 'apply', 'run' => $run['id'] ) );
ok( 400 === $st, 'apply with options differing from run refused' );

$revisions_before = count( wp_get_post_revisions( $ids['page_group'] ) );
list( , $applied ) = rest( 'POST', '/tools/accordion-converter/process', array( 'options' => $opts, 'ids' => $changed, 'mode' => 'apply', 'run' => $run['id'] ) );
$written = array_filter( $applied['results'], function ( $r ) { return $r['written']; } );
ok( 7 === count( $written ), 'apply wrote 7: ' . wp_json_encode( array_map( function ( $r ) { return array( $r['id'], $r['error'] ); }, $applied['results'] ) ) );
ok( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses filters restored after write' );
rest( 'POST', '/runs/' . $run['id'] . '/finish' );

$after_basic = get_post_field( 'post_content', $ids['post_basic'], 'raw' );
ok( 0 === strpos( $after_basic, $html_block ), 'iframe/script block survived the write byte-for-byte' );
ok( false !== strpos( $after_basic, '<!-- wp:method/accordion ' ) && false === strpos( $after_basic, '<!-- wp:accordion ' ), 'post converted' );
ok( count( wp_get_post_revisions( $ids['page_group'] ) ) > $revisions_before, 'revision created (despite the stale page template)' );
ok( 'templates/old-theme-template.php' === get_post_meta( $ids['page_group'], '_wp_page_template', true ), 'stale page template left as it was' );
ok( get_post_field( 'post_content', $ids['no_acc'], 'raw' ) === $original['no_acc'], 'non-candidate untouched' );
ok( get_post_field( 'post_content', $ids['trashed'], 'raw' ) === $original['trashed'], 'trash untouched' );
ok( get_post_field( 'post_content', $ids['classic_cpt'], 'raw' ) === $original['classic_cpt'], 'classic CPT untouched' );
ok( 7 === Runs::backups_remaining( $run['id'] ), 'backups stored' );
remove_filter( 'map_meta_cap', $nofilter, 10 );
wp_set_current_user( 0 );
wp_set_current_user( $admin->ID );

// Re-scan finds nothing to change (idempotent).
list( , $prev2 ) = rest( 'POST', '/tools/accordion-converter/process', array( 'options' => $opts, 'ids' => $changed, 'mode' => 'preview' ) );
ok( 0 === count( array_filter( $prev2['results'], function ( $r ) { return $r['changed']; } ) ), 'second scan: nothing to change' );

// Front-end render through Method's render callbacks.
global $post;
if ( WP_Block_Type_Registry::get_instance()->is_registered( 'method/accordion' ) ) :
$post = get_post( $ids['page_group'] );
setup_postdata( $post );
$html = apply_filters( 'the_content', $post->post_content );
ok( false !== strpos( $html, 'class="accordion-button' ) && false !== strpos( $html, 'method-accordion' ), 'front end renders Method accordion' );
ok( false !== strpos( $html, '<h3 class="accordion-header">' ), 'front end uses h3 (from core default level)' );
ok( false !== strpos( $html, 'In group' ), 'front end has headline text' );
preg_match_all( '/<div[^>]*class="[^"]*accordion-collapse[^"]*"[^>]*\sid="([^"]+)"/', $html, $panel_ids );
ok( 2 === count( $panel_ids[1] ) && 2 === count( array_unique( $panel_ids[1] ) ) || ! function_exists( 'method_accordion_collapse_id' ), 'front end: panel ids unique across the two accordions (beta28+): ' . wp_json_encode( $panel_ids[1] ) );
wp_reset_postdata();
endif;

// List runs.
list( , $runs ) = rest( 'GET', '/tools/accordion-converter/runs' );
ok( $runs && $runs[0]['id'] === $run['id'] && 7 === $runs[0]['backups'] && 7 === $runs[0]['changed'], 'run listed with backups' );

// Edit one post after the run, then restore: edited post skipped, others restored.
wp_update_post( wp_slash( array( 'ID' => $ids['draft_styled'], 'post_content' => get_post_field( 'post_content', $ids['draft_styled'], 'raw' ) . "\n\n<!-- wp:paragraph -->\n<p>edit</p>\n<!-- /wp:paragraph -->" ) ) );
$after = 0;
$restored = array();
$skipped = array();
do {
	list( , $res ) = rest( 'POST', '/runs/' . $run['id'] . '/restore', array( 'after' => $after ) );
	$restored = array_merge( $restored, $res['restored'] );
	$skipped  = array_merge( $skipped, $res['skipped'] );
	$after    = $res['next'];
} while ( ! $res['done'] );
ok( 6 === count( $restored ) && 1 === count( $skipped ) && $skipped[0]['id'] === $ids['draft_styled'], 'restore: 6 restored, edited one skipped' );
foreach ( array( 'page_group', 'post_basic', 'pattern', 'private_nest', 'event', 'tpl_part' ) as $k ) {
	ok( get_post_field( 'post_content', $ids[ $k ], 'raw' ) === $original[ $k ], "restore: $k byte-identical to original" );
}
ok( 1 === Runs::backups_remaining( $run['id'] ), 'restore: skipped backup kept' );
list( , $res ) = rest( 'POST', '/runs/' . $run['id'] . '/restore', array( 'after' => 0, 'force' => true ) );
ok( array( $ids['draft_styled'] ) === $res['restored'] && $res['done'], 'forced restore' );
ok( get_post_field( 'post_content', $ids['draft_styled'], 'raw' ) === $original['draft_styled'], 'forced restore byte-identical' );
ok( 0 === Runs::backups_remaining( $run['id'] ), 'no backups left' );

// A backup row not written by the plugin (e.g. imported meta) is never restored.
list( , $run2 ) = rest( 'POST', '/tools/accordion-converter/runs', array( 'options' => $opts, 'criteria' => array( 'include' => $mine ) ) );
add_post_meta( $ids['no_acc'], Runs::META_PREFIX . $run2['id'], array( 'content' => '<p>planted</p><script>x()</script>', 'hash' => '', 'time' => 0 ) );
list( , $res ) = rest( 'POST', '/runs/' . $run2['id'] . '/restore', array( 'after' => 0, 'force' => true ) );
ok( ! $res['restored'] && 1 === count( $res['skipped'] ) && get_post_field( 'post_content', $ids['no_acc'], 'raw' ) === $original['no_acc'], 'planted backup refused' );
Runs::discard_backups( $run2['id'] );

echo $GLOBALS['p'] . ' passed, ' . $GLOBALS['f'] . " failed\n";
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $editor );
$all = get_option( Runs::OPTION );
unset( $all[ $run['id'] ], $all[ $run2['id'] ] );
update_option( Runs::OPTION, $all, false );
