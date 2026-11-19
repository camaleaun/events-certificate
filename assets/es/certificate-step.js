/**
 * The certificate step (CampTix): a day's choices show only when the day is
 * included; choosing a talk disables the talks at the same time (even in
 * part; back to back is fine); the document fields show with the second page;
 * The site's countries each ask for their own document (the CPF for Brazil, masked as 000.000.000-00); a foreigner gives a passport. The server checks all of
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

	// The document of the country chosen (or of the only one the site accepts), or a passport for a foreigner.
	// The country's options (or its hidden field) carry the document's label, mask and example.
	var country = form.querySelector( '[data-evcert-country]' );
	var foreigner = form.querySelector( '[data-evcert-foreigner]' );
	var doc = form.querySelector( '[data-evcert-doc]' );
	var label = form.querySelector( '[data-evcert-doc-label]' );
	function spec() {
		if ( foreigner && foreigner.checked ) {
			return { label: foreigner.getAttribute( 'data-label' ), mask: '', example: '' };
		}
		var source = country && country.options ? country.options[ country.selectedIndex ] : country;
		return source
			? { label: source.getAttribute( 'data-label' ), mask: source.getAttribute( 'data-mask' ) || '', example: source.getAttribute( 'data-example' ) || '' }
			: { label: '', mask: '', example: '' };
	}
	function documentType() {
		if ( ! label || ! doc ) {
			return;
		}
		var now = spec();
		label.textContent = now.label;
		doc.inputMode = now.mask ? 'numeric' : 'text';
		doc.maxLength = now.mask ? now.mask.length : 40;
		doc.placeholder = doc.getAttribute( 'data-masked' ) || now.example;
		applyMask();
	}
	// "0" in a mask is a digit, any other character stays; without a mask the number is left as typed.
	function applyMask() {
		var mask = spec().mask;
		if ( ! mask || ! doc ) {
			return;
		}
		var digits = doc.value.replace( /\D/g, '' );
		var out = '';
		var used = 0;
		for ( var i = 0; i < mask.length && used < digits.length; i++ ) {
			out += mask.charAt( i ) === '0' ? digits.charAt( used++ ) : mask.charAt( i );
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
		} else if ( event.target === country || event.target === foreigner ) {
			documentType();
		}
	} );
	if ( doc ) {
		doc.addEventListener( 'input', applyMask );
	}

	days();
	talks();
	if ( page2 ) {
		second();
	}
	documentType();
} )();
