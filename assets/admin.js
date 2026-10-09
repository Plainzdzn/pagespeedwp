/**
 * WebP-Umwandler – Admin-Skript.
 *
 * Scan per REST in Schritten mit Fortschrittsanzeige. Kein Build nötig.
 */
( function () {
	'use strict';

	var config = window.akwuAdmin || {};
	var i18n = config.i18n || {};

	/**
	 * POST an einen Endpunkt unter akwu/v1.
	 *
	 * @param {string} path Pfad, z. B. „scan“.
	 * @param {Object} body Daten.
	 * @return {Promise<Object>} Antwort.
	 */
	function post( path, body ) {
		return window
			.fetch( config.restUrl + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( body || {} ),
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( ( data && data.message ) || i18n.error );
						}
						return data;
					} );
			} );
	}

	/**
	 * Steuert eine Fortschrittsanzeige.
	 *
	 * @param {Element} root Element mit data-akwu-progress.
	 * @return {Object} Funktionen show, set, fail.
	 */
	function progress( root ) {
		var track = root.querySelector( '[role="progressbar"]' );
		var fill = root.querySelector( '.akwu-progress__fill' );
		var label = root.querySelector( '[data-akwu-progress-label]' );
		var percent = root.querySelector( '[data-akwu-progress-percent]' );
		var error = root.querySelector( '[data-akwu-progress-error]' );

		return {
			show: function () {
				root.hidden = false;
				error.hidden = true;
			},
			set: function ( value, text ) {
				var rounded = Math.max( 0, Math.min( 100, Math.round( value ) ) );
				track.setAttribute( 'aria-valuenow', String( rounded ) );
				fill.style.width = rounded + '%';
				percent.textContent = rounded + ' %';
				if ( text ) {
					label.textContent = text;
				}
			},
			fail: function ( message ) {
				error.textContent = message;
				error.hidden = false;
			},
		};
	}

	/**
	 * Scan starten oder fortsetzen und bis zum Ende Schritte anfordern.
	 *
	 * @param {HTMLElement} button Auslöser mit data-akwu-restart.
	 */
	function runScan( button ) {
		var root = document.querySelector( '[data-akwu-progress="scan"]' );
		var buttons = document.querySelectorAll( '[data-akwu-scan]' );
		var ui = root ? progress( root ) : null;
		var restart = button.getAttribute( 'data-akwu-restart' ) === '1';

		buttons.forEach( function ( element ) {
			element.disabled = true;
		} );

		if ( ui ) {
			ui.show();
			ui.set( 0, i18n.scanStart );
			root.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		function next() {
			return post( 'scan', { restart: restart } ).then( function ( data ) {
				restart = false;
				if ( ui ) {
					ui.set( data.percent, data.label );
				}
				if ( data.finished ) {
					if ( ui ) {
						ui.set( 100, i18n.scanDone );
					}
					window.location.reload();
					return null;
				}
				return next();
			} );
		}

		next().catch( function ( error ) {
			if ( ui ) {
				ui.fail( error.message );
			} else {
				window.alert( error.message ); // eslint-disable-line no-alert
			}
			buttons.forEach( function ( element ) {
				element.disabled = false;
			} );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-akwu-scan]' );
		if ( button ) {
			event.preventDefault();
			runScan( button );
		}
	} );
} )();
