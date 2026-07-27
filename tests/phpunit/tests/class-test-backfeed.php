<?php
/**
 * Tests for the Backfeed class (replies -> comments, threading, dedup).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group backfeed
 */

namespace RSS_Chat\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat\Syndication;
use RSS_Chat\Backfeed;

/**
 * Backfeed tests.
 */
class Test_Backfeed extends WP_UnitTestCase {

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
	 * Set up: connect as "me" and stub the reply feed.
	 */
	public function set_up(): void {
		parent::set_up();

		\update_option(
			Plugin::OPTION_ACCOUNT,
			array(
				'email'      => 'me@example.com',
				'code'       => 'secret-code',
				'screenname' => 'me',
			)
		);

		$this->pushed = false;
		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		\delete_option( Plugin::OPTION_ACCOUNT );
		Backfeed::$importing = false;
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
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => '{"id":1,"guid":"x"}',
			);
		}

		if ( false !== \strpos( $url, '/getitemandreplies' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => (string) \wp_json_encode( $this->feed() ),
			);
		}

		return $response;
	}

	/**
	 * The synthetic reply feed: the post, one reply, our own reply (skipped),
	 * and a nested reply to the first reply.
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
		\update_post_meta( $post_id, Syndication::POST_META_ID, $this->rss_id );
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
	 * Replies become comments; the post itself and our own reply are skipped.
	 */
	public function test_imports_others_replies_only() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();

		$comments = $this->comments_on( $post_id );
		$this->assertCount( 2, $comments, 'only Alice and Bob replies should import' );

		$guids = array();
		foreach ( $comments as $comment ) {
			$guids[] = \get_comment_meta( $comment->comment_ID, Syndication::POST_META_GUID, true );
		}
		$this->assertContains( 'https://rss.chat/?id=201', $guids );
		$this->assertContains( 'https://rss.chat/?id=203', $guids );
		$this->assertNotContains( 'https://rss.chat/?id=202', $guids, 'own reply skipped' );
		$this->assertNotContains( 'https://rss.chat/?id=200', $guids, 'the post itself skipped' );
	}

	/**
	 * The nested reply (203 -> 201) is threaded under the first reply's comment.
	 */
	public function test_threading_uses_in_reply_to() {
		$post_id = $this->synced_post();

		( new Backfeed() )->run();

		$by_rss_id = array();
		foreach ( $this->comments_on( $post_id ) as $comment ) {
			$rid               = (int) \get_comment_meta( $comment->comment_ID, Syndication::POST_META_ID, true );
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

		$this->assertCount( 2, $this->comments_on( $post_id ) );
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
}
