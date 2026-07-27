=== RSS Chat ===
Contributors: pfefferle
Tags: rss, chat, indieweb, social, feeds
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Participate in the rss.chat network from inside WordPress — read the network, post, and reply, with live updates.

== Description ==

RSS Chat turns your WordPress admin into a client for the [rss.chat](https://rss.chat) network by Dave Winer — a small chat network built on RSS 2.0 feeds and websockets.

One WordPress site = one rss.chat identity (the site owner). From **RSS Chat** in wp-admin you can:

* Sign in to rss.chat with a passwordless email link.
* Read the most recent posts on the network.
* Post to the network and reply to others.
* See new posts arrive live via the rss.chat firehose (with polling fallback).

Your credential is stored server-side and never exposed to the browser. The default server is `https://rss.chat`; you can point the plugin at a self-hosted rss.chat instance in **RSS Chat → Settings**.

This is an early draft (0.1.0). Media upload, likes UI polish, and publishing WordPress posts into rss.chat are planned.

== Installation ==

1. Upload the `rss-chat` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **RSS Chat → Settings**, enter your email, and open the login link rss.chat sends you.
4. Open **RSS Chat** to start reading and posting.

== Changelog ==

= 0.1.0 =
* Initial draft: login, read recent, post, reply, live firehose.
