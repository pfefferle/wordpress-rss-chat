<?php
/**
 * Tests for the Settings class (connection check).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group settings
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;
use RSS_Chat\Settings;

/**
 * Settings tests.
 */
class Test_Settings extends TestCase {

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
	 * Return the prepared response for /getuserdata.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/getuserdata' ) ) {
			return $this->response;
		}
		return $response;
	}

	/**
	 * A reachable server that knows the account is a success, and the message
	 * names the server version and the feed.
	 */
	public function test_connection_check_succeeds_when_the_account_exists() {
		$this->response = $this->mock_http_response( '{"screenname":"me","serverVersion":"0.6.4","feedUrl":"https://demo.rss.chat/users/me/rss.xml"}' );

		$result = ( new Settings() )->check_connection();

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '0.6.4', $result['message'] );
		$this->assertStringContainsString( 'https://demo.rss.chat/users/me/rss.xml', $result['message'] );
	}

	/**
	 * The server's reason is surfaced when the check fails.
	 */
	public function test_connection_check_reports_the_server_reason() {
		$this->response = $this->mock_http_response( 'Can\'t get user data for "me" because there is no user with that name.', 503 );

		$result = ( new Settings() )->check_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'no user with that name', $result['message'] );
	}

	/**
	 * A transport failure is reported as well.
	 */
	public function test_connection_check_reports_a_transport_error() {
		$this->response = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		$result = ( new Settings() )->check_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Could not resolve host', $result['message'] );
	}

	/**
	 * Render the settings page as an administrator and return the output.
	 *
	 * @return string
	 */
	private function render_page() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\ob_start();
		( new Settings() )->render();
		return (string) \ob_get_clean();
	}

	/**
	 * A connected site gets a "Test connection" button next to Disconnect.
	 */
	public function test_page_offers_a_test_connection_button_when_connected() {
		$html = $this->render_page();

		$this->assertStringContainsString( 'value="rss_chat_test_connection"', $html );
		$this->assertStringContainsString( 'Test connection', $html );
	}

	/**
	 * Disconnect and Test connection sit side by side, so both forms are inline.
	 */
	public function test_page_lines_up_the_disconnect_and_test_connection_buttons() {
		$html = $this->render_page();

		$this->assertMatchesRegularExpression( '/<form[^>]*display:inline-block[^>]*>\s*<input type="hidden" name="action" value="rss_chat_disconnect"/', $html );
		$this->assertMatchesRegularExpression( '/<form[^>]*display:inline-block[^>]*>\s*<input type="hidden" name="action" value="rss_chat_test_connection"/', $html );
	}

	/**
	 * A disconnected site has nothing to test, so no button.
	 */
	public function test_page_hides_the_test_connection_button_when_disconnected() {
		Plugin::clear_account();

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'rss_chat_test_connection', $html );
	}

	/**
	 * The notice shows the stored check result and consumes it.
	 */
	public function test_notice_shows_the_stored_check_result_once() {
		$_GET['rss_chat_notice'] = 'test_failed';
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\set_transient(
			'rss_chat_test_' . \get_current_user_id(),
			array(
				'success' => false,
				'message' => 'Could not resolve host',
			),
			MINUTE_IN_SECONDS
		);

		\ob_start();
		( new Settings() )->render();
		$html = (string) \ob_get_clean();
		unset( $_GET['rss_chat_notice'] );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Could not resolve host', $html );
		$this->assertFalse( \get_transient( 'rss_chat_test_' . \get_current_user_id() ) );
	}

	/**
	 * Without a stored account there is nothing to check.
	 */
	public function test_connection_check_fails_when_not_connected() {
		Plugin::clear_account();

		$result = ( new Settings() )->check_connection();

		$this->assertFalse( $result['success'] );
	}
}
