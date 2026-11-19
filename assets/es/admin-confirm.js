/**
 * A link with data-evcert-confirm asks before it goes (Revoke on the Certificates list).
 */
document.addEventListener( 'click', function ( event ) {
	var link = event.target.closest( '[data-evcert-confirm]' );
	if ( link && ! window.confirm( link.getAttribute( 'data-evcert-confirm' ) ) ) {
		event.preventDefault();
	}
} );
