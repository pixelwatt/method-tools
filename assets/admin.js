/**
 * Method Tools admin: drives every Post_Tool panel (scan → convert → history).
 * No build step; depends only on wp.apiFetch.
 */
( function () {
	'use strict';

	var settings = window.methodTools || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	if ( ! apiFetch ) {
		return;
	}

	function api( path, data, method ) {
		return apiFetch( {
			path: '/' + settings.namespace + path,
			method: method || 'POST',
			data: data,
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'className' ) {
				node.className = attrs[ key ];
			} else if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined && attrs[ key ] !== false ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function chunk( list, size ) {
		var out = [];
		for ( var i = 0; i < list.length; i += size ) {
			out.push( list.slice( i, i + size ) );
		}
		return out;
	}

	function errorText( err ) {
		return ( err && ( err.message || err.code ) ) || String( err );
	}

	function localTime( gmt ) {
		if ( ! gmt ) {
			return '';
		}
		var d = new Date( gmt.replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? gmt : d.toLocaleString();
	}

	function messageList( messages ) {
		if ( ! messages || ! messages.length ) {
			return null;
		}
		return el(
			'ul',
			{ className: 'mt-messages' },
			messages.map( function ( m ) {
				return el( 'li', { className: 'mt-msg mt-msg-' + m.level }, [ el( 'span', { className: 'mt-badge', text: m.level } ), ' ' + m.text ] );
			} )
		);
	}

	function Panel( root ) {
		this.root = root;
		this.config = JSON.parse( root.getAttribute( 'data-config' ) );
		this.form = root.querySelector( '.mt-form' );
		this.scanButton = root.querySelector( '.mt-scan' );
		this.applyButton = root.querySelector( '.mt-apply' );
		this.status = root.querySelector( '.mt-status' );
		this.progress = root.querySelector( '.mt-progress' );
		this.notices = root.querySelector( '.mt-notices' );
		this.results = root.querySelector( '.mt-results' );
		this.runsBody = root.querySelector( '.mt-runs-body' );
		this.scan = null;
		this.busy = false;

		var self = this;
		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.runScan();
		} );
		this.form.addEventListener( 'change', function () {
			self.invalidateScan();
		} );
		this.form.addEventListener( 'input', function () {
			self.invalidateScan();
		} );
		this.applyButton.addEventListener( 'click', function () {
			self.runApply();
		} );
		this.loadRuns();
	}

	Panel.prototype.request = function () {
		var form = this.form;
		var options = {};
		var criteria = { post_types: [], statuses: [], include: '', exclude: '' };

		Array.prototype.forEach.call( form.elements, function ( input ) {
			if ( ! input.name ) {
				return;
			}
			if ( input.name.indexOf( 'option:' ) === 0 ) {
				var key = input.name.slice( 7 );
				if ( input.type === 'radio' ) {
					if ( input.checked ) {
						options[ key ] = input.value;
					}
				} else if ( input.type === 'checkbox' ) {
					options[ key ] = input.checked;
				} else {
					options[ key ] = input.value;
				}
			} else if ( input.name === 'post_types' || input.name === 'statuses' ) {
				if ( input.checked ) {
					criteria[ input.name ].push( input.value );
				}
			} else if ( input.name === 'include' || input.name === 'exclude' ) {
				criteria[ input.name ] = input.value;
			}
		} );
		return { options: options, criteria: criteria };
	};

	Panel.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		this.scanButton.disabled = busy;
		this.applyButton.disabled = busy || ! this.scan || ! this.scan.changed.length;
		Array.prototype.forEach.call( this.form.elements, function ( input ) {
			if ( input.tagName !== 'BUTTON' ) {
				input.disabled = busy;
			}
		} );
	};

	Panel.prototype.setStatus = function ( text ) {
		this.status.textContent = text || '';
	};

	Panel.prototype.setProgress = function ( done, total ) {
		if ( ! total ) {
			this.progress.hidden = true;
			return;
		}
		this.progress.hidden = false;
		this.progress.value = Math.round( ( done / total ) * 100 );
	};

	Panel.prototype.invalidateScan = function () {
		if ( this.busy || ! this.scan ) {
			return;
		}
		this.scan = null;
		this.applyButton.disabled = true;
		this.applyButton.textContent = 'Convert';
		this.setStatus( 'Settings changed — scan again before converting.' );
	};

	Panel.prototype.showNotices = function ( notices ) {
		this.notices.textContent = '';
		var self = this;
		( notices || [] ).forEach( function ( n ) {
			self.notices.appendChild( el( 'div', { className: 'notice inline notice-' + ( n.level === 'error' ? 'error' : 'warning' ) }, [ el( 'p', { text: n.text } ) ] ) );
		} );
	};

	Panel.prototype.runScan = async function () {
		var req = this.request();
		var self = this;
		this.scan = null;
		this.results.hidden = true;
		this.setBusy( true );
		this.setStatus( 'Finding posts…' );

		try {
			var found = await api( '/tools/' + this.config.tool + '/candidates', req );
			this.showNotices( found.notices );

			if ( ! found.total ) {
				this.setStatus( 'No matching posts contain these blocks.' );
				this.scan = { request: req, rows: [], changed: [] };
				this.renderResults( 'preview' );
				return;
			}

			var rows = [];
			var batches = chunk( found.ids, settings.previewBatch || 25 );
			for ( var i = 0; i < batches.length; i++ ) {
				this.setStatus( 'Scanning ' + Math.min( ( i + 1 ) * ( settings.previewBatch || 25 ), found.total ) + ' of ' + found.total + '…' );
				this.setProgress( i, batches.length );
				var res = await api( '/tools/' + this.config.tool + '/process', { options: req.options, criteria: req.criteria, ids: batches[ i ], mode: 'preview' } );
				rows = rows.concat( res.results );
			}
			this.setProgress( 0, 0 );

			this.scan = {
				request: req,
				rows: rows,
				changed: rows.filter( function ( r ) {
					return r.changed && ! r.error && ! r.messages.some( function ( m ) {
						return m.level === 'error';
					} );
				} ).map( function ( r ) {
					return r.id;
				} ),
			};
			this.renderResults( 'preview' );
			this.setStatus( 'Dry run complete: ' + this.scan.changed.length + ' of ' + found.total + ' candidate posts would change. Nothing has been saved.' );
		} catch ( err ) {
			this.setStatus( 'Scan failed: ' + errorText( err ) );
		} finally {
			this.setProgress( 0, 0 );
			this.setBusy( false );
			if ( self.scan && self.scan.changed.length ) {
				self.applyButton.textContent = 'Convert ' + self.scan.changed.length + ( self.scan.changed.length === 1 ? ' post' : ' posts' );
			}
		}
	};

	Panel.prototype.runApply = async function () {
		if ( ! this.scan || ! this.scan.changed.length ) {
			return;
		}
		var count = this.scan.changed.length;
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Convert ' + count + ( count === 1 ? ' post' : ' posts' ) + '? Each post is backed up first and the run can be restored from Run history.' ) ) {
			return;
		}

		var req = this.scan.request;
		var ids = this.scan.changed.slice();
		var byId = {};
		this.scan.rows.forEach( function ( r ) {
			byId[ r.id ] = r;
		} );

		this.setBusy( true );
		var written = 0;
		var failed = 0;
		var current = 0;
		this.scan.attempted = ids.slice();

		try {
			var run = await api( '/tools/' + this.config.tool + '/runs', req );
			var batches = chunk( ids, settings.applyBatch || 10 );
			for ( var i = 0; i < batches.length; i++ ) {
				this.setStatus( 'Converting ' + Math.min( ( i + 1 ) * ( settings.applyBatch || 10 ), count ) + ' of ' + count + '…' );
				this.setProgress( i, batches.length );
				var res = await api( '/tools/' + this.config.tool + '/process', { options: req.options, criteria: req.criteria, ids: batches[ i ], mode: 'apply', run: run.id } );
				res.results.forEach( function ( r ) {
					byId[ r.id ] = r;
					if ( r.written ) {
						written++;
					} else if ( ! r.error && ! r.changed ) {
						current++; // Converted or edited since the scan; nothing left to do.
					} else {
						failed++;
					}
				} );
			}
			await api( '/runs/' + run.id + '/finish', {} );
			this.scan.rows = this.scan.rows.map( function ( r ) {
				return byId[ r.id ];
			} );
			this.scan.changed = [];
			this.renderResults( 'apply' );
			this.setStatus( 'Converted ' + written + ( written === 1 ? ' post' : ' posts' ) + ( current ? '; ' + current + ' no longer needed it' : '' ) + ( failed ? '; ' + failed + ' not saved (see below)' : '' ) + '. Run ' + run.id + '.' );
		} catch ( err ) {
			this.setStatus( 'Conversion stopped: ' + errorText( err ) + '. Posts already converted are listed in Run history and can be restored.' );
		} finally {
			this.setProgress( 0, 0 );
			this.setBusy( false );
			this.applyButton.textContent = 'Convert';
			this.loadRuns();
		}
	};

	Panel.prototype.renderResults = function ( mode ) {
		var scan = this.scan;
		var labels = this.config.countLabels || {};
		var keys = Object.keys( labels );
		this.results.textContent = '';
		this.results.hidden = false;

		var attempted = {};
		( scan.attempted || [] ).forEach( function ( id ) {
			attempted[ id ] = true;
		} );
		var rows = scan.rows.filter( function ( r ) {
			if ( mode === 'apply' ) {
				return !! attempted[ r.id ];
			}
			return r.changed || r.error || ( r.messages && r.messages.length );
		} );

		if ( ! rows.length ) {
			this.results.appendChild( el( 'p', { text: scan.rows.length ? 'No post needs converting.' : 'Nothing found.' } ) );
			return;
		}

		var totals = {};
		var warnings = 0;
		rows.forEach( function ( r ) {
			keys.forEach( function ( k ) {
				totals[ k ] = ( totals[ k ] || 0 ) + ( ( r.counts && r.counts[ k ] ) || 0 );
			} );
			warnings += ( r.messages || [] ).filter( function ( m ) {
				return m.level !== 'notice';
			} ).length;
		} );

		this.results.appendChild(
			el( 'p', { className: 'mt-summary' }, [
				el( 'strong', { text: mode === 'apply' ? 'Result: ' : 'Dry run: ' } ),
				keys.map( function ( k ) {
					return labels[ k ] + ' ' + totals[ k ];
				} ).join( ' · ' ) + ' · Posts ' + rows.length + ( warnings ? ' · ' + warnings + ' to review' : '' ),
			] )
		);

		var head = el( 'tr', {}, [ el( 'th', { text: 'Post' } ), el( 'th', { text: 'Type' } ) ]
			.concat( keys.map( function ( k ) {
				return el( 'th', { className: 'num', text: labels[ k ] } );
			} ) )
			.concat( [ el( 'th', { text: mode === 'apply' ? 'Saved' : 'Will change' } ), el( 'th', { text: 'Notes' } ) ] ) );

		var body = rows.map( function ( r ) {
			var title = r.edit_url ? el( 'a', { href: r.edit_url, target: '_blank', rel: 'noopener', text: r.title } ) : el( 'span', { text: r.title } );
			var state;
			if ( mode === 'apply' ) {
				state = r.written ? 'Yes' : ( ! r.error && ! r.changed ? 'Not needed' : 'No' );
			} else {
				state = r.changed ? 'Yes' : 'No';
			}
			var notes = el( 'td', {}, [ r.error ? el( 'p', { className: 'mt-msg mt-msg-error', text: r.error } ) : null, messageList( r.messages ) ] );
			return el( 'tr', { className: r.error ? 'mt-row-error' : '' }, [
				el( 'td', {}, [ title, el( 'div', { className: 'mt-meta', text: '#' + r.id + ' · ' + r.status } ) ] ),
				el( 'td', { text: r.type } ),
			]
				.concat( keys.map( function ( k ) {
					return el( 'td', { className: 'num', text: String( ( r.counts && r.counts[ k ] ) || 0 ) } );
				} ) )
				.concat( [ el( 'td', { text: state } ), notes ] ) );
		} );

		this.results.appendChild( el( 'table', { className: 'widefat striped mt-table' }, [ el( 'thead', {}, [ head ] ), el( 'tbody', {}, body ) ] ) );
	};

	Panel.prototype.loadRuns = async function () {
		var self = this;
		try {
			var runs = await api( '/tools/' + this.config.tool + '/runs', undefined, 'GET' );
			this.runsBody.textContent = '';
			if ( ! runs.length ) {
				this.runsBody.appendChild( el( 'p', { text: 'No runs yet.' } ) );
				return;
			}
			var head = el( 'tr', {}, [ 'Started', 'Run', 'By', 'Changed', 'Failed', 'Backups', '' ].map( function ( t ) {
				return el( 'th', { text: t } );
			} ) );
			var body = runs.map( function ( run ) {
				var actions = el( 'td', { className: 'mt-run-actions' } );
				if ( run.backups > 0 ) {
					actions.appendChild( el( 'button', { type: 'button', className: 'button button-small', text: 'Restore', onclick: function () {
						self.restoreRun( run, false );
					} } ) );
					actions.appendChild( el( 'button', { type: 'button', className: 'button-link mt-link-danger', text: 'Delete backups', onclick: function () {
						self.discardRun( run );
					} } ) );
				} else if ( run.restored ) {
					actions.appendChild( el( 'span', { className: 'mt-meta', text: 'Restored ' + localTime( run.restored ) } ) );
				}
				return el( 'tr', {}, [
					el( 'td', { text: localTime( run.started ) } ),
					el( 'td', {}, [ el( 'span', { text: run.label } ), el( 'div', { className: 'mt-meta', text: run.id + ( run.finished ? '' : ' · unfinished' ) } ) ] ),
					el( 'td', { text: run.user || '' } ),
					el( 'td', { className: 'num', text: String( run.changed ) } ),
					el( 'td', { className: 'num', text: String( run.failed ) } ),
					el( 'td', { className: 'num', text: String( run.backups ) } ),
					actions,
				] );
			} );
			this.runsBody.appendChild( el( 'table', { className: 'widefat striped mt-table' }, [ el( 'thead', {}, [ head ] ), el( 'tbody', {}, body ) ] ) );
		} catch ( err ) {
			this.runsBody.textContent = 'Could not load run history: ' + errorText( err );
		}
	};

	Panel.prototype.restoreRun = async function ( run, force ) {
		// eslint-disable-next-line no-alert
		if ( ! force && ! window.confirm( 'Restore ' + run.backups + ( run.backups === 1 ? ' post' : ' posts' ) + ' to their content from before run ' + run.id + '? Posts edited since the run are skipped.' ) ) {
			return;
		}
		this.setBusy( true );
		var restored = 0;
		var skipped = [];
		var after = 0;
		try {
			for ( var guard = 0; guard < 10000; guard++ ) {
				this.setStatus( 'Restoring… ' + restored + ' done' );
				var res = await api( '/runs/' + run.id + '/restore', { after: after, force: !! force } );
				restored += res.restored.length;
				skipped = skipped.concat( res.skipped );
				after = res.next;
				if ( res.done ) {
					break;
				}
			}
			this.setStatus( 'Restored ' + restored + ( restored === 1 ? ' post' : ' posts' ) + ( skipped.length ? '; ' + skipped.length + ' skipped.' : '.' ) );
			this.notices.textContent = '';
			if ( skipped.length ) {
				var self = this;
				var list = el( 'ul', {}, skipped.map( function ( s ) {
					return el( 'li', { text: '#' + s.id + ': ' + s.reason } );
				} ) );
				var forceButton = el( 'button', { type: 'button', className: 'button button-small', text: 'Restore these anyway', onclick: function () {
					// eslint-disable-next-line no-alert
					if ( window.confirm( 'Overwrite the current content of ' + skipped.length + ' post(s) with their pre-run content? Changes made since the run will be lost (revisions still have them).' ) ) {
						self.restoreRun( run, true );
					}
				} } );
				this.notices.appendChild( el( 'div', { className: 'notice inline notice-warning' }, [ el( 'p', { text: 'Skipped during restore:' } ), list, el( 'p', {}, [ forceButton ] ) ] ) );
			}
		} catch ( err ) {
			this.setStatus( 'Restore stopped: ' + errorText( err ) );
		} finally {
			this.setBusy( false );
			this.loadRuns();
		}
	};

	Panel.prototype.discardRun = async function ( run ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete the backups for run ' + run.id + '? The run can no longer be restored from here (post revisions are unaffected).' ) ) {
			return;
		}
		try {
			var res = await api( '/runs/' + run.id + '/discard', {} );
			this.setStatus( 'Deleted ' + res.deleted + ' backup(s).' );
		} catch ( err ) {
			this.setStatus( 'Could not delete backups: ' + errorText( err ) );
		}
		this.loadRuns();
	};

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.method-tools .mt-tool' ), function ( root ) {
			new Panel( root ); // eslint-disable-line no-new
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
