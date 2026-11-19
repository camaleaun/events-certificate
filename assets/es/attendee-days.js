/**
 * Attendees list (CampTix): the Days badges mark or clear a day; CampTix's
 * "Mark as attended" marks the filtered day, or, with no filter and more than
 * one day, asks which days first. includes/compat/camptix-days.php.
 */
( function () {
	var config = window.evcertDays;
	if ( ! config ) {
		return;
	}

	function send( box, data ) {
		var body = new FormData();
		body.append( 'action', 'evcert_attendee_day' );
		body.append( 'nonce', box.getAttribute( 'data-nonce' ) );
		Object.keys( data ).forEach( function ( key ) {
			[].concat( data[ key ] ).forEach( function ( value ) {
				body.append( Array.isArray( data[ key ] ) ? key + '[]' : key, value );
			} );
		} );
		box.setAttribute( 'aria-busy', 'true' );
		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				box.removeAttribute( 'aria-busy' );
				if ( ! json || ! json.success ) {
					return null;
				}
				box.outerHTML = json.data.badges;
				// CampTix's column follows: present on any day.
				var row = document.getElementById( 'post-' + data.attendee );
				var cell = row && row.querySelector( '.column-attended' );
				if ( cell && json.data.attended ) {
					cell.textContent = cell.getAttribute( 'data-attended-label' ) || cell.textContent;
				}
				return json.data;
			} );
	}

	function boxOf( attendee ) {
		var row = document.getElementById( 'post-' + attendee );
		return row && row.querySelector( '.evcert-days' );
	}

	// A badge: mark or clear its day.
	document.addEventListener( 'click', function ( event ) {
		var badge = event.target.closest( '.evcert-day' );
		if ( ! badge ) {
			return;
		}
		event.preventDefault();
		var box = badge.closest( '.evcert-days' );
		send( box, {
			attendee: badge.getAttribute( 'data-attendee' ),
			day: badge.getAttribute( 'data-day' ),
			present: badge.getAttribute( 'aria-pressed' ) === 'true' ? '' : '1',
		} );
	} );

	// CampTix's "Mark as attended": the filtered day, or a picker of days.
	document.addEventListener(
		'click',
		function ( event ) {
			var button = event.target.closest( '.tix-mark-attended' );
			if ( ! button ) {
				return;
			}
			var attendee = button.getAttribute( 'data-attendee-id' );
			var box = boxOf( attendee );
			if ( ! box || ( ! config.filtered && config.days.length < 2 ) ) {
				return; // CampTix marks it; one day follows on the server.
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if ( config.filtered ) {
				send( box, { attendee: attendee, day: config.filtered, present: '1' } ).then( function ( data ) {
					if ( data ) {
						button.replaceWith( document.createTextNode( '✓' ) );
					}
				} );
				return;
			}
			if ( button.nextElementSibling && button.nextElementSibling.classList.contains( 'evcert-days-picker' ) ) {
				return;
			}
			var picker = document.createElement( 'fieldset' );
			picker.className = 'evcert-days-picker';
			var legend = document.createElement( 'legend' );
			legend.textContent = config.i18n.which;
			picker.appendChild( legend );
			config.days.forEach( function ( day ) {
				var label = document.createElement( 'label' );
				var input = document.createElement( 'input' );
				input.type = 'checkbox';
				input.value = day.id;
				input.checked = !! box.querySelector( '.evcert-day.is-present[data-day="' + day.id + '"]' );
				label.appendChild( input );
				label.appendChild( document.createTextNode( ' ' + day.label ) );
				picker.appendChild( label );
			} );
			var ok = document.createElement( 'button' );
			ok.type = 'button';
			ok.className = 'button button-primary button-small';
			ok.textContent = config.i18n.confirm;
			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button button-small';
			cancel.textContent = config.i18n.cancel;
			picker.appendChild( ok );
			picker.appendChild( cancel );
			button.after( picker );
			picker.querySelector( 'input' ).focus();
			cancel.addEventListener( 'click', function () {
				picker.remove();
			} );
			ok.addEventListener( 'click', function () {
				var days = [].map.call( picker.querySelectorAll( 'input:checked' ), function ( input ) {
					return input.value;
				} );
				send( boxOf( attendee ), { attendee: attendee, days: days.length ? days : [ '' ] } ).then( function ( data ) {
					picker.remove();
					if ( data && data.attended ) {
						button.replaceWith( document.createTextNode( '✓' ) );
					}
				} );
			} );
		},
		true
	);
} )();
