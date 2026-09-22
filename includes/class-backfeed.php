<?php
/**
 * Pull rss.chat replies back into WordPress as comments (backfeed).
 *
 * A wp-cron job walks every post that was pushed to rss.chat, fetches its
 * replies, and stores new ones as comments. Threading is reconstructed from
 * the reply's inReplyToNum. Replies are deduped by the rss.chat guid. Likes
 * on the post come back too, as comments of type "like", keyed by item id
 * plus screenname since they carry no guid.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron-driven importer for rss.chat replies.
 */
class Backfeed {

	const HOOK     = 'rss_chat_backfeed';
	const INTERVAL = 'rss_chat_interval';

	/**
	 * True while this class is inserting comments, so Syndication does not
	 * push imported replies straight back to rss.chat.
	 *
	 * @var bool
	 */
	public static $importing = false;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		// A short interval is intentional: chat replies should arrive promptly.
		\add_filter( 'cron_schedules', array( $this, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		\add_action( self::HOOK, array( $this, 'run' ) );

		self::schedule();
	}

	/**
	 * Register a five-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_interval( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => \__( 'Every five minutes (RSS Chat)', 'rss-chat' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the cron event. Used on activation and lazily on init.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( \time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Clear the cron event. Used on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		\wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Import replies for every synced post.
	 *
	 * @return void
	 */
	public function run() {
		/**
		 * Filters whether the reply importer runs at all.
		 *
		 * A routing or bridge plugin that delivers replies another way (for
		 * example as verified Webmentions) can switch the importer off here
		 * so the same reply is never stored twice.
		 *
		 * @param bool $enabled Whether backfeed runs.
		 */
		if ( ! \apply_filters( 'rss_chat_backfeed_enabled', true ) ) {
			return;
		}

		if ( ! Plugin::is_connected() ) {
			return;
		}

		// Every registered type, not just the ones currently enabled for
		// publishing: an item that is already on rss.chat keeps getting its
		// replies even after its type is unticked. The synced id is the test.
		$posts = \get_posts(
			array(
				'post_type'      => \array_values( \get_post_types() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => Plugin::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		foreach ( $posts as $post_id ) {
			$rss_id = (int) \get_post_meta( $post_id, Plugin::META_ID, true );
			if ( $rss_id <= 0 ) {
				continue;
			}

			$this->import_replies( $post_id, $rss_id );
		}
	}

	/**
	 * Import the replies of one synced post.
	 *
	 * @param int $post_id Local post id.
	 * @param int $rss_id  rss.chat id of the post.
	 * @return void
	 */
	private function import_replies( $post_id, $rss_id ) {
		$items = ( new API() )->get_item_and_replies( $rss_id );
		if ( \is_wp_error( $items ) || ! \is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( ! \is_array( $item ) || empty( $item['guid'] ) ) {
				continue;
			}

			// The array leads with the post itself. Its likes are the one
			// thing we take from it; replies' likes stay on the network.
			if ( isset( $item['id'] ) && (int) $item['id'] === $rss_id ) {
				$this->import_likes( $post_id, $rss_id, $item );
				continue;
			}

			// The guid dedup below is the only loop guard we need: a reply that
			// WordPress pushed already carries its guid on a comment, so it is
			// skipped here. Replies the owner wrote directly on rss.chat, even
			// under the same account, have a guid we have not seen, so they come
			// home like anyone else's.
			if ( $this->already_imported( $item['guid'] ) ) {
				continue;
			}

			$this->insert_comment( $post_id, $item );
		}
	}

	/**
	 * Bring the post's likes in line with rss.chat: new likes become comments
	 * of type "like", likes taken back (togglelike) are deleted again.
	 *
	 * Likes have no guid, so each one is keyed by item id plus screenname.
	 * The list is only fetched when the item reports likes at all; at zero
	 * everything stored for the item goes.
	 *
	 * @param int   $post_id Local post id.
	 * @param int   $rss_id  rss.chat id of the post.
	 * @param array $item    The post's rss.chat item.
	 * @return void
	 */
	private function import_likes( $post_id, $rss_id, array $item ) {
		$likers = array();

		if ( ! empty( $item['ctLikes'] ) ) {
			$likers = ( new API() )->get_likers_list( $rss_id );
			// On a failed read leave the stored likes as they are, rather than
			// mistaking the error for "nobody likes this any more".
			if ( \is_wp_error( $likers ) || ! \is_array( $likers ) ) {
				return;
			}
		}

		$wanted = array();
		foreach ( $likers as $screenname ) {
			if ( \is_string( $screenname ) && '' !== $screenname ) {
				$wanted[ $rss_id . ':' . $screenname ] = $screenname;
			}
		}

		$stored = $this->stored_likes( $post_id, $rss_id );

		foreach ( \array_diff_key( $stored, $wanted ) as $comment_id ) {
			\wp_delete_comment( $comment_id, true );
		}

		foreach ( \array_diff_key( $wanted, $stored ) as $key => $screenname ) {
			$this->insert_like( $post_id, $screenname, $key );
		}
	}

	/**
	 * The like comments this plugin imported for one item, keyed by their
	 * META_LIKE value. Likes that came in another way (an ActivityPub like,
	 * say) carry no such meta and are left alone.
	 *
	 * @param int $post_id Local post id.
	 * @param int $rss_id  rss.chat id of the post.
	 * @return int[] Comment ids keyed by "<rss id>:<screenname>".
	 */
	private function stored_likes( $post_id, $rss_id ) {
		$comments = \get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => 'like',
				'status'     => 'all',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Plugin::META_LIKE,
						'value'   => $rss_id . ':',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$stored = array();
		foreach ( $comments as $comment ) {
			$key = (string) \get_comment_meta( $comment->comment_ID, Plugin::META_LIKE, true );
			if ( 0 === \strpos( $key, $rss_id . ':' ) ) {
				$stored[ $key ] = (int) $comment->comment_ID;
			}
		}
		return $stored;
	}

	/**
	 * Insert one like as a comment of type "like" (the ActivityPub plugin's
	 * convention, so themes that already render those pick these up too).
	 *
	 * @param int    $post_id    Local post id.
	 * @param string $screenname Screenname of the liker.
	 * @param string $key        Dedup key stored in META_LIKE.
	 * @return void
	 */
	private function insert_like( $post_id, $screenname, $key ) {
		// The liker's home link when they set one, else their rss.chat feed:
		// that is their identity on the network, and it is always there.
		$user = ( new API() )->get_user_data( $screenname );
		$url  = '';
		if ( \is_array( $user ) ) {
			foreach ( array( 'feedLink', 'feedUrl' ) as $field ) {
				if ( ! empty( $user[ $field ] ) && \is_string( $user[ $field ] ) ) {
					$url = $user[ $field ];
					break;
				}
			}
		}

		$commentdata = array(
			'comment_post_ID'    => $post_id,
			'comment_content'    => '',
			'comment_author'     => $screenname,
			'comment_author_url' => $url,
			'comment_parent'     => 0,
			'comment_approved'   => 1,
			'comment_type'       => 'like',
		);

		self::$importing = true;
		$comment_id      = \wp_insert_comment( $commentdata );
		self::$importing = false;

		if ( ! $comment_id ) {
			return;
		}

		\update_comment_meta( $comment_id, Plugin::META_PROTOCOL, Plugin::PROTOCOL );
		\update_comment_meta( $comment_id, Plugin::META_LIKE, $key );
	}

	/**
	 * Whether a reply with this guid is already stored.
	 *
	 * @param string $guid rss.chat guid.
	 * @return bool
	 */
	private function already_imported( $guid ) {
		return $this->find_comment_id_by_meta( Plugin::META_GUID, $guid ) > 0;
	}

	/**
	 * Find the first comment carrying a given meta key/value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return int Comment id, or 0 if none.
	 */
	private function find_comment_id_by_meta( $key, $value ) {
		$ids = \get_comments(
			array(
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Insert one reply as a comment, nested under its parent when known.
	 *
	 * @param int   $post_id Local post id.
	 * @param array $item    rss.chat reply item.
	 * @return void
	 */
	private function insert_comment( $post_id, array $item ) {
		$body = isset( $item['markdowntext'] ) && '' !== $item['markdowntext']
			? $item['markdowntext']
			: ( isset( $item['description'] ) ? \wp_strip_all_tags( $item['description'] ) : '' );

		$commentdata = array(
			'comment_post_ID'    => $post_id,
			'comment_content'    => $body,
			'comment_author'     => isset( $item['author'] ) ? $item['author'] : ( isset( $item['screenname'] ) ? $item['screenname'] : '' ),
			'comment_author_url' => isset( $item['feedLink'] ) ? $item['feedLink'] : '',
			'comment_parent'     => $this->local_parent( $item ),
			'comment_approved'   => 1,
			'comment_type'       => 'comment',
		);

		if ( isset( $item['pubDate'] ) ) {
			$ts = \strtotime( $item['pubDate'] );
			if ( $ts ) {
				$commentdata['comment_date_gmt'] = \gmdate( 'Y-m-d H:i:s', $ts );
				$commentdata['comment_date']     = \get_date_from_gmt( $commentdata['comment_date_gmt'] );
			}
		}

		self::$importing = true;
		$comment_id      = \wp_insert_comment( $commentdata );
		self::$importing = false;

		if ( ! $comment_id ) {
			return;
		}

		\update_comment_meta( $comment_id, Plugin::META_PROTOCOL, Plugin::PROTOCOL );
		\update_comment_meta( $comment_id, Plugin::META_GUID, $item['guid'] );

		if ( isset( $item['id'] ) ) {
			\update_comment_meta( $comment_id, Plugin::META_ID, (int) $item['id'] );
		}
	}

	/**
	 * Map a reply's rss.chat parent to a local comment, when we have imported
	 * that parent already. Otherwise the reply is a top-level comment.
	 *
	 * @param array $item rss.chat reply item.
	 * @return int Local comment id to nest under, or 0.
	 */
	private function local_parent( array $item ) {
		if ( empty( $item['inReplyToNum'] ) ) {
			return 0;
		}

		return $this->find_comment_id_by_meta( Plugin::META_ID, (int) $item['inReplyToNum'] );
	}
}
