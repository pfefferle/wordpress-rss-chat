<?php
/**
 * Pull rss.chat replies back into WordPress as comments (backfeed).
 *
 * A wp-cron job walks every post that was pushed to rss.chat, fetches its
 * replies, and stores new ones as comments. Threading is reconstructed from
 * the reply's inReplyToNum. Everything is deduped by the rss.chat guid.
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

		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
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
		if ( ! Plugin::is_connected() ) {
			return;
		}

		$posts = \get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => Syndication::POST_META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$own = Plugin::get_account()['screenname'];

		foreach ( $posts as $post_id ) {
			$rss_id = (int) \get_post_meta( $post_id, Syndication::POST_META_ID, true );
			if ( $rss_id <= 0 ) {
				continue;
			}
			$this->import_replies( $post_id, $rss_id, $own );
		}
	}

	/**
	 * Import the replies of one synced post.
	 *
	 * @param int    $post_id Local post id.
	 * @param int    $rss_id  rss.chat id of the post.
	 * @param string $own     Our own screen name, whose replies we skip.
	 * @return void
	 */
	private function import_replies( $post_id, $rss_id, $own ) {
		$items = ( new API() )->get_item_and_replies( $rss_id );
		if ( \is_wp_error( $items ) || ! \is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( ! \is_array( $item ) || empty( $item['guid'] ) ) {
				continue;
			}
			// The array leads with the post itself; skip it.
			if ( isset( $item['id'] ) && (int) $item['id'] === $rss_id ) {
				continue;
			}
			// Skip our own replies; they originated in WordPress.
			if ( '' !== $own && isset( $item['screenname'] ) && $item['screenname'] === $own ) {
				continue;
			}
			if ( $this->already_imported( $item['guid'] ) ) {
				continue;
			}

			$this->insert_comment( $post_id, $item );
		}
	}

	/**
	 * Whether a reply with this guid is already stored.
	 *
	 * @param string $guid rss.chat guid.
	 * @return bool
	 */
	private function already_imported( $guid ) {
		$existing = \get_comments(
			array(
				'meta_key'   => Syndication::POST_META_GUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $guid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		return ! empty( $existing );
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

		\update_comment_meta( $comment_id, 'protocol', Plugin::PROTOCOL );
		\update_comment_meta( $comment_id, Syndication::POST_META_GUID, $item['guid'] );
		if ( isset( $item['id'] ) ) {
			\update_comment_meta( $comment_id, Syndication::POST_META_ID, (int) $item['id'] );
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

		$parents = \get_comments(
			array(
				'meta_key'   => Syndication::POST_META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (int) $item['inReplyToNum'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return empty( $parents ) ? 0 : (int) $parents[0];
	}
}
