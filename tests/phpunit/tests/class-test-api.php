<?php
/**
 * Tests for the API class (HTTP wrapper around the rss.chat endpoints).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group api
 */

namespace RSS_Chat\Tests;

use RSS_Chat\API;

/**
 * API tests.
 */
class Test_API extends TestCase {

	/**
	 * The last URL the API requested.
	 *
	 * @var string
	 */
	private $last_url = '';

	/**
	 * The response the stub hands back.
	 *
	 * @var array
	 */
	private $response;

	/**
	 * Set up: stub HTTP.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->last_url = '';
		$this->response = $this->mock_http_response( '{}' );
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
	 * Record the URL and return the prepared response.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array
	 */
	public function stub_http( $response, $args, $url ) {
		$this->last_url = $url;
		return $this->response;
	}

	/**
	 * Asks /getuserdata for the screenname and decodes the answer.
	 */
	public function test_get_user_data_fetches_the_user_record() {
		$this->response = $this->mock_http_response( '{"screenname":"me","serverVersion":"0.6.4","feedUrl":"https://demo.rss.chat/users/me/rss.xml"}' );

		$result = ( new API() )->get_user_data( 'me' );

		$this->assertStringStartsWith( 'https://demo.rss.chat/getuserdata?', $this->last_url );
		$this->assertStringContainsString( 'screenname=me', $this->last_url );
		$this->assertSame( '0.6.4', $result['serverVersion'] );
		$this->assertSame( 'https://demo.rss.chat/users/me/rss.xml', $result['feedUrl'] );
	}

	/**
	 * Without a screenname only the server facts are requested.
	 */
	public function test_get_user_data_without_screenname_asks_for_server_facts_only() {
		( new API() )->get_user_data( '' );

		$this->assertSame( 'https://demo.rss.chat/getuserdata', $this->last_url );
	}

	/**
	 * The server's plain-text 503 becomes a WP_Error carrying that sentence.
	 */
	public function test_get_user_data_returns_the_server_error() {
		$this->response = $this->mock_http_response( 'Can\'t get user data for "me" because there is no user with that name.', 503 );

		$result = ( new API() )->get_user_data( 'me' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'no user with that name', $result->get_error_message() );
	}
}
