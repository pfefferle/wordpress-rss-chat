<?php
/**
 * Plugin Name:       RSS Chat
 * Plugin URI:        https://github.com/pfefferle/wordpress-rss-chat
 * Description:        Participate in the rss.chat network from inside WordPress. Read the network, post, and reply, with live updates from the rss.chat firehose.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Matthias Pfefferle
 * Author URI:        https://notiz.blog/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rss-chat
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\define( 'RSS_CHAT_VERSION', '0.1.0' );
\define( 'RSS_CHAT_FILE', __FILE__ );
\define( 'RSS_CHAT_PATH', \plugin_dir_path( __FILE__ ) );
\define( 'RSS_CHAT_URL', \plugin_dir_url( __FILE__ ) );

/**
 * Default rss.chat server, overridable in settings.
 */
\define( 'RSS_CHAT_DEFAULT_SERVER', 'https://rss.chat' );

require_once RSS_CHAT_PATH . 'includes/class-plugin.php';
require_once RSS_CHAT_PATH . 'includes/class-api.php';
require_once RSS_CHAT_PATH . 'includes/class-settings.php';
require_once RSS_CHAT_PATH . 'includes/class-rest.php';
require_once RSS_CHAT_PATH . 'includes/class-admin.php';
require_once RSS_CHAT_PATH . 'includes/class-dashboard.php';

/**
 * Boot the plugin once WordPress is ready.
 */
function bootstrap() {
	Plugin::instance()->init();
}
\add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
