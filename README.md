=== RSS Chat ===
Contributors: pfefferle
Tags: rss, chat, indieweb, social, feeds
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WordPress posts to the rss.chat network using the native chat post format, and get the replies back as comments.

== Description ==

RSS Chat connects your WordPress site to the [rss.chat](https://rss.chat) network by Dave Winer using WordPress primitives, not a separate chat app. It follows the POSSE + backfeed pattern:

* Write a post with the built-in **chat** post format. On publish it is pushed to rss.chat.
* Replies to it are pulled back on a schedule and stored as **comments**.
* A comment you write on a synced post is pushed back as an rss.chat reply.

No custom post type, no new admin screen, no local copy of the network. One WordPress site = one rss.chat identity (the site owner). Sign in with a passwordless link sent to your WordPress admin email, under **Settings → RSS Chat**. The default server is `https://rss.chat`; you can point the plugin at a self-hosted instance.

This is an early draft (0.1.0).

== Installation ==

1. Upload the `rss-chat` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings → RSS Chat**, click **Send login link**, and open the link rss.chat sends to your admin email.
4. Publish a post with the **chat** post format.

== Changelog ==

= 0.1.0 =
* Initial draft: login, POSSE chat-format posts, comment replies, cron backfeed.
