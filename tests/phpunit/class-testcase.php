<?php
/**
 * Shared base test case for RSS Chat.
 *
 * Provides the connected-account lifecycle, a synthetic HTTP response factory,
 * and the chat-post fixture used across the suites.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat\Backfeed;

/**
 * Base test case: connect an account, tidy up, and share fixtures.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Connect a test account before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		Plugin::update_account(
			array(
				'email'      => 'me@example.com',
				'code'       => 'secret-code',
				'screenname' => 'me',
			)
		);
	}

	/**
	 * Reset shared state after each test.
	 */
	public function tear_down(): void {
		Plugin::clear_account();
		Backfeed::$importing = false;
		parent::tear_down();
	}

	/**
	 * Build a synthetic wp_remote_* response for a pre_http_request stub.
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return array
	 */
	protected function mock_http_response( $body, $code = 200 ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) $body,
		);
	}

	/**
	 * Create a published post with the chat post format.
	 *
	 * @return int Post id.
	 */
	protected function create_chat_post() {
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
}
