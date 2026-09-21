<?php
/**
 * Push WordPress content into rss.chat (POSSE).
 *
 * A published post with the native "chat" post format becomes a top-level
 * rss.chat item. A comment on a synced post becomes a reply. The rss.chat id
 * of each is stored as meta so backfeed and this class never loop.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publish hook + comment hook that mirror WordPress into rss.chat.
 */
class Syndication {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		// Two entry points so the post format is already saved when we check it:
		// classic/programmatic saves finish in wp_after_insert_post, while the
		// block editor (REST) sets the format after that, before rest_after_insert.
		// The "already synced" guard keeps a single save from pushing twice.
		\add_action( 'wp_after_insert_post', array( $this, 'push_from_insert' ), 10, 2 );
		\add_action( 'rest_after_insert_post', array( $this, 'push_from_rest' ), 10, 1 );
		\add_action( 'wp_insert_comment', array( $this, 'maybe_push_comment' ), 10, 2 );
		// PHP_INT_MAX so this runs after every theme/plugin has registered its
		// own post-format support, and we merge into the final list.
		\add_action( 'after_setup_theme', array( $this, 'ensure_chat_post_format' ), PHP_INT_MAX );
	}

	/**
	 * Make sure the "chat" post format is available whatever the active theme
	 * declares. Without it the format cannot be chosen in the editor, so the
	 * plugin's per-post opt-in would be unreachable.
	 *
	 * WordPress replaces the whole post-format list on each add_theme_support()
	 * call rather than adding to it, so this reads the current list and
	 * re-registers it with "chat" appended, never dropping the theme's formats.
	 *
	 * @return void
	 */
	public function ensure_chat_post_format() {
		$support  = \get_theme_support( 'post-formats' );
		$existing = ( \is_array( $support ) && isset( $support[0] ) && \is_array( $support[0] ) )
			? $support[0]
			: array();

		// The theme already offers chat; nothing to do.
		if ( \in_array( 'chat', $existing, true ) ) {
			return;
		}

		if ( empty( $existing ) ) {
			// Block themes (and some others) declare no post formats. Offer the
			// full standard set (which includes chat) rather than a lonely "Chat".
			$formats = \get_post_format_slugs();
			unset( $formats['standard'] );
			$formats = \array_values( $formats );
		} else {
			// Keep the theme's own formats and add chat.
			$formats   = $existing;
			$formats[] = 'chat';
		}

		\add_theme_support( 'post-formats', $formats );
	}

	/**
	 * Classic/programmatic entry point (the wp_after_insert_post action).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public function push_from_insert( $post_id, $post ) {
		$this->maybe_push_post( $post );
	}

	/**
	 * Block editor entry point (the rest_after_insert_post action), which fires
	 * after the post format has been saved.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	public function push_from_rest( $post ) {
		$this->maybe_push_post( $post );
	}

	/**
	 * Push a published post to rss.chat, once.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private function maybe_push_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( \wp_is_post_revision( $post->ID ) || \wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! \in_array( $post->post_type, Plugin::supported_post_types(), true ) ) {
			return;
		}

		if ( '' !== $post->post_password ) {
			return;
		}

		/**
		 * Filters whether a post is pushed to rss.chat.
		 *
		 * @param bool     $syndicate Whether to push this post to rss.chat.
		 * @param \WP_Post $post      The post.
		 */
		if ( ! \apply_filters( 'rss_chat_should_syndicate', 'chat' === \get_post_format( $post ), $post ) ) {
			return;
		}

		if ( ! Plugin::is_connected() ) {
			return;
		}

		// Already synced: don't create a duplicate.
		if ( '' !== (string) \get_post_meta( $post->ID, Plugin::META_ID, true ) ) {
			return;
		}

		$item = array(
			'description' => \apply_filters( 'the_content', $post->post_content ),
		);

		// The canonical WordPress permalink. The server stores it on the
		// item and feeds emit it, which is what lets a reply's Webmention
		// find its way back to this post.
		$permalink = \get_permalink( $post );
		if ( \is_string( $permalink ) && '' !== $permalink ) {
			$item['link'] = $permalink;
		}

		$title = \get_the_title( $post );
		if ( '' !== $title ) {
			$item['title'] = $title;
		}

		/**
		 * Filters the item payload sent to rss.chat's /newpost.
		 *
		 * @param array    $item The item payload.
		 * @param \WP_Post $post The post being pushed.
		 */
		$item = (array) \apply_filters( 'rss_chat_post_item', $item, $post );

		$result = ( new API() )->new_post( $item );
		if ( \is_wp_error( $result ) ) {
			return;
		}

		$this->store_result_ids(
			$result,
			function ( $key, $value ) use ( $post ) {
				\update_post_meta( $post->ID, $key, $value );
			}
		);
	}

	/**
	 * Push an approved comment on a synced post as an rss.chat reply.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    The comment.
	 * @return void
	 */
	public function maybe_push_comment( $comment_id, $comment ) {
		// Comments created by backfeed must never be pushed back.
		if ( Backfeed::$importing ) {
			return;
		}

		if ( 1 !== (int) $comment->comment_approved ) {
			return;
		}

		// Only comments written by a WordPress user on this site leave it.
		// Comments that arrived FROM another network (a Webmention, an
		// ActivityPub reply, a pingback) carry a non-comment type or a
		// `protocol` meta value; re-broadcasting them would echo the same
		// event across networks. A comment from the public form with nobody
		// logged in has no user either, so it stays home as well.
		if ( 'comment' !== $comment->comment_type ) {
			return;
		}

		if ( '' !== (string) \get_comment_meta( $comment_id, Plugin::META_PROTOCOL, true ) ) {
			return;
		}

		if ( 0 === (int) $comment->user_id ) {
			return;
		}

		if ( '' !== (string) \get_comment_meta( $comment_id, Plugin::META_GUID, true ) ) {
			return;
		}

		if ( ! Plugin::is_connected() ) {
			return;
		}

		$parent_id = $this->resolve_reply_target( $comment );
		if ( 0 === $parent_id ) {
			return;
		}

		/**
		 * Filters whether a comment is pushed to rss.chat as a reply.
		 *
		 * Runs last, so it only ever sees comments that would otherwise be
		 * pushed: approved, written by a user of this site, not yet on
		 * rss.chat, and with something on rss.chat to reply to.
		 *
		 * @param bool         $push    Whether to push this comment.
		 * @param \WP_Comment $comment The comment.
		 */
		if ( ! \apply_filters( 'rss_chat_should_push_comment', true, $comment ) ) {
			return;
		}

		$result = ( new API() )->new_post(
			array(
				'description' => \wpautop( $comment->comment_content ),
				// The server stores the parent in its "inReplyTo" field; "inReplyToNum"
				// is only the name a reply carries when read back, and older self-hosted
				// instances do not accept it on write. Always post as "inReplyTo".
				'inReplyTo'   => $parent_id,
			)
		);
		if ( \is_wp_error( $result ) ) {
			return;
		}

		$this->store_result_ids(
			$result,
			function ( $key, $value ) use ( $comment_id ) {
				\update_comment_meta( $comment_id, $key, $value );
			}
		);
	}

	/**
	 * Find the rss.chat id this comment is replying to: the parent comment's
	 * synced id for a reply, the post's synced id for a top-level comment.
	 *
	 * A reply to a comment that never went to rss.chat gets 0, not the post:
	 * sent as a top-level reply it would answer something that is not on the
	 * network, which is only confusing without the context.
	 *
	 * @param \WP_Comment $comment The comment.
	 * @return int rss.chat id, or 0 if none applies.
	 */
	private function resolve_reply_target( $comment ) {
		if ( (int) $comment->comment_parent > 0 ) {
			return (int) \get_comment_meta( $comment->comment_parent, Plugin::META_ID, true );
		}

		return (int) \get_post_meta( $comment->comment_post_ID, Plugin::META_ID, true );
	}

	/**
	 * Write the rss.chat id and guid from a /newpost response.
	 *
	 * The response shape lives here once; the caller's setter decides whether
	 * the ids land on a post or a comment.
	 *
	 * @param array    $result Decoded /newpost response.
	 * @param callable $store  Receives ( string $key, mixed $value ).
	 * @return void
	 */
	private function store_result_ids( array $result, callable $store ) {
		if ( isset( $result['id'] ) ) {
			$store( Plugin::META_ID, (int) $result['id'] );
		}

		if ( isset( $result['guid'] ) ) {
			$store( Plugin::META_GUID, $result['guid'] );
		}
	}
}
