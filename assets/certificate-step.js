/**
 * The certificate step (CampTix): a day's choices show only when the day is
 * included; choosing a talk disables the talks at the same time (even in
 * part; back to back is fine); the document fields show with the second page;
 * Brazil asks for a CPF, masked as 000.000.000-00. The server checks all of
 * it again (includes/compat/camptix-choices.php). Without this script every
 * field shows and the server answers.
 */
( function () {
	var form = document.querySelector( '[data-evcert-day-toggle]' );
	form = form && form.closest( 'form' );
	if ( ! form ) {
		return;
	}

	// Days: a day's table or talks only when the day is included.
	function days() {
		form.querySelectorAll( '[data-evcert-day-toggle]' ).forEach( function ( toggle ) {
			var body = toggle.closest( 'tr' ).querySelector( '[data-evcert-day-body]' );
			body.hidden = ! toggle.checked;
			body.querySelectorAll( 'select' ).forEach( function ( select ) {
				select.required = toggle.checked;
			} );
		} );
	}

	// Talks at the same time can't be chosen together.
	function talks() {
		var all = [].slice.call( form.querySelectorAll( 'input[data-start]' ) );
		all.forEach( function ( input ) {
			var start = +input.getAttribute( 'data-start' );
			var end = +input.getAttribute( 'data-end' );
			var clash = all.some( function ( other ) {
				return other !== input && other.checked && +other.getAttribute( 'data-start' ) < end && start < +other.getAttribute( 'data-end' );
			} );
			input.disabled = clash && ! input.checked;
			input.closest( 'label' ).style.opacity = input.disabled ? '0.5' : '';
		} );
	}

	// The second page's fields.
	var page2 = form.querySelector( '[data-evcert-page2]' );
	function second() {
		form.querySelectorAll( '[data-evcert-page2-body]' ).forEach( function ( row ) {
			row.hidden = ! page2.checked;
		} );
	}

	// The document: CPF for Brazil.
	var country = form.querySelector( '[data-evcert-country]' );
	var doc = form.querySelector( '[data-evcert-doc]' );
	var label = form.querySelector( '[data-evcert-doc-label]' );
	function isCpf() {
		return country && country.value === 'BR';
	}
	function documentType() {
		if ( ! label || ! doc ) {
			return;
		}
		label.textContent = label.getAttribute( isCpf() ? 'data-cpf' : 'data-other' );
		doc.inputMode = isCpf() ? 'numeric' : 'text';
		if ( isCpf() && ! doc.placeholder ) {
			doc.placeholder = '000.000.000-00';
		}
	}
	function cpfMask() {
		if ( ! isCpf() ) {
			return;
		}
		var digits = doc.value.replace( /\D/g, '' ).slice( 0, 11 );
		var out = digits.slice( 0, 3 );
		if ( digits.length > 3 ) {
			out += '.' + digits.slice( 3, 6 );
		}
		if ( digits.length > 6 ) {
			out += '.' + digits.slice( 6, 9 );
		}
		if ( digits.length > 9 ) {
			out += '-' + digits.slice( 9 );
		}
		doc.value = out;
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[data-evcert-day-toggle]' ) ) {
			days();
		} else if ( event.target.matches( 'input[data-start]' ) ) {
			talks();
		} else if ( event.target === page2 ) {
			second();
		} else if ( event.target === country ) {
			documentType();
			cpfMask();
		}
	} );
	if ( doc ) {
		doc.addEventListener( 'input', cpfMask );
	}

	days();
	talks();
	if ( page2 ) {
		second();
	}
	documentType();
} )();
