# RSS Chat

- Contributors: pfefferle
- Donate link: https://notiz.blog/donate/
- Tags: rss, chat, indieweb, social, feeds
- Requires at least: 6.4
- Tested up to: 6.8
- Requires PHP: 7.4
- Stable tag: 0.1.0
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WordPress posts to the rss.chat network using the native chat post format, and get the replies back as comments.

## Description

[rss.chat](https://rss.chat) is a small chat network by Dave Winer, built on RSS 2.0 feeds. This plugin connects your WordPress site to it using the WordPress you already have, your posts and comments. There is no separate chat app, no custom post type, and no local copy of the network.

It follows the POSSE + backfeed pattern:

* Write a post and give it the built-in **chat** post format. When you publish it, the post is pushed to rss.chat.
* Replies to it are pulled back on a schedule and stored as **comments** on that post, keeping the thread structure.
* A comment you write on one of your synced posts is pushed back to rss.chat as a reply.

Your site is one identity on the network, the site owner. You sign in once with a passwordless link sent to your WordPress admin email; no password is stored. Posts in any other format, and pages, are left alone, so the chat format is your explicit opt-in per post.

### How it works

1. Publishing a chat-format post sends it to rss.chat and remembers its id, so replies can find their way home.
2. Every few minutes a background task checks your pushed posts for new replies and stores them as comments. Imported comments are marked with a `protocol` meta of `rss.chat`, the same convention the ActivityPub plugin uses, and are never sent back out.
3. Replies you write from WordPress travel the other way: a comment on a synced post becomes a reply on rss.chat.

The plugin also decorates your RSS 2.0 feed with the rss.chat `source:` vocabulary for chat-format posts, so your feed is self-describing on the network.

By default you connect to `https://demo.rss.chat`, the public instance. You can point the plugin at a self-hosted one in the settings.

This is an early draft (0.1.0).

## Installation

1. Upload the `rss-chat` folder to `/wp-content/plugins/`, or install it with Composer (`composer require pfefferle/wordpress-rss-chat`).
2. Activate the plugin.
3. Go to **Settings &rarr; RSS Chat**, click **Send login link**, and open the link rss.chat sends to your admin email.
4. Publish a post with the **chat** post format.

## Frequently Asked Questions

### Which posts get published?

Only posts with the built-in **chat** post format, and only when they are first published. Pages, and posts in any other format, are never sent.

Other plugins can widen or narrow that with the `rss_chat_should_syndicate` filter:

```php
// Send posts in the "note" kind, whatever post format they carry.
add_filter(
	'rss_chat_should_syndicate',
	function ( $syndicate, $post ) {
		return $syndicate || has_term( 'note', 'kind', $post );
	},
	10,
	2
);
```

The revision, post type and post status guards still apply (password-protected posts are never sent), and a post that has already been sent is never sent twice.

Three more hooks round out the surface:

- `rss_chat_post_item` filters the item payload before it goes to `/newpost`. The payload now carries the post's canonical permalink as `link`, which servers store and feed out — it's what lets a reply on the network point back at the WordPress post.
- `rss_chat_should_push_comment` filters whether a comment is pushed as a reply. Only comments written by a logged-in user of the site are pushed at all. Comments that arrived from another network (a Webmention, an ActivityPub reply, a pingback: anything with a non-comment type or a `protocol` meta value) and comments from the public form without a user never leave the site, so bridged networks can't echo the same event back and forth and nothing goes out under the site owner's name that they didn't write. A reply to a comment that was not pushed stays home too. The filter runs last, so it only sees comments that would otherwise go out.
- `rss_chat_backfeed_enabled` switches the reply importer off, for sites that receive replies another way and must not store them twice.

### Does this store a copy of the network in WordPress?

No. Only your own posts and the replies to them live in WordPress, as regular posts and comments. There is no custom post type and no local mirror of the wider network.

### Do I need an rss.chat account?

You sign in once with a passwordless link sent to your WordPress admin email. The email address is not editable; it is always your admin email, so the site keeps a single identity.

### Can I use my own rss.chat server?

Yes. Change the server URL under **Settings &rarr; RSS Chat** to point at a self-hosted instance. The default is `https://demo.rss.chat`, the public instance. `https://rss.chat` itself is a small private one.

### How do I know the connection still works?

Once signed in, **Settings &rarr; RSS Chat** has a **Test connection** button next to Disconnect. It asks the server for your account and reports the server version and your feed URL, or the server's reason when that fails. It checks that the server is reachable and knows your account; rss.chat has no side-effect-free way to verify the stored credential itself.

### How quickly do replies show up?

Replies are pulled by a background task that runs every five minutes, so they arrive shortly after they are posted, not in real time.

### What is the feed decoration for?

Chat-format posts get the rss.chat `source:` vocabulary added to your RSS 2.0 feed (identity, markdown body, a pointer to their replies feed). It makes your feed self-describing for other tools on the network. It is additive and not required for posting or backfeed to work.

## Changelog

### 0.1.0

* Initial draft: login, POSSE chat-format posts, comment replies, cron backfeed, and RSS feed decoration.
