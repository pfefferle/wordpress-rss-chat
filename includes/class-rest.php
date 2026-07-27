<?php
/**
 * REST proxy between wp-admin JS and the rss.chat server.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes a small set of routes under rss-chat/v1. Reads are forwarded so the
 * browser never talks cross-origin to rss.chat; writes inject the owner's
 * stored credential server-side.
 */
class REST {

	const NAMESPACE = 'rss-chat/v1';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the proxy routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			self::NAMESPACE,
			'/recent',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recent' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'ct' => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/replies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_replies' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'idparent' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/post',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'text'          => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'wp_kses_post',
					),
					'title'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'in_reply_to' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/like',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_like' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * All routes require an admin. The REST cookie nonce covers CSRF.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * GET /recent
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_recent( $request ) {
		return $this->respond( ( new API() )->get_recent_items( $request['ct'] ) );
	}

	/**
	 * GET /replies
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_replies( $request ) {
		return $this->respond( ( new API() )->get_item_and_replies( $request['idparent'] ) );
	}

	/**
	 * POST /post
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_post( $request ) {
		$item = array( 'description' => $request['text'] );

		if ( '' !== $request['title'] ) {
			$item['title'] = $request['title'];
		}
		if ( $request['in_reply_to'] > 0 ) {
			$item['inReplyToNum'] = $request['in_reply_to'];
		}

		return $this->respond( ( new API() )->new_post( $item ) );
	}

	/**
	 * POST /like
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_like( $request ) {
		return $this->respond( ( new API() )->toggle_like( $request['id'] ) );
	}

	/**
	 * Turn an API result into a REST response, mapping WP_Error to a 502.
	 *
	 * @param array|\WP_Error $result API result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( $result ) {
		if ( \is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}
}
