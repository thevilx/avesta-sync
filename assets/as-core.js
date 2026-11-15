/* Avesta Sync admin UI: core helpers (DOM, AJAX, formatting, modal). No innerHTML with data. */
( function () {
	'use strict';

	var cfg = window.AvestaSync;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;
	var UI = {
		cfg: cfg,
		t: t,
		modules: {},
		leaveGuards: {}
	};
	window.AvestaSyncUI = UI;

	var locale = cfg.locale || undefined;
	var modalStack = [];
	var modalSeq = 0;

	/* ---------- DOM ---------- */
	UI.$ = function ( id ) {
		return document.getElementById( id );
	};

	UI.el = function ( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = text;
		}
		return n;
	};

	UI.clear = function ( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
		return node;
	};

	UI.icon = function ( name ) {
		var i = UI.el( 'span', 'dashicons dashicons-' + name );
		i.setAttribute( 'aria-hidden', 'true' );
		return i;
	};

	/* Button: opts {label, cls, icon, onClick, type} */
	UI.btn = function ( label, cls, onClick, icon ) {
		var b = UI.el( 'button', 'as-btn ' + ( cls || '' ) );
		b.type = 'button';
		if ( icon ) {
			b.appendChild( UI.icon( icon ) );
		}
		b.appendChild( UI.el( 'span', 'as-btn__label', label ) );
		if ( onClick ) {
			b.addEventListener( 'click', onClick );
		}
		return b;
	};

	UI.root = function () {
		return document.querySelector( '.avesta-sync' );
	};

	/* Replace %s / %d / %1$s placeholders. */
	UI.fmt = function ( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(?:(\d+)\$)?[sd]/g, function ( m, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : v;
		} );
	};

	/*
	 * Plural-aware template lookup. A string with a count is passed as {one, other}
	 * (built with _n() in PHP). Languages with more than two plural forms only get
	 * the "one" form for 1 and the "other" form for everything else: accepted limitation.
	 */
	UI.tpl = function ( key, count ) {
		var v = t[ key ];
		if ( v && typeof v === 'object' ) {
			return count === 1 ? v.one : v.other;
		}
		return v;
	};

	/* Plain-text message (for aria-labels, announcements). */
	UI.msgText = function ( key, count, args ) {
		return UI.fmt.apply( null, [ UI.tpl( key, count ) ].concat( args || [] ) );
	};

	/* DOM message: every placeholder value is isolated in <bdi> so mixed digits/Latin never garble in RTL. */
	UI.msg = function ( key, count, args ) {
		var tpl = UI.tpl( key, count );
		var out = document.createElement( 'span' );
		var i = 0;
		var last = 0;
		var re = /%(?:(\d+)\$)?[sd]/g;
		var m;
		args = args || [];
		while ( ( m = re.exec( tpl ) ) !== null ) {
			if ( m.index > last ) {
				out.appendChild( document.createTextNode( tpl.slice( last, m.index ) ) );
			}
			var v = m[ 1 ] ? args[ parseInt( m[ 1 ], 10 ) - 1 ] : args[ i++ ];
			var b = document.createElement( 'bdi' );
			b.textContent = v === undefined ? '' : v;
			out.appendChild( b );
			last = m.index + m[ 0 ].length;
		}
		if ( last < tpl.length ) {
			out.appendChild( document.createTextNode( tpl.slice( last ) ) );
		}
		return out;
	};

	/* Replace a node's content with a DOM message. */
	UI.setMsg = function ( node, key, count, args ) {
		UI.clear( node ).appendChild( UI.msg( key, count, args ) );
		return node;
	};

	UI.modalOpen = function () {
		return modalStack.length > 0;
	};

	UI.num = function ( n ) {
		try {
			return new Intl.NumberFormat( locale ).format( n );
		} catch ( e ) {
			return String( n );
		}
	};

	UI.announce = function ( msg ) {
		var a = UI.$( 'as-announce' );
		if ( a ) {
			a.textContent = '';
			window.setTimeout( function () {
				a.textContent = msg;
			}, 30 );
		}
	};

	/* ---------- AJAX ---------- */
	UI.post = function ( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( txt ) {
				var j;
				try {
					j = JSON.parse( txt );
				} catch ( e ) {
					throw new Error( UI.fmt( t.errUnexpected, txt.replace( /\s+/g, ' ' ).slice( 0, 160 ) ) );
				}
				if ( ! j.success ) {
					throw new Error( ( j.data && j.data.message ) || t.errGeneric );
				}
				return j.data;
			} );
	};

	/* ---------- formatting ---------- */
	UI.fmtSize = function ( n ) {
		if ( n === null || n === undefined ) {
			return '';
		}
		var units = t.units;
		var i = 0;
		var v = Number( n );
		while ( v >= 1024 && i < units.length - 1 ) {
			v = v / 1024;
			i++;
		}
		return UI.num( i === 0 ? v : Math.round( v * 10 ) / 10 ) + ' ' + units[ i ];
	};

	UI.fmtDate = function ( ts ) {
		var d = new Date( ts * 1000 );
		try {
			return new Intl.DateTimeFormat( locale, { dateStyle: 'medium', timeStyle: 'short' } ).format( d );
		} catch ( e ) {
			return d.toLocaleString();
		}
	};

	UI.fmtRel = function ( ts ) {
		var diff = ts - Math.floor( Date.now() / 1000 );
		var abs = Math.abs( diff );
		var unit = 'second';
		var val = diff;
		if ( abs >= 86400 ) {
			unit = 'day';
			val = Math.round( diff / 86400 );
		} else if ( abs >= 3600 ) {
			unit = 'hour';
			val = Math.round( diff / 3600 );
		} else if ( abs >= 60 ) {
			unit = 'minute';
			val = Math.round( diff / 60 );
		}
		try {
			return new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } ).format( val, unit );
		} catch ( e ) {
			return '';
		}
	};

	/* Element: "Nov 15, 2026, 10:04 AM" + muted "5 minutes ago". */
	UI.timeNode = function ( ts ) {
		var wrap = UI.el( 'span', 'as-time' );
		var tm = UI.el( 'time', '', UI.fmtDate( ts ) );
		tm.setAttribute( 'datetime', new Date( ts * 1000 ).toISOString() );
		wrap.appendChild( tm );
		var rel = UI.fmtRel( ts );
		if ( rel ) {
			wrap.appendChild( UI.el( 'span', 'as-muted', ' · ' + rel ) );
		}
		return wrap;
	};

	UI.host = function ( url ) {
		try {
			return new URL( url ).host;
		} catch ( e ) {
			return url || '';
		}
	};

	UI.copy = function ( text, done ) {
		var fallback = function () {
			var ta = UI.el( 'textarea', 'screen-reader-text' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
			} catch ( e ) {
				/* ignore */
			}
			document.body.removeChild( ta );
			done();
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	};

	/* Inline error box with optional retry. */
	UI.errorBox = function ( msg, retry ) {
		var box = UI.el( 'div', 'as-alert as-alert--error' );
		box.setAttribute( 'role', 'alert' );
		box.appendChild( UI.icon( 'warning' ) );
		var txt = UI.el( 'div', 'as-alert__text' );
		txt.appendChild( UI.el( 'strong', '', t.errTitle ) );
		txt.appendChild( UI.el( 'span', 'as-alert__msg', msg ) );
		box.appendChild( txt );
		if ( retry ) {
			box.appendChild( UI.btn( t.retry, 'as-btn--secondary', retry, 'update' ) );
		}
		return box;
	};

	UI.empty = function ( icon, title, text, action ) {
		var box = UI.el( 'div', 'as-empty' );
		box.appendChild( UI.icon( icon ) );
		box.appendChild( UI.el( 'p', 'as-empty__title', title ) );
		if ( text ) {
			box.appendChild( UI.el( 'p', 'as-empty__text as-muted', text ) );
		}
		if ( action ) {
			box.appendChild( action );
		}
		return box;
	};

	UI.loading = function ( text ) {
		var box = UI.el( 'div', 'as-loading' );
		box.setAttribute( 'role', 'status' );
		box.appendChild( UI.el( 'span', 'as-spinner' ) );
		box.appendChild( UI.el( 'span', '', text || t.loading ) );
		return box;
	};

	/* ---------- modal ---------- */
	var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

	function focusables( root ) {
		return Array.prototype.filter.call( root.querySelectorAll( FOCUSABLE ), function ( n ) {
			return n.offsetWidth > 0 || n.offsetHeight > 0 || n === document.activeElement;
		} );
	}

	/* opts: title, body (Node), footer (Node[]), wide, onClose */
	UI.modal = function ( opts ) {
		/* One modal at a time, app-wide: a second request while one is open is ignored. */
		if ( modalStack.length ) {
			return null;
		}
		var opener = document.activeElement;
		var id = 'as-modal-title-' + ( ++modalSeq );
		var overlay = UI.el( 'div', 'as-modal-overlay' );
		var dlg = UI.el( 'div', 'as-modal' + ( opts.wide ? ' as-modal--wide' : '' ) );
		dlg.setAttribute( 'role', 'dialog' );
		dlg.setAttribute( 'aria-modal', 'true' );
		dlg.setAttribute( 'aria-labelledby', id );
		dlg.tabIndex = -1;

		var head = UI.el( 'div', 'as-modal__head' );
		var h = UI.el( 'h2', 'as-modal__title', opts.title );
		h.id = id;
		head.appendChild( h );
		var x = UI.el( 'button', 'as-modal__x' );
		x.type = 'button';
		x.setAttribute( 'aria-label', t.close );
		x.appendChild( UI.icon( 'no-alt' ) );
		head.appendChild( x );

		var body = UI.el( 'div', 'as-modal__body' );
		if ( opts.body ) {
			body.appendChild( opts.body );
		}
		var foot = UI.el( 'div', 'as-modal__foot' );
		( opts.footer || [] ).forEach( function ( n ) {
			foot.appendChild( n );
		} );

		dlg.appendChild( head );
		dlg.appendChild( body );
		dlg.appendChild( foot );
		overlay.appendChild( dlg );

		var closed = false;
		var api = { overlay: overlay, dlg: dlg, body: body, foot: foot };

		api.close = function () {
			if ( closed ) {
				return;
			}
			closed = true;
			overlay.parentNode && overlay.parentNode.removeChild( overlay );
			modalStack.splice( modalStack.indexOf( api ), 1 );
			var below = modalStack[ modalStack.length - 1 ];
			if ( below ) {
				below.overlay.removeAttribute( 'inert' );
			} else {
				document.documentElement.classList.remove( 'as-modal-open' );
			}
			if ( opener && document.body.contains( opener ) && opener.focus ) {
				opener.focus();
			}
			if ( opts.onClose ) {
				opts.onClose();
			}
		};

		x.addEventListener( 'click', api.close );
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				api.close();
			}
		} );
		overlay.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' || e.key === 'Esc' ) {
				e.stopPropagation();
				e.preventDefault();
				api.close();
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			var f = focusables( dlg );
			if ( ! f.length ) {
				e.preventDefault();
				dlg.focus();
				return;
			}
			var first = f[ 0 ];
			var last = f[ f.length - 1 ];
			if ( e.shiftKey && ( document.activeElement === first || document.activeElement === dlg ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );

		var below = modalStack[ modalStack.length - 1 ];
		if ( below ) {
			below.overlay.setAttribute( 'inert', '' );
		}
		modalStack.push( api );
		document.documentElement.classList.add( 'as-modal-open' );
		UI.root().appendChild( overlay );
		/* Prefer the safest (last, usually Cancel-adjacent) control: focus the dialog itself, then first footer button. */
		var initial = opts.initialFocus || dlg;
		initial.focus();
		return api;
	};

	/* opts: title, message | body, confirmLabel, danger, cancelLabel, onConfirm */
	UI.confirm = function ( opts ) {
		var body = opts.body || UI.el( 'p', 'as-modal__text', opts.message );
		var m;
		var cancel = UI.btn( opts.cancelLabel || t.cancel, 'as-btn--secondary', function () {
			m.close();
		} );
		var ok = UI.btn( opts.confirmLabel || t.confirm, opts.danger ? 'as-btn--danger' : 'as-btn--primary', function () {
			m.close();
			opts.onConfirm();
		} );
		m = UI.modal( { title: opts.title, body: body, footer: [ cancel, ok ], initialFocus: cancel } );
		return m;
	};
}() );
