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
 *
 * Two options are kept apart on purpose: OPTION_SETTINGS is owned by the
 * Settings API (options.php), OPTION_ACCOUNT is written only by the login flow.
 * Storing them together would let one save wipe the other.
 */
class Plugin {

	const OPTION_SETTINGS = 'rss_chat_settings';
	const OPTION_ACCOUNT  = 'rss_chat_account';

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
		( new Syndication() )->init();
		( new Backfeed() )->init();
	}

	/**
	 * The Settings-API-managed settings, merged with defaults.
	 *
	 * @return array{server_url:string}
	 */
	public static function get_settings() {
		$stored = \get_option( self::OPTION_SETTINGS, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		return \wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * Default settings.
	 *
	 * @return array{server_url:string}
	 */
	public static function default_settings() {
		return array(
			'server_url' => RSS_CHAT_DEFAULT_SERVER,
		);
	}

	/**
	 * Sanitize callback for the settings option (register_setting).
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{server_url:string}
	 */
	public static function sanitize_settings( $input ) {
		$input = \is_array( $input ) ? $input : array();

		$url = isset( $input['server_url'] ) ? \esc_url_raw( \trim( $input['server_url'] ) ) : '';

		return array(
			'server_url' => '' !== $url ? $url : RSS_CHAT_DEFAULT_SERVER,
		);
	}

	/**
	 * The stored rss.chat account, merged with defaults.
	 *
	 * @return array{email:string,code:string,screenname:string}
	 */
	public static function get_account() {
		$stored = \get_option( self::OPTION_ACCOUNT, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		return \wp_parse_args(
			$stored,
			array(
				'email'      => '',
				'code'       => '',
				'screenname' => '',
			)
		);
	}

	/**
	 * Store the rss.chat account credential.
	 *
	 * @param array $account Account fields (email, code, screenname).
	 * @return void
	 */
	public static function update_account( array $account ) {
		\update_option( self::OPTION_ACCOUNT, \wp_parse_args( $account, self::get_account() ) );
	}

	/**
	 * Forget the stored credential.
	 *
	 * @return void
	 */
	public static function clear_account() {
		\delete_option( self::OPTION_ACCOUNT );
	}

	/**
	 * Whether the owner has completed the rss.chat login.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$account = self::get_account();
		return '' !== $account['email'] && '' !== $account['code'];
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
}
