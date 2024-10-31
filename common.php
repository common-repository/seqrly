<?php
/**
 * Common functions.
 */

// -- WP Hooks
// Add hooks to handle actions in WordPress
add_action( 'init', 'seqrly_textdomain' ); // load textdomain

// include internal stylesheet
add_action( 'wp', 'seqrly_style');

// parse request
add_action('parse_request', 'seqrly_parse_request');
add_action('query_vars', 'seqrly_query_vars');
add_action('generate_rewrite_rules', 'seqrly_rewrite_rules');

add_action( 'cleanup_seqrly', 'seqrly_cleanup' );


add_filter( 'seqrly_user_data', 'seqrly_get_user_data_sreg', 8, 2);
add_filter( 'seqrly_user_data', 'seqrly_get_user_data_ax', 10, 2);

//Include required JQuery files
/* The following by themselves do not work, you need to include a theme specific JS (foo.js, non-existing) to be able to load jquery and jquery-ui-core
wp_enqueue_script('jquery');
wp_enqueue_script('jquery-ui-core'); */

wp_enqueue_script('foo',get_bloginfo('template_directory') . '/js/foo.js',array('jquery','jquery-ui-core'));


if (isset($wpmu_version)) {
	// wpmu doesn't support non-autoload options
	add_option( 'seqrly_associations', array(), null, 'yes' );
	add_option( 'seqrly_nonces', array(), null, 'yes' );
} else {
	add_option( 'seqrly_associations', array(), null, 'no' );
	add_option( 'seqrly_nonces', array(), null, 'no' );
}



/**
 * Set the textdomain for this plugin so we can support localizations.
 */
function seqrly_textdomain() {
	load_plugin_textdomain('seqrly', null, 'seqrly/localization');
}


/**
 * Get the internal SQL Store.  If it is not already initialized, do so.
 *
 * @return WordPressSeqrly_Store internal SQL store
 */
function seqrly_getStore() {
	static $store;

	if (!$store) {
		$store = new WordPress_Seqrly_OptionStore();
	}

	return $store;
}


/**
 * Called on plugin activation and upgrading.
 *
 * @see register_activation_hook
 */
function seqrly_activate_plugin() {
	global $wp_rewrite;

	// if first time activation, set Seqrly capability for administrators
	if (get_option('seqrly_plugin_revision') === false) {
		global $wp_roles;
		$role = $wp_roles->get_role('administrator');
		if ($role) $role->add_cap('use_seqrly_provider');
	}

	// for some reason, show_on_front is not always set, causing is_front_page() to fail
	$show_on_front = get_option('show_on_front');
	if ( empty($show_on_front) ) {
		update_option('show_on_front', 'posts');
	}

	// Add custom Seqrly options
	add_option( 'seqrly_enable_openid_provider', false );
	add_option( 'seqrly_enable_other_openids', false );
	add_option( 'seqrly_enable_commentform', true );
	add_option( 'seqrly_plugin_enabled', true );
	add_option( 'seqrly_plugin_revision', 0 );
	add_option( 'seqrly_db_revision', 0 );
	add_option( 'seqrly_enable_approval', false );
	add_option( 'seqrly_xrds_returnto', true );
	add_option( 'seqrly_comment_displayname_length', 12 );

	seqrly_create_tables();
	seqrly_migrate_old_data();

	// setup schedule cleanup
	wp_clear_scheduled_hook('cleanup_seqrly');
	wp_schedule_event(time(), 'hourly', 'cleanup_seqrly');

	// flush rewrite rules
	if ( !isset($wp_rewrite) ) { $wp_rewrite = new WP_Rewrite(); }
	$wp_rewrite->flush_rules();

	// set current revision
	update_option( 'seqrly_plugin_revision', SEQRLY_PLUGIN_REVISION );

	seqrly_remove_historical_options();
}


/**
 * Remove options that were used by previous versions of the plugin.
 */
function seqrly_remove_historical_options() {

}


/**
 * Called on plugin deactivation.  Cleanup all transient data.
 *
 * @see register_deactivation_hook
 */
function seqrly_deactivate_plugin() {
	wp_clear_scheduled_hook('cleanup_seqrly');
	delete_option('seqrly_associations');
	delete_option('seqrly_nonces');
	delete_option('seqrly_server_associations');
	delete_option('seqrly_server_nonces');
}


/**
 * Delete options in database
 */
function seqrly_uninstall_plugin() {
	seqrly_delete_tables();
	wp_clear_scheduled_hook('cleanup_seqrly');

	// current options
	delete_option('seqrly_enable_openid_provider');
	delete_option('seqrly_enable_other_openids');
	delete_option('seqrly_enable_commentform');
	delete_option('seqrly_plugin_enabled');
	delete_option('seqrly_plugin_revision');
	delete_option('seqrly_db_revision');
	delete_option('seqrly_enable_approval');
	delete_option('seqrly_xrds_returnto');
	delete_option('seqrly_comment_displayname_length');
	delete_option('seqrly_associations');
	delete_option('seqrly_nonces');
	delete_option('seqrly_server_associations');
	delete_option('seqrly_server_nonces');
	delete_option('seqrly_blog_owner');
	delete_option('seqrly_no_require_name');
	delete_option('seqrly_required_for_registration');

	// historical options
	seqrly_remove_historical_options();
}


/**
 * Cleanup expired nonces and associations from the Seqrly store.
 */
function seqrly_cleanup() {
	$store =& seqrly_getStore();
	$store->cleanupNonces();
	$store->cleanupAssociations();
}


/*
 * Customer error handler for calls into the JanRain library
 */
function seqrly_customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
	if( (2048 & $errno) == 2048 ) return;

	if (!defined('WP_DEBUG') || !(WP_DEBUG)) {
		// XML errors
		if (strpos($errmsg, 'DOMDocument::loadXML') === 0) return;
		if (strpos($errmsg, 'domxml') === 0) return;

		// php-seqrly errors
		//if (strpos($errmsg, 'Successfully fetched') === 0) return;
		//if (strpos($errmsg, 'Got no response code when fetching') === 0) return;
		//if (strpos($errmsg, 'Fetching URL not allowed') === 0) return;

		// curl errors
		//if (strpos($errmsg, 'CURL error (6)') === 0) return; // couldn't resolve host
		//if (strpos($errmsg, 'CURL error (7)') === 0) return; // couldn't connect to host
	}

	seqrly_error( "Library Error $errno: $errmsg in $filename :$linenum");
}


/**
 * Generate a unique WordPress username for the given OpenID URL.
 *
 * @param string $url OpenID URL to generate username for
 * @param boolean $append should we try appending a number if the username is already taken
 * @return mixed generated username or null if unable to generate
 */
function seqrly_generate_new_username($url, $append = true) {
	$base = seqrly_normalize_username($url);
	$i='';
	while(true) {
		$username = seqrly_normalize_username( $base . $i );
		$user = get_userdatabylogin($username);
		if ( $user ) {
			if (!$append) return null;
			$i++;
			continue;
		}
		// TODO: add hook
		return $username;
	}
}


/**
 * Normalize the OpenID URL into a username.  This includes rules like:
 *  - remove protocol prefixes like 'http://' and 'xri://'
 *  - remove the 'xri.net' domain for i-names
 *  - substitute certain characters which are not allowed by WordPress
 *
 * @param string $username username to be normalized
 * @return string normalized username
 * @uses apply_filters() Calls 'seqrly_normalize_username' just before returning normalized username
 */
function seqrly_normalize_username($username) {
	$normalized = $username;

	$normalized = preg_replace('|^https?://(xri.net/([^@]!?)?)?|', '', $normalized);
	$normalized = preg_replace('|^xri://([^@]!?)?|', '', $normalized);
	$normalized = preg_replace('|/$|', '', $normalized);
	$normalized = sanitize_user( $normalized );
	$normalized = preg_replace('|[^a-z0-9 _.\-@]+|i', '-', $normalized);

	$normalized = apply_filters('seqrly_normalize_username', $normalized, $username);
        

	return $normalized;
}


/**
 * Get the OpenID trust root for the given return_to URL.
 *
 * @param string $return_to OpenID return_to URL
 * @return string OpenID trust root
 */
function seqrly_trust_root($return_to = null) {
	$trust_root = trailingslashit(get_option('home'));

	// If return_to is HTTPS, trust_root must be as well
	if (!empty($return_to) && preg_match('/^https/', $return_to)) {
		$trust_root = preg_replace('/^http\:/', 'https:', $trust_root);
	}

	$trust_root = apply_filters('seqrly_trust_root', $trust_root, $return_to);
	return $trust_root;
}


/**
 * Login user with specified identity URL.  This will find the WordPress user account connected to this
 * OpenID and set it as the current user.  Only call this function AFTER you've verified the identity URL.
 *
 * @param string $identity userID or OpenID to set as current user
 * @param boolean $remember should we set the "remember me" cookie
 * @return void
 */
function seqrly_set_current_user($identity, $remember = true) {
	if (is_numeric($identity)) {
		$user_id = $identity;
	} else {
		$user_id = get_user_by_seqrly($identity);
	}

	if (!$user_id) return;

	$user = set_current_user($user_id);
	wp_set_auth_cookie($user->ID, $remember);

	do_action('wp_login', $user->user_login);
}


/**
 * Create a new WordPress user with the specified identity URL and user data.
 *
 * @param string $identity_url OpenID to associate with the newly
 * created account
 * @param array $user_data array of user data
 */
function seqrly_create_new_user($identity_url, &$user_data) {
	global $wpdb;
        
        /*error_log("seqrly_create_new_user: identity_url: " . $identity_url . "\n", 3, "/var/chroot/home/content/75/8267375/html/php-errors.log");
        foreach (array_keys($user_data) as $key ) {
            error_log("seqrly_create_new_user: data[ $key ]: " . $user_data[$key] . "\n", 3, "/var/chroot/home/content/75/8267375/html/php-errors.log");
        }*/

	// Identity URL is new, so create a user
	@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
	@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4

	// otherwise, try to use preferred username
	if ( empty($username) && array_key_exists('nickname', $user_data) ) {
		$username = seqrly_generate_new_username($user_data['nickname'], false);
	}

	// finally, build username from OpenID URL
	if (empty($username)) {
		$username = seqrly_generate_new_username($identity_url);
	}

	$user_data['user_login'] = $username;
	$user_data['user_pass'] = substr( md5( uniqid( microtime() ) ), 0, 7);
	$user_id = wp_insert_user( $user_data );

        if ( is_wp_error($user_id) ) {
            /* print "--------WP_error message is  is : " . $user_id->get_error_message();
            $error_data = $user_id->error_data;
            foreach (array_keys($error_data) as $key ) {
                print "seqrly_create_new_user: error_data[ $key ]: " . $error_data[$key] . "<br/>";
            } */

            // failed to create user for some reason.
            seqrly_message(__('OpenID authentication successful, but failed to create WordPress user. This is probably a bug.', 'seqrly'));
            seqrly_status('error');
            seqrly_error(seqrly_message($user_id->get_error_message()));

        } else { //Created OK

            $user_data['ID'] = $user_id;
            // XXX this all looks redundant, see seqrly_set_current_user

            $user = new WP_User( $user_id );

            if( ! wp_login( $user->user_login, $user_data['user_pass'] ) ) {
                    seqrly_message(__('User was created fine, but wp_login() for the new user failed. This is probably a bug.', 'seqrly'));
                    seqrly_status('error');
                    seqrly_error(seqrly_message());
                    return;
            }

            // notify of user creation
            wp_new_user_notification( $user->user_login );

            wp_clearcookie();
            wp_setcookie( $user->user_login, md5($user->user_pass), true, '', '', true );

            // Bind the provided identity to the just-created user
            seqrly_add_user_identity($user_id, $identity_url);

            seqrly_status('redirect');

            if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

	}

}

/**
 * Create a new WordPress user with the specified identity URL and user data.
 *
 * @param string $identity_url OpenID to associate with the newly
 * created account
 * @param array $user_data array of user data
 */
function seqrly_update_user($identity_url, &$data) {
	global $wpdb;

        require_once( ABSPATH . WPINC . '/registration.php');
        $user_id = wp_update_user( $data);

	if( $user_id ) { // updated ok

	} else {
		// failed to update user for some reason.
		seqrly_message(__('OpenID authentication successful, but failed to update WordPress user. This is probably a bug.', 'seqrly'));
		seqrly_status('error');
		seqrly_error(seqrly_message());
	}

}


/**
 * Get user data for the given identity URL.  Data is returned as an associative array with the keys:
 *   ID, user_url, user_nicename, display_name
 *
 * Multiple soures of data may be available and are attempted in the following order:
 *   - OpenID Attribute Exchange      !! not yet implemented
 * 	 - OpenID Simple Registration
 * 	 - hCard discovery                !! not yet implemented
 * 	 - default to identity URL
 *
 * @param string $identity_url OpenID to get user data about
 * @return array user data
 * @uses apply_filters() Calls 'seqrly_user_data' to gather profile data associated with the identity URL
 */
function seqrly_get_user_data($identity_url) {
	$data = array(
			'ID' => null,
			'user_url' => $identity_url,
			'user_nicename' => $identity_url,
			'display_name' => $identity_url
	);

	// create proper website URL if OpenID is an i-name
	if (preg_match('/^[\=\@\+].+$/', $identity_url)) {
		$data['user_url'] = 'http://xri.net/' . $identity_url;
	}

	$data = apply_filters('seqrly_user_data', $data, $identity_url);

	// if display_name is still the same as the URL, clean that up a bit
	if ($data['display_name'] == $identity_url) {
		$parts = parse_url($identity_url);
		if ($parts !== false) {
			$host = preg_replace('/^www./', '', $parts['host']);

			$path = substr($parts['path'], 0, get_option('seqrly_comment_displayname_length'));
			if (strlen($path) < strlen($parts['path'])) $path .= '&hellip;';

			$data['display_name'] = $host . $path;
		}
	}

	return $data;
}


/**
 * Retrieve user data from OpenID Attribute Exchange.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function seqrly_get_user_data_ax($data, $identity_url) {
	set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
	require_once('Auth/OpenID/AX.php');
	restore_include_path();

	$response = seqrly_response();
	$ax = Auth_OpenID_AX_FetchResponse::fromSuccessResponse($response);

	if (!$ax) return $data;

	$email = $ax->getSingle('http://axschema.org/contact/email');
	if ($email && !is_a($email, 'Auth_OpenID_AX_Error')) {
		$data['user_email'] = $email;
	}

	$nickname = $ax->getSingle('http://axschema.org/namePerson/friendly');
	if ($nickname && !is_a($nickname, 'Auth_OpenID_AX_Error')) {
		$data['nickname'] = $ax->getSingle('http://axschema.org/namePerson/friendly');
		$data['user_nicename'] = $ax->getSingle('http://axschema.org/namePerson/friendly');
		$data['display_name'] = $ax->getSingle('http://axschema.org/namePerson/friendly');
	}

	$fullname = $ax->getSingle('http://axschema.org/namePerson');
	if ($fullname && !is_a($fullname, 'Auth_OpenID_AX_Error')) {
		$namechunks = explode( ' ', $fullname, 2 );
		if( isset($namechunks[0]) ) $data['first_name'] = $namechunks[0];
		if( isset($namechunks[1]) ) $data['last_name'] = $namechunks[1];
		$data['display_name'] = $fullname;
	}

	return $data;
}


/**
 * Retrieve user data from OpenID Simple Registration.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function seqrly_get_user_data_sreg($data, $identity_url) {
	require_once(dirname(__FILE__) . '/Auth/OpenID/SReg.php');
	$response = seqrly_response();
	$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
	$sreg = $sreg_resp->contents();

	if (!$sreg) return $data;

	if (array_key_exists('email', $sreg) && $sreg['email']) {
		$data['user_email'] = $sreg['email'];
	}

	if (array_key_exists('nickname', $sreg) && $sreg['nickname']) {
		$data['nickname'] = $sreg['nickname'];
		$data['user_nicename'] = $sreg['nickname'];
		$data['display_name'] = $sreg['nickname'];
	}

	if (array_key_exists('fullname', $sreg) && $sreg['fullname']) {
		$namechunks = explode( ' ', $sreg['fullname'], 2 );
		if( isset($namechunks[0]) ) $data['first_name'] = $namechunks[0];
		if( isset($namechunks[1]) ) $data['last_name'] = $namechunks[1];
		$data['display_name'] = $sreg['fullname'];
	}

	return $data;
}


/**
 * Retrieve user data from hCard discovery.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function seqrly_get_user_data_hcard($data, $identity_url) {
	// TODO implement hcard discovery
	return $data;
}


/**
 * Parse the WordPress request.  If the query var 'seqrly' is present, then
 * handle the request accordingly.
 *
 * @param WP $wp WP instance for the current request
 */
function seqrly_parse_request($wp) {
    if (array_key_exists('seqrly', $wp->query_vars)) {

        seqrly_clean_request();

        switch ($wp->query_vars['seqrly']) {
            case 'consumer':
                    @session_start();

                    $action = $_SESSION['seqrly_action'];

                    // no action, which probably means OP-initiated login.  Set
                    // action to 'login', and redirect to home page when finished
                    if (empty($action)) {
                            $action = 'login';
                            if (empty($_SESSION['seqrly_finish_url'])) {
                                    //$_SESSION['seqrly_finish_url'] = get_option('home');
                            }
                    }

                    finish_seqrly($action);
                    break;

            case 'server':
                    seqrly_server_request($_REQUEST['action']);
                    break;

            case 'ajax':
                    if ( check_admin_referer('seqrly_ajax') ) {
                            header('Content-Type: application/json');
                            //echo '{ valid:' . ( is_url_seqrly( $_REQUEST['url'] ) ? 'true' : 'false' ) . ', nonce:"' . wp_create_nonce('seqrly_ajax') . '" }';
                            echo '{ "valid":' . ( is_url_seqrly( $_REQUEST['url'] ) ? 'true' : 'false' ) . ', "nonce":"' . wp_create_nonce('seqrly_ajax') . '" }';
                            exit;
                    }
        }
    }
}


/**
 * Check if the provided URL is a valid OpenID.
 *
 * @param string $url URL to check
 * @return boolean true if the URL is a valid OpenID
 */
function is_url_seqrly( $url ) {
	$auth_request = seqrly_begin_consumer( $url );
	return ( $auth_request != null );
}


/**
 * Clean HTTP request parameters for OpenID.
 *
 * Apache's rewrite module is often used to produce "pretty URLs" in WordPress.  
 * Other webservers, such as lighttpd, nginx, and Microsoft IIS each have ways 
 * (read: hacks) for simulating this kind of functionality. This function 
 * reverses the side-effects of these hacks so that the OpenID request 
 * variables are in the form that the OpenID library expects.
 */
function seqrly_clean_request() {

	if (array_key_exists('q', $_GET)) {

		// handle nginx web server, which adds an additional query string parameter named "q"

		unset($_GET['q']);

		$vars = explode('&', $_SERVER['QUERY_STRING']);
		$clean = array();

		foreach ($vars as $v) {
			if (strpos($v, 'q=') !== 0) {
				$clean[] = $v;
			}
		}
		
		$_SERVER['QUERY_STRING'] = implode('&', $clean);

	} else if ($_SERVER['argc'] >= 1 && $_SERVER['argv'][0] == 'error=404') {

		// handle lighttpd hack which uses a custom error-handler, passing 404 errors to WordPress.  
		// This results in the QUERY_STRING not having the correct information, but fortunately we 
		// can pull it out of REQUEST_URI

		list($path, $query) = explode('?', $_SERVER['REQUEST_URI'], 2);
		$_SERVER['QUERY_STRING'] = $query;
	}
}


/**
 * Build an OpenID service URL.
 *
 * @param string $service service to build URL for
 * @param string $scheme URL scheme to use for URL (see site_url())
 * @return string service URL
 * @see site_url
 */
function seqrly_service_url($service, $scheme = null) {
	global $wp_rewrite;
	if (!$wp_rewrite) $wp_rewrite = new WP_Rewrite();

	if (!defined('SEQRLY_SSL') || !SEQRLY_SSL) $scheme = null;
	$url = site_url('/', $scheme);

	if ($wp_rewrite->using_permalinks()) {
		$url .= 'index.php/seqrly/' . $service;
	} else {
		$url .= '?seqrly=' . $service;
	}

	return $url;
}


/**
 * Add rewrite rules to WP_Rewrite for the OpenID services.
 */
function seqrly_rewrite_rules($wp_rewrite) {
	$seqrly_rules = array(
		'seqrly/(.+)' => 'index.php?seqrly=$matches[1]',
	);

	$wp_rewrite->rules = $seqrly_rules + $wp_rewrite->rules;
}


/**
 * Add valid query vars to WordPress for OpenID.
 */
function seqrly_query_vars($vars) {
	$vars[] = 'seqrly';
	return $vars;
}

function seqrly_status($new = null) {
	static $status;
	return ($new == null) ? $status : $status = $new;
}

function seqrly_message($new = null) {
	static $message;
	return ($new == null) ? $message : $message = $new;
}

function seqrly_response($new = null) {
	static $response;
	return ($new == null) ? $response : $response = $new;
}

function seqrly_enabled($new = null) {
	static $enabled;
	if ($enabled == null) $enabled = true;
	return ($new == null) ? $enabled : $enabled = $new;
}


/**
 * Send HTTP post through the user-agent.  If javascript is not supported, the
 * user will need to click on a "continue" button.
 *
 * @param string $action form action (URL to POST form to)
 * @param array $parameters key-value pairs of parameters to include in the form
 * @uses do_action() Calls 'seqrly_page_head' hook action
 */
function seqrly_repost($action, $parameters) {
	$html = '
	<noscript><p>' . __('Since your browser does not support JavaScript, you must press the Continue button once to proceed.', 'seqrly') . '</p></noscript>
	<form action="'.$action.'" method="post">';

	foreach ($parameters as $k => $v) {
		if ($k == 'submit') continue;
		$html .= "\n" . '<input type="hidden" name="'.$k.'" value="' . htmlspecialchars(stripslashes($v), ENT_COMPAT, get_option('blog_charset')) . '" />';
	}
	$html .= '
		<noscript><div><input type="submit" value="' . __('Continue') . '" /></div></noscript>
	</form>

	<script type="text/javascript">
		document.write("<h2>'.__('Please Wait...', 'seqrly').'</h2>");
		document.forms[0].submit()
	</script>';

	seqrly_page($html, __('OpenID Authentication Redirect', 'seqrly'));
}


function seqrly_page($message, $title = '') {
	global $wp_locale;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title><?php echo $title ?></title>
<?php
	wp_admin_css('install', true);
	if ( ($wp_locale) && ('rtl' == $wp_locale->text_direction) ) {
		wp_admin_css('login-rtl', true);
	}

	do_action('admin_head');
	do_action('seqrly_page_head');
?>
</head>
<body id="seqrly-page">
	<?php echo $message; ?>
</body>
</html>
<?php
	die();
}


/**
 * Enqueue required javascript libraries on appropriate pages.
 *
 * @action: init
 **/
function seqrly_js_setup() {
	if (have_comments() || comments_open() || is_admin()) {
		wp_enqueue_script('seqrly', plugins_url('seqrly/f/seqrly.js'), array('jquery'), SEQRLY_PLUGIN_REVISION);
	}
}


/**
 * Include OpenID stylesheet.  
 *
 * "Intelligently" decides whether to enqueue or print the CSS file, based on whether * the 'wp_print_styles' 
 * action has been run.  (This logic taken from the core wp_admin_css function)
 **/
function seqrly_style() {
	if ( !wp_style_is('seqrly', 'registered') ) {
		wp_register_style('seqrly', plugins_url('seqrly/f/seqrly.css'), array(), SEQRLY_PLUGIN_REVISION);
	}

	if ( did_action('wp_print_styles') ) {
		wp_print_styles('seqrly');
	} else {
		wp_enqueue_style('seqrly');
	}
}



// ---------------------- //
// OpenID User Management //
// ---------------------- //


/**
 * When a WordPress user is deleted, make sure all associated OpenIDs are deleted as well.
 */
function delete_user_openids($userid) {
	seqrly_drop_all_identities($userid);
}
add_action( 'delete_user', 'delete_user_openids' );


/**
 * Add the specified identity URL to the user.
 *
 * @param int $user_id user id
 * @param string $identity_url identity url to add
 */
function seqrly_add_user_identity($user_id, $identity_url) {
	seqrly_add_identity($user_id, $identity_url);
}


/**
 * Add identity url to user.
 *
 * @param int $user_id user id
 * @param string $url identity url to add
 */
function seqrly_add_identity($user_id, $url) {
	global $wpdb;
	$sql = $wpdb->prepare('INSERT INTO ' . seqrly_identity_table() . ' (user_id,url,hash) VALUES ( %s, %s, MD5(%s) )', $user_id, $url, $url);
	return $wpdb->query( $sql );
}


/**
 * Remove identity url from user.
 *
 * @param int $user_id user id
 * @param string $identity_url identity url to remove
 */
function seqrly_drop_identity($user_id, $identity_url) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare('DELETE FROM '.seqrly_identity_table().' WHERE user_id = %s AND url = %s', $user_id, $identity_url) );
}


/**
 * Remove all identity urls from user.
 *
 * @param int $user_id user id
 */
function seqrly_drop_all_identities($user_id) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare('DELETE FROM '.seqrly_identity_table().' WHERE user_id = %s', $user_id ) );
}



// -------------- //
// Other Function //
// -------------- //

/**
 * Format OpenID for display... namely, remove the fragment if present.
 * @param string $url url to display
 * @return url formatted for display
 */
function seqrly_display_identity($url) {
	return preg_replace('/#.+$/', '', $url);
}


function seqrly_error($msg) {
	error_log('[Seqrly] ' . $msg);
}


function seqrly_debug($msg) {
	if (defined('WP_DEBUG') && WP_DEBUG) {
		seqrly_error($msg);
	}
}

?>
