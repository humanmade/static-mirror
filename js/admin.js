/**
 * Bind datepicker JS to the fields
 */
(function($) {
    $(function() {
		// Check to make sure the input box exists
		if( 0 < $( '.datepicker' ).length ) {

            var pickerOpts = { dateFormat:"yy-mm-dd" };

            $( '.datepicker' ).datepicker( pickerOpts );
		}
	});
}(jQuery));
