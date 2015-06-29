/**
 * Bind datepicker JS to the fields
 */
(function($) {
	$(function() {
		// Check to make sure the date picker input boxes exist
		if( 0 < $( '.datepicker' ).length ) {
			$( '.datepicker' ).datepicker({
				dateFormat: "yy-mm-dd"
			});
		}
	});
}(jQuery));
