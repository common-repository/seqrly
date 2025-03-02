/* yuicompress openid.js -o openid.min.js
 * @see http://developer.yahoo.com/yui/compressor/
 */

jQuery(function() {
	jQuery('#seqrly_system_status').hide();

	jQuery('#seqrly_status_link').click( function() {
		jQuery('#seqrly_system_status').toggle();
		return false;
	});
});

function stylize_profilelink() {
	jQuery("#commentform a[href$='profile.php']").addClass('openid_link');
}

function stylize_profilelink_seqrly() {
	jQuery("#commentform a[href$='profile.php']").addClass('seqrly_comment_icon');
}

/**
 * Properly integrate the 'Authenticate with OpenID' checkbox into the comment form.
 * This will move the checkbox below the Website field, and add an AJAX hook to 
 * show/hide the checkbox depending on whether the given URL is a valid OpenID.
 */
function add_seqrly_to_comment_form(wp_url, nonce) {
	var openid_nonce = nonce;

	var openid_comment = jQuery('#seqrly_comment');
	var openid_checkbox = jQuery('#login_with_seqrly');
	var url = jQuery('#url');

	jQuery('label[for="url"],#url').filter(':last').after(openid_comment.show());

	if ( url.val() ) check_openid( url );
	url.blur( function() { check_openid(jQuery(this)); } );

}


	/**
	 * Make AJAX call to WordPress to check if the given URL is a valid OpenID.
	 * AJAX response should be a JSON structure with two values:
	 *   'valid' - (boolean) whether or not the given URL is a valid OpenID
	 *   'nonce' - (string) new nonce to use for next AJAX call
	 */
	function check_openid( url ) {
		url.addClass('openid_loading');

		if ( url.val() == '' ) {
			openid_checkbox.attr('checked', '');
			openid_comment.slideUp();
			return;
		} 

		jQuery.getJSON(wp_url + '?seqrly=ajax', {url: url.val(), _wpnonce: openid_nonce}, function(data, textStatus) {
			url.removeClass('openid_loading');
			if ( data.valid ) {
				openid_checkbox.attr('checked', 'checked');
				openid_comment.slideDown();
			} else {
				openid_checkbox.attr('checked', '');
				openid_comment.slideUp();
			}
			openid_nonce = data.nonce;
		});
	}

