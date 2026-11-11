<?php
/**
 * GP Populate Anything: Readonly only when a value was populated by GPPA.
 *
 * Replace the previous snippet; do not run both snippets together.
 * Configure form IDs and field IDs at the bottom of this file.
 * Uses GPPA's Populate Value settings, not standalone Live Merge Tags.
 * Supports text inputs and textareas (not selects, radios, or checkboxes).
 * Drafts saved before installing this version have no origin metadata, so their
 * restored values remain editable until GPPA populates them again.
 * This is a UI convenience, not server-side protection against modified values.
 */
class GW_GPPA_Readonly_When_Populated {
	private $config;
	private $fresh = array();

	public function __construct( $config ) {
		$this->config = $config;
		add_filter( 'gppa_pre_populate_field', array( $this, 'track_population' ), 100, 6 );
		add_filter( 'gform_form_tag', array( $this, 'state_input' ), 10, 2 );
		add_filter( 'gform_incomplete_submission_pre_save', array( $this, 'save_state' ), 10, 3 );
		add_action( 'wp_footer', array( $this, 'script' ), 100 );
		// GF's built-in preview does not call wp_footer.
		add_action( 'gform_preview_footer', array( $this, 'script' ), 100 );
	}

	public function track_population( $result, $field, $form, $values, $entry, $force_value ) {
		if ( in_array( (int) $field->id, isset( $this->config[ $form['id'] ] ) ? $this->config[ $form['id'] ] : array(), true ) ) {
			// GPPA preserves submitted/resumed values when $force_value is true.
			$this->fresh[ $form['id'] ][ $field->id ] = $result === null && ! $force_value && ! empty( $field->{'gppa-values-enabled'} );
		}
		return $result;
	}

	private function input_name( $form_id ) {
		return 'gw_gppa_readonly_' . (int) $form_id;
	}

	private function decode_state( $json ) {
		$state = json_decode( $json, true );
		return is_array( $state ) ? array_filter( $state, 'is_string' ) : array();
	}

	public function state_input( $tag, $form ) {
		$form_id = (int) $form['id'];
		if ( ! isset( $this->config[ $form_id ] ) ) {
			return $tag;
		}

		$name  = $this->input_name( $form_id );
		$state = array();
		if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
			$state = $this->decode_state( wp_unslash( $_POST[ $name ] ) );
		} else {
			$token = sanitize_key( rgget( 'gf_token' ) );
			if ( $token ) {
				$draft = GFFormsModel::get_draft_submission_values( $token );
				if ( $draft && (int) $draft['form_id'] === $form_id ) {
					$submission = json_decode( $draft['submission'], true );
					$state      = $this->decode_state( wp_json_encode( isset( $submission['gw_gppa_readonly'] ) ? $submission['gw_gppa_readonly'] : array() ) );
				}
			}
		}

		$enabled = array();
		$fresh   = array();
		foreach ( $form['fields'] as $field ) {
			if ( in_array( (int) $field->id, $this->config[ $form_id ], true ) && ! empty( $field->{'gppa-values-enabled'} ) ) {
				$enabled[] = (int) $field->id;
				if ( ! empty( $this->fresh[ $form_id ][ $field->id ] ) ) {
					$fresh[] = (int) $field->id;
				}
			}
		}

		return $tag . sprintf(
			'<input type="hidden" class="gw-gppa-readonly-state" name="%s" value="%s" data-fields="%s" data-fresh="%s" />',
			esc_attr( $name ),
			esc_attr( wp_json_encode( (object) $state ) ),
			esc_attr( wp_json_encode( $enabled ) ),
			esc_attr( wp_json_encode( $fresh ) )
		);
	}

	public function save_state( $json, $token, $form ) {
		$name = $this->input_name( $form['id'] );
		if ( isset( $this->config[ $form['id'] ], $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
			$submission                     = json_decode( $json, true );
			$submission['gw_gppa_readonly'] = $this->decode_state( wp_unslash( $_POST[ $name ] ) );
			return wp_json_encode( $submission );
		}
		return $json;
	}

	public function script() {
		?>
		<style>
			.gw-locked input[readonly],
			.gw-locked textarea[readonly] { background: #f2f2f2; color: #555; }
		</style>
		<script>
		( function( $ ) {
			var selector = 'input:not([type=hidden]):not([type=radio]):not([type=checkbox]):not([type=submit]):not([type=button]), textarea';

			function sync( formId, populatedIds ) {
				var form = document.getElementById( 'gform_' + formId );
				var hidden = form && form.querySelector( '.gw-gppa-readonly-state' );
				if ( ! hidden ) { return; }

				var state = JSON.parse( hidden.value );
				var fields = JSON.parse( hidden.dataset.fields );
				var fresh = hidden.gwInitialized ? [] : JSON.parse( hidden.dataset.fresh );
				hidden.gwInitialized = true;
				fresh = fresh.concat( populatedIds || [] ).map( String );

				fields.forEach( function( fieldId ) {
					var field = document.getElementById( 'field_' + formId + '_' + fieldId );
					if ( ! field ) { return; } // Retain state for fields on other pages.
					var locked = false;
					field.querySelectorAll( selector ).forEach( function( input ) {
						var value = input.value.trim();
						if ( fresh.indexOf( String( fieldId ) ) !== -1 ) {
							if ( value !== '' ) {
								state[ input.name ] = value;
							} else {
								delete state[ input.name ];
							}
						}
						// Only a known GPPA value is readonly, never an arbitrary nonblank value.
						input.readOnly = value !== '' && Object.prototype.hasOwnProperty.call( state, input.name ) && state[ input.name ] === value;
						if ( ! input.readOnly ) { delete state[ input.name ]; }
						locked = locked || input.readOnly;
					} );
					field.classList.toggle( 'gw-locked', locked );
				} );
				hidden.value = JSON.stringify( state );
			}

			function init() {
				document.querySelectorAll( '.gw-gppa-readonly-state' ).forEach( function( hidden ) {
					sync( hidden.form.dataset.formid || hidden.form.id.replace( 'gform_', '' ) );
				} );
			}

			$( document ).on( 'gppa_updated_batch_fields', function( event, formId, fieldIds ) {
				sync( formId, fieldIds );
			} );
			$( document ).on( 'gform_post_render', init );
			document.addEventListener( 'gform/post_render', init );
			$( document ).on( 'input change', '.gform_wrapper input, .gform_wrapper textarea', function() {
				if ( this.form ) { sync( this.form.dataset.formid || this.form.id.replace( 'gform_', '' ) ); }
			} );
			$( init );
		} )( jQuery );
		</script>
		<?php
	}
}

new GW_GPPA_Readonly_When_Populated( array(
	9 => array( 47, 122, 154 ),
	// 98 => array( 9 ),
) );
