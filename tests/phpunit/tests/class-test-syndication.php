<?php
/**
 * Tests for the Syndication class (POSSE push + loop guard).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group syndication
 */

namespace RSS_Chat\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat\Syndication;
use RSS_Chat\Backfeed;

/**
 * Syndication tests.
 */
class Test_Syndication extends WP_UnitTestCase {

	/**
	 * Captured /newpost request URLs during a test.
	 *
	 * @var string[]
	 */
	private $newposts = array();

	/**
	 * Set up: connect an account and stub /newpost.
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

		$this->newposts = array();
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
	 * Stub rss.chat: capture /newpost calls and return a synthetic item.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			$this->newposts[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => (string) \wp_json_encode(
					array(
						'id'   => 4242,
						'guid' => 'https://rss.chat/?id=4242',
					)
				),
			);
		}
		return $response;
	}

	/**
	 * Decode the jsontext payload from a captured /newpost URL.
	 *
	 * @param string $url Captured URL.
	 * @return array
	 */
	private function payload( $url ) {
		$query = \wp_parse_url( $url, \PHP_URL_QUERY );
		$vars  = array();
		\parse_str( (string) $query, $vars );
		return isset( $vars['jsontext'] ) ? (array) \json_decode( $vars['jsontext'], true ) : array();
	}

	/**
	 * Create a published post with the chat post format.
	 *
	 * @return int Post id.
	 */
	private function create_chat_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Hello network',
				'post_content' => 'This is a chat post.',
			)
		);
		\set_post_format( $post_id, 'chat' );
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		return $post_id;
	}

	/**
	 * A published chat-format post is pushed and its ids stored.
	 */
	public function test_publish_chat_post_pushes_and_stores_meta() {
		$post_id = $this->create_chat_post();

		$this->assertCount( 1, $this->newposts, 'chat post should push exactly once' );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Syndication::POST_META_ID, true ) );
		$this->assertSame( 'https://rss.chat/?id=4242', \get_post_meta( $post_id, Syndication::POST_META_GUID, true ) );
	}

	/**
	 * A standard post (no chat format) is not pushed.
	 */
	public function test_non_chat_post_is_not_pushed() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A post already carrying a synced id is not pushed again on re-publish.
	 */
	public function test_already_synced_post_is_not_pushed_again() {
		$post_id = $this->create_chat_post();
		$this->assertCount( 1, $this->newposts );

		// Move to draft and back to publish.
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertCount( 1, $this->newposts, 'must not double-push a synced post' );
	}

	/**
	 * Without a stored credential, nothing is pushed.
	 */
	public function test_not_connected_skips_push() {
		\delete_option( Plugin::OPTION_ACCOUNT );

		$this->create_chat_post();

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A comment on a synced post is pushed as a reply with inReplyToNum.
	 */
	public function test_comment_on_synced_post_pushes_reply() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Syndication::POST_META_ID, 100 );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'nice one',
				'comment_approved' => 1,
			)
		);

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( 100, (int) $payload['inReplyToNum'] );
	}

	/**
	 * A comment created during backfeed import must never be pushed back.
	 */
	public function test_backfed_comment_is_not_pushed() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Syndication::POST_META_ID, 100 );

		Backfeed::$importing = true;
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'imported reply',
				'comment_approved' => 1,
			)
		);
		Backfeed::$importing = false;

		$this->assertCount( 0, $this->newposts, 'loop guard: imported comments must not push' );
	}
}
