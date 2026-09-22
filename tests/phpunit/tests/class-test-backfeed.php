<?php
/**
 * Tests for the Backfeed class (replies -> comments, threading, dedup).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group backfeed
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;
use RSS_Chat\Backfeed;

/**
 * Backfeed tests.
 */
class Test_Backfeed extends TestCase {

	/**
	 * Whether a /newpost push happened (should never, during import).
	 *
	 * @var bool
	 */
	private $pushed = false;

	/**
	 * The rss.chat id of the synced post under test.
	 *
	 * @var int
	 */
	private $rss_id = 200;

	/**
	 * Screennames /getlikerslist answers with for the post under test.
	 *
	 * @var string[]
	 */
	private $likers = array();

	/**
	 * How often /getlikerslist was requested.
	 *
	 * @var int
	 */
	private $likers_requests = 0;

	/**
	 * How often /getuserdata was requested.
	 *
	 * @var int
	 */
	private $user_requests = 0;

	/**
	 * Set up: stub the reply feed.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->pushed          = false;
		$this->likers          = array();
		$this->likers_requests = 0;
		$this->user_requests   = 0;
		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Stub rss.chat: the post plus four replies, and flag any push.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			$this->pushed = true;
			return $this->mock_http_response( '{"id":1,"guid":"x"}' );
		}

		if ( false !== \strpos( $url, '/getitemandreplies' ) ) {
			return $this->mock_http_response( (string) \wp_json_encode( $this->feed() ) );
		}

		if ( false !== \strpos( $url, '/getlikerslist' ) ) {
			++$this->likers_requests;
			return $this->mock_http_response( (string) \wp_json_encode( $this->likers ) );
		}

		if ( false !== \strpos( $url, '/getuserdata' ) ) {
			++$this->user_requests;
			$user = array( 'feedUrl' => 'https://rss.chat/users/x/rss.xml' );
			if ( false !== \strpos( $url, 'screenname=carol' ) ) {
				$user['feedLink'] = 'https://carol.example/';
			}
			return $this->mock_http_response( (string) \wp_json_encode( $user ) );
		}

		return $response;
	}

	/**
	 * The synthetic reply feed: the post, one reply, our own reply (which now
	 * comes home too, deduped only by guid), and a nested reply to the first.
	 *
	 * @return array
	 */
	private function feed() {
		return array(
			array(
				'id'          => $this->rss_id,
				'guid'        => 'https://rss.chat/?id=200',
				'screenname'  => 'me',
				'description' => 'the post',
				'ctLikes'     => \count( $this->likers ),
			),
			array(
				'id'           => 201,
				'guid'         => 'https://rss.chat/?id=201',
				'author'       => 'Alice',
				'screenname'   => 'alice',
				'markdowntext' => 'first reply',
				'inReplyToNum' => 200,
			),
			array(
				'id'           => 202,
				'guid'         => 'https://rss.chat/?id=202',
				'author'       => 'Me',
				'screenname'   => 'me',
				'markdowntext' => 'my own reply',
				'inReplyToNum' => 200,
			),
			array(
				'id'           => 203,
				'guid'         => 'https://rss.chat/?id=203',
				'author'       => 'Bob',
				'screenname'   => 'bob',
				'markdowntext' => 'nested',
				'inReplyToNum' => 201,
			),
		);
	}

	/**
	 * Create a synced post pointing at rss.chat id 200.
	 *
	 * @return int Post id.
	 */
	private function synced_post() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Plugin::META_ID, $this->rss_id );
		return $post_id;
	}

	/**
	 * Get the comments on a post, newest-agnostic.
	 *
	 * @param int $post_id Post id.
	 * @return \WP_Comment[]
	 */
	private function comments_on( $post_id ) {
		return \get_comments( array( 'post_id' => $post_id ) );
	}

	/**
	 * Replies become comments, including the owner's own reply written on
	 * rss.chat; only the post itself is skipped.
	 */
	public function test_imports_all_replies_including_own() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();

		$comments = $this->comments_on( $post_id );
		$this->assertCount( 3, $comments, 'Alice, Bob and the owner reply should import' );

		$guids = array();
		foreach ( $comments as $comment ) {
			$guids[] = \get_comment_meta( $comment->comment_ID, Plugin::META_GUID, true );
		}
		$this->assertContains( 'https://rss.chat/?id=201', $guids );
		$this->assertContains( 'https://rss.chat/?id=202', $guids, 'own reply comes home too' );
		$this->assertContains( 'https://rss.chat/?id=203', $guids );
		$this->assertNotContains( 'https://rss.chat/?id=200', $guids, 'the post itself skipped' );
	}

	/**
	 * A reply WordPress itself pushed is not re-imported: it already carries its
	 * guid on a comment, so guid dedup catches it even under the owner account.
	 */
	public function test_own_pushed_reply_not_reimported() {
		$post_id = $this->synced_post();

		// Simulate the comment WordPress pushed for reply 202: it stored the guid.
		$pushed = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'my own reply',
				'comment_approved' => 1,
			)
		);
		\update_comment_meta( $pushed, Plugin::META_GUID, 'https://rss.chat/?id=202' );

		( new Backfeed() )->run();

		$with_guid = \get_comments(
			array(
				'post_id'    => $post_id,
				'meta_key'   => Plugin::META_GUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'https://rss.chat/?id=202', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$this->assertCount( 1, $with_guid, 'reply 202 must not be imported a second time' );
	}

	/**
	 * Imported comments are marked with the rss.chat protocol meta.
	 */
	public function test_sets_protocol_meta() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();

		foreach ( $this->comments_on( $post_id ) as $comment ) {
			$this->assertSame(
				Plugin::PROTOCOL,
				\get_comment_meta( $comment->comment_ID, Plugin::META_PROTOCOL, true ),
				'each imported comment carries protocol=rss.chat'
			);
		}
	}

	/**
	 * The nested reply (203 -> 201) is threaded under the first reply's comment.
	 */
	public function test_threading_uses_in_reply_to() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();

		$by_rss_id = array();
		foreach ( $this->comments_on( $post_id ) as $comment ) {
			$rid               = (int) \get_comment_meta( $comment->comment_ID, Plugin::META_ID, true );
			$by_rss_id[ $rid ] = $comment;
		}

		$this->assertArrayHasKey( 201, $by_rss_id );
		$this->assertArrayHasKey( 203, $by_rss_id );
		$this->assertSame( 0, (int) $by_rss_id[201]->comment_parent, 'top-level reply' );
		$this->assertSame(
			(int) $by_rss_id[201]->comment_ID,
			(int) $by_rss_id[203]->comment_parent,
			'nested reply parented to reply 201'
		);
	}

	/**
	 * Running twice does not duplicate comments (dedup by guid).
	 */
	public function test_dedup_on_second_run() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();
		( new Backfeed() )->run();

		$this->assertCount( 3, $this->comments_on( $post_id ) );
	}

	/**
	 * Importing must not push the imported comments back (loop guard).
	 */
	public function test_import_does_not_push_back() {
		$this->synced_post();

		( new Backfeed() )->run();

		$this->assertFalse( $this->pushed, 'imported replies must not be pushed to rss.chat' );
		$this->assertFalse( Backfeed::$importing, 'import flag reset after run' );
	}

	/**
	 * The rss_chat_backfeed_enabled filter turns the importer off.
	 */
	public function test_backfeed_enabled_filter_turns_the_importer_off() {
		$post_id = $this->synced_post();

		\add_filter( 'rss_chat_backfeed_enabled', '__return_false' );

		( new Backfeed() )->run();

		\remove_filter( 'rss_chat_backfeed_enabled', '__return_false' );

		$this->assertCount( 0, \get_comments( array( 'post_id' => $post_id ) ) );
	}

	/**
	 * A synced item of a custom post type receives its replies, whether or not
	 * its type is (still) enabled: the synced id is what makes it a member.
	 */
	public function test_replies_are_imported_for_a_synced_custom_post_type() {
		\register_post_type( 'rssclub', array( 'public' => true ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'rssclub',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $post_id, Plugin::META_ID, $this->rss_id );

		( new Backfeed() )->run();

		\unregister_post_type( 'rssclub' );

		$this->assertCount( 3, $this->comments_on( $post_id ) );
	}

	/**
	 * The like comments on a post.
	 *
	 * @param int $post_id Post id.
	 * @return \WP_Comment[]
	 */
	private function likes_on( $post_id ) {
		return \get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'like',
			)
		);
	}

	/**
	 * A like on the post comes back as a comment of type "like", carrying the
	 * liker's screenname and feed link, and the protocol meta.
	 */
	public function test_imports_likes_on_the_post() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'carol' );

		( new Backfeed() )->run();

		$likes = $this->likes_on( $post_id );
		$this->assertCount( 1, $likes );
		$this->assertSame( 'carol', $likes[0]->comment_author );
		$this->assertSame( 'https://carol.example/', $likes[0]->comment_author_url );
		$this->assertSame( 0, (int) $likes[0]->comment_parent, 'a like on the post is top-level' );
		$this->assertSame( Plugin::PROTOCOL, \get_comment_meta( $likes[0]->comment_ID, Plugin::META_PROTOCOL, true ) );
		$this->assertCount(
			3,
			\get_comments(
				array(
					'post_id' => $post_id,
					'type'    => 'comment',
				)
			),
			'the replies are untouched, likes are a separate type'
		);
	}

	/**
	 * Running twice stores each like once (dedup by item id + screenname).
	 */
	public function test_likes_are_not_duplicated_on_second_run() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'carol', 'dave' );

		( new Backfeed() )->run();
		( new Backfeed() )->run();

		$this->assertCount( 2, $this->likes_on( $post_id ) );
	}

	/**
	 * A like taken back on rss.chat (togglelike) is removed here too.
	 */
	public function test_withdrawn_like_is_removed() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'carol', 'dave' );

		( new Backfeed() )->run();
		$this->assertCount( 2, $this->likes_on( $post_id ) );

		$this->likers = array( 'dave' );
		( new Backfeed() )->run();

		$likes = $this->likes_on( $post_id );
		$this->assertCount( 1, $likes );
		$this->assertSame( 'dave', $likes[0]->comment_author );
	}

	/**
	 * When every like is taken back the item reports ctLikes 0; the stored
	 * likes still go, without an extra request for an empty list.
	 */
	public function test_all_likes_withdrawn_clears_stored_likes() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'carol' );

		( new Backfeed() )->run();
		$this->assertCount( 1, $this->likes_on( $post_id ) );

		$this->likers          = array();
		$this->likers_requests = 0;
		( new Backfeed() )->run();

		$this->assertCount( 0, $this->likes_on( $post_id ) );
		$this->assertSame( 0, $this->likers_requests, 'no request when the item reports no likes' );
	}

	/**
	 * Un-liking must not touch the site's own like comments from elsewhere
	 * (an ActivityPub like, say): only comments this plugin imported are
	 * reconciled.
	 */
	public function test_reconcile_leaves_foreign_likes_alone() {
		$post_id = $this->synced_post();
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'like',
				'comment_author'   => 'fedi-user',
				'comment_approved' => 1,
			)
		);

		( new Backfeed() )->run();

		$this->assertCount( 1, $this->likes_on( $post_id ) );
	}

	/**
	 * Imported likes are never pushed back to rss.chat.
	 */
	public function test_imported_likes_are_not_pushed_back() {
		$this->synced_post();
		$this->likers = array( 'carol' );

		( new Backfeed() )->run();

		$this->assertFalse( $this->pushed );
	}

	/**
	 * A liker without a home link (feedLink) gets their rss.chat feed as the
	 * author URL, which is their identity on the network.
	 */
	public function test_like_author_url_falls_back_to_feed_url() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'dave' );

		( new Backfeed() )->run();

		$likes = $this->likes_on( $post_id );
		$this->assertCount( 1, $likes );
		$this->assertSame( 'https://rss.chat/users/x/rss.xml', $likes[0]->comment_author_url );
	}

	/**
	 * A liker's user record is fetched once and cached: importing the same
	 * screenname again does not ask rss.chat again.
	 */
	public function test_user_lookup_is_cached() {
		$post_id      = $this->synced_post();
		$this->likers = array( 'carol' );

		( new Backfeed() )->run();
		$this->assertSame( 1, $this->user_requests );

		// Drop the stored like so the next run imports carol again.
		foreach ( $this->likes_on( $post_id ) as $like ) {
			\wp_delete_comment( $like->comment_ID, true );
		}
		( new Backfeed() )->run();

		$this->assertCount( 1, $this->likes_on( $post_id ) );
		$this->assertSame( 1, $this->user_requests, 'second import served from cache' );
	}

	/**
	 * A post with many likes is not synced in one go: each run stores at most
	 * LIKES_PER_RUN new likes, so a cron run stays bounded, and the rest
	 * follow on the next run.
	 */
	public function test_new_likes_are_capped_per_run() {
		$post_id = $this->synced_post();
		for ( $i = 1; $i <= Backfeed::LIKES_PER_RUN + 5; $i++ ) {
			$this->likers[] = 'user' . $i;
		}

		( new Backfeed() )->run();
		$this->assertCount( Backfeed::LIKES_PER_RUN, $this->likes_on( $post_id ) );

		( new Backfeed() )->run();
		$this->assertCount( Backfeed::LIKES_PER_RUN + 5, $this->likes_on( $post_id ) );
	}
}
