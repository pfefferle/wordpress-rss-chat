<?php
/**
 * Tests for the Syndication class (POSSE push + loop guard).
 *
 * @package RSS_Chat
 * @group rss-chat
 * @group syndication
 */

namespace RSS_Chat\Tests;

use RSS_Chat\Plugin;
use RSS_Chat\Backfeed;

/**
 * Syndication tests.
 */
class Test_Syndication extends TestCase {

	/**
	 * Captured /newpost request URLs during a test.
	 *
	 * @var string[]
	 */
	private $newposts = array();

	/**
	 * Set up: stub /newpost.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->newposts = array();
		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Stub rss.chat: capture /newpost calls and return a synthetic item.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			$this->newposts[] = $url;
			return $this->mock_http_response(
				(string) \wp_json_encode(
					array(
						'id'   => 4242,
						'guid' => 'https://rss.chat/?id=4242',
					)
				)
			);
		}
		return $response;
	}

	/**
	 * Decode the jsontext payload from a captured /newpost URL.
	 *
	 * @param string $url Captured URL.
	 * @return array
	 */
	private function payload( $url ) {
		$query = \wp_parse_url( $url, \PHP_URL_QUERY );
		$vars  = array();
		\parse_str( (string) $query, $vars );
		return isset( $vars['jsontext'] ) ? (array) \json_decode( $vars['jsontext'], true ) : array();
	}

	/**
	 * A published chat-format post is pushed and its ids stored.
	 */
	public function test_publish_chat_post_pushes_and_stores_meta() {
		$post_id = $this->create_chat_post();

		$this->assertCount( 1, $this->newposts, 'chat post should push exactly once' );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
		$this->assertSame( 'https://rss.chat/?id=4242', \get_post_meta( $post_id, Plugin::META_GUID, true ) );
	}

	/**
	 * A standard post (no chat format) is not pushed.
	 */
	public function test_non_chat_post_is_not_pushed() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A post already carrying a synced id is not pushed again on re-publish.
	 */
	public function test_already_synced_post_is_not_pushed_again() {
		$post_id = $this->create_chat_post();
		$this->assertCount( 1, $this->newposts );

		// Move to draft and back to publish.
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertCount( 1, $this->newposts, 'must not double-push a synced post' );
	}

	/**
	 * Without a stored credential, nothing is pushed.
	 */
	public function test_not_connected_skips_push() {
		Plugin::clear_account();

		$this->create_chat_post();

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A non-chat post opted in by the filter is pushed.
	 */
	public function test_filter_can_opt_in_a_non_chat_post() {
		$opt_in = function () {
			return true;
		};
		\add_filter( 'rss_chat_should_syndicate', $opt_in );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\remove_filter( 'rss_chat_should_syndicate', $opt_in );

		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * A chat post opted out by the filter is not pushed.
	 */
	public function test_filter_can_opt_out_a_chat_post() {
		$opt_out = function () {
			return false;
		};
		\add_filter( 'rss_chat_should_syndicate', $opt_out );

		$this->create_chat_post();

		\remove_filter( 'rss_chat_should_syndicate', $opt_out );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * The filter receives the post it is deciding about.
	 */
	public function test_filter_receives_the_post() {
		$seen    = null;
		$capture = function ( $syndicate, $post ) use ( &$seen ) {
			$seen = $post;
			return $syndicate;
		};
		\add_filter( 'rss_chat_should_syndicate', $capture, 10, 2 );

		$post_id = $this->create_chat_post();

		\remove_filter( 'rss_chat_should_syndicate', $capture, 10 );

		$this->assertInstanceOf( \WP_Post::class, $seen );
		$this->assertSame( $post_id, $seen->ID );
	}

	/**
	 * A comment on a synced post is pushed as a reply with inReplyTo.
	 */
	public function test_comment_on_synced_post_pushes_reply() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Plugin::META_ID, 100 );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'nice one',
				'comment_approved' => 1,
				'user_id'          => self::factory()->user->create(),
			)
		);

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( 100, (int) $payload['inReplyTo'] );
	}

	/**
	 * The chat format is added without dropping the theme's existing formats.
	 */
	public function test_ensures_chat_post_format_without_dropping_others() {
		$before = \get_theme_support( 'post-formats' );

		// Simulate a theme that already offers a couple of formats.
		\remove_theme_support( 'post-formats' );
		\add_theme_support( 'post-formats', array( 'aside', 'image' ) );

		( new \RSS_Chat\Syndication() )->ensure_chat_post_format();

		$formats = \get_theme_support( 'post-formats' )[0];
		$this->assertContains( 'chat', $formats, 'chat is added' );
		$this->assertContains( 'aside', $formats, 'existing formats are kept' );
		$this->assertContains( 'image', $formats, 'existing formats are kept' );

		// Restore prior theme support to avoid leaking global state.
		\remove_theme_support( 'post-formats' );
		if ( \is_array( $before ) && isset( $before[0] ) && \is_array( $before[0] ) ) {
			\add_theme_support( 'post-formats', $before[0] );
		}
	}

	/**
	 * A theme with no post formats (e.g. a block theme) gets the full set.
	 */
	public function test_adds_standard_formats_when_theme_declares_none() {
		$before = \get_theme_support( 'post-formats' );
		\remove_theme_support( 'post-formats' );

		( new \RSS_Chat\Syndication() )->ensure_chat_post_format();

		$formats = \get_theme_support( 'post-formats' )[0];
		$this->assertContains( 'chat', $formats );
		$this->assertContains( 'aside', $formats );
		$this->assertContains( 'image', $formats );

		// Restore prior theme support to avoid leaking global state.
		\remove_theme_support( 'post-formats' );
		if ( \is_array( $before ) && isset( $before[0] ) && \is_array( $before[0] ) ) {
			\add_theme_support( 'post-formats', $before[0] );
		}
	}

	/**
	 * A comment created during backfeed import must never be pushed back.
	 */
	public function test_backfed_comment_is_not_pushed() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Plugin::META_ID, 100 );

		Backfeed::$importing = true;
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'imported reply',
				'comment_approved' => 1,
			)
		);
		Backfeed::$importing = false;

		$this->assertCount( 0, $this->newposts, 'loop guard: imported comments must not push' );
	}

	/**
	 * A password-protected chat post is not pushed.
	 */
	public function test_password_protected_post_is_not_pushed() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'hunter2',
			)
		);
		\set_post_format( $post_id, 'chat' );
		\wp_update_post( array( 'ID' => $post_id ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * The pushed item carries the post's canonical permalink.
	 */
	public function test_pushed_item_carries_the_permalink() {
		$post_id = $this->create_chat_post();

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( \get_permalink( $post_id ), $payload['link'] ?? null );
	}

	/**
	 * The rss_chat_post_item filter can alter the payload.
	 */
	public function test_post_item_filter_can_alter_the_payload() {
		\add_filter(
			'rss_chat_post_item',
			function ( $item ) {
				$item['title'] = 'Filtered title';
				return $item;
			}
		);

		$this->create_chat_post();

		\remove_all_filters( 'rss_chat_post_item' );

		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( 'Filtered title', $payload['title'] ?? null );
	}

	/**
	 * A webmention-typed comment is not pushed as a reply.
	 */
	public function test_webmention_typed_comment_is_not_pushed() {
		$post_id        = $this->create_chat_post();
		$this->newposts = array();

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Inbound.',
				'comment_approved' => 1,
				'user_id'          => self::factory()->user->create(),
				'comment_type'     => 'webmention',
			)
		);

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A comment carrying another network's protocol meta is not pushed.
	 */
	public function test_foreign_protocol_comment_is_not_pushed() {
		$post_id        = $this->create_chat_post();
		$this->newposts = array();

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Inbound.',
				'comment_approved' => 1,
				'user_id'          => self::factory()->user->create(),
				'comment_type'     => 'comment',
				'comment_meta'     => array( 'protocol' => 'webmention' ),
			)
		);

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * The rss_chat_should_push_comment filter can hold a local comment back.
	 */
	public function test_should_push_comment_filter_can_opt_out() {
		$post_id        = $this->create_chat_post();
		$this->newposts = array();

		\add_filter( 'rss_chat_should_push_comment', '__return_false' );

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Local.',
				'comment_approved' => 1,
				'user_id'          => self::factory()->user->create(),
				'comment_type'     => 'comment',
			)
		);

		\remove_filter( 'rss_chat_should_push_comment', '__return_false' );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A filter that returns something other than an array must not kill the
	 * publish request (API::new_post() has an array type hint).
	 */
	public function test_post_item_filter_returning_a_non_array_does_not_fatal() {
		\add_filter( 'rss_chat_post_item', '__return_null' );

		$this->create_chat_post();

		\remove_filter( 'rss_chat_post_item', '__return_null' );

		$this->assertCount( 1, $this->newposts );
	}

	/**
	 * When get_permalink() has nothing to offer, no `link` is sent at all
	 * rather than `link: false`.
	 */
	public function test_missing_permalink_is_not_sent_as_link() {
		\add_filter( 'post_link', '__return_false' );

		$this->create_chat_post();

		\remove_filter( 'post_link', '__return_false' );

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertArrayNotHasKey( 'link', $payload );
	}

	/**
	 * A comment from the public comment form (no WordPress user) stays home.
	 */
	public function test_comment_without_a_user_is_not_pushed() {
		$post_id        = $this->create_chat_post();
		$this->newposts = array();

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Anonymous.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => 0,
			)
		);

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A reply to a comment that was never pushed stays home too, instead of
	 * going out as a top-level reply to the post.
	 */
	public function test_reply_to_an_unpushed_comment_is_not_pushed() {
		$post_id = $this->create_chat_post();
		$user_id = self::factory()->user->create();

		// A parent that never made it to rss.chat: no synced id on it.
		$parent_id = \wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Never left the site.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => 0,
			)
		);
		\delete_comment_meta( $parent_id, Plugin::META_ID );
		$this->newposts = array();

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_parent'   => $parent_id,
				'comment_content'  => 'Replying to the one that stayed.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => $user_id,
			)
		);

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A reply to a pushed comment still goes out, threaded under it.
	 */
	public function test_reply_to_a_pushed_comment_is_threaded_under_it() {
		$post_id = $this->create_chat_post();
		$user_id = self::factory()->user->create();

		$parent_id = \wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Parent.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => $user_id,
			)
		);
		\update_comment_meta( $parent_id, Plugin::META_ID, 555 );
		$this->newposts = array();

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_parent'   => $parent_id,
				'comment_content'  => 'Child.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => $user_id,
			)
		);

		$this->assertCount( 1, $this->newposts );
		$payload = $this->payload( $this->newposts[0] );
		$this->assertSame( 555, (int) $payload['inReplyTo'] );
	}

	/**
	 * The rss_chat_should_push_comment filter only runs for comments that
	 * could actually be pushed: a comment on a post that is not on rss.chat
	 * never reaches it.
	 */
	public function test_should_push_comment_filter_is_not_called_without_a_reply_target() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$called  = false;
		$capture = function ( $push ) use ( &$called ) {
			$called = true;
			return $push;
		};
		\add_filter( 'rss_chat_should_push_comment', $capture );

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Local.',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
			)
		);

		\remove_filter( 'rss_chat_should_push_comment', $capture );

		$this->assertFalse( $called );
		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * Register a public post type with post-format support and publish a
	 * chat-format item of it.
	 *
	 * @return int Post id.
	 */
	private function create_cpt_chat_post() {
		\register_post_type(
			'rssclub',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'post-formats' ),
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'rssclub',
				'post_status'  => 'draft',
				'post_title'   => 'Club chat',
				'post_content' => 'A chat post from a custom type.',
			)
		);
		\set_post_format( $post_id, 'chat' );
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}

	/**
	 * Enable the given post types in the plugin settings.
	 *
	 * @param string[] $post_types Post type names.
	 * @return void
	 */
	private function enable_post_types( array $post_types ) {
		\update_option(
			Plugin::OPTION_SETTINGS,
			array(
				'server_url' => RSS_CHAT_DEFAULT_SERVER,
				'post_types' => $post_types,
			)
		);
	}

	/**
	 * A chat-format item of a custom post type is not pushed unless its type
	 * is enabled in the settings.
	 */
	public function test_custom_post_type_is_not_pushed_by_default() {
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->create_cpt_chat_post();

		\unregister_post_type( 'rssclub' );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * Once its type is enabled, a chat-format item of a custom post type is
	 * pushed like a post.
	 */
	public function test_custom_post_type_is_pushed_when_enabled() {
		$this->enable_post_types( array( 'post', 'rssclub' ) );

		$post_id = $this->create_cpt_chat_post();

		\unregister_post_type( 'rssclub' );
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * Unticking "post" stops posts from being pushed, too.
	 */
	public function test_post_is_not_pushed_when_its_type_is_disabled() {
		$this->enable_post_types( array() );

		$this->create_chat_post();

		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * A post that was skipped at publish time (its type was not enabled yet)
	 * goes out on its next update once the type is enabled. Nothing has to be
	 * un- and re-published.
	 */
	public function test_updating_a_skipped_post_pushes_it_once_its_type_is_enabled() {
		\delete_option( Plugin::OPTION_SETTINGS );

		$post_id = $this->create_cpt_chat_post();
		$this->assertCount( 0, $this->newposts, 'skipped while the type is off' );

		$this->enable_post_types( array( 'post', 'rssclub' ) );
		\wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Club chat, edited',
			)
		);

		\unregister_post_type( 'rssclub' );
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * A chat-format item of a custom post type saved through the REST API (the
	 * block editor) is pushed too. The format is set before core fires
	 * wp_after_insert_post, so the generic hook covers every post type.
	 */
	public function test_custom_post_type_saved_via_rest_is_pushed() {
		\register_post_type(
			'rssclub',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'post-formats' ),
			)
		);
		$this->enable_post_types( array( 'post', 'rssclub' ) );
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/rssclub' );
		$request->set_body_params(
			array(
				'title'   => 'Club chat via REST',
				'content' => 'Saved by the block editor.',
				'status'  => 'publish',
				'format'  => 'chat',
			)
		);
		$response = \rest_get_server()->dispatch( $request );

		\unregister_post_type( 'rssclub' );
		\delete_option( Plugin::OPTION_SETTINGS );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 4242, (int) \get_post_meta( $response->get_data()['id'], Plugin::META_ID, true ) );
	}
}
