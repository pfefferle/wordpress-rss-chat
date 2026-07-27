<?php
/**
 * Main plugin controller.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's pieces together and exposes shared settings helpers.
 */
class Plugin {

	const OPTION_KEY = 'rss_chat_settings';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks for the sub-components.
	 *
	 * @return void
	 */
	public function init() {
		( new Settings() )->init();
		( new REST() )->init();
		( new Admin() )->init();
		( new Dashboard() )->init();
	}

	/**
	 * Read the plugin settings, merged with defaults.
	 *
	 * @return array{server_url:string,email:string,code:string,screenname:string}
	 */
	public static function get_settings() {
		$defaults = array(
			'server_url' => RSS_CHAT_DEFAULT_SERVER,
			'email'      => '',
			'code'       => '',
			'screenname' => '',
		);

		$stored = \get_option( self::OPTION_KEY, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		return \wp_parse_args( $stored, $defaults );
	}

	/**
	 * Persist the plugin settings.
	 *
	 * @param array $settings Settings to merge and store.
	 * @return void
	 */
	public static function update_settings( array $settings ) {
		$merged = \wp_parse_args( $settings, self::get_settings() );
		\update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Whether the owner has completed the rss.chat login.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$settings = self::get_settings();
		return '' !== $settings['email'] && '' !== $settings['code'];
	}

	/**
	 * Normalized base URL of the configured rss.chat server (no trailing slash).
	 *
	 * @return string
	 */
	public static function server_url() {
		$settings = self::get_settings();
		return \untrailingslashit( $settings['server_url'] );
	}

	/**
	 * Websocket firehose URL derived from the configured server.
	 *
	 * https://rss.chat -> wss://rss.chat/
	 *
	 * @return string
	 */
	public static function firehose_url() {
		$http = self::server_url();
		$ws   = \preg_replace( '#^https?://#', '', $http );
		$is_secure = ( 0 === \strpos( $http, 'https://' ) );
		return ( $is_secure ? 'wss://' : 'ws://' ) . $ws . '/';
	}
}
