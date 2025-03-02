<?php

require_once 'Auth/OpenID/Server.php';
require_once 'server_ext.php';

add_filter( 'xrds_simple', 'seqrly_provider_xrds_simple');
add_action( 'wp_head', 'seqrly_provider_link_tags');


/**
 * Get the URL of the OpenID server endpoint.
 *
 * @see seqrly_service_url
 */
function seqrly_server_url() {
	return seqrly_service_url('server', 'login_post');
}


/**
 * Add XRDS entries for OpenID Server.  Entries added will be highly 
 * dependant on the requested URL and plugin configuration.
 *
 * @uses apply_filters() Calls 'seqrly_server_xrds_types' before returning XRDS Types for OpenID authentication services.
 */
function seqrly_provider_xrds_simple($xrds) {
	global $wp_roles;

	if (!$wp_roles) $wp_roles = new WP_Roles();

	$provider_enabled = false;
	foreach ($wp_roles->role_names as $key => $name) {
		$role = $wp_roles->get_role($key);
		if ($role->has_cap('use_seqrly_provider')) {
			$provider_enabled = true;
			break;
		}
	}

	if (!$provider_enabled) return $xrds;


	$user = seqrly_server_requested_user();
	
	if (!$user && get_option('seqrly_blog_owner')) {
		$url_parts = parse_url(get_option('home'));
		$path = trailingslashit($url_parts['path']);

		$script = preg_replace('/index.php$/', '', $_SERVER['SCRIPT_NAME']);
		$script = trailingslashit($script);

		if ($path != $script && !is_admin()) {
			return $xrds;
		}

		if (!defined('SEQRLY_DISALLOW_OWNER') || !SEQRLY_DISALLOW_OWNER) {
			$user = get_userdatabylogin(get_option('seqrly_blog_owner'));
		}
	}

	if ($user) {
		// if user doesn't have capability, bail
		$user_object = new WP_User($user->ID);
		if (!$user_object->has_cap('use_seqrly_provider')) return $xrds;

		if (get_user_meta($user->ID, 'seqrly_delegate', true)) {
			$services = get_user_meta($user->ID, 'seqrly_delegate_services', true);
		} else {
			$services = array();

			$tmp_types = apply_filters('seqrly_server_xrds_types', array('http://specs.openid.net/auth/2.0/signon'));
			$types = array();
			foreach ($tmp_types as $t) {
				$types[] = array('content' => $t);
			}
			$services[] = array(
							'Type' => $types,
							'URI' => seqrly_server_url(),
							'LocalID' => get_author_posts_url($user->ID),
						);

			$tmp_types = apply_filters('seqrly_server_xrds_types', array('http://openid.net/signon/1.1'));
			$types = array();
			foreach ($tmp_types as $t) {
				$types[] = array('content' => $t);
			}
			$services[] = array(
							'Type' => $types,
							'URI' => seqrly_server_url(),
							'seqrly:Delegate' => get_author_posts_url($user->ID),
						);
		}
	} else {
		$services = array(
			array(
				'Type' => array(array('content' => 'http://specs.openid.net/auth/2.0/server')),
				'URI' => seqrly_server_url(),
				'LocalID' => 'http://specs.openid.net/auth/2.0/identifier_select',
			)
		);
	}


	if (!empty($services)) {
		foreach ($services as $index => $service) {
			$name = 'OpenID Provider Service (' . $index . ')';
			$xrds = xrds_add_service($xrds, 'main', $name, $service, $index);
		}
	}

	return $xrds;
}


/**
 * Parse the request URL to determine which author is associated with it.
 *
 * @return bool|object false on failure, User DB row object
 */
function seqrly_server_requested_user() {
	global $wp_rewrite;

	if ($_REQUEST['author']) {
		if (is_numeric($_REQUEST['author'])) {
			return get_userdata($_REQUEST['author']);
		} else {
			return get_userdatabylogin($_REQUEST['author']);
		}
	} else {
		$regex = preg_replace('/%author%/', '(.+)', $wp_rewrite->get_author_permastruct());
		preg_match('|'.$regex.'|', $_SERVER['REQUEST_URI'], $matches);
		$username = sanitize_user($matches[1], true);
		return get_userdatabylogin($username);
	}
}


/**
 * Process an OpenID Server request.
 *
 * @uses apply_filters() Calls 'seqrly_server_auth_response' before sending the authentication response.
 */
function seqrly_server_request() {
    
    if ( !get_option('seqrly_enable_openid_provider')) {
        header ('HTTP/1.1 500');
        wp_die('This site is not configured to act like an OpenID provider.');
    }

    $server = seqrly_server();

    // get OpenID request, either from session or HTTP request
    $request = $server->decodeRequest();
    if (!$request || Auth_OpenID_isError($request)) {
            @session_start();
            if ($_SESSION['seqrly_server_request']) {
                    $request = $_SESSION['seqrly_server_request'];
                    unset($_SESSION['seqrly_server_request']);
            }
    }

    if (!$request || Auth_OpenID_isError($request)) {
            $html = '<h1>This is an OpenID Server.</h1>';

            if (Auth_OpenID_isError($request)) {
                    $html .= '<p><strong>Request Error:</strong> ' . $request->toString() . '</p>';
            } else {
                    $html .= '<p>Nothing to see here&#8230; move along.</p>';
            }

            wp_die($html);
    }

    // process request
    if (in_array($request->mode, array('checkid_immediate', 'checkid_setup'))) {
            $response = seqrly_server_auth_request($request);
            $response = apply_filters('seqrly_server_auth_response', $response);
    } else {
            $response = $server->handleRequest($request);
    }

    seqrly_server_process_response($response);
}


/**
 * Process an OpenID Server authentication request.
 *
 * @uses do_action() Calls the 'seqrly_server_pre_auth' hook action before checking if the user is logged in.
 * @uses do_action() Calls the 'seqrly_server_post_auth' hook action after ensuring that the user is logged in.
 */
function seqrly_server_auth_request($request) {

	do_action('seqrly_server_pre_auth', $request);

	// user must be logged in
	if (!is_user_logged_in()) {
		if ($request->mode == 'checkid_immediate') {
			return $request->answer(false);
		} else {
			@session_start();
			$_SESSION['seqrly_server_request'] = $request;
			auth_redirect();
		}
	}

	do_action('seqrly_server_post_auth', $request);

	// get some user data
	$user = wp_get_current_user();
	$author_url = get_author_posts_url($user->ID);
	$id_select = ($request->identity == 'http://specs.openid.net/auth/2.0/identifier_select');

	// bail if user does not have access to OpenID provider
	if (!$user->has_cap('use_seqrly_provider')) return $request->answer(false);

	// bail if user doesn't own identity and not using id select
	if (!$id_select && ($author_url != $request->identity)) {
		return $request->answer(false);
	}

	// if using id select but user is delegating, display error to user (unless checkid_immediate)
	if ($id_select && get_user_meta($user->ID, 'seqrly_delegate', true)) {
		if ($request->mode != 'checkid_immediate') {
			if ($_REQUEST['action'] == 'cancel') {
				check_admin_referer('seqrly-server_cancel');
				return $request->answer(false);
			} else {
				@session_start();
				$_SESSION['seqrly_server_request'] = $request;
				ob_start();

				echo '<h1>'.__('OpenID Login Error', 'seqrly').'</h1>';
				echo '<p>'; 
				printf(__('Because you have delegated your OpenID, you cannot login with the URL <strong>%s</strong>. Instead, you must use your full OpenID when logging in.', 'seqrly'), trailingslashit(get_option('home')));  
				echo'</p>';
				echo '<p>' . sprintf(__('Your full OpenID is: %s', 'seqrly'), '<strong>'.$author_url.'</strong>') . '</p>';

				echo '
					<form method="post">
						<p class="submit">
							<input type="submit" value="'.__('Continue').'" />
							<input type="hidden" name="action" value="cancel" />
							<input type="hidden" name="seqrly_server" value="1" />
						</p>'
					. wp_nonce_field('seqrly-server_cancel', '_wpnonce', true, false)
					.'</form>';

				$html = ob_get_contents();
				ob_end_clean();
				wp_die($html, 'OpenID Login Error');
			}
		}
	}

	// if user trusts site, we're done
	$trusted_sites = get_user_meta($user->ID, 'seqrly_trusted_sites', true);
	$site_hash = md5($request->trust_root);
	if (is_array($trusted_sites) && array_key_exists($site_hash, $trusted_sites)) {
		$trusted_sites[$site_hash]['last_login'] = time();
		update_user_meta($user->ID, 'seqrly_trusted_sites', $trusted_sites);

		if ($id_select) { 
			return $request->answer(true, null, $author_url);
		} else { 
			return $request->answer(true);
		}
	}

	// that's all we can do without interacting with the user... bail if using immediate
	if ($request->mode == 'checkid_immediate') {
		return $request->answer(false);
	}
		
	// finally, prompt the user to trust this site
	if (seqrly_server_user_trust($request)) {
		if ($id_select) { 
			return $request->answer(true, null, $author_url);
		} else { 
			return $request->answer(true);
		}
	} else {
		return $request->answer(false);
	}
}



/**
 * Check that the current user's author URL matches the claimed URL.
 *
 * @param string $claimed claimed url
 * @return bool whether the current user matches the claimed URL
 */
function seqrly_server_check_user_login($claimed) {
	$user = wp_get_current_user();
	if (!$user) return false;

	$identifier = get_author_posts_url($user->ID);
	return ($claimed == $identifier);
}


/**
 * Process OpenID server response
 *
 * @param object $response response object
 */
function seqrly_server_process_response($response) {
	$server = seqrly_server();

	$web_response = $server->encodeResponse($response);

	if ($web_response->code != AUTH_SEQRLY_HTTP_OK) {
		header(sprintf('HTTP/1.1 %d', $web_response->code), true, $web_response->code);
	}
	foreach ($web_response->headers as $k => $v) {
		header("$k: $v");
	}

	print $web_response->body;
	exit;
}


/**
 * Get Auth_OpenID_Server singleton.
 *
 * @return object Auth_OpenID_Server singleton instance
 */
function seqrly_server() {
	static $server;

	if (!$server || !is_a($server, 'Auth_OpenID_Server')) {
		$server = new Auth_OpenID_Server(seqrly_getStore(), seqrly_server_url());
	}

	return $server;
}


/**
 * Add OpenID HTML link tags when appropriate.
 */
function seqrly_provider_link_tags() {

	if (is_front_page()) {
		if (!defined('SEQRLY_DISALLOW_OWNER') || !SEQRLY_DISALLOW_OWNER) {
			$user = get_userdatabylogin(get_option('seqrly_blog_owner'));
		}
	} else if (is_author()) {
		global $wp_query;
		$user = $wp_query->get_queried_object();
	}

	if ( isset($user) && $user) {
		// if user doesn't have capability, bail
		$user_object = new WP_User($user->ID);
		if (!$user_object->has_cap('use_seqrly_provider')) return;

		if (get_user_meta($user->ID, 'seqrly_delegate', true)) {
			$services = get_user_meta($user->ID, 'seqrly_delegate_services', true);
			$seqrly_1 = false;
			$seqrly_2 = false;

			foreach($services as $service) {
				if (!$seqrly_1 && $service['seqrly:Delegate']) {
					echo '
					<link rel="seqrly.server" href="'.$service['URI'].'" />
					<link rel="seqrly.delegate" href="'.$service['seqrly:Delegate'].'" />';
					$seqrly_1 = true;
				}

				if (!$seqrly_2 && $service['LocalID']) {
					echo '
					<link rel="seqrly2.provider" href="'.$service['URI'].'" />
					<link rel="seqrly2.local_id" href="'.$service['LocalID'].'" />';
					$seqrly_2 = true;
				}
			}
		} else  {
			$server = seqrly_server_url();
			$identifier = get_author_posts_url($user->ID);

			echo '
			<link rel="seqrly2.provider" href="'.$server.'" />
			<link rel="seqrly2.local_id" href="'.$identifier.'" />
			<link rel="seqrly.server" href="'.$server.'" />
			<link rel="seqrly.delegate" href="'.$identifier.'" />';
		}

	}

}


function seqrly_server_add_trust_site($user_id, $site_url, $site_name = null, $release_attributes) {
}

function seqrly_server_remove_trust_site() {
}

/**
 * Determine if the current user trusts the the relying party of the OpenID authentication request.
 *
 * @uses do_action() Calls the 'seqrly_server_trust_form' hook action when displaying the trust form.
 * @uses do_action() Calls the 'seqrly_server_trust_submit' hook action when processing the submitted trust form.
 * @uses apply_filters() Calls 'seqrly_server_store_trusted_site' before storing trusted site data.
 */
function seqrly_server_user_trust($request) {
	$user = wp_get_current_user();

	if ($_REQUEST['seqrly_trust']) {
		$trust = null;

		if ($_REQUEST['seqrly_trust'] == 'cancel') {
			$trust = false;
		} else {
			check_admin_referer('seqrly-server_trust');
			$trust = true;
		}

		do_action('seqrly_server_trust_submit', $trust, $request);

		if ($trust) {
			// store trusted site (unless hidden constant is set)
			if (!defined('SEQRLY_NO_AUTO_TRUST') || !SEQRLY_NO_AUTO_TRUST) {
				$site = array( 'url' => $request->trust_root, 'last_login' => time());
				$site = apply_filters('seqrly_server_store_trusted_site', $site);

				$trusted_sites = get_user_meta($user->ID, 'seqrly_trusted_sites', true);
				$site_hash = md5($request->trust_root);
				$trusted_sites[$site_hash] = $site;

				update_user_meta($user->ID, 'seqrly_trusted_sites', $trusted_sites);
			}
		}

		return $trust;

	} else {
		// prompt the user to make a trust decision
		@session_start();
		$_SESSION['seqrly_server_request'] = $request;

		ob_start();
		echo '
			<style type="text/css">
				#banner { margin-bottom: 4em; }
				#banner #site { float: left; color: #555; }
				#banner #loggedin { font-size: 0.7em; float: right; }
				p.trust_form_add {
					margin: 3em auto 1em; padding: 0.5em; border: 1px solid #999; background: #FFEBE8; width: 80%; font-size: 0.8em; -moz-border-radius: 3px;
				}
				#submit { font-size: 18px; padding: 10px 35px; margin-left: 1em; }
			</style>

			<div id="banner">
				<div id="site">'.get_option('blogname').'</div>';

		if (is_user_logged_in()) {
			$user = wp_get_current_user();
			$logout_url = site_url('wp-login.php?action=logout&redirect_to=' . urlencode(seqrly_server_url()), 'login');
			echo '
				<div id="loggedin">' . sprintf(__('Logged in as %1$s (%2$s). <a href="%3$s">Use a different account?</a>', 'seqrly'), $user->display_name, $user->user_login, $logout_url ) . '</div>';
		}

		echo '
			</div>

			<form action="' . seqrly_server_url() . '" method="post">
			<h1>'.__('Verify Your Identity', 'seqrly').'</h1>
			<p style="margin: 1.5em 0 1em 0;">'
				. sprintf(__('%s has asked to verify your identity.', 'seqrly'), '<strong>'.$request->trust_root.'</strong>')
				. '</p>
			
			<p style="margin: 1em 0;">'
				. __('Click <strong>Continue</strong> to verify your identity and login without creating a new password.', 'seqrly')
			. '</p>';

		do_action('seqrly_server_trust_form');

		echo '
			<p class="submit" style="text-align: center; margin-top: 2.4em;">
				<a href="' . add_query_arg('seqrly_trust', 'cancel', seqrly_server_url()) . '">'.__('Cancel and go back', 'seqrly').'</a>
				<input type="submit" id="submit" name="seqrly_trust" value="'.__('Continue', 'seqrly').'" />
			</p>

			<p style="margin: 3em 0 1em 0; font-size: 0.8em;">'
				. sprintf(__('Manage or remove access on the <a href="%s" target="_blank">Trusted Sites</a> page.', 'seqrly'), 
					admin_url((current_user_can('edit_users') ? 'users.php' : 'profile.php') . '?page=seqrly_trusted_sites'))
				. '</p>
			<p style="margin: 1em 0; font-size: 0.8em;">'
				. sprintf(__('<a href="%s" target="_blank">Edit your profile</a> to change the information that gets shared with Trusted Sites.', 'seqrly'), admin_url('profile.php'))
				. '</p>
		';

		wp_nonce_field('seqrly-server_trust', '_wpnonce', true);

		echo '
			</form>';

		$html = ob_get_contents();
		ob_end_clean();

		seqrly_page($html, __('Verify Your Identity', 'seqrly'));
	}
}


/**
 * Discover and cache OpenID services for a user's delegate OpenID.
 *
 * @param int $userid user ID
 * @url string URL to discover.  If not provided, user's current delegate will be used
 * @return bool true if successful
 */
function seqrly_server_get_delegation_info($userid, $url = null) {
	if (empty($url)) $url = get_user_meta($userid, 'seqrly_delegate', true);
	if (empty($url)) return false;

	$fetcher = Auth_Yadis_Yadis::getHTTPFetcher();
	$discoveryResult = Auth_Yadis_Yadis::discover($url, $fetcher);
	$endpoints = Auth_OpenID_ServiceEndpoint::fromDiscoveryResult($discoveryResult);
	$services = array();

	if (!empty($endpoints)) {
		foreach ($endpoints as $endpoint) {
			$service = array(
				'Type' => array(),
				'URI' => $endpoint->server_url,
			);

			foreach ($endpoint->type_uris as $type) {
				$service['Type'][] = array('content' => $type);

				if ($type == Auth_OpenID_TYPE_2_0_IDP) {
					$service['LocalID'] = Auth_OpenID_IDENTIFIER_SELECT;
				} else if ($type == Auth_OpenID_TYPE_2_0) {
					$service['LocalID'] = $endpoint->local_id;
				} else if (in_array($type, array(Auth_OpenID_TYPE_1_0, Auth_OpenID_TYPE_1_1, Auth_OpenID_TYPE_1_2))) {
					$service['seqrly:Delegate'] = $endpoint->local_id;
				}
			}

			$services[] = $service;
		}
	}

	if (empty($services)) {
		// resort to checking for HTML links
		$response = $fetcher->get($url);
		$html_content = $response->body;
		$p = new Auth_OpenID_Parse();
		$link_attrs = $p->parseLinkAttrs($html_content);

		// check HTML for OpenID2
		$server_url = $p->findFirstHref($link_attrs, 'seqrly2.provider');
		if ($server_url !== null) {
			$seqrly_url = $p->findFirstHref($link_attrs, 'seqrly2.local_id');
			if ($seqrly_url == null) $seqrly_url = $url;
			$services[] = array(
				'Type' => array(array('content' => Auth_OpenID_Type_1_1)),
				'URI' => $server_url,
				'LocalID' => $seqrly_url,
			);
		}

		// check HTML for OpenID1
		$server_url = $p->findFirstHref($link_attrs, 'seqrly.server');
		if ($server_url !== null) {
			$seqrly_url = $p->findFirstHref($link_attrs, 'seqrly.delegate');
			if ($seqrly_url == null) $seqrly_url = $url;
			$services[] = array(
				'Type' => array(array('content' => Auth_OpenID_Type_2_0)),
				'URI' => $server_url,
				'seqrly:Delegate' => $seqrly_url,
			);
		}
	}

	if (empty($services)) return false;

	return array(
		'url' => $url,
		'services' => $services
	);
}

?>
