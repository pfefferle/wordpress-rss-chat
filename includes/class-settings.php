<?php
/**
 * Settings screen and rss.chat passwordless login.
 *
 * The server URL is handled by the Settings API (options.php). The login
 * actions (send link, disconnect) are handled by admin-post.php. The credential
 * itself arrives as a redirect back from rss.chat and is captured on load.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the options page, the settings field, and the login handlers.
 */
class Settings {

	const MENU_SLUG    = 'rss-chat-settings';
	const OPTION_GROUP = 'rss_chat';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
		// On admin_init (any admin page) so it also works when rss.chat sends the
		// owner back to a query-less URL; it self-gates on the emailconfirmed arg.
		\add_action( 'admin_init', array( $this, 'maybe_capture_login_redirect' ) );
		\add_action( 'admin_post_rss_chat_send_email', array( $this, 'handle_send_email' ) );
		\add_action( 'admin_post_rss_chat_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Add the options page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		$hook = \add_options_page(
			\__( 'RSS Chat', 'rss-chat' ),
			\__( 'RSS Chat', 'rss-chat' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		// Contextual help explaining how the plugin works.
		\add_action( 'load-' . $hook, array( $this, 'add_help_tabs' ) );
	}

	/**
	 * Add contextual Help tabs to the settings screen.
	 *
	 * @return void
	 */
	public function add_help_tabs() {
		$screen = \get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'rss-chat-overview',
				'title'   => \__( 'How it works', 'rss-chat' ),
				'content' =>
					'<h2>' . \esc_html__( 'How it works', 'rss-chat' ) . '</h2>' .
					'<p>' . \esc_html__( 'RSS Chat connects your site to the rss.chat network using WordPress you already know: your posts and comments. There is no separate chat app and no local copy of the network. Your site is one rss.chat identity, the site owner.', 'rss-chat' ) . '</p>' .
					'<p>' . \esc_html__( 'It works in two directions: your chat posts are pushed to rss.chat, and the replies come back to you as comments.', 'rss-chat' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'rss-chat-posting',
				'title'   => \__( 'Posting', 'rss-chat' ),
				'content' =>
					'<h2>' . \esc_html__( 'Posting to the network', 'rss-chat' ) . '</h2>' .
					'<p>' . \esc_html__( 'Write a normal post and give it the built-in "chat" post format. When you publish it, the post is pushed to rss.chat as a new item. Posts in any other format are left alone.', 'rss-chat' ) . '</p>' .
					'<p>' . \esc_html__( 'Nothing extra is stored: the post stays a normal WordPress post, and a note of its rss.chat id is kept so replies can find their way back.', 'rss-chat' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'rss-chat-replies',
				'title'   => \__( 'Replies', 'rss-chat' ),
				'content' =>
					'<h2>' . \esc_html__( 'Replies and comments', 'rss-chat' ) . '</h2>' .
					'<p>' . \esc_html__( 'Every few minutes the plugin checks your pushed posts for replies on rss.chat and stores new ones as comments, keeping the thread structure. These comments are marked so they are not sent back out again.', 'rss-chat' ) . '</p>' .
					'<p>' . \esc_html__( 'When you reply from WordPress, a comment on one of your synced posts is pushed back to rss.chat as a reply. Comments and replies stay in sync both ways.', 'rss-chat' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'rss-chat-setup',
				'title'   => \__( 'Setup', 'rss-chat' ),
				'content' =>
					'<h2>' . \esc_html__( 'Signing in', 'rss-chat' ) . '</h2>' .
					'<p>' . \esc_html__( 'Click "Send login link" to receive a confirmation link at your WordPress admin email. Open it and you are brought back here, connected. No password is stored, and the address is not editable: it is always your admin email.', 'rss-chat' ) . '</p>' .
					'<p>' . \esc_html__( 'By default you connect to https://rss.chat. Change the server URL above to use a self-hosted instance.', 'rss-chat' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . \esc_html__( 'For more information:', 'rss-chat' ) . '</strong></p>' .
			'<p><a href="' . \esc_url( 'https://rss.chat' ) . '">' . \esc_html__( 'The rss.chat network', 'rss-chat' ) . '</a></p>' .
			'<p><a href="' . \esc_url( 'https://github.com/scripting/rss.chat' ) . '">' . \esc_html__( 'rss.chat on GitHub', 'rss-chat' ) . '</a></p>'
		);
	}

	/**
	 * Register the setting, section, and field with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		\register_setting(
			self::OPTION_GROUP,
			Plugin::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Plugin::class, 'sanitize_settings' ),
				'default'           => Plugin::default_settings(),
			)
		);

		\add_settings_section(
			'rss_chat_server',
			\__( 'Server', 'rss-chat' ),
			array( $this, 'render_server_section' ),
			self::MENU_SLUG
		);

		\add_settings_field(
			'rss_chat_server_url',
			\__( 'Server URL', 'rss-chat' ),
			array( $this, 'render_server_url_field' ),
			self::MENU_SLUG,
			'rss_chat_server',
			array( 'label_for' => 'rss_chat_server_url' )
		);
	}

	/**
	 * Render the description under the Server section heading.
	 *
	 * @return void
	 */
	public function render_server_section() {
		echo '<p>' . \esc_html__( 'Where this site connects to the network.', 'rss-chat' ) . '</p>';
	}

	/**
	 * Render the server URL input.
	 *
	 * @return void
	 */
	public function render_server_url_field() {
		$settings = Plugin::get_settings();
		printf(
			'<input type="url" id="rss_chat_server_url" name="%1$s[server_url]" value="%2$s" class="regular-text code" />',
			\esc_attr( Plugin::OPTION_SETTINGS ),
			\esc_attr( $settings['server_url'] )
		);
		echo '<p class="description">' . \esc_html__( 'The rss.chat instance to connect to. Default: https://rss.chat', 'rss-chat' ) . '</p>';
	}

	/**
	 * Capture the credential rss.chat appends to the redirect URL after the
	 * owner clicks the confirmation link.
	 *
	 * @return void
	 */
	public function maybe_capture_login_redirect() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Values come from the rss.chat redirect, not a local form.
		if ( empty( $_GET['emailconfirmed'] ) ) {
			return;
		}

		$email = isset( $_GET['email'] ) ? \sanitize_email( \wp_unslash( $_GET['email'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? \sanitize_text_field( \wp_unslash( $_GET['code'] ) ) : '';
		$name  = isset( $_GET['screenname'] ) ? \sanitize_text_field( \wp_unslash( $_GET['screenname'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $email || '' === $code ) {
			$this->redirect_back( 'login_failed' );
		}

		Plugin::update_account(
			array(
				'email'      => $email,
				'code'       => $code,
				'screenname' => $name,
			)
		);

		$this->redirect_back( 'connected' );
	}

	/**
	 * Handle the "send login link" action from admin-post.php.
	 *
	 * @return void
	 */
	public function handle_send_email() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do this.', 'rss-chat' ) );
		}
		\check_admin_referer( 'rss_chat_send_email' );

		// The identity is the site's admin email; it is not user-editable.
		$email = \sanitize_email( (string) \get_option( 'admin_email' ) );

		if ( '' === $email || ! \is_email( $email ) ) {
			$this->redirect_back( 'bad_email' );
		}

		// A query-less redirect: rss.chat appends its result with "?", which
		// would corrupt an existing query string. maybe_capture_login_redirect
		// runs on admin_init, so any admin URL works as the landing page.
		$redirect = \admin_url( 'options-general.php' );
		$result   = ( new API() )->send_confirming_email( $email, $redirect );

		$this->redirect_back( \is_wp_error( $result ) ? 'email_error' : 'email_sent' );
	}

	/**
	 * Handle the "disconnect" action from admin-post.php.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do this.', 'rss-chat' ) );
		}
		\check_admin_referer( 'rss_chat_disconnect' );

		Plugin::clear_account();
		$this->redirect_back( 'disconnected' );
	}

	/**
	 * Redirect back to the options page with a notice code and exit.
	 *
	 * @param string $notice Notice slug.
	 * @return void
	 */
	private function redirect_back( $notice ) {
		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'            => self::MENU_SLUG,
					'rss_chat_notice' => $notice,
				),
				\admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the options page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$account   = Plugin::get_account();
		$connected = Plugin::is_connected();

		$this->render_notice();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'RSS Chat', 'rss-chat' ); ?></h1>
			<p><?php \esc_html_e( 'Take part in the rss.chat network from your WordPress site. See the Help tab for how it works.', 'rss-chat' ); ?></p>

			<form action="options.php" method="post">
				<?php
				\settings_fields( self::OPTION_GROUP );
				\do_settings_sections( self::MENU_SLUG );
				\submit_button();
				?>
			</form>

			<h2><?php \esc_html_e( 'Account', 'rss-chat' ); ?></h2>
			<p><?php \esc_html_e( 'This site\'s connection to the network.', 'rss-chat' ); ?></p>
			<?php if ( $connected ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: screen name, 2: email address. */
						\esc_html__( 'Connected as %1$s (%2$s).', 'rss-chat' ),
						'<strong>' . \esc_html( '' !== $account['screenname'] ? $account['screenname'] : \__( 'unknown', 'rss-chat' ) ) . '</strong>',
						'<code>' . \esc_html( $account['email'] ) . '</code>'
					);
					?>
				</p>
				<form action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="rss_chat_disconnect" />
					<?php \wp_nonce_field( 'rss_chat_disconnect' ); ?>
					<?php \submit_button( \__( 'Disconnect', 'rss-chat' ), 'delete', 'submit', true ); ?>
				</form>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %s: the site's admin email address. */
						\esc_html__( 'Sign in as %s (your WordPress admin email). rss.chat sends a confirmation link; open it and you are brought back here, connected. No password needed.', 'rss-chat' ),
						'<code>' . \esc_html( (string) \get_option( 'admin_email' ) ) . '</code>'
					);
					?>
				</p>
				<form action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="rss_chat_send_email" />
					<?php \wp_nonce_field( 'rss_chat_send_email' ); ?>
					<?php \submit_button( \__( 'Send login link', 'rss-chat' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render an admin notice for the login actions.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a status slug.
		$notice = isset( $_GET['rss_chat_notice'] ) ? \sanitize_key( \wp_unslash( $_GET['rss_chat_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'connected'    => array( 'success', \__( 'Connected to rss.chat.', 'rss-chat' ) ),
			'disconnected' => array( 'success', \__( 'Disconnected from rss.chat.', 'rss-chat' ) ),
			'email_sent'   => array( 'success', \__( 'Login link sent. Check your inbox and open the link.', 'rss-chat' ) ),
			'email_error'  => array( 'error', \__( 'Could not reach the rss.chat server to send the login link.', 'rss-chat' ) ),
			'bad_email'    => array( 'error', \__( 'Please enter a valid email address.', 'rss-chat' ) ),
			'login_failed' => array( 'error', \__( 'The rss.chat login did not return a valid credential.', 'rss-chat' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			\esc_attr( $type ),
			\esc_html( $text )
		);
	}
}
