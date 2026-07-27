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
		\add_action( 'transition_post_status', array( $this, 'maybe_push_post' ), 10, 3 );
		\add_action( 'wp_insert_comment', array( $this, 'maybe_push_comment' ), 10, 2 );
		\add_action( 'after_setup_theme', array( $this, 'ensure_chat_post_format' ), 99 );
	}

	/**
	 * Make sure the "chat" post format is available whatever the active theme
	 * declares. Without it the format cannot be chosen in the editor, so the
	 * plugin's per-post opt-in would be unreachable. Runs late so it merges with,
	 * rather than replaces, the theme's own post-format support.
	 *
	 * @return void
	 */
	public function ensure_chat_post_format() {
		$support = \get_theme_support( 'post-formats' );
		$formats = ( \is_array( $support ) && isset( $support[0] ) && \is_array( $support[0] ) )
			? $support[0]
			: array();

		if ( \in_array( 'chat', $formats, true ) ) {
			return;
		}

		$formats[] = 'chat';
		\add_theme_support( 'post-formats', $formats );
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
		if ( '' !== (string) \get_post_meta( $post->ID, Plugin::META_ID, true ) ) {
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

		$result = ( new API() )->new_post(
			array(
				'description'  => \wpautop( $comment->comment_content ),
				'inReplyToNum' => $parent_id,
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
	 * synced id if it has one, otherwise the post's synced id.
	 *
	 * @param \WP_Comment $comment The comment.
	 * @return int rss.chat id, or 0 if none applies.
	 */
	private function resolve_reply_target( $comment ) {
		if ( (int) $comment->comment_parent > 0 ) {
			$parent = (int) \get_comment_meta( $comment->comment_parent, Plugin::META_ID, true );
			if ( $parent > 0 ) {
				return $parent;
			}
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
