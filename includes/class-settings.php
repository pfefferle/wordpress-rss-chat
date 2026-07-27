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

		// Capture the rss.chat login redirect only when our page loads.
		\add_action( 'load-' . $hook, array( $this, 'maybe_capture_login_redirect' ) );
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
			'__return_false',
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

		$email = isset( $_POST['rss_chat_email'] )
			? \sanitize_email( \wp_unslash( $_POST['rss_chat_email'] ) )
			: '';

		if ( '' === $email || ! \is_email( $email ) ) {
			$this->redirect_back( 'bad_email' );
		}

		$redirect = \admin_url( 'options-general.php?page=' . self::MENU_SLUG );
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

			<form action="options.php" method="post">
				<?php
				\settings_fields( self::OPTION_GROUP );
				\do_settings_sections( self::MENU_SLUG );
				\submit_button();
				?>
			</form>

			<h2><?php \esc_html_e( 'Account', 'rss-chat' ); ?></h2>
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
				<p><?php \esc_html_e( 'Sign in with your email. rss.chat sends a confirmation link; open it and you are brought back here, connected. No password needed.', 'rss-chat' ); ?></p>
				<form action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="rss_chat_send_email" />
					<?php \wp_nonce_field( 'rss_chat_send_email' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="rss_chat_email"><?php \esc_html_e( 'Email address', 'rss-chat' ); ?></label>
							</th>
							<td>
								<input name="rss_chat_email" id="rss_chat_email" type="email" class="regular-text"
									value="<?php echo \esc_attr( \wp_get_current_user()->user_email ); ?>" />
							</td>
						</tr>
					</table>
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
