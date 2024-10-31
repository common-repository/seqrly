<?php
/**
 * All the code required for handling OpenID comments.  These functions should not be considered public, 
 * and may change without notice.
 */


// -- WordPress Hooks
add_action( 'preprocess_comment', 'seqrly_process_comment', -90);
add_action( 'init', 'seqrly_setup_akismet');
add_action( 'akismet_spam_caught', 'seqrly_akismet_spam_caught');
add_action( 'comment_post', 'update_comment_seqrly', 5 );
add_filter( 'option_require_name_email', 'seqrly_option_require_name_email' );
add_action( 'sanitize_comment_cookies', 'seqrly_sanitize_comment_cookies', 15);
add_action( 'seqrly_finish_auth', 'seqrly_finish_comment', 10, 2 );
if( get_option('seqrly_enable_approval') ) {
	add_filter('pre_comment_approved', 'seqrly_comment_approval');
}
add_filter( 'get_comment_author_link', 'seqrly_comment_author_link');
if( get_option('seqrly_enable_commentform') ) {
	add_action( 'wp', 'seqrly_js_setup', 9);
	add_action( 'wp_footer', 'seqrly_comment_profilelink', 10);
	add_action( 'comment_form', 'seqrly_comment_form', 10);
}
add_filter( 'seqrly_user_data', 'seqrly_get_user_data_form', 6, 2);
add_action( 'delete_comment', 'unset_comment_seqrly' );

add_action( 'init', 'seqrly_recent_comments');


/**
 * Ensure akismet runs before OpenID.
 */
function seqrly_setup_akismet() {
	if (has_filter('preprocess_comment', 'akismet_auto_check_comment')) {
		remove_action('preprocess_comment', 'akismet_auto_check_comment', 1);
		add_action('preprocess_comment', 'akismet_auto_check_comment', -99);
	}
}


/**
 * Akismet caught this comment as spam, so no need to do OpenID discovery on the URL.
 */
function seqrly_akismet_spam_caught() {
	remove_action( 'preprocess_comment', 'seqrly_process_comment', -90);
}

/**
 * Intercept comment submission and check if it includes a valid OpenID.  If it does, save the entire POST
 * array and begin the OpenID authentication process.
 *
 * regarding comment_type: http://trac.wordpress.org/ticket/2659
 *
 * @param array $comment comment data
 * @return array comment data
 */
function seqrly_process_comment( $comment ) {
	if ( array_key_exists('seqrly_skip', $_REQUEST) && $_REQUEST['seqrly_skip'] ) return $comment;
	if ( $comment['comment_type'] != '' ) return $comment;
	
	if ( array_key_exists('openid_identifier', $_POST) ) {
            $seqrly_url = $_POST['openid_identifier'];
	} elseif ( $_REQUEST['login_with_seqrly'] ) {

            if ("seqrly" === $_POST['login_with_seqrly'] ) {
                $seqrly_url = 'https://login.seqrly.com';
            } else {
                if (get_option('seqrly_enable_other_openids')) {
                    $seqrly_url = $_POST['url'];
                }
            }
	}

	@session_start();
	unset($_SESSION['seqrly_posted_comment']);

	if ( !empty($seqrly_url) ) {  // Comment form's OpenID url is filled in.
		$_SESSION['seqrly_comment_post'] = $_POST;
		$_SESSION['seqrly_comment_post']['comment_author_seqrly'] = $seqrly_url;
		$_SESSION['seqrly_comment_post']['seqrly_skip'] = 1;

		seqrly_start_login($seqrly_url, 'comment');

		// Failure to redirect at all, the URL is malformed or unreachable.

		// Display an error message only if an explicit OpenID field was used.  Otherwise,
		// just ignore the error... it just means the user entered a normal URL.
		if (array_key_exists('openid_identifier', $_POST)) {
			seqrly_repost_comment_anonymously($_SESSION['seqrly_comment_post']);
		}
	}

	// duplicate name and email check from wp-comments-post.php
	if ( $comment['comment_type'] == '') {
		seqrly_require_name_email();
	}

	return $comment;
}


/**
 * Duplicated code from wp-comments-post.php to check for presence of comment author name and email 
 * address.
 */
function seqrly_require_name_email() {
	$user = wp_get_current_user();
	global $comment_author, $comment_author_email;

	if ( get_option('require_name_email') && !$user->ID ) { 
		if ( 6 > strlen($comment_author_email) || '' == $comment_author ) {
			wp_die( __('Error: please fill the required fields (name, email).', 'seqrly') );
		} elseif ( !is_email($comment_author_email)) {
			wp_die( __('Error: please enter a valid email address.', 'seqrly') );
		}
	}
}


/**
 * This filter callback simply approves all OpenID comments, but later it could do more complicated logic
 * like whitelists.
 *
 * @param string $approved comment approval status
 * @return string new comment approval status
 */
function seqrly_comment_approval($approved) {
	return ($_SESSION['seqrly_posted_comment'] ? 1 : $approved);
}


/**
 * If the comment contains a valid OpenID, skip the check for requiring a name and email address.  Even if
 * this data isn't provided in the form, we may get it through other methods, so we don't want to bail out
 * prematurely.  After OpenID authentication has completed (and $_REQUEST['seqrly_skip'] is set), we don't
 * interfere so that this data can be required if desired.
 *
 * @param boolean $value existing value of flag, whether to require name and email
 * @return boolean new value of flag, whether to require name and email
 * @see get_user_data
 */
function seqrly_option_require_name_email( $value ) {
	
	$comment_page = (defined('SEQRLY_COMMENTS_POST_PAGE') ? SEQRLY_COMMENTS_POST_PAGE : 'wp-comments-post.php');

	if ($GLOBALS['pagenow'] != $comment_page) {
		return $value;
	}

	if (array_key_exists('seqrly_skip', $_REQUEST) && $_REQUEST['seqrly_skip']) {
		return get_option('seqrly_no_require_name') ? false : $value;
	}
	
	// make sure we only process this once per request
	static $bypass;
	if ($bypass) {
		return $value;
	} else {
		$bypass = true;
	}


	if (array_key_exists('openid_identifier', $_POST)) {
		if( !empty( $_POST['openid_identifier'] ) ) {
			return false;
		}
	} else {
            if ($_REQUEST['login_with_seqrly']) {
                return false;
            }
		global $comment_author_url;
		if ( !empty($comment_author_url) ) {
			return false;
		}
	}

	return $value;
}


/**
 * Make sure that a user's OpenID is stored and retrieved properly.  This is important because the OpenID
 * may be an i-name, but WordPress is expecting the comment URL cookie to be a valid URL.
 *
 * @wordpress-action sanitize_comment_cookies
 */
function seqrly_sanitize_comment_cookies() {
	if ( isset($_COOKIE['comment_author_seqrly_'.COOKIEHASH]) ) {

		// this might be an i-name, so we don't want to run clean_url()
		remove_filter('pre_comment_author_url', 'clean_url');

		$comment_author_url = apply_filters('pre_comment_author_url',
		$_COOKIE['comment_author_seqrly_'.COOKIEHASH]);
		$comment_author_url = stripslashes($comment_author_url);
		$_COOKIE['comment_author_url_'.COOKIEHASH] = $comment_author_url;
	}
}


/**
 * Add OpenID class to author link.
 *
 * @filter: get_comment_author_link
 **/
function seqrly_comment_author_link( $html ) {
    $seqrlyString = 'seqrly';
    $seqrlyURL = stripos($html, $seqrlyString);
	if( is_comment_seqrly() ) {
		if (preg_match('/<a[^>]* class=[^>]+>/', $html)) {
                    if ($seqrlyURL === false) {
			return preg_replace( '/(<a[^>]* class=[\'"]?)/', '\\1openid_link ' , $html );
                    } else {
			return preg_replace( '/(<a[^>]* class=[\'"]?)/', '\\1seqrly_comment_icon ' , $html );
                    }
		} else {
                    if ($seqrlyURL === false) {
			return preg_replace( '/(<a[^>]*)/', '\\1 class="openid_link"' , $html );
                    } else {
			return preg_replace( '/(<a[^>]*)/', '\\1 class="seqrly_comment_icon"' , $html );
                    }
		}
	}
	return $html;
}


/**
 * Check if the comment was posted with OpenID, either directly or by an author registered with OpenID.  Update the comment accordingly.
 *
 * @action post_comment
 */
function update_comment_seqrly($comment_ID) {
	session_start();

	if ($_SESSION['seqrly_posted_comment']) {
		set_comment_seqrly($comment_ID);
		unset($_SESSION['seqrly_posted_comment']);
	} else {
		$comment = get_comment($comment_ID);

		if ( is_user_openid($comment->user_id) ) {
			set_comment_seqrly($comment_ID);
		}
	}

}


/**
 * Print jQuery call for slylizing profile link.
 *
 * @action: comment_form
 **/
function seqrly_comment_profilelink() {
	global $wp_scripts;

	if (comments_open() && is_user_openid() && $wp_scripts->query('seqrly')) {
                if (is_user_seqrly()) {
                    echo '<script type="text/javascript">stylize_profilelink_seqrly()</script>';
                } else {
                    echo '<script type="text/javascript">stylize_profilelink()</script>';
                }
	}
}


/**
 * Print jQuery call to modify comment form.
 *
 * @action: comment_form
 **/
function seqrly_comment_form() {
	global $wp_scripts;

	if (comments_open() && !is_user_logged_in() && isset($wp_scripts) && $wp_scripts->query('seqrly')) {
?>
            <span id="seqrly_comment"><br />
                <label style="margin-top: 20px;">
                    <?php _e('Comment using ', 'seqrly'); ?>
                    <?php if (get_option('seqrly_enable_other_openids')) { ?>
                        <input type="radio" name="login_with_seqrly" value="openID" style="width:20px;margin-left:12px;" /><span class="seqrly_link">OpenID</span>
                    <?php } ?>
                    <input type="radio" name="login_with_seqrly" value="seqrly" style="width:20px;margin-left:12px;" /><span class="commentWithSeqrly"><img src="<?php echo plugins_url('f/Seqrly16.png', __FILE__); ?>"</span>
                </label>
            </span>
            <script type="text/javascript">jQuery(function(){ add_seqrly_to_comment_form('<?php echo site_url('index.php') ?>', '<?php echo wp_create_nonce('seqrly_ajax') ?>') })</script>
<?php
	}
}


function seqrly_repost_comment_anonymously($post) {
	$comment_page = (defined('SEQRLY_COMMENTS_POST_PAGE') ? SEQRLY_COMMENTS_POST_PAGE : 'wp-comments-post.php');

	$html = '
	<h1>'.__('OpenID Authentication Error', 'seqrly').'</h1>
	<p id="error">'.__('We were unable to authenticate your claimed Seqrly / OpenID, however you '
	. 'can continue to post your comment without Seqrly / OpenID:', 'seqrly').'</p>

	<form action="' . site_url("/$comment_page") . '" method="post">
		<p>Name: <input name="author" value="'.$post['author'].'" /></p>
		<p>Email: <input name="email" value="'.$post['email'].'" /></p>
		<p>URL: <input name="url" value="'.$post['url'].'" /></p>
		<textarea name="comment" cols="80%" rows="10">'.stripslashes($post['comment']).'</textarea>
		<input type="submit" name="submit" value="'.__('Submit Comment').'" />';
	foreach ($post as $name => $value) {
		if (!in_array($name, array('author', 'email', 'url', 'comment', 'submit'))) {
			$html .= '
		<input type="hidden" name="'.$name.'" value="'.$value.'" />';
		}
	}
	
	$html .= '</form>';
	seqrly_page($html, __('Seqrly / OpenID Authentication Error', 'seqrly'));
}


/**
 * Action method for completing the 'comment' action.  This action is used when leaving a comment.
 *
 * @param string $identity_url verified OpenID URL
 */
function seqrly_finish_comment($identity_url, $action) {
	if ($action != 'comment') return;

	if (empty($identity_url)) {
		seqrly_repost_comment_anonymously($_SESSION['seqrly_comment_post']);
	}
		
	seqrly_set_current_user($identity_url);
		
	if (is_user_logged_in()) {
		// simulate an authenticated comment submission
		$_SESSION['seqrly_comment_post']['author'] = null;
		$_SESSION['seqrly_comment_post']['email'] = null;
		$_SESSION['seqrly_comment_post']['url'] = null;
	} else {
		// try to get user data from the verified OpenID
		$user_data =& seqrly_get_user_data($identity_url);

		if (!empty($user_data['display_name'])) {
			$_SESSION['seqrly_comment_post']['author'] = $user_data['display_name'];
		}
		if (!empty($user_data['user_email'])) {
			$_SESSION['seqrly_comment_post']['email'] = $user_data['user_email'];
		}
		$_SESSION['seqrly_comment_post']['url'] = $identity_url;
	}
		
	// record that we're about to post an OpenID authenticated comment.
	// We can't actually record it in the database until after the repost below.
	$_SESSION['seqrly_posted_comment'] = true;

	$comment_page = (defined('SEQRLY_COMMENTS_POST_PAGE') ? SEQRLY_COMMENTS_POST_PAGE : 'wp-comments-post.php');

	seqrly_repost(site_url("/$comment_page"), array_filter($_SESSION['seqrly_comment_post']));
}


/**
 * Mark the specified comment as an OpenID comment.
 *
 * @param int $id id of comment to set as OpenID
 */
function set_comment_seqrly($id) {
	$comment = get_comment($id);
	$seqrly_comments = get_post_meta($comment->comment_post_ID, 'seqrly_comments', true);
	if (!is_array($seqrly_comments)) {
		$seqrly_comments = array();
	}
	$seqrly_comments[] = $id;
	update_post_meta($comment->comment_post_ID, 'seqrly_comments', array_unique($seqrly_comments));
}


/**
 * Unmark the specified comment as an OpenID comment
 *
 * @param int $id id of comment to set as OpenID
 */
function unset_comment_seqrly($id) {
	$comment = get_comment($id);
	$seqrly_comments = get_post_meta($comment->comment_post_ID, 'seqrly_comments', true);

	if (is_array($seqrly_comments) && in_array($id, $seqrly_comments)) {
		$new = array();
		foreach($seqrly_comments as $c) {
			if ($c == $id) continue;
			$new[] = $c;
		}
		update_post_meta($comment->comment_post_ID, 'seqrly_comments', array_unique($new));
	}
}


/**
 * Retrieve user data from comment form.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function seqrly_get_user_data_form($data, $identity_url) {
	if ( array_key_exists('seqrly_comment_post', $_SESSION) ) {
		$comment = $_SESSION['seqrly_comment_post'];
	}

	if ( !isset($comment) || !$comment) {
		return $data;
	}

	if ($comment['email']) {
		$data['user_email'] = $comment['email'];
	}

	if ($comment['author']) {
		$data['nickname'] = $comment['author'];
		$data['user_nicename'] = $comment['author'];
		$data['display_name'] = $comment['author'];
	}

	return $data;
}


/**
 * Remove the CSS snippet added by the Recent Comments widget because it breaks entries that include the OpenID logo.
 */
function seqrly_recent_comments() {
	global $wp_widget_factory;

	if ( $wp_widget_factory && array_key_exists('WP_Widget_Recent_Comments', $wp_widget_factory->widgets) ) {
		// this is an ugly hack because remove_action doesn't actually work the way it should with objects
		foreach ( array_keys($GLOBALS['wp_filter']['wp_head'][10]) as $key ) {
			if ( strpos($key, 'WP_Widget_Recent_Commentsrecent_comments_style') === 0 ) {
				remove_action('wp_head', $key);
				return;
			}
		}
	}
}

?>
