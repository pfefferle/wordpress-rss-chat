<?php
/**
 * Decorate the WordPress RSS2 feed with the rss.chat "source" vocabulary.
 *
 * This makes chat-format posts self-describing in rss.network terms: the
 * channel carries the account identity and self URL, and each chat item carries
 * its markdown body and a pointer to its per-post replies feed. It is additive
 * to the POSSE flow and does not require it.
 *
 * @package RSS_Chat
 * @link https://source.scripting.com/
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds source: namespace elements to the RSS2 feed.
 */
class Feed {

	const NAMESPACE_URI = 'https://source.scripting.com/';

	/**
	 * Hook into the RSS2 feed template.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'rss2_ns', array( $this, 'namespace_declaration' ) );
		\add_action( 'rss2_head', array( $this, 'channel_elements' ) );
		\add_action( 'rss2_item', array( $this, 'item_elements' ) );
	}

	/**
	 * Declare the source: namespace on the <rss> element.
	 *
	 * @return void
	 */
	public function namespace_declaration() {
		echo 'xmlns:source="' . \esc_url( self::NAMESPACE_URI ) . '"' . "\n";
	}

	/**
	 * Emit channel-level identity: the rss.chat account and the feed self URL.
	 *
	 * @return void
	 */
	public function channel_elements() {
		if ( ! Plugin::is_connected() ) {
			return;
		}

		$screenname = Plugin::get_account()['screenname'];
		if ( '' === $screenname ) {
			return;
		}

		printf(
			"\t<source:account service=\"rss.chat\">%s</source:account>\n",
			\esc_html( $screenname )
		);
		printf(
			"\t<source:self>%s</source:self>\n",
			\esc_url( \get_self_link() )
		);
	}

	/**
	 * Emit item-level elements for chat-format posts: the markdown body and a
	 * pointer to the per-post replies feed.
	 *
	 * @return void
	 */
	public function item_elements() {
		$post = \get_post();
		if ( ! $post || 'chat' !== \get_post_format( $post ) ) {
			return;
		}

		// WordPress has no stored markdown, so approximate it with the rendered
		// content as plain text.
		$markdown = \wp_strip_all_tags( \get_the_content_feed( 'rss2' ) );
		if ( '' !== $markdown ) {
			printf(
				"\t\t<source:markdown>%s</source:markdown>\n",
				\esc_html( $markdown )
			);
		}

		printf(
			"\t\t<source:comments count=\"%1\$d\" feedUrl=\"%2\$s\"/>\n",
			(int) \get_comments_number( $post ),
			\esc_url( \get_post_comments_feed_link( $post->ID ) )
		);
	}
}
