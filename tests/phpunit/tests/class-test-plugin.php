<?php
/**
 * Tests for the Plugin class (settings and defaults).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group plugin
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;

/**
 * Plugin tests.
 */
class Test_Plugin extends TestCase {

	/**
	 * Without a saved setting the plugin talks to the public demo server.
	 */
	public function test_default_server_is_the_public_demo_instance() {
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertSame( 'https://demo.rss.chat', Plugin::server_url() );
	}

	/**
	 * An empty server URL falls back to the default, not to nothing.
	 */
	public function test_empty_server_url_falls_back_to_the_default() {
		$settings = Plugin::sanitize_settings( array( 'server_url' => '' ) );

		$this->assertSame( 'https://demo.rss.chat', $settings['server_url'] );
	}
}
