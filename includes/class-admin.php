<?php
/**
 * The chat admin page.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level RSS Chat menu, the chat screen, and its assets.
 */
class Admin {

	const MENU_SLUG = 'rss-chat';

	/**
	 * The admin screen id (set once the page is registered).
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the top-level menu and its default (chat) page.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = \add_menu_page(
			\__( 'RSS Chat', 'rss-chat' ),
			\__( 'RSS Chat', 'rss-chat' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			76
		);
	}

	/**
	 * Enqueue the chat client on our screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		\wp_enqueue_style(
			'rss-chat',
			RSS_CHAT_URL . 'assets/css/chat.css',
			array(),
			RSS_CHAT_VERSION
		);

		\wp_enqueue_script(
			'rss-chat',
			RSS_CHAT_URL . 'assets/js/chat.js',
			array( 'wp-api-fetch', 'wp-element', 'wp-dom-ready' ),
			RSS_CHAT_VERSION,
			true
		);

		\wp_localize_script(
			'rss-chat',
			'rssChatConfig',
			array(
				'restBase'    => \esc_url_raw( \rest_url( REST::NAMESPACE ) ),
				'nonce'       => \wp_create_nonce( 'wp_rest' ),
				'firehoseUrl' => Plugin::firehose_url(),
				'connected'   => Plugin::is_connected(),
				'screenname'  => Plugin::get_settings()['screenname'],
				'settingsUrl' => \admin_url( 'admin.php?page=' . Settings::MENU_SLUG ),
			)
		);
	}

	/**
	 * Render the chat screen shell. The JS client mounts into #rss-chat-app.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap rss-chat-wrap">
			<h1><?php \esc_html_e( 'RSS Chat', 'rss-chat' ); ?></h1>
			<div id="rss-chat-app">
				<p class="rss-chat-loading"><?php \esc_html_e( 'Loading the network…', 'rss-chat' ); ?></p>
				<noscript><?php \esc_html_e( 'RSS Chat needs JavaScript enabled.', 'rss-chat' ); ?></noscript>
			</div>
		</div>
		<?php
	}
}
