<?php
/**
 * Functions related to the OpenID Consumer.
 */


// hooks for getting user data
add_filter('seqrly_auth_request_extensions', 'seqrly_add_sreg_extension', 10, 2);
add_filter('seqrly_auth_request_extensions', 'seqrly_add_ax_extension', 10, 2);

add_filter( 'xrds_simple', 'seqrly_consumer_xrds_simple');

/**
 * Get the internal OpenID Consumer object.  If it is not already initialized, do so.
 *
 * @return Auth_OpenID_Consumer OpenID consumer object
 */
function seqrly_getConsumer() {
	static $consumer;

	if (!$consumer) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID/Consumer.php';
		restore_include_path();

		$store = seqrly_getStore();
		$consumer = new Auth_OpenID_Consumer($store);
		if( null === $consumer ) {
			seqrly_error('OpenID consumer could not be created properly.');
			seqrly_enabled(false);
		}

	}

	return $consumer;
}


/**
 * Send the user to their OpenID provider to authenticate.
 *
 * @param Auth_OpenID_AuthRequest $auth_request OpenID authentication request object
 * @param string $trust_root OpenID trust root
 * @param string $return_to URL where the OpenID provider should return the user
 */
function seqrly_redirect($auth_request, $trust_root, $return_to) {
	do_action('seqrly_redirect', $auth_request, $trust_root, $return_to);

	$message = $auth_request->getMessage($trust_root, $return_to, false);

	if (Auth_OpenID::isFailure($message)) {
		return seqrly_error('Could not redirect to server: '.$message->message);
	}

	$_SESSION['seqrly_return_to'] = $message->getArg(Auth_OpenID_SEQRLY_NS, 'return_to');

	// send 302 redirect or POST
	if ($auth_request->shouldSendRedirect()) {
		$redirect_url = $auth_request->redirectURL($trust_root, $return_to);
		wp_redirect( $redirect_url );
	} else {
		seqrly_repost($auth_request->endpoint->server_url, $message->toPostArgs());
	}
}


/**
 * Finish OpenID Authentication.
 *
 * @return String authenticated identity URL, or null if authentication failed.
 */
function finish_seqrly_auth() {
	@session_start();

	$consumer = seqrly_getConsumer();
	if ( array_key_exists('seqrly_return_to', $_SESSION) ) {
		$seqrly_return_to = $_SESSION['seqrly_return_to'];
	}
	if ( empty($seqrly_return_to) ) {
		$seqrly_return_to = seqrly_service_url('consumer');
	}

	$response = $consumer->complete($seqrly_return_to);

	unset($_SESSION['seqrly_return_to']);
	seqrly_response($response);

	switch( $response->status ) {
		case Auth_OpenID_CANCEL:
			seqrly_message(__('OpenID login was cancelled.', 'seqrly'));
			seqrly_status('error');
			break;

		case Auth_OpenID_FAILURE:
			seqrly_message(sprintf(__('OpenID login failed: %s', 'seqrly'), $response->message));
			seqrly_status('error');
			break;

		case Auth_OpenID_SUCCESS:
			seqrly_message(__('OpenID login successful', 'seqrly'));
			seqrly_status('success');

			$identity_url = $response->identity_url;
			$escaped_url = htmlspecialchars($identity_url, ENT_QUOTES);
			return $escaped_url;

		default:
			seqrly_message(__('Unknown Status. Bind not successful. This is probably a bug.', 'seqrly'));
			seqrly_status('error');
	}

	return null;
}


/**
 * Begin login by activating the OpenID consumer.
 *
 * @param string $url claimed ID
 * @return Auth_OpenID_Request OpenID Request
 */
function seqrly_begin_consumer($url) {
	static $request;

	@session_start();
	if ($request == NULL) {
		set_error_handler( 'seqrly_customer_error_handler');

		$consumer = seqrly_getConsumer();
                
		$request = $consumer->begin($url);
                
                // Create a request for registration data
                $sreg = Auth_OpenID_SRegRequest::build(array('email', 'fullname'), array('nickname'));
                if (!$sreg) {
                    //TODO
                }
                $request->addExtension($sreg);


		restore_error_handler();
	}

	return $request;
}


/**
 * Start the OpenID authentication process.
 *
 * @param string $claimed_url claimed OpenID URL
 * @param string $action OpenID action being performed
 * @param string $finish_url stored in user session for later redirect
 * @uses apply_filters() Calls 'seqrly_auth_request_extensions' to gather extensions to be attached to auth request
 */
function seqrly_start_login( $claimed_url, $action, $finish_url = null) {
	if ( empty($claimed_url) ) return; // do nothing.

	$auth_request = seqrly_begin_consumer( $claimed_url );

	if ( null === $auth_request ) {
		seqrly_status('error');
		seqrly_message(sprintf(
			__('Could not discover an OpenID identity server endpoint at the url: %s', 'seqrly'),
			htmlentities($claimed_url)
		));

		return;
	}

	@session_start();
	$_SESSION['seqrly_action'] = $action;
	$_SESSION['seqrly_finish_url'] = $finish_url;

	$extensions = apply_filters('seqrly_auth_request_extensions', array(), $auth_request);
	foreach ($extensions as $e) {
		if (is_a($e, 'Auth_OpenID_Extension')) {
			$auth_request->addExtension($e);
		}
	}

	$return_to = seqrly_service_url('consumer', 'login_post');
	$return_to = apply_filters('seqrly_return_to', $return_to);

	$trust_root = seqrly_trust_root($return_to);

	seqrly_redirect($auth_request, $trust_root, $return_to);
	exit(0);
}


/**
 * Build an Attribute Exchange attribute query extension if we've never seen this OpenID before.
 */
function seqrly_add_ax_extension($extensions, $auth_request) {
	if(!get_user_by_seqrly($auth_request->endpoint->claimed_id)) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once('Auth/OpenID/AX.php');
		restore_include_path();

		if ($auth_request->endpoint->usesExtension(Auth_OpenID_AX_NS_URI)) {
			$ax_request = new Auth_OpenID_AX_FetchRequest();
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson/friendly', 1, true));
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/contact/email', 1, true));
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson', 1, true));

			$extensions[] = $ax_request;
		}
	}

	return $extensions;
}


/**
 * Build an SReg attribute query extension if we've never seen this OpenID before.
 */
function seqrly_add_sreg_extension($extensions, $auth_request) {
	if(!get_user_by_seqrly($auth_request->endpoint->claimed_id)) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once('Auth/OpenID/SReg.php');
		restore_include_path();

		if ($auth_request->endpoint->usesExtension(Auth_OpenID_SREG_NS_URI_1_0) || $auth_request->endpoint->usesExtension(Auth_OpenID_SREG_NS_URI_1_1)) {
			$extensions[] = Auth_OpenID_SRegRequest::build(array(),array('nickname','email','fullname'));
		}
	}

	return $extensions;
}


/**
 * Finish OpenID authentication.
 *
 * @param string $action login action that is being performed
 * @uses do_action() Calls 'seqrly_finish_auth' hook action after processing the authentication response.
 */
function finish_seqrly($action) {

        $identity_url = finish_seqrly_auth();

        //Seqrly Changes - BEGIN
        $data = array();
        $isSeqrlyOP = stripos($identity_url, 'seqrly');
        if ( $isSeqrlyOP !== FALSE) { //Extract SREG or Attribute Exchange data only for Seqrly
            
            // Get Simple Registration info
            $data = seqrly_get_user_data_sreg($data, $identity_url);
            $data = seqrly_get_user_data_ax($data, $identity_url);
        }

        do_action('seqrly_finish_auth', $identity_url, $action, $data);
        //Seqrly Changes - END

        //do_action('seqrly_finish_auth', $identity_url, $action);
}


/**
 *
 * @uses apply_filters() Calls 'seqrly_consumer_return_urls' to collect return_to URLs to be included in XRDS document.
 */
function seqrly_consumer_xrds_simple($xrds) {

	if (get_option('seqrly_xrds_returnto')) {
		// OpenID Consumer Service
		$return_urls = array_unique(apply_filters('seqrly_consumer_return_urls', array(seqrly_service_url('consumer', 'login_post'))));
		if (!empty($return_urls)) {
			$xrds = xrds_add_simple_service($xrds, 'OpenID Consumer Service', 'http://specs.openidid.net/auth/2.0/return_to', $return_urls);
		}
	}

	return $xrds;
}




