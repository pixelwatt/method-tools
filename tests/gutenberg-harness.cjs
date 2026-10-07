/**
 * Gutenberg harness: serializes and validates block markup with the real
 * @wordpress/blocks + @wordpress/block-library of a given WordPress release,
 * plus Method's actual compiled accordion block scripts.
 *
 * Setup (one directory per WordPress release; versions must be pinned
 * together or mixed copies of @wordpress/rich-text break serialization):
 *
 *   mkdir harness-wp-6.9 && cd harness-wp-6.9
 *   # package.json: dependencies { "@wordpress/block-library": "<npm view @wordpress/block-library@wp-6.9 version>",
 *   #   "jsdom": "24.1.3", "react": "18.3.1", "react-dom": "18.3.1" }, plus an "overrides" map pinning every
 *   #   @wordpress/* package in the tree to `npm view <pkg>@wp-6.9 version`.
 *   npm install --legacy-peer-deps
 *
 * Usage:
 *   METHOD_ACCORDION_BUILD=<method>/lib/blocks/method-accordion/build \
 *     node gutenberg-harness.cjs <harness-dir> samples <out.json>
 *   METHOD_ACCORDION_BUILD=… node gutenberg-harness.cjs <harness-dir> validate <in.json> [<out.json>]
 *     in.json: { "<case>": "<post_content>", ... } (e.g. convert-samples.php output)
 */
const path = require( 'path' );
const fs = require( 'fs' );
const { createRequire } = require( 'module' );

const base = path.resolve( process.argv[ 2 ] );
const mode = process.argv[ 3 ];
const req = createRequire( path.join( base, 'package.json' ) );

// ---- DOM globals -------------------------------------------------------
const { JSDOM } = req( 'jsdom' );
const dom = new JSDOM( '<!doctype html><html><head></head><body></body></html>', {
	url: 'http://localhost/',
	pretendToBeVisual: true,
} );
const w = dom.window;
global.window = w;
for ( const key of Object.getOwnPropertyNames( w ) ) {
	if ( key in global ) continue;
	try {
		global[ key ] = w[ key ];
	} catch ( e ) {}
}
global.document = w.document;
try { global.navigator = w.navigator; } catch ( e ) {}
w.matchMedia = global.matchMedia = () => ( {
	matches: false, media: '', onchange: null,
	addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent() { return false; },
} );
global.requestAnimationFrame = w.requestAnimationFrame = ( cb ) => setTimeout( cb, 0 );
global.cancelAnimationFrame = w.cancelAnimationFrame = ( id ) => clearTimeout( id );
global.ResizeObserver = w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
global.IntersectionObserver = w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
global.MutationObserver = w.MutationObserver;
global.CSS = w.CSS = { supports: () => false, escape: ( s ) => s };

// Quiet the library's own logging (validation logs go to console).
const logs = [];
for ( const k of [ 'log', 'info', 'warn', 'error', 'group', 'groupCollapsed', 'groupEnd' ] ) {
	console[ k ] = ( ...args ) => logs.push( [ k, args.map( String ).join( ' ' ).slice( 0, 400 ) ] );
}
const out = ( s ) => process.stdout.write( s + '\n' );

// ---- WordPress packages --------------------------------------------------
const blocks = req( '@wordpress/blocks' );
const blockEditor = req( '@wordpress/block-editor' );
const element = req( '@wordpress/element' );
const data = req( '@wordpress/data' );
const components = req( '@wordpress/components' );
const library = req( '@wordpress/block-library' );
library.registerCoreBlocks();

// ---- Method's compiled accordion scripts --------------------------------
w.wp = { blocks, blockEditor, element, data, components };
w.ReactJSXRuntime = req( 'react/jsx-runtime' );
const methodBuild = process.env.METHOD_ACCORDION_BUILD;
for ( const b of [ 'accordion', 'item', 'body' ] ) {
	const src = fs.readFileSync( path.join( methodBuild, b, 'index.js' ), 'utf8' );
	// Scripts reference `window.wp...`; run with window in scope.
	new Function( 'window', src )( w );
}
for ( const n of [ 'core/accordion', 'core/accordion-item', 'core/accordion-heading', 'core/accordion-panel', 'method/accordion', 'method/accordion-item', 'method/accordion-body' ] ) {
	if ( ! blocks.getBlockType( n ) ) {
		throw new Error( 'Block not registered: ' + n );
	}
}

const { createBlock, serialize, parse } = blocks;

// ---- Modes ---------------------------------------------------------------
function para( text ) {
	return createBlock( 'core/paragraph', { content: text } );
}

/** Build a core accordion the way the editor's templates do. */
function coreAccordion( opts = {} ) {
	const level = opts.headingLevel || 3;
	const accAttrs = Object.assign( {}, opts.attrs || {} );
	if ( opts.headingLevel ) accAttrs.headingLevel = opts.headingLevel;
	const items = ( opts.items || [] ).map( ( it ) => {
		const open = !! it.open;
		const heading = createBlock( 'core/accordion-heading', Object.assign( { level, title: it.title }, opts.iconPosition ? { iconPosition: opts.iconPosition } : {}, opts.showIcon === false ? { showIcon: false } : {}, it.headingAttrs || {} ) );
		const panelAttrs = blocks.getBlockType( 'core/accordion-panel' ).attributes.openByDefault ? { openByDefault: open } : {};
		const panel = createBlock( 'core/accordion-panel', Object.assign( panelAttrs, it.panelAttrs || {} ), it.inner || [ para( 'Panel text for ' + it.title ) ] );
		return createBlock( 'core/accordion-item', Object.assign( { openByDefault: open }, it.itemAttrs || {} ), [ heading, panel ] );
	} );
	if ( opts.iconPosition ) accAttrs.iconPosition = opts.iconPosition;
	if ( opts.showIcon === false ) accAttrs.showIcon = false;
	return createBlock( 'core/accordion', accAttrs, items );
}

function samples() {
	const s = {};
	s.basic = serialize( [ coreAccordion( { items: [ { title: 'First question' }, { title: 'Second <strong>bold</strong> &amp; <em>em</em>' } ] } ) ] );
	s.first_open = serialize( [ coreAccordion( { items: [ { title: 'Open one', open: true }, { title: 'Closed two' }, { title: 'Closed three' } ] } ) ] );
	s.level2_left_icon = serialize( [ coreAccordion( { headingLevel: 2, iconPosition: 'left', items: [ { title: 'H2 left A' }, { title: 'H2 left B' } ] } ) ] );
	s.no_icon_h4 = serialize( [ coreAccordion( { headingLevel: 4, showIcon: false, items: [ { title: 'No icon' } ] } ) ] );
	s.styled = serialize( [ coreAccordion( {
		attrs: { className: 'faq is-style-plain', align: 'wide', anchor: 'faq', backgroundColor: 'base', autoclose: true },
		items: [
			{ title: 'Styled', itemAttrs: { className: 'item-x', textColor: 'contrast' }, headingAttrs: { anchor: 'q1', className: 'hd' }, panelAttrs: { className: 'pn' } },
			{ title: 'Link <a href="https://example.com">here</a> and <code>code</code>', open: true },
		],
	} ) ] );
	s.middle_open = serialize( [ coreAccordion( { items: [ { title: 'A' }, { title: 'B', open: true }, { title: 'C', open: true } ] } ) ] );
	const nestedInner = [ para( 'Outer text' ), coreAccordion( { items: [ { title: 'Inner A' }, { title: 'Inner B' } ] } ), para( 'After inner' ) ];
	s.nested = serialize( [ coreAccordion( { items: [ { title: 'Outer', inner: nestedInner }, { title: 'Outer 2' } ] } ) ] );
	s.in_group_with_siblings = serialize( [
		createBlock( 'core/heading', { content: 'FAQ', level: 2 } ),
		createBlock( 'core/group', { layout: { type: 'constrained' } }, [
			para( 'Intro <a href="/x">link</a>' ),
			coreAccordion( { items: [ { title: 'In group', open: true, inner: [ para( 'one' ), createBlock( 'core/list', {}, [ createBlock( 'core/list-item', { content: 'li' } ) ] ) ] } ] } ),
		] ),
		coreAccordion( { items: [ { title: 'Second accordion on page' } ] } ),
		para( 'Tail' ),
	] );
	s.empty_panel = serialize( [ coreAccordion( { items: [ { title: 'Empty', inner: [] } ] } ) ] );
	s.empty_title = serialize( [ coreAccordion( { items: [ { title: '' } ] } ) ] );
	s.special_chars = serialize( [ coreAccordion( { items: [ { title: 'Quotes "double" \'single\' -- dashes < > ✓ émoji 🎉' } ] } ) ] );

	// Method markup produced by Method's own save() for the reverse direction.
	const methodItem = ( headline, idx, accId, closed, inner ) => {
		const attrs = { headline, itemIndex: idx, parentAccordionId: accId };
		if ( closed ) attrs.closed = true;
		return createBlock( 'method/accordion-item', attrs, [ createBlock( 'method/accordion-body', {}, inner || [ para( 'Body ' + idx ) ] ) ] );
	};
	const methodAcc = ( attrs, items ) => createBlock( 'method/accordion', attrs, items );
	s.method_basic = serialize( [ methodAcc( { accordionId: 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa' }, [
		methodItem( 'First', 1, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa' ),
		methodItem( 'Second <strong>bold</strong>', 2, 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa' ),
	] ) ] );
	s.method_closed_h4 = serialize( [ methodAcc( { accordionId: 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb', closed: true, hTag: 'h4', align: 'wide', className: 'm-cls' }, [
		methodItem( 'Closed A', 1, 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb', true ),
		methodItem( 'Tom & Jerry "quoted" <em>x</em>', 2, 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb', true, [ para( 'p1' ), createBlock( 'core/image', { url: 'https://example.com/a.jpg', alt: 'a' } ) ] ),
	] ) ] );
	return s;
}

function walk( list, path, outArr ) {
	list.forEach( ( b, i ) => {
		const p = path.concat( i );
		outArr.push( {
			path: p.join( '.' ),
			name: b.name,
			isValid: b.isValid,
			attributes: b.attributes,
			issues: ( b.validationIssues || [] ).map( ( v ) => String( v.args ? v.args.join( ' | ' ) : v ) ).slice( 0, 3 ),
		} );
		walk( b.innerBlocks || [], p, outArr );
	} );
}

function validate( input ) {
	const results = {};
	for ( const [ key, content ] of Object.entries( input ) ) {
		logs.length = 0;
		const parsed = parse( content );
		const all = [];
		walk( parsed, [], all );
		const unknown = all.filter( ( b ) => b.name === 'core/missing' || ! blocks.getBlockType( b.name ) );
		const invalid = all.filter( ( b ) => b.isValid === false );
		// Re-serialize: tells us whether the editor would rewrite the markup on save.
		const reserialized = serialize( parsed );
		results[ key ] = {
			ok: invalid.length === 0 && unknown.length === 0,
			invalid,
			unknown: unknown.map( ( b ) => b.name ),
			blocks: all.map( ( b ) => ( { path: b.path, name: b.name, attributes: b.attributes } ) ),
			reserializedSame: reserialized === content,
			reserialized,
		};
	}
	return results;
}

if ( mode === 'samples' ) {
	fs.writeFileSync( process.argv[ 4 ], JSON.stringify( samples(), null, 2 ) );
	out( 'wrote ' + process.argv[ 4 ] );
} else if ( mode === 'validate' ) {
	const input = JSON.parse( fs.readFileSync( process.argv[ 4 ], 'utf8' ) );
	const res = validate( input );
	if ( process.argv[ 5 ] ) fs.writeFileSync( process.argv[ 5 ], JSON.stringify( res, null, 2 ) );
	for ( const [ k, r ] of Object.entries( res ) ) {
		out( ( r.ok ? 'VALID  ' : 'INVALID' ) + ' ' + k + ( r.ok ? '' : ' :: ' + JSON.stringify( { invalid: r.invalid.map( ( b ) => ( { path: b.path, name: b.name, issues: b.issues } ) ), unknown: r.unknown } ) ) + ( r.reserializedSame ? '' : '  (editor would re-serialize differently)' ) );
	}
} else {
	out( 'unknown mode' );
	process.exit( 1 );
}
process.exit( 0 );
