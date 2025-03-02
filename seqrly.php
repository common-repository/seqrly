<?php
/*
 Plugin Name: Seqrly
 Plugin URI: http://wordpress.org/extend/plugins/seqrly
 Description: Allows the use of Seqrly (or OpenID) for account registration, authentication, and commenting.  Also includes an OpenID provider which can turn WordPress author URLs into OpenIDs.
 Author: LiberatID, Inc.
 Author URI: http://seqrly.com/
 Version: 1.0
 License: Dual GPL (http://www.fsf.org/licensing/licenses/info/GPLv2.html) and Modified BSD (http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD)
 Text Domain: seqrly
 */

define ( 'SEQRLY_PLUGIN_REVISION', preg_replace( '/\$Rev: (.+) \$/', '\\1',
	'$Rev: 1 $') ); // this needs to be on a separate line so that svn:keywords can work its magic

// last plugin revision that 1 database schema changes
define ( 'SEQRLY_DB_REVISION', 24426);


$seqrly_include_path = dirname(__FILE__);

// check source of randomness
if ( !@is_readable('/dev/urandom') ) { 
	define('Auth_OpenID_RAND_SOURCE', null); 
}

set_include_path( $seqrly_include_path . PATH_SEPARATOR . get_include_path() );
require_once 'common.php';
require_once 'consumer.php';
require_once 'admin_panels.php';
require_once 'comments.php';
require_once 'login.php';
require_once 'server.php';
require_once 'store.php';
restore_include_path();

$userOpenIDURLs = NULL;

// register activation (and similar) hooks
register_activation_hook('seqrly/seqrly.php', 'seqrly_activate_plugin');
register_deactivation_hook('seqrly/seqrly.php', 'seqrly_deactivate_plugin');
register_uninstall_hook('seqrly/seqrly.php', 'seqrly_uninstall_plugin');

// run activation function if new revision of plugin
if ( get_option('seqrly_plugin_revision') === false || SEQRLY_PLUGIN_REVISION != get_option('seqrly_plugin_revision') ) {
	add_action('admin_init', 'seqrly_activate_plugin');
}


// ---------------- //
// Public Functions //
// ---------------- //

/**
 * Check if the user has any OpenIDs.
 *
 * @param mixed $user the username or ID.  If not provided, the current user will be used.
 * @return bool true if the user has any OpenIDs
 * @since 1.0
 */
function is_user_openid($user = null) {
    if (is_null($userOpenIDURLs)) {
        $userOpenIDURLs = get_user_openids($user);
    }
    //$urls = get_user_openids($user);
    return ( !empty($userOpenIDURLs) );
}

/**
 * Check if the user has any OpenIDs.
 *
 * @param mixed $user the username or ID.  If not provided, the current user will be used.
 * @return bool true if the user has any OpenIDs
 * @since 1.0
 */
function is_user_seqrly($user = null) {
    if (is_null($userOpenIDURLs)) {
        $userOpenIDURLs = get_user_openids($user);
    }
    $seqrlyString = 'seqrly';
    //$urls = get_user_openids($user);
    if ( empty($userOpenIDURLs) ) {
        return FALSE;
    } else {
        foreach ($userOpenIDURLs as &$value) {
            if (stripos($value, $seqrlyString)) {
                return TRUE;
            }
        }
        return FALSE;
    }
}


/**
 * Check if the current comment was submitted using an OpenID. Useful for 
 * <pre><?php echo ( is_comment_seqrly() ? 'Submitted with OpenID' : '' ); ?></pre>
 *
 * @param int $id comment ID to check for.  If not provided, the current comment will be used.
 * @return bool true if the comment was submitted using an OpenID
 * @access public
 * @since 1.0
 */
function is_comment_seqrly($id = null) {
	if ( is_numeric($id) ) {
		$comment = get_comment($id);
	} else {
		global $comment;
	}

	$seqrly_comments = get_post_meta($comment->comment_post_ID, 'seqrly_comments', true);

	if ( is_array($seqrly_comments) ) {
		if ( in_array($comment->comment_ID, $seqrly_comments) ) {
			return true;
		}
	}

	return false;
}


/**
 * Get the OpenID identities for the specified user.
 *
 * @param mixed $id_or_name the username or ID.  If not provided, the current user will be used.
 * @return array array of user's OpenID identities
 * @access public
 * @since 3.0
 */
function get_user_openids($id_or_name = null) {
	$user = get_userdata_by_various($id_or_name);

	if ( $user ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare('SELECT url FROM '.seqrly_identity_table().' WHERE user_id = %s', $user->ID) );
	} else {
		return array();
	}
}


/**
 * Get the user associated with the specified OpenID.
 *
 * @param string $seqrly identifier to match
 * @return int|null ID of associated user, or null if no associated user
 * @access public
 * @since 3.0
 */
function get_user_by_seqrly($url) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare('SELECT user_id FROM '.seqrly_identity_table().' WHERE url = %s', $url) );
}


/**
 * Get a simple OpenID input field.
 *
 * @access public
 * @since 2.0
 */
function seqrly_input() {
	return '<input type="text" id="openid_identifier" name="openid_identifier" />';
}


/**
 * Convenience method to get user data by ID, username, or from current user.
 *
 * @param mixed $id_or_name the username or ID.  If not provided, the current user will be used.
 * @return bool|object False on failure, User DB row object
 * @access public
 * @since 3.0
 */
if (!function_exists('get_userdata_by_various')) :
function get_userdata_by_various($id_or_name = null) {
	if ( $id_or_name === null ) {
		$user = wp_get_current_user();
		if ($user == null) return false;
		return $user->data;
	} else if ( is_numeric($id_or_name) ) {
		return get_userdata($id_or_name);
	} else {
		return get_userdatabylogin($id_or_name);
	}
}
endif;

// -- end of public functions


/**
 * Get the file for the plugin, including the path.  This method will handle the case where the 
 * actual plugin files do not reside within the WordPress directory on the filesystem (such as 
 * a symlink).  The standard value should be 'seqrly/seqrly.php' unless files or folders have
 * been renamed.
 *
 * @return string plugin file
 */
function seqrly_plugin_file() {
	static $file;

	if ( empty($file) ) {
		$path = 'seqrly';

		$base = plugin_basename(__FILE__);
		if ( $base != __FILE__ ) {
			$path = basename(dirname($base));
		}

		$file = $path . '/' . basename(__FILE__);
	}

	return $file;
}

