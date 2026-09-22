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

	/**
	 * Without a saved setting only the built-in post type is pushed.
	 */
	public function test_default_supported_post_types_is_post() {
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertSame( array( 'post' ), Plugin::supported_post_types() );
	}

	/**
	 * A ticked custom post type is kept, and post is not forced back in.
	 */
	public function test_sanitize_keeps_registered_post_types() {
		\register_post_type( 'rssclub', array( 'public' => true ) );

		$settings = Plugin::sanitize_settings( array( 'post_types' => array( 'rssclub' ) ) );

		\unregister_post_type( 'rssclub' );

		$this->assertSame( array( 'rssclub' ), $settings['post_types'] );
	}

	/**
	 * Names that are not a registered post type are dropped.
	 */
	public function test_sanitize_drops_unknown_post_types() {
		$settings = Plugin::sanitize_settings( array( 'post_types' => array( 'post', 'nope', 'revision' ) ) );

		$this->assertSame( array( 'post' ), $settings['post_types'] );
	}

	/**
	 * Unticking everything is a deliberate off-switch, not a fallback to post.
	 */
	public function test_sanitize_allows_no_post_types() {
		$settings = Plugin::sanitize_settings( array( 'server_url' => 'https://demo.rss.chat' ) );

		$this->assertSame( array(), $settings['post_types'] );
	}

	/**
	 * A saved setting is what supported_post_types() reports.
	 */
	public function test_supported_post_types_reads_the_saved_setting() {
		\update_option(
			Plugin::OPTION_SETTINGS,
			array(
				'server_url' => 'https://demo.rss.chat',
				'post_types' => array( 'post', 'page' ),
			)
		);

		$this->assertSame( array( 'post', 'page' ), Plugin::supported_post_types() );

		\delete_option( Plugin::OPTION_SETTINGS );
	}

	/**
	 * A crafted request with nested arrays in post_types is discarded, not
	 * fatal. (sanitize_key() returns "" for anything non-scalar.)
	 */
	public function test_sanitize_discards_nested_post_type_values() {
		$settings = Plugin::sanitize_settings(
			array(
				'post_types' => array(
					array( 'x' => 'post' ),
					'post',
					42,
					null,
				),
			)
		);

		$this->assertSame( array( 'post' ), $settings['post_types'] );
	}

	/**
	 * Likes are imported only when a plugin that renders like comments is
	 * active. None is, in the test suite.
	 */
	public function test_likes_are_not_imported_by_default() {
		$this->assertFalse( Plugin::should_import_likes() );
	}

	/**
	 * The rss_chat_import_likes filter switches the import on.
	 */
	public function test_import_likes_filter_switches_it_on() {
		\add_filter( 'rss_chat_import_likes', '__return_true' );

		$supported = Plugin::should_import_likes();

		\remove_filter( 'rss_chat_import_likes', '__return_true' );

		$this->assertTrue( $supported );
	}
}
