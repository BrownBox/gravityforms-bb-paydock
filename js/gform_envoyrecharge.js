function EnvoyToggleRecurring(e) {
	var checkboxId = e.attr('id');
	var select = jQuery('select[data-checkbox='+checkboxId+']');
    if (e.is(':checked'))
    	select.show();
    else
    	select.hide();
}

jQuery(document).ready(function() {
	jQuery('input[id^=ginput_envoyrecharge_recurring_]').each(function() {
		EnvoyToggleRecurring(jQuery(this));
	});
});