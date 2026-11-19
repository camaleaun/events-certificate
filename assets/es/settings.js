/**
 * The settings: the rows that depend on a feature are dimmed and inert while the feature is off (still shown,
 * and still sent, so nothing saved is lost), and a signature chosen from a file is shown before saving.
 */
( function () {
	// A feature's switch and the rows that need it.
	[ [ 'evcert_verification', 'evcert-needs-verification' ], [ 'evcert_declaration', 'evcert-needs-declaration' ] ].forEach( function ( pair ) {
		var box = document.getElementById( pair[ 0 ] );
		if ( ! box ) {
			return;
		}
		function apply() {
			document.querySelectorAll( '.' + pair[ 1 ] ).forEach( function ( row ) {
				row.classList.toggle( 'evcert-off', ! box.checked );
				row.inert = ! box.checked;
			} );
		}
		box.addEventListener( 'change', apply );
		apply();
	} );

	// The languages as tags: add one from the installed ones, remove it with the x.
	document.querySelectorAll( '[data-evcert-tags]' ).forEach( function ( box ) {
		var select = box.querySelector( 'select' );
		var list = box.querySelector( '.evcert-tags__list' );
		box.querySelector( '[data-evcert-tag-add]' ).addEventListener( 'click', function () {
			var option = select.options[ select.selectedIndex ];
			if ( ! option || ! option.value ) {
				return;
			}
			var item = document.createElement( 'li' );
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = box.getAttribute( 'data-name' );
			input.value = option.value;
			var name = document.createElement( 'span' );
			name.textContent = option.textContent;
			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'evcert-tags__remove';
			remove.setAttribute( 'data-evcert-tag-remove', '' );
			remove.textContent = '\u00d7';
			item.append( input, name, ' ', remove );
			list.append( item );
			option.remove();
			select.selectedIndex = 0;
		} );
		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-evcert-tag-remove]' );
			if ( ! button ) {
				return;
			}
			var item = button.closest( 'li' );
			var option = document.createElement( 'option' );
			option.value = item.querySelector( 'input' ).value;
			option.textContent = item.querySelector( 'span' ).textContent;
			select.append( option );
			item.remove();
		} );
	} );

	// A signature file: its code goes into the pasted-code field (the server cleans it) and it is previewed.
	var file = document.getElementById( 'evcert_signature_file' );
	var code = document.getElementById( 'evcert_signature_svg' );
	var preview = document.querySelector( '.evcert-signature--new' );
	if ( file && code ) {
		file.addEventListener( 'change', function () {
			var chosen = file.files && file.files[ 0 ];
			if ( ! chosen ) {
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				code.value = String( reader.result );
				if ( preview ) {
					preview.querySelector( 'img' ).src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( code.value );
					preview.hidden = false;
				}
			};
			reader.readAsText( chosen );
		} );
	}
} )();
