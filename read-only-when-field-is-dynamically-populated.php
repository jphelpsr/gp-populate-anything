<?php
add_action( 'wp_footer', function () {
	?>
	<style>
		.gw-locked input,
		.gw-locked textarea { background: #f2f2f2; color: #555; }
	</style>
	<script>
	( function() {
		// Form ID: [ field IDs to lock when populated ]
		// To add another form, add a line like:  98: [ 9 ],
		var config = {
			9: [ 47, 122, 154 ],
		};

		function check() {
			Object.keys( config ).forEach( function( formId ) {
				config[ formId ].forEach( function( id ) {
					var field = document.getElementById( 'field_' + formId + '_' + id );
					if ( ! field ) { return; }

					field.querySelectorAll( 'input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea' ).forEach( function( input ) {
						var value = input.value.trim();

						// Nothing changed since the last check.
						if ( input.getAttribute( 'data-gw-last' ) === value ) { return; }
						input.setAttribute( 'data-gw-last', value );

						// Empty field: always editable.
						if ( value === '' ) {
							input.readOnly = false;
							field.classList.remove( 'gw-locked' );
							return;
						}

						// The visitor is typing in it themselves: leave it editable.
						if ( document.activeElement === input ) { return; }

						// The value arrived from elsewhere (Populate Anything): lock it.
						input.readOnly = true;
						field.classList.add( 'gw-locked' );
					} );
				} );
			} );
		}

		setInterval( check, 300 );
	} )();
	</script>
	<?php
}, 100 );
