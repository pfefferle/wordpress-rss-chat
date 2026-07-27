/**
 * RSS Chat admin client.
 *
 * Reads go through the WordPress REST proxy (avoids cross-origin calls to
 * rss.chat). Writes go through the authenticated proxy. Live updates come from
 * the rss.chat firehose websocket. Post bodies are rendered as text to keep the
 * admin screen safe from untrusted HTML in this draft.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.rssChatConfig || {};
	var apiFetch = wp.apiFetch;
	var root = null;
	var feedEl = null;
	var items = []; // Newest first, keyed loosely by id.
	var replyTo = 0;

	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
	apiFetch.use( apiFetch.createRootURLMiddleware( cfg.restBase + '/' ) );

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	function upsert( item ) {
		if ( ! item || typeof item.id === 'undefined' ) {
			return;
		}
		var i;
		for ( i = 0; i < items.length; i++ ) {
			if ( items[ i ].id === item.id ) {
				items[ i ] = item;
				renderFeed();
				return;
			}
		}
		items.unshift( item );
		renderFeed();
	}

	function renderItem( item ) {
		var card = el( 'article', 'rss-chat-item' );

		var head = el( 'header', 'rss-chat-item__head' );
		if ( item.imageUrl ) {
			var avatar = el( 'img', 'rss-chat-item__avatar' );
			avatar.src = item.imageUrl;
			avatar.alt = '';
			head.appendChild( avatar );
		}
		head.appendChild( el( 'span', 'rss-chat-item__author', item.author || item.screenname || '' ) );
		if ( item.pubDate ) {
			head.appendChild( el( 'time', 'rss-chat-item__date', item.pubDate ) );
		}
		card.appendChild( head );

		if ( item.title ) {
			card.appendChild( el( 'h3', 'rss-chat-item__title', item.title ) );
		}

		// Render as text: strip any HTML the server may include.
		var bodyText = ( item.markdowntext || item.description || '' ).replace( /<[^>]*>/g, '' );
		card.appendChild( el( 'div', 'rss-chat-item__body', bodyText ) );

		var actions = el( 'div', 'rss-chat-item__actions' );

		var likeBtn = el( 'button', 'button-link rss-chat-action', '♥ ' + ( item.ctLikes || 0 ) );
		likeBtn.type = 'button';
		likeBtn.addEventListener( 'click', function () {
			apiFetch( { path: '/like', method: 'POST', data: { id: item.id } } ).catch( showError );
		} );
		actions.appendChild( likeBtn );

		var replyBtn = el( 'button', 'button-link rss-chat-action', '↩ ' + ( item.ctReplies || 0 ) );
		replyBtn.type = 'button';
		replyBtn.addEventListener( 'click', function () {
			replyTo = item.id;
			renderComposer();
			var box = document.getElementById( 'rss-chat-text' );
			if ( box ) {
				box.focus();
			}
		} );
		actions.appendChild( replyBtn );

		card.appendChild( actions );
		return card;
	}

	function renderFeed() {
		if ( ! feedEl ) {
			return;
		}
		feedEl.innerHTML = '';
		if ( ! items.length ) {
			feedEl.appendChild( el( 'p', 'rss-chat-empty', 'No posts yet.' ) );
			return;
		}
		items.forEach( function ( item ) {
			feedEl.appendChild( renderItem( item ) );
		} );
	}

	function renderComposer() {
		var existing = document.getElementById( 'rss-chat-composer' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}

		var form = el( 'form', 'rss-chat-composer' );
		form.id = 'rss-chat-composer';

		if ( replyTo > 0 ) {
			var hint = el( 'div', 'rss-chat-composer__hint', 'Replying to post #' + replyTo );
			var cancel = el( 'button', 'button-link', 'cancel' );
			cancel.type = 'button';
			cancel.addEventListener( 'click', function () {
				replyTo = 0;
				renderComposer();
			} );
			hint.appendChild( document.createTextNode( ' ' ) );
			hint.appendChild( cancel );
			form.appendChild( hint );
		}

		var textarea = el( 'textarea', 'rss-chat-composer__text' );
		textarea.id = 'rss-chat-text';
		textarea.rows = 3;
		textarea.placeholder = replyTo > 0 ? 'Write a reply…' : 'Post to the network…';
		form.appendChild( textarea );

		var submit = el( 'button', 'button button-primary', replyTo > 0 ? 'Reply' : 'Post' );
		submit.type = 'submit';
		form.appendChild( submit );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var text = textarea.value.trim();
			if ( ! text ) {
				return;
			}
			submit.disabled = true;
			apiFetch( {
				path: '/post',
				method: 'POST',
				data: { text: text, in_reply_to: replyTo },
			} )
				.then( function () {
					textarea.value = '';
					replyTo = 0;
					renderComposer();
					// The firehose echoes the new post; refresh as a fallback.
					loadRecent();
				} )
				.catch( showError )
				.finally( function () {
					submit.disabled = false;
				} );
		} );

		root.insertBefore( form, feedEl );
	}

	function loadRecent() {
		return apiFetch( { path: '/recent?ct=50' } )
			.then( function ( data ) {
				if ( Array.isArray( data ) ) {
					items = data;
					renderFeed();
				}
			} )
			.catch( showError );
	}

	function connectFirehose() {
		if ( ! cfg.firehoseUrl || typeof window.WebSocket === 'undefined' ) {
			startPolling();
			return;
		}
		var ws;
		try {
			ws = new window.WebSocket( cfg.firehoseUrl );
		} catch ( e ) {
			startPolling();
			return;
		}
		ws.addEventListener( 'message', function ( event ) {
			var sep = event.data.indexOf( '\r' );
			if ( sep === -1 ) {
				return;
			}
			var payload = event.data.slice( sep + 1 );
			try {
				var parsed = JSON.parse( payload );
				upsert( parsed.item || parsed );
			} catch ( e ) {
				/* ignore malformed frame */
			}
		} );
		ws.addEventListener( 'close', startPolling );
		ws.addEventListener( 'error', function () {
			ws.close();
		} );
	}

	var polling = null;
	function startPolling() {
		if ( polling ) {
			return;
		}
		polling = window.setInterval( loadRecent, 15000 );
	}

	function showError( error ) {
		var msg = ( error && ( error.message || error.raw ) ) || 'Something went wrong.';
		var banner = document.getElementById( 'rss-chat-error' );
		if ( ! banner ) {
			banner = el( 'div', 'notice notice-error rss-chat-error' );
			banner.id = 'rss-chat-error';
			root.insertBefore( banner, root.firstChild );
		}
		banner.textContent = msg;
	}

	function renderConnectPrompt() {
		root.innerHTML = '';
		var p = el( 'p', null, 'Connect your rss.chat account to start.' );
		var a = el( 'a', 'button button-primary', 'Open settings' );
		a.href = cfg.settingsUrl;
		p.appendChild( document.createTextNode( ' ' ) );
		p.appendChild( a );
		root.appendChild( p );
	}

	function boot() {
		root = document.getElementById( 'rss-chat-app' );
		if ( ! root ) {
			return;
		}

		if ( ! cfg.connected ) {
			renderConnectPrompt();
			return;
		}

		root.innerHTML = '';
		feedEl = el( 'div', 'rss-chat-feed' );
		root.appendChild( feedEl );
		renderComposer();

		loadRecent().then( connectFirehose );
	}

	wp.domReady( boot );
} )( window.wp );
