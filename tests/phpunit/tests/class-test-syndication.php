<?php
/**
 * Tests for the Syndication class (POSSE push + loop guard).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group syndication
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;
use RSS_Chat\Backfeed;

/**
 * Syndication tests.
 */
class Test_Syndication extends TestCase {

	/**
	 * Captured /newpost request URLs during a test.
	 *
	 * @var string[]
	 */
	private $newposts = array();

	/**
	 * Set up: stub /newpost.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->newposts = array();
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
			return $this->mock_http_response(
				(string) \wp_json_encode(
					array(
						'id'   => 4242,
						'guid' => 'https://rss.chat/?id=4242',
					)
				)
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
	 * A published chat-format post is pushed and its ids stored.
	 */
	public function test_publish_chat_post_pushes_and_stores_meta() {
		$post_id = $this->create_chat_post();

		$this->assertCount( 1, $this->newposts, 'chat post should push exactly once' );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
		$this->assertSame( 'https://rss.chat/?id=4242', \get_post_meta( $post_id, Plugin::META_GUID, true ) );
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
		Plugin::clear_account();

		$this->create_chat_post();

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A comment on a synced post is pushed as a reply with inReplyTo.
	 */
	public function test_comment_on_synced_post_pushes_reply() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Plugin::META_ID, 100 );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'nice one',
				'comment_approved' => 1,
			)
		);

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( 100, (int) $payload['inReplyTo'] );
	}

	/**
	 * The chat format is added without dropping the theme's existing formats.
	 */
	public function test_ensures_chat_post_format_without_dropping_others() {
		$before = \get_theme_support( 'post-formats' );

		// Simulate a theme that already offers a couple of formats.
		\remove_theme_support( 'post-formats' );
		\add_theme_support( 'post-formats', array( 'aside', 'image' ) );

		( new \RSS_Chat\Syndication() )->ensure_chat_post_format();

		$formats = \get_theme_support( 'post-formats' )[0];
		$this->assertContains( 'chat', $formats, 'chat is added' );
		$this->assertContains( 'aside', $formats, 'existing formats are kept' );
		$this->assertContains( 'image', $formats, 'existing formats are kept' );

		// Restore prior theme support to avoid leaking global state.
		\remove_theme_support( 'post-formats' );
		if ( \is_array( $before ) && isset( $before[0] ) && \is_array( $before[0] ) ) {
			\add_theme_support( 'post-formats', $before[0] );
		}
	}

	/**
	 * A theme with no post formats (e.g. a block theme) gets the full set.
	 */
	public function test_adds_standard_formats_when_theme_declares_none() {
		$before = \get_theme_support( 'post-formats' );
		\remove_theme_support( 'post-formats' );

		( new \RSS_Chat\Syndication() )->ensure_chat_post_format();

		$formats = \get_theme_support( 'post-formats' )[0];
		$this->assertContains( 'chat', $formats );
		$this->assertContains( 'aside', $formats );
		$this->assertContains( 'image', $formats );

		// Restore prior theme support to avoid leaking global state.
		\remove_theme_support( 'post-formats' );
		if ( \is_array( $before ) && isset( $before[0] ) && \is_array( $before[0] ) ) {
			\add_theme_support( 'post-formats', $before[0] );
		}
	}

	/**
	 * A comment created during backfeed import must never be pushed back.
	 */
	public function test_backfed_comment_is_not_pushed() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Plugin::META_ID, 100 );

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
