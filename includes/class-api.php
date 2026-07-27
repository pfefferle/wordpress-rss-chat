<?php
/**
 * Thin HTTP client for the rss.chat server API.
 *
 * @package RSS_Chat
 */

namespace RSS_Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the rss.chat HTTP endpoints documented in server/docs/api.md.
 *
 * Reads are unauthenticated. Writes carry the owner's stored email + code.
 */
class API {

	/**
	 * Fetch a post and its direct replies, oldest first.
	 *
	 * @param int $idparent Parent post id.
	 * @return array|\WP_Error
	 */
	public function get_item_and_replies( $idparent ) {
		return $this->get( '/getitemandreplies', array( 'idparent' => (int) $idparent ) );
	}

	/**
	 * Publish a post.
	 *
	 * @param array $item Item payload (e.g. description, title, inReplyToNum).
	 * @return array|\WP_Error
	 */
	public function new_post( array $item ) {
		return $this->post(
			'/newpost',
			array( 'jsontext' => \wp_json_encode( $item ) )
		);
	}

	/**
	 * Request a confirming login email.
	 *
	 * @param string $email        Email address to confirm.
	 * @param string $url_redirect Where rss.chat sends the owner back to.
	 * @return array|\WP_Error
	 */
	public function send_confirming_email( $email, $url_redirect ) {
		return $this->get(
			'/sendconfirmingemail',
			array(
				'email'       => $email,
				'urlredirect' => $url_redirect,
			)
		);
	}

	/**
	 * Perform an unauthenticated GET against the server.
	 *
	 * @param string $path  Endpoint path beginning with a slash.
	 * @param array  $query Query args.
	 * @return array|\WP_Error
	 */
	private function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, $query );
	}

	/**
	 * Perform an authenticated POST against the server.
	 *
	 * Credentials are appended as query args, matching the rss.chat API.
	 *
	 * @param string $path  Endpoint path beginning with a slash.
	 * @param array  $query Query args (credentials are added automatically).
	 * @return array|\WP_Error
	 */
	private function post( $path, array $query = array() ) {
		$account = Plugin::get_account();

		if ( '' === $account['email'] || '' === $account['code'] ) {
			return new \WP_Error(
				'rss_chat_not_connected',
				\__( 'Not connected to rss.chat. Complete the login on the settings screen first.', 'rss-chat' )
			);
		}

		$query['emailaddress'] = $account['email'];
		$query['emailcode']    = $account['code'];

		return $this->request( 'POST', $path, $query );
	}

	/**
	 * Build the URL, make the request, and parse the response.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Endpoint path beginning with a slash.
	 * @param array  $query  Query args.
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, array $query ) {
		$url = \add_query_arg(
			\array_map( 'rawurlencode', $query ),
			Plugin::server_url() . $path
		);

		$response = \wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		return $this->parse( $response );
	}

	/**
	 * Normalize a wp_remote_* response into decoded JSON or a WP_Error.
	 *
	 * The rss.chat API signals failure with HTTP 503 and a plain-text body.
	 *
	 * @param array|\WP_Error $response Raw response.
	 * @return array|\WP_Error
	 */
	private function parse( $response ) {
		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$body   = \wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			return new \WP_Error(
				'rss_chat_server_error',
				'' !== $body ? $body : \sprintf( /* translators: %d: HTTP status code. */ \__( 'rss.chat returned status %d.', 'rss-chat' ), $status ),
				array( 'status' => $status )
			);
		}

		return \json_decode( $body, true );
	}
}
