<?php
/**
 * Tests for the Feed class (source: namespace decoration of the RSS2 feed).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group feed
 */

namespace RSS_Chat\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;

/**
 * Feed tests.
 */
class Test_Feed extends WP_UnitTestCase {

	/**
	 * Set up: connect an account and swallow the publish push.
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

		// Return a synthetic /newpost response so publishing a chat post does
		// not error on the blocked live request.
		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		\delete_option( Plugin::OPTION_ACCOUNT );
		parent::tear_down();
	}

	/**
	 * Stub any rss.chat write.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => '{"id":1,"guid":"https://rss.chat/?id=1"}',
			);
		}
		return $response;
	}

	/**
	 * Render the site's RSS2 feed to a string.
	 *
	 * @return string
	 */
	private function render_feed() {
		$this->go_to( '/?feed=rss2' );
		ob_start();
		require ABSPATH . 'wp-includes/feed-rss2.php';
		return (string) ob_get_clean();
	}

	/**
	 * Create a published chat-format post.
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
	 * The namespace and channel identity are declared when connected.
	 */
	public function test_declares_namespace_and_account() {
		$this->create_chat_post();

		$feed = $this->render_feed();

		$this->assertStringContainsString( 'xmlns:source="https://source.scripting.com/"', $feed );
		$this->assertStringContainsString( '<source:account service="rss.chat">me</source:account>', $feed );
		$this->assertStringContainsString( '<source:self>', $feed );
	}

	/**
	 * A chat-format item carries markdown and a comments feed pointer.
	 */
	public function test_chat_item_has_source_elements() {
		$this->create_chat_post();

		$feed = $this->render_feed();

		$this->assertStringContainsString( '<source:markdown>', $feed );
		$this->assertStringContainsString( 'This is a chat post.', $feed );
		$this->assertStringContainsString( '<source:comments count=', $feed );
	}

	/**
	 * A standard post gets no item-level source: elements.
	 */
	public function test_non_chat_item_is_not_decorated() {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Just a normal post.',
			)
		);

		$feed = $this->render_feed();

		$this->assertStringNotContainsString( '<source:markdown>', $feed );
	}

	/**
	 * Without a credential, the channel identity is omitted.
	 */
	public function test_channel_identity_omitted_when_disconnected() {
		\delete_option( Plugin::OPTION_ACCOUNT );
		$this->create_chat_post();

		$feed = $this->render_feed();

		$this->assertStringNotContainsString( '<source:account', $feed );
	}
}
