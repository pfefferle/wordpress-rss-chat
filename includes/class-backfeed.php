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
	 * How many requests one run spends on likes, across all posts: reading a
	 * post's liker list costs one, storing a new liker one more, and asking
	 * after a liker the server would not hand over one more again. That keeps
	 * a cron run bounded when posts with many likes are synced for the first
	 * time; the rest follow on the next run.
	 */
	const LIKE_REQUESTS_PER_RUN = 20;

	/**
	 * Where the next run starts, so the budget does not always go to the same
	 * posts: the id of the post this run ran out of budget on.
	 */
	const OPTION_CURSOR = 'rss_chat_backfeed_cursor';

	/**
	 * Option row holding the lease that keeps two runs apart.
	 */
	const OPTION_LOCK = 'rss_chat_backfeed_lock';

	/**
	 * How long a run may hold the lease before another may take it over.
	 * Longer than the cron interval and than a slow run over a hundred posts,
	 * so a lease is only ever taken over from a run that really died.
	 */
	const LOCK_TTL = 600;

	/**
	 * The lease this run holds, empty when it holds none.
	 *
	 * @var string
	 */
	private $lease = '';

	/**
	 * Like requests this run may still make.
	 *
	 * @var int
	 */
	private $like_budget = self::LIKE_REQUESTS_PER_RUN;

	/**
	 * The post the budget ran out on, if any.
	 *
	 * @var int
	 */
	private $stalled_at = 0;

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
		/* A short interval is intentional: chat replies should arrive promptly. */
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

		/*
		 * wp-cron can start a second run while one is still going, and two
		 * runs walking the same posts would both find the same reply or like
		 * missing and store it twice.
		 */
		if ( ! $this->lock() ) {
			return;
		}

		try {
			$this->import_all();
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Take the lease, or report that another run holds it.
	 *
	 * INSERT IGNORE is what makes this atomic: the options table's unique
	 * index on option_name decides the winner, on every install, with no
	 * object cache required. An expired lease is taken over, so a run that
	 * died mid-flight blocks nothing for longer than LOCK_TTL.
	 *
	 * @return bool Whether this run owns the lease.
	 */
	public function lock() {
		global $wpdb;

		$lease = \wp_generate_uuid4() . '|' . ( \time() + self::LOCK_TTL );

		if ( $this->insert_lease( $lease ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$held = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
		);

		/*
		 * No row, so the insert failed on something other than the lease
		 * already being there, or it was given back in between. Try once
		 * more rather than leaving the importer stopped.
		 */
		if ( null === $held ) {
			return $this->insert_lease( $lease );
		}

		if ( \time() < (int) \substr( (string) $held, \strpos( (string) $held, '|' ) + 1 ) ) {
			return false;
		}

		/*
		 * The lease has run out. Take it over only if nobody else did first:
		 * the value we read is part of the condition.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$lease,
				self::OPTION_LOCK,
				(string) $held
			)
		);

		$this->forget_cached_lease();

		if ( 1 !== (int) $taken ) {
			return false;
		}

		$this->lease = $lease;

		return true;
	}

	/**
	 * Write the lease, unless one is already there.
	 *
	 * INSERT IGNORE is what makes this atomic: the options table's unique
	 * index on option_name decides the winner, on every install, with no
	 * object cache required.
	 *
	 * @param string $lease The lease to write.
	 * @return bool Whether this run wrote it.
	 */
	private function insert_lease( $lease ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::OPTION_LOCK,
				$lease,
				'no'
			)
		);

		$this->forget_cached_lease();

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		$this->lease = $lease;

		return true;
	}

	/**
	 * Drop what the object cache remembers about the lease row, which is
	 * written and read behind the options API.
	 *
	 * @return void
	 */
	private function forget_cached_lease() {
		\wp_cache_delete( self::OPTION_LOCK, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Give the lease back.
	 *
	 * @return void
	 */
	public function unlock() {
		global $wpdb;

		if ( '' === $this->lease ) {
			return;
		}

		/*
		 * Only ours. A run that overran its lease, and lost it to the run
		 * after it, must not take that one's lease away on its way out.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_LOCK,
				$this->lease
			)
		);

		$this->lease = '';

		$this->forget_cached_lease();
	}

	/**
	 * Walk every synced post, while holding the lease.
	 *
	 * @return void
	 */
	private function import_all() {
		$this->like_budget = self::LIKE_REQUESTS_PER_RUN;
		$this->stalled_at  = 0;

		/*
		 * Every registered type, not just the ones currently enabled for
		 * publishing: an item that is already on rss.chat keeps getting its
		 * replies even after its type is unticked. The synced id is the test.
		 */
		$posts = \get_posts(
			array(
				'post_type'      => \array_values( \get_post_types() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => Plugin::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		/*
		 * Replies are read for every post, but the like budget is not enough
		 * for all of them at once, so start where the last run stopped.
		 */
		$posts = $this->start_at( \array_map( 'intval', $posts ), (int) \get_option( self::OPTION_CURSOR, 0 ) );

		foreach ( $posts as $post_id ) {
			$rss_id = (int) \get_post_meta( $post_id, Plugin::META_ID, true );
			if ( $rss_id <= 0 ) {
				continue;
			}

			$this->import_replies( $post_id, $rss_id );
		}

		\update_option( self::OPTION_CURSOR, $this->stalled_at, false );
	}

	/**
	 * Rotate the post list so it begins at a given post, keeping every post
	 * in the run. A cursor of 0, or one that is no longer in the list, leaves
	 * the order alone.
	 *
	 * @param int[] $post_ids Post ids.
	 * @param int   $cursor   Post id to begin at.
	 * @return int[]
	 */
	private function start_at( array $post_ids, $cursor ) {
		if ( $cursor <= 0 ) {
			return $post_ids;
		}

		$at = \array_search( $cursor, $post_ids, true );
		if ( false === $at || 0 === $at ) {
			return $post_ids;
		}

		return \array_merge( \array_slice( $post_ids, $at ), \array_slice( $post_ids, 0, $at ) );
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
			if ( ! \is_array( $item ) ) {
				continue;
			}

			/*
			 * The array leads with the post itself. Its likes are the one
			 * thing we take from it; replies' likes stay on the network. It
			 * is never stored as a comment, so it needs no guid.
			 */
			if ( isset( $item['id'] ) && (int) $item['id'] === $rss_id ) {
				$this->import_likes( $post_id, $rss_id, $item );
				continue;
			}

			/* A reply is deduped by its guid, so one without is unusable. */
			if ( empty( $item['guid'] ) ) {
				continue;
			}

			/*
			 * The guid dedup below is the only loop guard we need: a reply that
			 * WordPress pushed already carries its guid on a comment, so it is
			 * skipped here. Replies the owner wrote directly on rss.chat, even
			 * under the same account, have a guid we have not seen, so they come
			 * home like anyone else's.
			 */
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
	 * Runs only when a plugin that renders like comments is active.
	 *
	 * Likes have no guid, so each one is keyed by item id plus screenname.
	 * The list is only fetched when the item reports likes; at a count of
	 * zero everything stored for the item goes.
	 *
	 * @param int   $post_id Local post id.
	 * @param int   $rss_id  rss.chat id of the post.
	 * @param array $item    The post's rss.chat item.
	 * @return void
	 */
	private function import_likes( $post_id, $rss_id, array $item ) {
		/*
		 * Nothing here renders a like: leave them on the network rather than
		 * filling the comment list with empty comments.
		 */
		if ( ! Plugin::should_import_likes() ) {
			return;
		}

		/*
		 * No count at all (an older server, a partial item) is not zero:
		 * there is nothing to reconcile against, so leave things as they are.
		 */
		if ( ! isset( $item['ctLikes'] ) ) {
			return;
		}

		$likers = array();

		if ( (int) $item['ctLikes'] > 0 ) {
			/*
			 * Reading the list is a request too. Out of budget: leave this
			 * post whole for the next run rather than half-reconciled.
			 */
			if ( $this->like_budget <= 0 ) {
				$this->stall_at( $post_id );
				return;
			}

			--$this->like_budget;
			$likers = ( new API() )->get_likes( $rss_id );

			/*
			 * On a failed read leave the stored likes as they are, rather than
			 * mistaking the error for "nobody likes this any more".
			 */
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

		/*
		 * The count says there are likes but none of them came back as a
		 * screenname: an unexpected shape, not an empty list. Deleting every
		 * stored like on that reading would be wrong.
		 */
		if ( (int) $item['ctLikes'] > 0 && empty( $wanted ) ) {
			return;
		}

		$stored = $this->stored_likes( $post_id, $rss_id );

		foreach ( \array_diff_key( $stored, $wanted ) as $comment_id ) {
			\wp_delete_comment( $comment_id, true );
		}

		$outstanding = \array_diff_key( $wanted, $stored );
		$missing     = \array_slice( $outstanding, 0, \max( 0, $this->like_budget ), true );

		if ( \count( $missing ) < \count( $outstanding ) ) {
			$this->stall_at( $post_id );
		}

		foreach ( $missing as $key => $screenname ) {
			/*
			 * Checked as we go, not only up front: a liker the server will
			 * not hand over costs a second request, so the slice above is
			 * the most we could do, not what we can still afford.
			 */
			if ( $this->like_budget <= 0 ) {
				$this->stall_at( $post_id );
				break;
			}

			--$this->like_budget;
			$this->insert_like( $post_id, $screenname, $key );
		}
	}

	/**
	 * Remember the first post this run could not finish, so the next one
	 * begins there.
	 *
	 * @param int $post_id Local post id.
	 * @return void
	 */
	private function stall_at( $post_id ) {
		if ( 0 === $this->stalled_at ) {
			$this->stalled_at = (int) $post_id;
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
		/*
		 * The record is only read this once, so a failed read must not leave
		 * the like with a blank URL for good: skip it, the next run retries.
		 */
		$url = $this->author_url( $screenname );
		if ( null === $url ) {
			return;
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
	 * The URL to file a liker under: their home link when they set one, else
	 * their rss.chat feed, which is their identity on the network and always
	 * there.
	 *
	 * @param string $screenname Screenname of the liker.
	 * @return string|null URL (empty when the record has none), or null when
	 *                     the lookup failed.
	 */
	private function author_url( $screenname ) {
		$user = ( new API() )->get_user_data( $screenname );

		if ( \is_wp_error( $user ) ) {
			/*
			 * The server answers 503 for every kind of failure, so the error
			 * alone does not say whether this user is gone or the server is
			 * having a moment. Ask it plainly: only an account it does not
			 * have is filed without a URL, rather than asked for again every
			 * five minutes. Anything else waits for a better run.
			 */
			--$this->like_budget;

			return false === ( new API() )->user_exists( $screenname ) ? '' : null;
		}

		if ( ! \is_array( $user ) ) {
			return null;
		}

		/*
		 * The home link is a preference (items carry it flattened as
		 * feedLink, the user record does not).
		 */
		if ( isset( $user['prefs']['myFeedLink'] ) && \is_string( $user['prefs']['myFeedLink'] ) && '' !== $user['prefs']['myFeedLink'] ) {
			return $user['prefs']['myFeedLink'];
		}

		if ( isset( $user['feedUrl'] ) && \is_string( $user['feedUrl'] ) ) {
			return $user['feedUrl'];
		}

		return '';
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
