( function () {
	'use strict';

	if ( ! window.IndexLaneRila ) {
		return;
	}

	const config = window.IndexLaneRila;
	const form = document.getElementById( 'indexlane-rila-scan-form' );
	const verificationForm = document.getElementById( 'indexlane-rila-verification-form' );
	const panel = document.getElementById( 'indexlane-rila-session' );
	const results = document.getElementById( 'indexlane-rila-results' );
	const startButton = document.getElementById( 'indexlane-rila-start' );
	const verificationButton = document.getElementById( 'indexlane-rila-start-verification' );
	const pauseButton = document.getElementById( 'indexlane-rila-pause' );
	const resumeButton = document.getElementById( 'indexlane-rila-resume' );
	const extendButton = document.getElementById( 'indexlane-rila-extend' );
	const cancelButton = document.getElementById( 'indexlane-rila-cancel' );
	const newScanButton = document.getElementById( 'indexlane-rila-new-scan' );
	const stateLabel = document.getElementById( 'indexlane-rila-state' );
	const stateMessage = document.getElementById( 'indexlane-rila-message' );
	const progress = document.getElementById( 'indexlane-rila-progress' );
	const requestError = document.getElementById( 'indexlane-rila-request-error' );
	const metrics = {
		content_items_processed: document.getElementById( 'indexlane-rila-stat-content' ),
		links_extracted: document.getElementById( 'indexlane-rila-stat-links' ),
		unique_destinations_checked: document.getElementById( 'indexlane-rila-stat-destinations' ),
		http_requests: document.getElementById( 'indexlane-rila-stat-requests' ),
		actionable_issues: document.getElementById( 'indexlane-rila-stat-issues' ),
	};

	let session = config.initialSession || null;
	let requestInFlight = false;
	let queuedCommand = '';
	let timer = 0;
	let haltedByError = false;
	let clientMessage = '';

	function setHidden( element, hidden ) {
		if ( element ) {
			element.hidden = hidden;
		}
	}

	function render() {
		const hasSession = !! session;
		const active = hasSession && [ 'running', 'paused', 'limit_reached' ].includes( session.status );
		setHidden( panel, ! hasSession );
		setHidden( results, ! hasSession || session.status !== 'complete' );
		setHidden( form, hasSession );
		setHidden( requestError, hasSession || ! clientMessage );
		if ( requestError && ! hasSession && clientMessage ) {
			requestError.querySelector( 'p' ).textContent = clientMessage;
		}

		if ( form ) {
			form.classList.toggle( 'is-disabled', active );
			Array.from( form.elements ).forEach( function ( control ) {
				control.disabled = active || requestInFlight;
			} );
		}
		if ( verificationButton ) {
			verificationButton.disabled = active || requestInFlight;
		}

		if ( ! hasSession ) {
			return;
		}

		panel.setAttribute( 'aria-busy', requestInFlight ? 'true' : 'false' );
		stateLabel.textContent = haltedByError ? config.strings.interrupted : session.state_label;
		stateMessage.textContent = clientMessage || session.message;

		const total = Math.max( 0, Number( session.total_items ) || 0 );
		const processed = Math.min( total, Number( session.stats.content_items_processed ) || 0 );
		progress.max = Math.max( 1, total );
		progress.value = processed;

		Object.keys( metrics ).forEach( function ( key ) {
			metrics[ key ].textContent = String( session.stats[ key ] || 0 );
		} );

		setHidden( pauseButton, session.status !== 'running' || haltedByError );
		setHidden( resumeButton, session.status !== 'paused' && ! ( session.status === 'running' && haltedByError ) );
		setHidden( extendButton, session.status !== 'limit_reached' );
		setHidden( cancelButton, session.status === 'complete' );
		setHidden( newScanButton, session.status !== 'complete' );
	}

	function errorMessage( payload ) {
		if ( payload && payload.data && payload.data.message ) {
			return payload.data.message;
		}

		return config.strings.networkError;
	}

	async function request( action, body ) {
		const data = body || new FormData();
		data.set( 'action', action );
		data.set( 'nonce', config.nonce );

		const response = await window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) {
			throw new Error( errorMessage( payload ) );
		}

		return payload.data;
	}

	function scheduleBatch() {
		window.clearTimeout( timer );
		if ( session && session.status === 'running' && ! haltedByError && ! queuedCommand ) {
			timer = window.setTimeout( runBatch, 80 );
		}
	}

	async function runBatch() {
		if ( requestInFlight || haltedByError || ! session || session.status !== 'running' ) {
			return;
		}

		requestInFlight = true;
		clientMessage = '';
		render();
		try {
			const body = new FormData();
			body.set( 'session_id', session.id );
			const data = await request( 'indexlane_rila_run_batch', body );
			session = data.session;
		} catch ( error ) {
			haltedByError = true;
			clientMessage = error instanceof Error ? error.message : config.strings.networkError;
		}
		requestInFlight = false;
		render();

		if ( queuedCommand ) {
			const command = queuedCommand;
			queuedCommand = '';
			performControl( command );
			return;
		}

		if ( session && session.status === 'complete' ) {
			window.location.reload();
			return;
		}

		scheduleBatch();
	}

	async function performControl( command ) {
		if ( requestInFlight || ! session ) {
			return;
		}

		requestInFlight = true;
		haltedByError = false;
		clientMessage = '';
		render();
		try {
			const body = new FormData();
			body.set( 'session_id', session.id );
			body.set( 'command', command );
			const data = await request( 'indexlane_rila_control_scan', body );
			session = data.session;
			clientMessage = data.session ? ( data.message || '' ) : '';
		} catch ( error ) {
			haltedByError = true;
			clientMessage = error instanceof Error ? error.message : config.strings.networkError;
		}
		requestInFlight = false;
		render();
		scheduleBatch();
	}

	function queueControl( command ) {
		window.clearTimeout( timer );
		if ( requestInFlight ) {
			queuedCommand = command;
			clientMessage = command === 'cancel' ? config.strings.canceling : config.strings.pausing;
			render();
			return;
		}

		performControl( command );
	}

	async function startScan( scanForm, isVerification ) {
		if ( requestInFlight || ( session && [ 'running', 'paused', 'limit_reached' ].includes( session.status ) ) ) {
			return;
		}

		if ( isVerification && session && session.status === 'complete' && ! window.confirm( config.strings.confirmVerification ) ) {
			return;
		}

		const scanData = new FormData( scanForm );
		requestInFlight = true;
		clientMessage = '';
		render();
		try {
			const data = await request( 'indexlane_rila_start_scan', scanData );
			session = data.session;
			haltedByError = false;
			clientMessage = '';
		} catch ( error ) {
			clientMessage = error instanceof Error ? error.message : config.strings.networkError;
		}
		requestInFlight = false;
		render();
		scheduleBatch();
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			startScan( form, false );
		} );

		const limitInput = document.getElementById( 'indexlane-rila-max-posts' );
		if ( limitInput ) {
			limitInput.addEventListener( 'focus', function () {
				const limitChoice = form.querySelector( 'input[name="content_scope"][value="limit"]' );
				if ( limitChoice ) {
					limitChoice.checked = true;
				}
			} );
		}
	}

	if ( verificationForm ) {
		verificationForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			startScan( verificationForm, true );
		} );
	}

	pauseButton.addEventListener( 'click', function () {
		queueControl( 'pause' );
	} );
	resumeButton.addEventListener( 'click', function () {
		if ( haltedByError && session && session.status === 'running' ) {
			haltedByError = false;
			clientMessage = '';
			render();
			scheduleBatch();
			return;
		}
		queueControl( 'resume' );
	} );
	extendButton.addEventListener( 'click', function () {
		queueControl( 'extend' );
	} );
	cancelButton.addEventListener( 'click', function () {
		if ( window.confirm( config.strings.confirmCancel ) ) {
			queueControl( 'cancel' );
		}
	} );
	newScanButton.addEventListener( 'click', function () {
		window.clearTimeout( timer );
		session = null;
		haltedByError = false;
		clientMessage = '';
		render();
		form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

	render();
	scheduleBatch();
}() );
