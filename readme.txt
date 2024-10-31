=== Seqrly ===
Contributors: seqrly
Tags: seqrly, openid, authentication, login, comments
Requires at least: 2.8
Tested up to: 3.5
Stable tag: trunk
License: GPLv2 or later

Allow user login or registration using Seqrly service. Additionally, OpenIDs for authentication of users and comments is also supported.

== Description ==

Seqrly is an innovative site that specializes in providing secure technology choices for users to secure their online logins. Seqrly puts the control of one's identity in the individual's hands, allowing them to secure their logins in their own way.

Seqrly supports OpenID for logins. OpenID is an [open standard][] that allows users to authenticate to websites
without having to create a new password.  This plugin allows users to login to
their local WordPress account using an OpenID, as well as enabling commenters
to leave authenticated comments with OpenID.  The plugin also includes an OpenID
provider, enabling users to login to OpenID-enabled sites using their
own personal WordPress account. [XRDS-Simple][] is required for the OpenID
Provider and some features of the OpenID Consumer.

[open standard]: http://openid.net/
[XRDS-Simple]: http://wordpress.org/extend/plugins/xrds-simple/

== Installation ==

This plugin follows the [standard WordPress installation method][]:

1. Upload the `seqrly` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Configure the plugin through the 'Seqrly' section of the 'Options' menu

[standard WordPress installation method]: http://codex.wordpress.org/Managing_Plugins#Installing_Plugins


== Frequently Asked Questions ==

= Why do I get blank screens when I activate the plugin? =

In some cases the plugin may have problems if not enough memory has been
allocated to PHP.  Try ensuring that the PHP memory\_limit is at least 8MB
(limits of 64MB are not uncommon).

= Why don't `https` OpenIDs work? =

SSL certificate problems creep up when working with some OpenID providers
(namely MyOpenID).  This is typically due to an outdated CA cert bundle being
used by libcurl.  An explanation of the problem and a couple of solutions 
can be found [here][libcurl].

[libcurl]: http://lists.openidenabled.com/pipermail/dev/2007-August/000784.html

= Why do I get the error "Invalid openid.mode '<No mode set>'"? =

There are actually a couple of reasons that can cause this, but it seems one of
the more common causes is a conflict with certain mod_security rules.  See 
[this blog post][ioni2] for instructions on how to resolve this issue.

[ioni2]: http://ioni2.com/2009/wordpress-openid-login-failed-invalid-openid-mode-no-mode-set-solved-for-both-wordpress-and-drupal/


= How do I use SSL for OpenID transactions? =

First, be aware that this only works in WordPress 2.6 and up.  Make sure you've
turned on SSL in WordPress by [defining either of the following][wp-ssl]
globals as "true" in your `wp-config.php` file:

 - FORCE\_SSL\_LOGIN
 - FORCE\_SSL\_ADMIN

Then, also define the following global as "true" in your `wp-config.php` file:

 - OPENID\_SSL

Be aware that you will almost certainly have trouble with this if you are not
using a certificate purchased from a well-known certificate authority.

[wp-ssl]: http://codex.wordpress.org/Administration_Over_SSL

= How do I get help if I have a problem? =

Please direct support questions to the "Plugins and Hacks" section of the
[WordPress.org Support Forum][].  Just make sure and include the tag 'seqrly'
so that we'll see your post.  Additionally, you can report a bug at <http://seqrly.com/contact>.  
Also, you can send an email to support@seqrly.com.

[WordPress.org Support Forum]: http://wordpress.org/support/


== Screenshots ==

1. Commentors can use their Seqrly or OpenID when leaving a comment
2. Users can register with their Seqrly in addition to username/email.
3. This plugin also supports other OpenID providers. So users can register with other OpenID providers (if configured to).
4. Users can login with their Seqrly in addition to username/password.
5. This plugin also supports other OpenID providers. So users can login with other OpenID providers (if configured to).


== Changelog ==

= version 1.0 (Jan 20, 2013) =
 - first release


Full SVN logs are available at <http://dev.wp-plugins.org/log/seqrly/>.

The original Seqrly plugin for WordPress was derived from the OpenID plugin authored by willnorris and factoryjoe. LiberatID, Inc. expresses their deep gratitude for their work, without which the Seqrly plugin could not have been released in record time.
