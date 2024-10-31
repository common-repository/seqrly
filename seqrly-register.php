<?php
/**
 * Seqrly User Registration
 *
 * Handles creating new account or associating with an existing account.
 *
 * @package Seqrly
 */

/** Make sure that the WordPress bootstrap has run before continuing. */

// Redirect to https login if forced to use SSL
if ( force_ssl_admin() && !is_ssl() ) {
	if ( 0 === strpos($_SERVER['REQUEST_URI'], 'http') ) {
		wp_redirect(preg_replace('|^http://|', 'https://', $_SERVER['REQUEST_URI']));
		exit();
	} else {
		wp_redirect('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		exit();
	}
}

/**
 * Outputs the header for the login page.
 *
 * @uses do_action() Calls the 'login_head' for outputting HTML in the Log In
 *		header.
 * @uses apply_filters() Calls 'login_headerurl' for the top login link.
 * @uses apply_filters() Calls 'login_headertitle' for the top login title.
 * @uses apply_filters() Calls 'login_message' on the message to display in the
 *		header.
 * @uses $error The error global, which is checked for displaying errors.
 *
 * @param string $title Optional. WordPress Log In Page title to display in
 *		<title/> element.
 * @param string $message Optional. Message to display in header.
 * @param WP_Error $wp_error Optional. WordPress Error Object
 */
function login_header($title = 'Log In', $message = '', $wp_error = '') {
	global $error, $is_iphone, $interim_login, $current_site;

	// Don't index any of these forms
	add_filter( 'pre_option_blog_public', '__return_zero' );
	add_action( 'login_head', 'noindex' );

	if ( empty($wp_error) )
		$wp_error = new WP_Error();

	// Shake it!
	$shake_error_codes = array( 'system_error', 'username_exists', 'empty_password', 'email_exists', 'empty_email', 'invalid_email', 'invalidcombo', 'empty_username', 'invalid_username', 'incorrect_password' );
	$shake_error_codes = apply_filters( 'shake_error_codes', $shake_error_codes );

	if ( $shake_error_codes && $wp_error->get_error_code() && in_array( $wp_error->get_error_code(), $shake_error_codes ) )
		add_action( 'login_head', 'wp_shake_js', 12 );

	?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
	<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php bloginfo('charset'); ?>" />
	<title><?php bloginfo('name'); ?> &rsaquo; <?php echo $title; ?></title>
<?php
	wp_admin_css( 'login', true );
	wp_admin_css( 'colors-fresh', true );

	if ( $is_iphone ) { ?>
	<meta name="viewport" content="width=320; initial-scale=0.9; maximum-scale=1.0; user-scalable=0;" />
	<style type="text/css" media="screen">
	form { margin-left: 0px; }
	#login { margin-top: 20px; }
	</style>
<?php
	} elseif ( isset($interim_login) && $interim_login ) { ?>
	<style type="text/css" media="all">
	.login #login { margin: 20px auto; }
	</style>
<?php
	}

	do_action( 'login_enqueue_scripts' );
	do_action( 'login_head' );
?>
</head>
<body class="login">
<?php   if ( !is_multisite() ) { ?>
<div id="login"><h1><a href="<?php echo apply_filters('login_headerurl', 'http://wordpress.org/'); ?>" title="<?php echo apply_filters('login_headertitle', esc_attr__('Powered by WordPress')); ?>"><?php bloginfo('name'); ?></a></h1>
<?php   } else { ?>
<div id="login"><h1><a href="<?php echo apply_filters('login_headerurl', network_home_url() ); ?>" title="<?php echo apply_filters('login_headertitle', esc_attr($current_site->site_name) ); ?>"><span class="hide"><?php bloginfo('name'); ?></span></a></h1>
<?php   }

	$message = apply_filters('login_message', $message);
	if ( !empty( $message ) ) echo $message . "\n";

	// In case a plugin uses $error rather than the $wp_errors object
	if ( !empty( $error ) ) {
		$wp_error->add('error', $error);
		unset($error);
	}

	if ( $wp_error->get_error_code() ) {
		$errors = '';
		$messages = '';
		foreach ( $wp_error->get_error_codes() as $code ) {
			$severity = $wp_error->get_error_data($code);
			foreach ( $wp_error->get_error_messages($code) as $error ) {
				if ( 'message' == $severity )
					$messages .= '	' . $error . "<br />\n";
				else
					$errors .= '	' . $error . "<br />\n";
			}
		}
		if ( !empty($errors) )
			echo '<div id="login_error">' . apply_filters('login_errors', $errors) . "</div>\n";
		if ( !empty($messages) )
			echo '<p class="message">' . apply_filters('login_messages', $messages) . "</p>\n";
	}
} // End of login_header()

/**
 * Outputs the footer for the login page.
 *
 * @param string $input_id Which input to auto-focus
 */
function login_footer($input_id = '') {
	?>
	<p id="backtoblog"><a href="<?php bloginfo('url'); ?>/" title="<?php esc_attr_e('Are you lost?') ?>"><?php printf(__('&larr; Back to %s'), get_bloginfo('title', 'display' )); ?></a></p>
	</div>

<?php if ( !empty($input_id) ) : ?>
<script type="text/javascript">
try{document.getElementById('<?php echo $input_id; ?>').focus();}catch(e){}
if(typeof wpOnload=='function')wpOnload();
</script>
<?php endif; ?>

<?php do_action('login_footer'); ?>
</body>
</html>
<?php
}

function wp_shake_js() {
	global $is_iphone;
	if ( $is_iphone )
		return;
?>
<script type="text/javascript">
addLoadEvent = function(func){if(typeof jQuery!="undefined")jQuery(document).ready(func);else if(typeof wpOnload!='function'){wpOnload=func;}else{var oldonload=wpOnload;wpOnload=function(){oldonload();func();}}};
function s(id,pos){g(id).left=pos+'px';}
function g(id){return document.getElementById(id).style;}
function shake(id,a,d){c=a.shift();s(id,c);if(a.length>0){setTimeout(function(){shake(id,a,d);},d);}else{try{g(id).position='static';wp_attempt_focus();}catch(e){}}}
addLoadEvent(function(){ var p=new Array(15,30,15,0,-15,-30,-15,0);p=p.concat(p.concat(p));var i=document.forms[0].id;g(i).position='relative';shake(i,p,20);});
</script>
<?php
}


set_include_path( $seqrly_include_path . PATH_SEPARATOR . get_include_path() );
require_once 'common.php';

session_start(); // start up your PHP session! 

$user_data = $_SESSION['user_data'];

$seqrly_email = $user_data['user_email'];
$seqrly_nickname = $user_data['nickname'];
$user_email = $seqrly_email;
$identity_url = $user_data['user_url'];

if ( empty ( $user_data['user_login'] ) ) {
    $seqrly_username = seqrly_generate_new_username($user_data['nickname'], false);

    // finally, build username from OpenID URL
    if (empty($seqrly_username)) {
            $seqrly_username = seqrly_generate_new_username($user_data['user_url']);
    }
    $user_data['user_login'] = $seqrly_username;
}
            

$user_login = $seqrly_nickname;


/**
 * Handles registering a new user.
 *
 * @param string $user_login User's username for logging in
 * @param string $user_email User's email address to send password and add
 * @return int|WP_Error Either user's ID or error on failure.
 */
function register_new_user( $user_login, $user_email, $seqrly_email, $user_data ) {
	$errors = new WP_Error();

	$sanitized_user_login = sanitize_user( $user_login );
	$user_email = apply_filters( 'user_registration_email', $user_email );

	// Check the username
	if ( $sanitized_user_login == '' ) {
		$errors->add( 'empty_username', __( '<strong>ERROR</strong>: Please enter a username.' ) );
	} elseif ( ! validate_username( $user_login ) ) {
		$errors->add( 'invalid_username', __( '<strong>ERROR</strong>: This username is invalid because it uses illegal characters. Please enter a valid username.' ) );
		$sanitized_user_login = '';
	} elseif ( username_exists( $sanitized_user_login ) ) {
		$errors->add( 'username_exists', __( '<strong>ERROR</strong>: This username is already registered, please choose another one.' ) );
	}

	// Check the e-mail address
	if ( $user_email == '' ) {
		$errors->add( 'empty_email', __( '<strong>ERROR</strong>: Please type your e-mail address.' ) );
	} elseif ( ! is_email( $user_email ) ) {
		$errors->add( 'invalid_email', __( '<strong>ERROR</strong>: The email address isn&#8217;t correct.' ) );
		$user_email = '';
	} elseif ( email_exists( $user_email ) ) {
		$errors->add( 'email_exists', __( '<strong>ERROR</strong>: This email is already registered, please choose another one.' ) );
	} else {
            if ( ! empty ($seqrly_email) && $user_email !== $seqrly_email) { 
		$errors->add( 'email_exists', __( '<strong>ERROR</strong>: Your email cannot be different from your OpenID email.' ) );
            }
        }
        
        //Now update user_data with new values
        $user_data['user_login'] = $sanitized_user_login;
        $user_data['display_name'] = $sanitized_user_login;
        $user_data['user_email'] = $user_email;
	$user_data['user_pass'] = substr( md5( uniqid( microtime() ) ), 0, 7);


	if ( $errors->get_error_code() )
		return $errors;

	$user_id = wp_insert_user( $user_data );

        if ( is_wp_error($user_id) ) {
		$errors->add( 'system_error', __( '<strong>ERROR</strong>: Could not add user: ' . $user_id->get_error_message() ) );
                return $errors;
        } else { //Created OK

            $user_data['ID'] = $user_id;
            // XXX this all looks redundant, see seqrly_set_current_user
            $creds = array();
            $creds['user_login'] = $user_data['user_login'];
            $creds['user_password'] = $user_data['user_pass'];
            $creds['remember'] = false;
            $user = wp_signon($creds);

            if( ! $user ) {
                $errors->add( 'system_error', __( 'User was created fine, but wp_login() for the new user failed. This is probably a bug.' ) );
                return $errors;
            }

            // notify of user creation
            wp_new_user_notification( $user->user_login );

            wp_clearcookie();
            wp_setcookie( $user->user_login, md5($user->user_pass), true, '', '', true );

            // Bind the provided identity to the just-created user
            seqrly_add_user_identity($user_id, $user_data['user_url']);

            return $user_id;
	}
}

//
// Main
//

if ( !get_option('users_can_register') ) {
        wp_redirect( site_url('wp-login.php?registration=disabled') );
        exit();
}


$regType = isset($_REQUEST['regType']) ? $_REQUEST['regType'] : 'none';
$errors = new WP_Error();

// validate action so as to default to the login screen
if ( !in_array($regType, array('none', 'existing', 'new'), true) )
	$regType = 'login';

nocache_headers();

header('Content-Type: '.get_bloginfo('html_type').'; charset='.get_bloginfo('charset'));

if ( defined('RELOCATE') ) { // Move flag is set
	if ( isset( $_SERVER['PATH_INFO'] ) && ($_SERVER['PATH_INFO'] != $_SERVER['PHP_SELF']) )
		$_SERVER['PHP_SELF'] = str_replace( $_SERVER['PATH_INFO'], '', $_SERVER['PHP_SELF'] );

	$schema = is_ssl() ? 'https://' : 'http://';
	if ( dirname($schema . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) != get_option('siteurl') )
		update_option('siteurl', dirname($schema . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) );
}

//Set a cookie now to see if they are supported by the browser.
setcookie(TEST_COOKIE, 'WP Cookie check', 0, COOKIEPATH, COOKIE_DOMAIN);
if ( SITECOOKIEPATH != COOKIEPATH )
	setcookie(TEST_COOKIE, 'WP Cookie check', 0, SITECOOKIEPATH, COOKIE_DOMAIN);


$http_post = ('POST' == $_SERVER['REQUEST_METHOD']);

switch ($regType) {

case 'none' :
break;

case 'existing' :
    $user_login = '';
    $user_pass = '';
    if ( $http_post ) {
            $user_login = $_POST['log'];
            $user_pass = $_POST['pwd'];
            
            $user = get_userdatabylogin($user_login);
            if ( ! $user ) {
		$errors->add( 'invalid_username', __( '<strong>ERROR</strong>: This username does not exist.' ) );
            }

            $creds = array();
            $creds['user_login'] = $user_login;
            $creds['user_password'] = $user_pass;
            $creds['remember'] = false;
            $user = wp_signon($creds);

            if ( is_wp_error($user) ) {

		$errors->add( 'incorrect_password', __( '<strong>ERROR</strong>: Given password not valid for given username.' ) );
                
            } else {
                //Ready to update the user
                $user_data['ID'] = $user->ID;
                $user_data['nickname'] = $user->nickname; //Do not change the nickname
                $user_data['display_name'] = $user->display_name; //Do not change the nickname
                $user_id = wp_update_user($user_data);
                
                // Bind the provided identity to the just-created user
                seqrly_add_user_identity($user_id, $identity_url);
                
                // return to Home page
                $url = get_option('siteurl') . '/';

                wp_safe_redirect($url);
                exit();
            }
    }
    break;

case 'new' :
default:

    $user_login = '';
    $user_email = '';
    if ( $http_post ) {
            $user_login = $_POST['user_login'];
            $user_email = $_POST['user_email'];

            $errors = register_new_user($user_login, $user_email, $seqrly_email, $user_data);
            if ( !is_wp_error($errors) ) {

                // return to Home page
                $url = get_option('siteurl') . '/';

                wp_safe_redirect($url);
                exit();
            }
    }
    break;
} // end action switch

$headerMessage = "Register with your OpenID URL: " . $user_data['user_url'];
if (stripos($user_data['user_url'], 'seqrly')) {
    $headerMessage = "Register with your Seqrly URL: " . $user_data['user_url'];
}

    login_header(__('Registration Form'), '<p class="message register">' . __($headerMessage) . '</p>', $errors);
?>

<form name="registerform" id="registerform" action="<?php echo site_url('wp-content/plugins/seqrly/seqrly-register.php?regType=new', 'login_post') ?>" method="post">

    <p>
        <h3>Register as a new user </h3>
        <label><?php _e('Username') ?><br />
        <input type="text" name="user_login" id="user_login" class="input" value="<?php echo esc_attr(stripslashes($user_login)); ?>" size="20" tabindex="10" /></label>
    </p>
    <p>
            <label><?php _e('E-mail') ?><br />
            <input type="text" name="user_email" id="user_email" class="input" value="<?php echo esc_attr(stripslashes($user_email)); ?>" size="25" tabindex="20" /></label>
    </p>

    <br class="clear" />
    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
    <p class="submit"><input type="submit" name="wp-submit" id="wp-submit" class="button-primary" value="<?php esc_attr_e('Register New'); ?>" tabindex="100" /></p>
</form>

<form name="registerform" id="registerform" action="<?php echo site_url('wp-content/plugins/seqrly/seqrly-register.php?regType=existing', 'login_post') ?>" method="post">
    
    <p>
        <h3>Register as an existing user </h3>
        <label><?php _e('Username') ?><br />
        <input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr($user_login); ?>" size="20" tabindex="30" /></label>
    </p>
    <p>
            <label><?php _e('Password') ?><br />
            <input type="password" name="pwd" id="user_pass" class="input" value="" size="20" tabindex="40" /></label>
    </p>
    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
    <p class="submit"><input type="submit" name="wp-submit" id="wp-submit" class="button-primary" value="<?php esc_attr_e('Register Existing'); ?>" tabindex="100" /></p>
</form>


<?php
login_footer('user_login');
?>
