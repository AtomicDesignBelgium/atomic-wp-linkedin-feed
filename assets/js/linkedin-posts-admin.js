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
	var titleField = qs( '#atomic-linkedin-title' );
	var embedField = qs( '#atomic-linkedin-embed' );
	var publishedField = qs( '#atomic-linkedin-published' );
	var cancelBtn = qs( '#atomic-linkedin-cancel' );
	var saveBtn = qs( '#atomic-linkedin-save' );
	var addBtn = qs( '#atomic-linkedin-feed-add' );
	var previewBtn = qs( '#atomic-linkedin-preview' );
	var advanced = qs( '#atomic-linkedin-advanced' );
	var compatMode = qs( '#atomic-linkedin-compat-height-mode' );
	var compatHeight = qs( '#atomic-linkedin-compat-height' );

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
			return { strategy: 'official', id: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
		}
		m = raw.match( /linkedin\.com\/embed\/feed\/update\/urn:li:share:(\d+)/i );
		if ( m && m[ 1 ] ) {
			return { strategy: 'official', id: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
		}
		m = raw.match( /urn:li:activity:(\d+)/i );
		if ( m && m[ 1 ] ) {
			return { strategy: 'activity_fallback', id: m[ 1 ], urn: 'urn:li:activity:' + m[ 1 ] };
		}
		m = raw.match( /activity-(\d+)/i );
		if ( m && m[ 1 ] ) {
			return { strategy: 'activity_fallback', id: m[ 1 ], urn: 'urn:li:activity:' + m[ 1 ] };
		}
		m = raw.match( /^(\d+)$/ );
		if ( m && m[ 1 ] ) {
			return { strategy: 'official', id: m[ 1 ], urn: 'urn:li:share:' + m[ 1 ] };
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
		var methodLabel = i18n.method || 'Method';
		var officialLabel = i18n.methodOfficial || 'Official LinkedIn embed';
		var compatLabel = i18n.methodCompat || 'Compatibility embed';
		var compatMsg = i18n.compatMessage || "LinkedIn does not provide an official embed option for some post formats. Atomic will attempt to display this post using LinkedIn's public embed renderer. Preview the post before publishing.";
		if ( ! parsed ) {
			setHtml(
				result,
				'<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;">' +
					'<p style="margin:0 0 6px 0;"><strong>' + escapeHtml( shareIdLabel ) + '</strong><br>— ' + escapeHtml( i18n.notDetectedYet || 'Not detected yet' ) + '</p>' +
				'</div>'
			);
			return;
		}
		var isCompat = parsed.strategy === 'activity_fallback';
		var method = isCompat ? compatLabel : officialLabel;
		var prefix = isCompat ? '⚠' : '✓';
		var title = isCompat ? ( i18n.postDetectedCompat || 'LinkedIn post detected' ) : ( i18n.postDetected || 'LinkedIn post detected' );
		setHtml(
			result,
			'<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;">' +
				'<p style="margin:0 0 6px 0;' + ( isCompat ? 'color:#b45309' : 'color:#008a20' ) + '"><strong>' + prefix + ' ' + escapeHtml( title ) + '</strong></p>' +
				'<p style="margin:0 0 6px 0;"><strong>' + escapeHtml( methodLabel ) + '</strong><br>' + escapeHtml( method ) + '</p>' +
				( isCompat ? '<p style="margin:0 0 8px 0;"><em>' + escapeHtml( compatMsg ) + '</em></p>' : '' ) +
				'<details><summary>' + escapeHtml( i18n.techDetails || 'Technical details' ) + '</summary>' +
					'<p style="margin:8px 0 6px 0;"><strong>' + escapeHtml( shareIdLabel ) + '</strong><br><code>' + escapeHtml( parsed.id ) + '</code></p>' +
					'<p style="margin:0;"><strong>' + escapeHtml( urnLabel ) + '</strong><br><code>' + escapeHtml( parsed.urn ) + '</code></p>' +
				'</details>' +
			'</div>'
		);
	}

	function syncCompatHeightUi( parsed, existingOverride ) {
		var isCompat = parsed && parsed.strategy === 'activity_fallback';
		if ( compatMode ) { compatMode.disabled = ! isCompat; }
		if ( ! isCompat ) {
			if ( compatMode ) { compatMode.value = 'default'; }
			if ( compatHeight ) { compatHeight.value = ''; }
			if ( compatHeight ) { compatHeight.disabled = true; }
			if ( advanced ) { advanced.open = false; }
			return;
		}
		if ( typeof existingOverride === 'number' && existingOverride > 0 ) {
			if ( compatMode ) { compatMode.value = 'custom'; }
			if ( compatHeight ) { compatHeight.value = String( existingOverride ); }
		} else {
			if ( compatMode ) { compatMode.value = 'default'; }
			if ( compatHeight ) { compatHeight.value = ''; }
		}
		if ( compatHeight ) { compatHeight.disabled = ! ( compatMode && compatMode.value === 'custom' ); }
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
		if ( titleField ) { titleField.value = ''; }
		if ( embedField ) { embedField.value = ''; }
		if ( publishedField ) { publishedField.value = nowInWpTimezone(); }
		if ( saveBtn ) {
			saveBtn.textContent = ( mode === 'edit' ) ? ( i18n.saveChanges || 'Save changes' ) : ( i18n.addPost || 'Add post' );
		}
		renderDetection( null );
		syncCompatHeightUi( null, 0 );
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
		var editTitleBtn = e.target.closest( '.atomic-linkedin-edit-title' );
		if ( editTitleBtn ) {
			e.preventDefault();
			var cell = editTitleBtn.closest( 'td' );
			if ( ! cell ) { return; }
			var titleEl = qs( '.atomic-linkedin-title-text', cell );
			if ( ! titleEl ) { return; }

			// Prevent multiple editors in the same row.
			if ( qs( '.atomic-linkedin-title-inline-editor', cell ) ) { return; }

			var postId = editTitleBtn.getAttribute( 'data-post-id' );
			var current = editTitleBtn.getAttribute( 'data-current-title' ) || ( titleEl.textContent || '' );
			var errorEl = qs( '.atomic-linkedin-inline-title-error', cell );
			if ( errorEl ) { errorEl.style.display = 'none'; errorEl.textContent = ''; }

			var wrap = document.createElement( 'div' );
			wrap.className = 'atomic-linkedin-title-inline-editor';
			wrap.style.marginTop = '6px';

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'regular-text';
			input.value = current;
			input.setAttribute( 'aria-label', i18n.editorialTitle || 'Editorial title' );
			input.style.maxWidth = '100%';

			var save = document.createElement( 'button' );
			save.type = 'button';
			save.className = 'button button-small atomic-linkedin-title-save';
			save.textContent = i18n.saveInline || 'Save';
			save.style.marginLeft = '6px';

			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button button-small atomic-linkedin-title-cancel';
			cancel.textContent = i18n.cancelInline || 'Cancel';
			cancel.style.marginLeft = '6px';

			save.setAttribute( 'data-post-id', postId );
			cancel.setAttribute( 'data-post-id', postId );

			wrap.appendChild( input );
			wrap.appendChild( save );
			wrap.appendChild( cancel );

			titleEl.style.display = 'none';
			editTitleBtn.style.display = 'none';
			cell.insertBefore( wrap, qs( 'code', cell ) || null );
			input.focus();
			input.select();
			return;
		}

		var cancelInlineBtn = e.target.closest( '.atomic-linkedin-title-cancel' );
		if ( cancelInlineBtn ) {
			e.preventDefault();
			var cellCancel = cancelInlineBtn.closest( 'td' );
			if ( ! cellCancel ) { return; }
			var titleElCancel = qs( '.atomic-linkedin-title-text', cellCancel );
			var editBtnCancel = qs( '.atomic-linkedin-edit-title', cellCancel );
			var wrapCancel = qs( '.atomic-linkedin-title-inline-editor', cellCancel );
			if ( wrapCancel ) { wrapCancel.remove(); }
			if ( titleElCancel ) { titleElCancel.style.display = ''; }
			if ( editBtnCancel ) { editBtnCancel.style.display = ''; }
			return;
		}

		var saveInlineBtn = e.target.closest( '.atomic-linkedin-title-save' );
		if ( saveInlineBtn ) {
			e.preventDefault();
			var cellSave = saveInlineBtn.closest( 'td' );
			if ( ! cellSave ) { return; }
			var wrapSave = qs( '.atomic-linkedin-title-inline-editor', cellSave );
			var inputSave = wrapSave ? qs( 'input', wrapSave ) : null;
			var titleElSave = qs( '.atomic-linkedin-title-text', cellSave );
			var editBtnSave = qs( '.atomic-linkedin-edit-title', cellSave );
			var errorSave = qs( '.atomic-linkedin-inline-title-error', cellSave );
			var postIdSave = saveInlineBtn.getAttribute( 'data-post-id' );
			if ( ! inputSave || ! postIdSave ) { return; }

			if ( errorSave ) { errorSave.style.display = 'none'; errorSave.textContent = ''; }
			saveInlineBtn.disabled = true;
			var cancelBtnInline = qs( '.atomic-linkedin-title-cancel', wrapSave );
			if ( cancelBtnInline ) { cancelBtnInline.disabled = true; }

			ajax( 'atomic_linkedin_update_title_inline', { post_id: postIdSave, title: inputSave.value } ).then( function ( data ) {
				if ( titleElSave ) { titleElSave.textContent = data.title || ''; }
				if ( editBtnSave ) { editBtnSave.setAttribute( 'data-current-title', data.title || '' ); }
				if ( wrapSave ) { wrapSave.remove(); }
				if ( titleElSave ) { titleElSave.style.display = ''; }
				if ( editBtnSave ) { editBtnSave.style.display = ''; }
			} ).catch( function ( err ) {
				if ( errorSave ) {
					errorSave.textContent = err && err.message ? err.message : 'Error';
					errorSave.style.display = 'block';
				}
			} ).then( function () {
				saveInlineBtn.disabled = false;
				if ( cancelBtnInline ) { cancelBtnInline.disabled = false; }
			}, function () {
				saveInlineBtn.disabled = false;
				if ( cancelBtnInline ) { cancelBtnInline.disabled = false; }
			} );
			return;
		}

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
				if ( titleField ) { titleField.value = data.title || ''; }
				if ( embedField ) { embedField.value = data.urn || ''; }
				if ( publishedField ) { publishedField.value = data.published_local || ''; }
				var parsed = parseLinkedInInput( data.urn || '' );
				renderDetection( parsed );
				syncCompatHeightUi( parsed, parseInt( data.height_override || 0, 10 ) || 0 );
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
			var parsed = parseLinkedInInput( embedField.value );
			renderDetection( parsed );
			syncCompatHeightUi( parsed, 0 );
		} );
	}

	if ( compatMode ) {
		compatMode.addEventListener( 'change', function () {
			if ( ! compatHeight ) { return; }
			if ( compatMode.value === 'custom' ) {
				compatHeight.disabled = false;
			} else {
				compatHeight.value = '';
				compatHeight.disabled = true;
			}
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var postId = postIdField ? postIdField.value : '';
		var payload = {
			post_id: postId,
			editorial_title: titleField ? titleField.value : '',
			embed_input: embedField ? embedField.value : '',
			published_at: publishedField ? publishedField.value : '',
			compat_height_mode: compatMode ? compatMode.value : 'default',
			compat_height: compatHeight ? compatHeight.value : ''
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

	if ( previewBtn ) {
		previewBtn.addEventListener( 'click', function () {
			var parsed = parseLinkedInInput( embedField ? embedField.value : '' );
			if ( ! parsed ) {
				showError( 'Paste a LinkedIn embed or post link first.' );
				return;
			}
			var isCompat = parsed.strategy === 'activity_fallback';
			var title = isCompat ? ( i18n.compatPreviewTitle || 'Compatibility preview' ) : ( i18n.previewTitle || 'LinkedIn preview' );
			var src = isCompat
				? ( 'https://www.linkedin.com/embed/feed/update/' + parsed.urn )
				: ( 'https://www.linkedin.com/embed/feed/update/' + parsed.urn + '?collapsed=1' );
			var h = 650;
			if ( isCompat ) {
				if ( compatMode && compatMode.value === 'custom' && compatHeight && String( compatHeight.value || '' ).trim() ) {
					h = parseInt( compatHeight.value, 10 ) || 0;
				}
				if ( ! h ) { h = 720; }
			}
			var html = '<p style="margin-top:0;"><strong>' + escapeHtml( title ) + '</strong></p>' +
				'<iframe style="width:100%;border:0" src="' + escapeHtml( src ) + '" height="' + escapeHtml( String( h ) ) + '" title="' + escapeHtml( title ) + '" loading="lazy" allowfullscreen></iframe>' +
				'<p class="description" style="margin-bottom:0;">' + escapeHtml( 'Atomic cannot reliably inspect LinkedIn iframe contents. Preview visually before publishing.' ) + '</p>';
			setHtml( qs( '#atomic-linkedin-preview-container' ), html );
			if ( window.tb_show ) {
				window.tb_show( title, '#TB_inline?width=700&height=720&inlineId=atomic-linkedin-preview-modal' );
			}
		} );
	}

	// Initial state (page load).
	resetForm( 'add' );
} )();
