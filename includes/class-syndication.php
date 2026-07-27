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

	const POST_META_ID   = '_rss_chat_id';
	const POST_META_GUID = '_rss_chat_guid';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'transition_post_status', array( $this, 'maybe_push_post' ), 10, 3 );
		\add_action( 'wp_insert_comment', array( $this, 'maybe_push_comment' ), 10, 2 );
	}

	/**
	 * Push a chat-format post when it first becomes published.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function maybe_push_post( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( 'chat' !== \get_post_format( $post ) ) {
			return;
		}
		if ( ! Plugin::is_connected() ) {
			return;
		}
		// Already synced (e.g. a re-publish): don't create a duplicate.
		if ( '' !== (string) \get_post_meta( $post->ID, self::POST_META_ID, true ) ) {
			return;
		}

		$item = array(
			'description' => \apply_filters( 'the_content', $post->post_content ),
		);

		$title = \get_the_title( $post );
		if ( '' !== $title ) {
			$item['title'] = $title;
		}

		$result = ( new API() )->new_post( $item );
		if ( \is_wp_error( $result ) ) {
			return;
		}

		$this->store_post_ids( $post->ID, $result );
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
		if ( '' !== (string) \get_comment_meta( $comment_id, self::POST_META_GUID, true ) ) {
			return;
		}
		if ( ! Plugin::is_connected() ) {
			return;
		}

		$parent_id = $this->resolve_reply_target( $comment );
		if ( 0 === $parent_id ) {
			return;
		}

		$result = ( new API() )->new_post(
			array(
				'description'  => \wpautop( $comment->comment_content ),
				'inReplyToNum' => $parent_id,
			)
		);
		if ( \is_wp_error( $result ) ) {
			return;
		}

		if ( isset( $result['id'] ) ) {
			\update_comment_meta( $comment_id, self::POST_META_ID, (int) $result['id'] );
		}
		if ( isset( $result['guid'] ) ) {
			\update_comment_meta( $comment_id, self::POST_META_GUID, $result['guid'] );
		}
	}

	/**
	 * Find the rss.chat id this comment is replying to: the parent comment's
	 * synced id if it has one, otherwise the post's synced id.
	 *
	 * @param \WP_Comment $comment The comment.
	 * @return int rss.chat id, or 0 if none applies.
	 */
	private function resolve_reply_target( $comment ) {
		if ( (int) $comment->comment_parent > 0 ) {
			$parent = (int) \get_comment_meta( $comment->comment_parent, self::POST_META_ID, true );
			if ( $parent > 0 ) {
				return $parent;
			}
		}

		return (int) \get_post_meta( $comment->comment_post_ID, self::POST_META_ID, true );
	}

	/**
	 * Persist the rss.chat id and guid returned for a pushed post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $result  Decoded /newpost response.
	 * @return void
	 */
	private function store_post_ids( $post_id, array $result ) {
		if ( isset( $result['id'] ) ) {
			\update_post_meta( $post_id, self::POST_META_ID, (int) $result['id'] );
		}
		if ( isset( $result['guid'] ) ) {
			\update_post_meta( $post_id, self::POST_META_GUID, $result['guid'] );
		}
	}
}
