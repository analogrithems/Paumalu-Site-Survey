/**
 * Media-library picker for the Settings page's logo field.
 *
 * Vanilla, not part of the React bundle — this is one field on a plain wp-admin screen, and
 * wp.media (bundled with WordPress core) is already on the page via wp_enqueue_media(), which is
 * the standard, dependency-free way to open the real media library from an admin screen.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var pickBtn = document.getElementById( 'pe-logo-pick' );
		var removeBtn = document.getElementById( 'pe-logo-remove' );
		var input = document.getElementById( 'pe-logo-id' );
		var preview = document.getElementById( 'pe-logo-preview' );

		if ( ! pickBtn || ! input || ! preview || ! window.wp || ! wp.media ) {
			return;
		}

		var frame = null;

		pickBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			// Built once and reopened after that — a fresh frame each click would forget whatever
			// the user was last browsing in the library.
			if ( ! frame ) {
				frame = wp.media( {
					title: pickBtn.getAttribute( 'data-title' ),
					button: { text: pickBtn.getAttribute( 'data-button' ) },
					library: { type: 'image' },
					multiple: false,
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var previewUrl = ( attachment.sizes && attachment.sizes.medium )
						? attachment.sizes.medium.url
						: attachment.url;

					input.value = attachment.id;
					preview.src = previewUrl;
					preview.hidden = false;

					if ( removeBtn ) {
						removeBtn.hidden = false;
					}
				} );
			}

			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				input.value = '';
				preview.hidden = true;
				removeBtn.hidden = true;
			} );
		}
	} );
} )();
