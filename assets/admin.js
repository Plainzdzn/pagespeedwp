/**
 * WebP-Umwandler – Admin-Skript.
 *
 * Scan und Umwandlung per REST in Schritten mit Fortschrittsanzeige. Kein Build nötig.
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
							var error = new Error(
								( data && data.message ) || i18n.error
							);
							error.code = ( data && data.code ) || '';
							error.status = response.status;
							throw error;
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

	/**
	 * Startet eine Umwandlung von der Übersicht aus und wechselt zur Umwandlungsseite.
	 *
	 * @param {HTMLElement} button Auslöser mit data-akwu-convert (all oder test).
	 */
	function startConversion( button ) {
		var backup = document.querySelector( '[data-akwu-backup]' );
		var error = document.querySelector( '[data-akwu-convert-error]' );
		var buttons = document.querySelectorAll( '[data-akwu-convert]' );

		function fail( message ) {
			if ( error ) {
				error.textContent = message;
				error.hidden = false;
			} else {
				window.alert( message ); // eslint-disable-line no-alert
			}
		}

		if ( ! backup || ! backup.checked ) {
			fail( i18n.backup );
			if ( backup ) {
				backup.focus();
			}
			return;
		}

		buttons.forEach( function ( element ) {
			element.disabled = true;
		} );

		post( 'convert/start', {
			mode: button.getAttribute( 'data-akwu-convert' ),
			backup: true,
		} )
			.then( function () {
				window.location.href = config.convertUrl;
			} )
			.catch( function ( exception ) {
				fail( exception.message );
				buttons.forEach( function ( element ) {
					element.disabled = false;
				} );
			} );
	}

	/**
	 * Überträgt eine Antwort von convert/* in die Seite und die Admin-Leiste.
	 *
	 * @param {Element} root Element mit data-akwu-run.
	 * @param {Object}  data Antwort.
	 */
	function renderRun( root, data ) {
		var track = root.querySelector( '[role="progressbar"]' );
		var fill = root.querySelector( '.akwu-progress__fill' );
		var barItem = document.querySelector(
			'#wp-admin-bar-akwu-progress .akwu-bar-label'
		);

		root.setAttribute( 'data-akwu-run-status', data.status );
		track.setAttribute( 'aria-valuenow', String( data.percent ) );
		fill.style.width = data.percent + '%';

		[ 'eyebrow', 'headline', 'accent', 'batch_label' ].forEach(
			function ( key ) {
				var element = root.querySelector(
					'[data-akwu-run-field="' + key + '"]'
				);
				if ( element ) {
					element.textContent = data[ key ];
				}
			}
		);
		root.querySelector( '[data-akwu-run-field="percent"]' ).textContent =
			data.percent + ' %';

		Object.keys( data.tiles || {} ).forEach( function ( key ) {
			var element = root.querySelector(
				'[data-akwu-run-tile="' + key + '"]'
			);
			if ( element ) {
				element.textContent = data.tiles[ key ];
			}
		} );

		// HTML kommt fertig maskiert vom Server (Run_Presenter).
		[ 'steps_html', 'log_html' ].forEach( function ( key ) {
			var element = root.querySelector(
				'[data-akwu-run-html="' + key + '"]'
			);
			if ( element && typeof data[ key ] === 'string' ) {
				element.innerHTML = data[ key ];
			}
		} );

		if ( barItem ) {
			barItem.textContent = data.bar_label;
		}
	}

	/**
	 * Fordert Pakete an, solange der Lauf läuft. Lädt die Seite neu, wenn er endet oder pausiert.
	 *
	 * @param {Element} root Element mit data-akwu-run.
	 */
	function runLoop( root ) {
		var error = root.querySelector( '[data-akwu-run-error]' );
		var failures = 0;

		function showError( message ) {
			error.textContent = message;
			error.hidden = false;
		}

		function next() {
			post( 'convert/step' )
				.then( function ( data ) {
					failures = 0;
					error.hidden = true;
					renderRun( root, data );

					if (
						data.finished ||
						( data.status !== 'running' &&
							data.status !== 'cancelling' )
					) {
						window.location.reload();
						return;
					}
					next();
				} )
				.catch( function ( exception ) {
					// Ein anderes Fenster oder ein abgestürzter Schritt hält die Sperre: warten.
					// Netzwerk- und Serverfehler: mit wachsender Pause neu versuchen, der Lauf macht beim
					// nächsten Paket weiter.
					var locked = exception.code === 'akwu_locked';
					var retryable =
						locked ||
						! exception.status ||
						exception.status >= 500;

					if ( ! retryable ) {
						showError( exception.message );
						return;
					}

					failures++;
					showError(
						locked
							? exception.message
							: i18n.retry + ' (' + exception.message + ')'
					);
					window.setTimeout(
						next,
						Math.min( 30, locked ? 5 : 2 * failures ) * 1000
					);
				} );
		}

		next();
	}

	/**
	 * Pausieren, Fortsetzen oder Abbrechen aus der Kopfzeile.
	 *
	 * @param {HTMLElement} button Auslöser mit data-akwu-run-action.
	 */
	function runAction( button ) {
		var action = button.getAttribute( 'data-akwu-run-action' );

		if ( action === 'cancel' && ! window.confirm( i18n.cancel ) ) { // eslint-disable-line no-alert
			return;
		}

		document
			.querySelectorAll( '[data-akwu-run-action]' )
			.forEach( function ( element ) {
				element.disabled = true;
			} );

		post( 'convert/' + action )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( exception ) {
				window.alert( exception.message ); // eslint-disable-line no-alert
				window.location.reload();
			} );
	}

	/**
	 * Arbeitet einen Job (Rückgängig, Originale löschen) Schritt für Schritt ab und lädt danach
	 * die Seite mit dem Ergebnis neu.
	 *
	 * @param {string} type rollback oder purge.
	 */
	function jobLoop( type ) {
		var root = document.querySelector( '[data-akwu-progress="job"]' );
		var ui = progress( root );
		var failures = 0;

		ui.show();
		root.scrollIntoView( { behavior: 'smooth', block: 'center' } );

		function next() {
			post( 'job/step' )
				.then( function ( data ) {
					failures = 0;
					ui.set( data.percent, data.label );
					if ( data.finished ) {
						var url = new window.URL( window.location.href );
						url.searchParams.delete( 'paged' );
						url.searchParams.set( 'akwu_done', type );
						window.location.href = url.toString();
						return;
					}
					next();
				} )
				.catch( function ( exception ) {
					var locked = exception.code === 'akwu_locked';
					if (
						! locked &&
						exception.status &&
						exception.status < 500
					) {
						ui.fail( exception.message );
						return;
					}
					failures++;
					ui.fail(
						locked
							? exception.message
							: i18n.retry + ' (' + exception.message + ')'
					);
					window.setTimeout(
						next,
						Math.min( 30, locked ? 5 : 2 * failures ) * 1000
					);
				} );
		}

		next();
	}

	/**
	 * Startet einen Job.
	 *
	 * @param {string} type    rollback oder purge.
	 * @param {Object} payload ids oder confirm.
	 * @return {Promise} Erfüllt nach dem Start.
	 */
	function startJob( type, payload ) {
		var buttons = document.querySelectorAll(
			'[data-akwu-job], [data-akwu-purge-open], [data-akwu-purge-form] button'
		);

		buttons.forEach( function ( element ) {
			element.disabled = true;
		} );

		return post(
			'job/start',
			Object.assign( { type: type }, payload || {} )
		)
			.then( function () {
				jobLoop( type );
			} )
			.catch( function ( exception ) {
				buttons.forEach( function ( element ) {
					element.disabled = false;
				} );
				throw exception;
			} );
	}

	/**
	 * Rückgängig für alle oder ein Bild.
	 *
	 * @param {HTMLElement} button Auslöser mit data-akwu-job.
	 */
	function startRollback( button ) {
		var ids = button.getAttribute( 'data-akwu-ids' );
		var question = button.getAttribute( 'data-akwu-confirm' );

		if ( question && ! window.confirm( question ) ) { // eslint-disable-line no-alert
			return;
		}

		startJob( 'rollback', {
			ids: ids ? ids.split( ',' ).map( Number ) : [],
		} ).catch( function ( exception ) {
			window.alert( exception.message ); // eslint-disable-line no-alert
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var scan = event.target.closest( '[data-akwu-scan]' );
		var convert = event.target.closest( '[data-akwu-convert]' );
		var action = event.target.closest( '[data-akwu-run-action]' );
		var job = event.target.closest( '[data-akwu-job]' );
		var purgeOpen = event.target.closest( '[data-akwu-purge-open]' );
		var purgeClose = event.target.closest( '[data-akwu-purge-close]' );
		var form = document.querySelector( '[data-akwu-purge-form]' );

		if ( scan ) {
			event.preventDefault();
			runScan( scan );
		} else if ( convert ) {
			event.preventDefault();
			startConversion( convert );
		} else if ( action ) {
			event.preventDefault();
			runAction( action );
		} else if ( job ) {
			event.preventDefault();
			startRollback( job );
		} else if ( purgeOpen && form ) {
			event.preventDefault();
			form.hidden = false;
			purgeOpen.hidden = true;
			form.querySelector( '[data-akwu-purge-input]' ).focus();
		} else if ( purgeClose && form ) {
			event.preventDefault();
			form.hidden = true;
			document.querySelector( '[data-akwu-purge-open]' ).hidden = false;
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-akwu-purge-form]' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();

		var input = form.querySelector( '[data-akwu-purge-input]' );
		var error = form.querySelector( '[data-akwu-purge-error]' );
		var value = parseInt( input.value.replace( /\D/g, '' ), 10 );

		error.hidden = true;
		startJob( 'purge', { confirm: isNaN( value ) ? -1 : value } ).catch(
			function ( exception ) {
				error.textContent = exception.message;
				error.hidden = false;
				input.focus();
			}
		);
	} );

	/**
	 * Setzt einen offenen Lauf oder Job auf seiner Seite automatisch fort.
	 */
	function resumeOpenRun() {
		var root = document.querySelector( '[data-akwu-run]' );
		var status = root ? root.getAttribute( 'data-akwu-run-status' ) : '';
		var job = document.querySelector(
			'[data-akwu-progress="job"][data-akwu-job-status="running"]'
		);

		if ( status === 'running' || status === 'cancelling' ) {
			runLoop( root );
		} else if ( job ) {
			jobLoop( job.getAttribute( 'data-akwu-job-type' ) );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', resumeOpenRun );
	} else {
		resumeOpenRun();
	}
} )();
