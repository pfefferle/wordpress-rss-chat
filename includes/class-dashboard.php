<?php
/**
 * Dashboard widgets for the rss.chat network.
 *
 * Read-only, no login, no storage. Follows the same pattern as the IndieNews
 * plugin: register widgets on wp_dashboard_setup and render feeds into the
 * core .rss-widget markup. Per-user feeds are clean RSS 2.0 so they go straight
 * through wp_widget_rss_output(); the whole-network "river" has no RSS feed, so
 * it is rendered from the /getrecentitems JSON into the same markup.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the rss.chat dashboard widgets.
 */
class Dashboard {

	/**
	 * How many items to show in the river.
	 *
	 * @var int
	 */
	const RIVER_ITEMS = 15;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'wp_dashboard_setup', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register the dashboard widgets.
	 *
	 * @return void
	 */
	public function register_widgets() {
		if ( ! \current_user_can( 'read' ) ) {
			return;
		}

		\wp_add_dashboard_widget(
			'rss_chat_river',
			\__( 'RSS Chat', 'rss-chat' ),
			array( $this, 'render_river' )
		);
	}

	/**
	 * Render the whole-network river from the JSON endpoint.
	 *
	 * @return void
	 */
	public function render_river() {
		$items = ( new API() )->get_recent_items( self::RIVER_ITEMS );

		if ( \is_wp_error( $items ) ) {
			echo '<p class="rss-widget">' . \esc_html__( 'Could not reach the rss.chat server.', 'rss-chat' ) . '</p>';
			return;
		}

		if ( ! \is_array( $items ) || empty( $items ) ) {
			echo '<p class="rss-widget">' . \esc_html__( 'No posts on the network yet.', 'rss-chat' ) . '</p>';
			return;
		}

		echo '<div class="rss-widget"><ul>';
		foreach ( $items as $item ) {
			$this->render_river_item( $item );
		}
		echo '</ul></div>';
	}

	/**
	 * Render a single river item in the core .rss-widget shape:
	 * a linked title, a cite for the author, and a short summary.
	 *
	 * @param array $item An item record from /getrecentitems.
	 * @return void
	 */
	private function render_river_item( array $item ) {
		$guid    = isset( $item['guid'] ) ? $item['guid'] : '';
		$author  = isset( $item['author'] ) ? $item['author'] : ( isset( $item['screenname'] ) ? $item['screenname'] : '' );
		$title   = isset( $item['title'] ) && '' !== $item['title'] ? $item['title'] : '';
		$body    = isset( $item['markdowntext'] ) ? $item['markdowntext'] : ( isset( $item['description'] ) ? $item['description'] : '' );
		$summary = \wp_trim_words( \wp_strip_all_tags( $body ), 30 );

		// A title-less chat post: use the trimmed body as the link text.
		$link_text = '' !== $title ? $title : $summary;
		if ( '' === $link_text ) {
			$link_text = \__( '(untitled)', 'rss-chat' );
		}

		echo '<li>';

		if ( '' !== $guid ) {
			printf(
				'<a class="rsswidget" href="%1$s">%2$s</a>',
				\esc_url( $guid ),
				\esc_html( $link_text )
			);
		} else {
			echo \esc_html( $link_text );
		}

		if ( '' !== $author ) {
			printf( ' <cite>%s</cite>', \esc_html( $author ) );
		}

		// Show the summary only when there is a distinct title above it.
		if ( '' !== $title && '' !== $summary ) {
			printf( '<div class="rssSummary">%s</div>', \esc_html( $summary ) );
		}

		echo '</li>';
	}
}
