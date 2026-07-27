<?php
/**
 * Tests for the Feed class (source: namespace decoration of the RSS2 feed).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group feed
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;

/**
 * Feed tests.
 */
class Test_Feed extends TestCase {

	/**
	 * Set up: swallow the publish push.
	 */
	public function set_up(): void {
		parent::set_up();

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
	 * Stub any rss.chat write.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			return $this->mock_http_response( '{"id":1,"guid":"https://rss.chat/?id=1"}' );
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

		// The feed template calls header(); output has already started under
		// PHPUnit, so swallow only that "headers already sent" warning.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only: scope a single expected warning.
		\set_error_handler(
			static function ( $errno, $errstr ) {
				return false !== \strpos( $errstr, 'Cannot modify header information' );
			}
		);

		ob_start();
		require ABSPATH . 'wp-includes/feed-rss2.php';
		$feed = (string) ob_get_clean();

		\restore_error_handler();

		return $feed;
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
		Plugin::clear_account();
		$this->create_chat_post();

		$feed = $this->render_feed();

		$this->assertStringNotContainsString( '<source:account', $feed );
	}
}
