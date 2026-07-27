<?php
/**
 * Settings screen and rss.chat passwordless login flow.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the settings page, handles the "send login email" action, and
 * captures the credential rss.chat returns via redirect.
 */
class Settings {

	const MENU_SLUG = 'rss-chat-settings';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
		\add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
	}

	/**
	 * Add the top-level RSS Chat settings menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		\add_menu_page(
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
	 * Handle form submissions and the login redirect. admin_init runs on every
	 * admin request, so we gate strictly on our own page and capability.
	 *
	 * @return void
	 */
	public function maybe_handle_actions() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page gate only; each branch verifies its own nonce or origin.
		$page = isset( $_GET['page'] ) ? \sanitize_key( \wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		$this->maybe_capture_login_redirect();
		$this->maybe_save_server_url();
		$this->maybe_send_login_email();
		$this->maybe_disconnect();
	}

	/**
	 * Capture the credential rss.chat appends to the redirect URL after the
	 * owner clicks the confirmation link.
	 *
	 * @return void
	 */
	private function maybe_capture_login_redirect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Values are provided by the rss.chat redirect, not a local form.
		if ( empty( $_GET['emailconfirmed'] ) ) {
			return;
		}

		$email = isset( $_GET['email'] ) ? \sanitize_email( \wp_unslash( $_GET['email'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? \sanitize_text_field( \wp_unslash( $_GET['code'] ) ) : '';
		$name  = isset( $_GET['screenname'] ) ? \sanitize_text_field( \wp_unslash( $_GET['screenname'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $email || '' === $code ) {
			$this->redirect_with_notice( 'login_failed' );
		}

		Plugin::update_settings(
			array(
				'email'      => $email,
				'code'       => $code,
				'screenname' => $name,
			)
		);

		$this->redirect_with_notice( 'connected' );
	}

	/**
	 * Save the rss.chat server URL.
	 *
	 * @return void
	 */
	private function maybe_save_server_url() {
		if ( ! isset( $_POST['rss_chat_save_server'] ) ) {
			return;
		}
		\check_admin_referer( 'rss_chat_save_server' );

		$raw = isset( $_POST['rss_chat_server_url'] )
			? \esc_url_raw( \wp_unslash( $_POST['rss_chat_server_url'] ) )
			: RSS_CHAT_DEFAULT_SERVER;

		Plugin::update_settings( array( 'server_url' => $raw ? $raw : RSS_CHAT_DEFAULT_SERVER ) );
		$this->redirect_with_notice( 'server_saved' );
	}

	/**
	 * Ask rss.chat to email a confirmation link back to this settings page.
	 *
	 * @return void
	 */
	private function maybe_send_login_email() {
		if ( ! isset( $_POST['rss_chat_send_email'] ) ) {
			return;
		}
		\check_admin_referer( 'rss_chat_send_email' );

		$email = isset( $_POST['rss_chat_email'] )
			? \sanitize_email( \wp_unslash( $_POST['rss_chat_email'] ) )
			: '';

		if ( '' === $email || ! \is_email( $email ) ) {
			$this->redirect_with_notice( 'bad_email' );
		}

		$redirect = \admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$result   = ( new API() )->send_confirming_email( $email, $redirect );

		$this->redirect_with_notice( \is_wp_error( $result ) ? 'email_error' : 'email_sent' );
	}

	/**
	 * Clear the stored credential.
	 *
	 * @return void
	 */
	private function maybe_disconnect() {
		if ( ! isset( $_POST['rss_chat_disconnect'] ) ) {
			return;
		}
		\check_admin_referer( 'rss_chat_disconnect' );

		Plugin::update_settings(
			array(
				'email'      => '',
				'code'       => '',
				'screenname' => '',
			)
		);
		$this->redirect_with_notice( 'disconnected' );
	}

	/**
	 * Redirect back to the settings page with a notice code and exit.
	 *
	 * @param string $notice Notice slug.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'            => self::MENU_SLUG,
					'rss_chat_notice' => $notice,
				),
				\admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = Plugin::get_settings();
		$connected = Plugin::is_connected();

		$this->render_notice();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'RSS Chat Settings', 'rss-chat' ); ?></h1>

			<h2><?php \esc_html_e( 'Server', 'rss-chat' ); ?></h2>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin.php' ) ); ?>">
				<?php \wp_nonce_field( 'rss_chat_save_server' ); ?>
				<input type="hidden" name="page" value="<?php echo \esc_attr( self::MENU_SLUG ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="rss_chat_server_url"><?php \esc_html_e( 'Server URL', 'rss-chat' ); ?></label>
						</th>
						<td>
							<input name="rss_chat_server_url" id="rss_chat_server_url" type="url"
								class="regular-text code"
								value="<?php echo \esc_attr( $settings['server_url'] ); ?>" />
							<p class="description">
								<?php \esc_html_e( 'The rss.chat instance to connect to. Default: https://rss.chat', 'rss-chat' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php \submit_button( \__( 'Save server', 'rss-chat' ), 'secondary', 'rss_chat_save_server' ); ?>
			</form>

			<h2><?php \esc_html_e( 'Account', 'rss-chat' ); ?></h2>
			<?php if ( $connected ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: screen name, 2: email address. */
						\esc_html__( 'Connected as %1$s (%2$s).', 'rss-chat' ),
						'<strong>' . \esc_html( $settings['screenname'] ? $settings['screenname'] : \__( 'unknown', 'rss-chat' ) ) . '</strong>',
						'<code>' . \esc_html( $settings['email'] ) . '</code>'
					);
					?>
				</p>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin.php' ) ); ?>">
					<?php \wp_nonce_field( 'rss_chat_disconnect' ); ?>
					<input type="hidden" name="page" value="<?php echo \esc_attr( self::MENU_SLUG ); ?>" />
					<?php \submit_button( \__( 'Disconnect', 'rss-chat' ), 'delete', 'rss_chat_disconnect', true ); ?>
				</form>
			<?php else : ?>
				<p><?php \esc_html_e( 'Sign in with your email. rss.chat will send you a confirmation link; open it and you will be brought back here, connected. No password needed.', 'rss-chat' ); ?></p>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin.php' ) ); ?>">
					<?php \wp_nonce_field( 'rss_chat_send_email' ); ?>
					<input type="hidden" name="page" value="<?php echo \esc_attr( self::MENU_SLUG ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="rss_chat_email"><?php \esc_html_e( 'Email address', 'rss-chat' ); ?></label>
							</th>
							<td>
								<input name="rss_chat_email" id="rss_chat_email" type="email"
									class="regular-text"
									value="<?php echo \esc_attr( \wp_get_current_user()->user_email ); ?>" />
							</td>
						</tr>
					</table>
					<?php \submit_button( \__( 'Send login link', 'rss-chat' ), 'primary', 'rss_chat_send_email' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render an admin notice based on the notice code in the URL.
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
			'server_saved' => array( 'success', \__( 'Server URL saved.', 'rss-chat' ) ),
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
