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
		// wp_after_insert_post (not transition_post_status) so the post format
		// term is already saved when we check it; the block editor writes terms
		// after the status transition.
		\add_action( 'wp_after_insert_post', array( $this, 'maybe_push_post' ), 10, 4 );
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
	 * Push a chat-format post when it first becomes published.
	 *
	 * @param int           $post_id     Post id.
	 * @param \WP_Post      $post        The post.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before The post before this save, if any.
	 * @return void
	 */
	public function maybe_push_post( $post_id, $post, $update, $post_before ) {
		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		// Only on the transition into publish, not on later edits.
		if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
			return;
		}
		if ( 'chat' !== \get_post_format( $post ) ) {
			return;
		}
		if ( ! Plugin::is_connected() ) {
			return;
		}
		// Already synced: don't create a duplicate.
		if ( '' !== (string) \get_post_meta( $post_id, Plugin::META_ID, true ) ) {
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
