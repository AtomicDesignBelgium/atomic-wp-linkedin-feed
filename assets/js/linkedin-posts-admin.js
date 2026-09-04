( function () {
	'use strict';

	function qs( sel, root ) { return ( root || document ).querySelector( sel ); }
	function setHtml( el, html ) { if ( el ) { el.innerHTML = html; } }
	function escapeHtml( s ) { return String( s || '' ).replace( /[&<>"']/g, function ( c ) { return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' } )[ c ]; } ); }

	var cfg = window.atomicLinkedInFeedAdmin || {};
	var form = qs( '#atomic-linkedin-feed-form' );
	if ( ! form ) { return; }

	var result = qs( '#atomic-linkedin-result' );
	var postIdField = qs( '#atomic-linkedin-post-id' );
	var embedField = qs( '#atomic-linkedin-embed' );
	var publishedField = qs( '#atomic-linkedin-published' );
	var cancelBtn = qs( '#atomic-linkedin-cancel' );
	var saveBtn = qs( '#atomic-linkedin-save' );
	var addBtn = qs( '#atomic-linkedin-feed-add' );

	var i18n = cfg.i18n || {};

	function closeThickbox() {
		if ( window.tb_remove ) { window.tb_remove(); }
	}

	function decodeEntities( input ) {
		// Decode copy/pasted HTML entities (e.g. &quot; in iframe strings).
		var doc = document.implementation && document.implementation.createHTMLDocument ? document.implementation.createHTMLDocument( '' ) : null;
		if ( ! doc ) { return String( input || '' ); }
		var div = doc.createElement( 'div' );
		div.innerHTML = String( input || '' );
		return div.textContent || div.innerText || '';
	}

	function parseLinkedInInput( raw ) {
		raw = decodeEntities( raw );
		raw = String( raw || '' ).replace( /`/g, '' ).trim();
		if ( ! raw ) { return null; }

		var m;
		m = raw.match( /urn:li:share:(\d+)/i );
		if ( m && m[ 1 ] ) {
			return { shareId: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
		}
		m = raw.match( /linkedin\.com\/embed\/feed\/update\/urn:li:share:(\d+)/i );
		if ( m && m[ 1 ] ) {
			return { shareId: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
		}
		m = raw.match( /^(\d+)$/ );
		if ( m && m[ 1 ] ) {
			return { shareId: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
		}
		return null;
	}

	function nowInWpTimezone() {
		// Prefer Intl with explicit WP timezone; fallback to server/browser local time if unavailable.
		var tz = cfg.wpTimeZone || '';
		try {
			if ( tz && window.Intl && Intl.DateTimeFormat ) {
				var parts = new Intl.DateTimeFormat( 'sv-SE', {
					timeZone: tz,
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					hour12: false
				} ).formatToParts( new Date() );
				var map = {};
				parts.forEach( function ( p ) { map[ p.type ] = p.value; } );
				return map.year + '-' + map.month + '-' + map.day + 'T' + map.hour + ':' + map.minute;
			}
		} catch ( e ) {}
		var d = new Date();
		var pad = function ( n ) { return String( n ).padStart( 2, '0' ); };
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + 'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() );
	}

	function renderDetection( parsed ) {
		var shareIdLabel = i18n.shareId || 'Share ID';
		var urnLabel = i18n.normalizedUrn || 'Normalized URN';
		if ( ! parsed ) {
			setHtml(
				result,
				'<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;">' +
					'<p style="margin:0 0 6px 0;"><strong>' + escapeHtml( shareIdLabel ) + '</strong><br>— ' + escapeHtml( i18n.notDetectedYet || 'Not detected yet' ) + '</p>' +
				'</div>'
			);
			return;
		}
		setHtml(
			result,
			'<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;">' +
				'<p style="margin:0 0 6px 0;color:#008a20;"><strong>✓ ' + escapeHtml( i18n.postDetected || 'LinkedIn post detected' ) + '</strong></p>' +
				'<p style="margin:0 0 6px 0;"><strong>' + escapeHtml( shareIdLabel ) + '</strong><br><code>' + escapeHtml( parsed.shareId ) + '</code></p>' +
				'<p style="margin:0;"><strong>' + escapeHtml( urnLabel ) + '</strong><br><code>' + escapeHtml( parsed.urn ) + '</code></p>' +
			'</div>'
		);
	}

	function showError( message ) {
		setHtml( result, '<div class="notice notice-error inline"><p>' + escapeHtml( message || 'Error' ) + '</p></div>' );
	}

	function showSuccess( urn ) {
		var label = i18n.embedValidated || 'LinkedIn embed validated';
		setHtml( result, '<div class="notice notice-success inline"><p><strong>' + escapeHtml( label ) + '</strong><br><code>' + escapeHtml( urn || '' ) + '</code></p></div>' );
	}

	function resetForm( mode ) {
		if ( postIdField ) { postIdField.value = ''; }
		if ( embedField ) { embedField.value = ''; }
		if ( publishedField ) { publishedField.value = nowInWpTimezone(); }
		if ( saveBtn ) {
			saveBtn.textContent = ( mode === 'edit' ) ? ( i18n.saveChanges || 'Save changes' ) : ( i18n.addPost || 'Add post' );
		}
		renderDetection( null );
	}

	function ajax( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( data ).toString()
		} ).then( function ( r ) {
			return r.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var msg = payload && payload.data && payload.data.message ? payload.data.message : 'Request failed';
					throw new Error( msg );
				}
				return payload.data;
			} );
		} );
	}

	// Ensure Add resets even when Thickbox intercepts the click.
	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () { resetForm( 'add' ); }, true );
	}

	document.addEventListener( 'click', function ( e ) {
		var edit = e.target.closest( '.atomic-linkedin-edit' );
		if ( edit ) {
			e.preventDefault();
			resetForm( 'edit' );
			var id = edit.getAttribute( 'data-post-id' );
			if ( postIdField ) { postIdField.value = id; }
			if ( window.tb_show ) {
				window.tb_show( i18n.editTitle || 'Edit LinkedIn Post', '#TB_inline?width=600&height=420&inlineId=atomic-linkedin-feed-modal' );
			}
			ajax( 'atomic_linkedin_get_post', { post_id: id } ).then( function ( data ) {
				if ( embedField ) { embedField.value = data.urn || ''; }
				if ( publishedField ) { publishedField.value = data.published_local || ''; }
				renderDetection( parseLinkedInInput( data.urn || '' ) );
			} ).catch( function ( err ) {
				showError( err && err.message ? err.message : 'Error' );
			} );
			return;
		}

		var preview = e.target.closest( '.atomic-linkedin-preview' );
		if ( preview ) {
			e.preventDefault();
			var pid = preview.getAttribute( 'data-post-id' );
			ajax( 'atomic_linkedin_get_post', { post_id: pid } ).then( function ( data ) {
				var title = i18n.previewTitle || 'LinkedIn preview';
				var html = '';
				if ( data.preview_src ) {
					html = '<iframe style="width:100%;border:0" src="' + data.preview_src + '" height="' + ( data.preview_height || 650 ) + '" title="' + title + '" loading="lazy" allowfullscreen></iframe>';
				}
				setHtml( qs( '#atomic-linkedin-preview-container' ), html );
				if ( window.tb_show ) {
					window.tb_show( title, '#TB_inline?width=700&height=720&inlineId=atomic-linkedin-preview-modal' );
				}
			} ).catch( function ( err ) {
				alert( err && err.message ? err.message : 'Error' );
			} );
		}
	} );

	// Live detection while typing/pasting.
	if ( embedField ) {
		embedField.addEventListener( 'input', function () {
			renderDetection( parseLinkedInInput( embedField.value ) );
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var postId = postIdField ? postIdField.value : '';
		var payload = {
			post_id: postId,
			embed_input: embedField ? embedField.value : '',
			published_at: publishedField ? publishedField.value : ''
		};
		var action = postId ? 'atomic_linkedin_update_post' : 'atomic_linkedin_create_post';

		if ( saveBtn ) { saveBtn.disabled = true; }
		ajax( action, payload ).then( function ( data ) {
			showSuccess( data.urn );
			closeThickbox();
			resetForm( 'add' );
			window.setTimeout( function () { window.location.reload(); }, 200 );
		} ).catch( function ( err ) {
			showError( err && err.message ? err.message : 'Error' );
		} ).then( function () {
			if ( saveBtn ) { saveBtn.disabled = false; }
		}, function () {
			if ( saveBtn ) { saveBtn.disabled = false; }
		} );
	} );

	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', function () {
			resetForm( 'add' );
			closeThickbox();
		} );
	}

	// Initial state (page load).
	resetForm( 'add' );
} )();
