<?php
/**
 * store.php
 *
 * Database Connector for WordPress Seqrly plugin for OpenID
 * Dual Licence: GPL & Modified BSD
 */

require_once 'Auth/OpenID/Interface.php';
require_once 'Auth/OpenID/Association.php';

if (!class_exists('WordPress_Seqrly_OptionStore')):
/**
 * OpenID store that uses the WordPress options table for storage.  Originally 
 * written by Simon Willison for use in the mu-open-id plugin.  Modified a fair 
 * amount for use in WordPress OpenID.
 */
class WordPress_Seqrly_OptionStore extends Auth_OpenID_OpenIDStore {

	function storeAssociation($server_url, $association) {
		$key = $this->_getAssociationKey($server_url, $association->handle);
		$association_s = $association->serialize();
		// prevent the likelihood of a race condition - don't rely on cache
		wp_cache_delete('seqrly_associations', 'options');
		$associations = get_option('seqrly_associations');
		if ($associations == null) {
			$associations = array();
		}
		$associations[$key] = $association_s;
		update_option('seqrly_associations', $associations);
	}

	function getAssociation($server_url, $handle = null) {
		//wp_cache_delete('seqrly_associations', 'options');
		if ($handle === null) {
			$handle = '';
		}
		$key = $this->_getAssociationKey($server_url, $handle);
		$associations = get_option('seqrly_associations');
		if ($handle && array_key_exists($key, $associations)) {
			return Auth_OpenID_Association::deserialize(
				'Auth_OpenID_Association', $associations[$key]
			);
		} else {
			// Return the most recently issued association
			$matching_keys = array();
			foreach (array_keys($associations) as $assoc_key) {
				if (strpos($assoc_key, $key) === 0) {
					$matching_keys[] = $assoc_key;
				}
			}
			$matching_associations = array();
			// sort by time issued
			foreach ($matching_keys as $assoc_key) {
				if (array_key_exists($assoc_key, $associations)) {
					$association = Auth_OpenID_Association::deserialize(
						'Auth_OpenID_Association', $associations[$assoc_key]
					);
				}
				if ($association !== null) {
					$matching_associations[] = array(
						$association->issued, $association
					);
				}
			}
			$issued = array();
			$assocs = array();
			foreach ($matching_associations as $assoc_key => $assoc) {
				$issued[$assoc_key] = $assoc[0];
				$assocs[$assoc_key] = $assoc[1];
			}
			array_multisort($issued, SORT_DESC, $assocs, SORT_DESC,
							$matching_associations);

			// return the most recently issued one.
			if ($matching_associations) {
				list($issued, $assoc) = $matching_associations[0];
				return $assoc;
			} else {
				return null;
			}
		}
	}

	function _getAssociationKey($server_url, $handle) {
		if (strpos($server_url, '://') === false) {
			trigger_error(sprintf("Bad server URL: %s", $server_url),
						  E_USER_WARNING);
			return null;
		}
		list($proto, $rest) = explode('://', $server_url, 2);
		$parts = explode('/', $rest);
		$domain = $parts[0];
		$url_hash = base64_encode($server_url);
		if ($handle) {
			$handle_hash = base64_encode($handle);
		} else {
			$handle_hash = '';
		}
		return sprintf('%s-%s-%s-%s',
			$proto, $domain, $url_hash, $handle_hash);
	}

	function removeAssociation($server_url, $handle) {
		// Remove the matching association if it's found, and
		// returns whether the association was removed or not.
		$key = $this->_getAssociationKey($server_url, $handle);
		$assoc = $this->getAssociation($server_url, $handle);
		if ($assoc === null) {
			return false;
		} else {
			$associations = get_option('seqrly_associations');
			if (isset($associations[$key])) {
				unset($associations[$key]);
				update_option('seqrly_associations', $associations);
				return true;
			} else {
				return false;
			}
		}		
	}
	
	function useNonce($server_url, $timestamp, $salt) {
		global $Auth_OpenID_SKEW;

		if ( abs($timestamp - time()) > $Auth_OpenID_SKEW ) {
			return false;
		}

		$key = $this->_getNonceKey($server_url, $timestamp, $salt);

		// prevent the likelihood of a race condition - don't rely on cache
		wp_cache_delete('seqrly_nonces', 'options');
		$nonces = get_option('seqrly_nonces');
		if ($nonces == null) {
			$nonces = array();
		}

		if (array_key_exists($key, $nonces)) {
			return false;
		} else {
			$nonces[$key] = $timestamp;
			update_option('seqrly_nonces', $nonces);
			return true;
		}
	}

	function _getNonceKey($server_url, $timestamp, $salt) {
		if ($server_url) {
			list($proto, $rest) = explode('://', $server_url, 2);
		} else {
			$proto = '';
			$rest = '';
		}

		$parts = explode('/', $rest, 2);
		$domain = $parts[0];
		$url_hash = base64_encode($server_url);
		$salt_hash = base64_encode($salt);

		return sprintf('%08x-%s-%s-%s-%s', $timestamp, $proto, 
			$domain, $url_hash, $salt_hash);
	}

	function cleanupNonces() { 
		global $Auth_OpenID_SKEW;

		$nonces = get_option('seqrly_nonces');

		foreach ($nonces as $nonce => $time) {
			if ( abs($time - time()) > $Auth_OpenID_SKEW ) {
				unset($nonces[$nonce]);
			}
		}

		update_option('seqrly_nonces', $nonces);
	}

	function cleanupAssociations() { 
		$associations = get_option('seqrly_associations');

		foreach ($associations as $key => $assoc_s) {
			$assoc = Auth_OpenID_Association::deserialize('Auth_OpenID_Association', $assoc_s);

			if ( $assoc->getExpiresIn() == 0) {
				unset($associations[$key]);
			}
		}

		update_option('seqrly_associations', $associations);
	}

	function reset() { 
		update_option('seqrly_nonces', array());
		update_option('seqrly_associations', array());
	}
}
endif;


/**
 * Check to see whether the nonce, association, and identity tables exist.
 *
 * @param bool $retry if true, tables will try to be recreated if they are not okay
 * @return bool if tables are okay
 */
function seqrly_check_tables($retry=true) {
	global $wpdb;

	$ok = true;
	$message = array();
	$tables = array(
		seqrly_identity_table(),
	);
	foreach( $tables as $t ) {
		if( $wpdb->get_var("SHOW TABLES LIKE '$t'") != $t ) {
			$ok = false;
			$message[] = "Table $t doesn't exist.";
		} else {
			$message[] = "Table $t exists.";
		}
	}
		
	if( $retry and !$ok) {
		seqrly_create_tables();
		$ok = seqrly_check_tables( false );
	}
	return $ok;
}

/**
 * Create OpenID related tables in the WordPress database.
 */
function seqrly_create_tables()
{
	global $wpdb;

	$store = seqrly_getStore();

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	// Create the SQL and call the WP schema upgrade function
	$statements = array(
		"CREATE TABLE ".seqrly_identity_table()." (
		uurl_id bigint(20) NOT NULL auto_increment,
		user_id bigint(20) NOT NULL default '0',
		url text,
		hash char(32),
		PRIMARY KEY  (uurl_id),
		UNIQUE KEY uurl (hash),
		KEY url (url(30)),
		KEY user_id (user_id)
		)",
	);

	$sql = implode(';', $statements);
	dbDelta($sql);

	update_option('seqrly_db_revision', SEQRLY_DB_REVISION);
}


/**
 * Undo any database changes made by the OpenID plugin.  Do not attempt to preserve any data.
 */
function seqrly_delete_tables() {
	global $wpdb;
	$wpdb->query('DROP TABLE IF EXISTS ' . seqrly_identity_table());
	$wpdb->query( $wpdb->prepare('DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key=%s', 'seqrly_comments') );
	
	// old database changes... just to make sure
	$wpdb->query('DROP TABLE IF EXISTS ' . seqrly_table_prefix(true) . 'seqrly_nonces');
	$wpdb->query('DROP TABLE IF EXISTS ' . seqrly_table_prefix(true) . 'seqrly_associations');

	// clear old way of storing OpenID comments
	$seqrly_column = $wpdb->get_row('SHOW COLUMNS FROM ' . seqrly_table_prefix(true) . 'comments LIKE "seqrly"');
	if ($seqrly_column) {
		$wpdb->query('ALTER table ' . $comments_table . ' DROP COLUMN seqrly');
		$wpdb->query( $wpdb->prepare('UPDATE ' . $comments_table . ' SET comment_type=%s WHERE comment_type=%s', '', 'seqrly') );
	}
}


/**
 * Migrate old data to new locations.
 */
function seqrly_migrate_old_data() {
	global $wpdb;

	// remove old nonce and associations tables
	$wpdb->query('DROP TABLE IF EXISTS ' . seqrly_table_prefix(true) . 'seqrly_nonces');
	$wpdb->query('DROP TABLE IF EXISTS ' . seqrly_table_prefix(true) . 'seqrly_associations');
	
	$seqrly_column = $wpdb->get_row('SHOW COLUMNS FROM ' . seqrly_table_prefix(true) . 'comments LIKE "seqrly"');
	if ($seqrly_column) {
		// update old style of marking seqrly comments.  For performance reason, we 
		// migrate them en masse rather than using set_comment_seqrly()
		$comments_table = seqrly_table_prefix(true) . 'comments';
		$comment_data = $wpdb->get_results( $wpdb->prepare('SELECT comment_ID, comment_post_ID from ' . $comments_table . ' WHERE seqrly=%s OR comment_type=%s', 1, 'seqrly') );
		if (!empty($comment_data)) {
			$seqrly_comments = array();
			foreach ($comment_data as $comment) {
				if (!array_key_exists($comment->comment_post_ID, $seqrly_comments)) {
					$seqrly_comments[$comment->comment_post_ID] = array();
				}
				$seqrly_comments[$comment->comment_post_ID][] = $comment->comment_ID;
			}

			foreach ($seqrly_comments as $post_id => $comments) {
				$current = get_post_meta($post_id, 'seqrly_comments', true);
				if (!empty($current)) $comments = array_merge($comments, $current);
				update_post_meta($post_id, 'seqrly_comments', array_unique($comments));
			}
		}

		$wpdb->query('ALTER table ' . $comments_table . ' DROP COLUMN seqrly');
		$wpdb->query( $wpdb->prepare('UPDATE ' . $comments_table . ' SET comment_type=%s WHERE comment_type=%s', '', 'seqrly') );
	}


	// remove old style of marking seqrly users
	$usermeta_table = defined('CUSTOM_USER_META_TABLE') ? CUSTOM_USER_META_TABLE : seqrly_table_prefix() . 'usermeta'; 
	$wpdb->query( $wpdb->prepare('DELETE FROM ' . $usermeta_table . ' WHERE meta_key=%s OR meta_key=%s', 'has_seqrly', 'registered_with_seqrly') );
}

function seqrly_table_prefix($blog_specific = false) {
	global $wpdb;
	if (isset($wpdb->base_prefix)) {
		return $wpdb->base_prefix . ($blog_specific ? $wpdb->blogid . '_' : '');
	} else {
		return $wpdb->prefix;
	}
}

function seqrly_identity_table() { 
	return (defined('CUSTOM_SEQRLY_IDENTITY_TABLE') ? CUSTOM_SEQRLY_IDENTITY_TABLE : seqrly_table_prefix() . 'seqrly_identities'); 
}


?>
