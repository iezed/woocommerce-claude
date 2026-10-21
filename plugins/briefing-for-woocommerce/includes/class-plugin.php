<?php
/**
 * Main plugin class.
 *
 * @package WooCommerce\Briefing
 */

namespace WooCommerce\Briefing;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the briefing's calls on one REST route,
 * `/wp-json/briefing-for-woocommerce/call`, authenticated by the read-only key
 * the merchant approved on WooCommerce's own screen.
 *
 * Up to 0.3.0 this was an MCP server on the MCP adapter WooCommerce bundled.
 * WooCommerce 11.3 stops bundling it, and the briefing only ever makes a fixed
 * list of read calls, so a plain route does the same job with no dependency.
 */
class Plugin {

	/**
	 * The route resolves to `/wp-json/<namespace>/<route>`. The Briefing app
	 * detects the plugin from the namespace's index, so keep it unversioned.
	 */
	const REST_NAMESPACE = 'briefing-for-woocommerce';
	const REST_ROUTE     = 'call';

	/**
	 * Most calls one request may carry. The briefing's weekly read is nine.
	 */
	const MAX_CALLS = 20;

	/**
	 * The start of the app name Briefing asks WooCommerce to approve a key for.
	 * WooCommerce describes approved keys as "<app name> - API (<date>)".
	 */
	const KEY_APP_NAME = 'Briefing for WooCommerce';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Return the singleton instance, constructing it on first access.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );

		add_action( 'rest_api_init', array( $this, 'register_route' ) );

		// The `ck_…` consumer key isn't a WordPress login, so WordPress's
		// application-password check would reject it with a 401 before our own
		// check runs, on any site where an application password exists.
		add_filter( 'application_password_is_api_request', array( $this, 'exclude_route_from_app_password_auth' ) );
	}

	/**
	 * Register the route: POST only, behind the key check.
	 */
	public function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'call' ),
				'permission_callback' => array( $this, 'authenticate_request' ),
				'args'                => array(
					'calls' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => self::MAX_CALLS,
						'items'    => array(
							'type'       => 'object',
							'required'   => array( 'name' ),
							'properties' => array(
								'name'      => array( 'type' => 'string' ),
								'arguments' => array( 'type' => 'object' ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Run each call in order and answer `{ results: [...] }`, one result per
	 * call, so the Briefing app makes one request per batch.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return \WP_REST_Response
	 */
	public function call( $request ) {
		$results = array();
		foreach ( (array) $request->get_param( 'calls' ) as $call ) {
			$results[] = self::run_call( (array) $call );
		}
		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * Run one call: `{ ok: true, data }`, or `{ ok: false, code, error }`.
	 *
	 * Only the briefing's own tools run. `execute()` checks `manage_woocommerce`
	 * and the input schema, as the MCP adapter did, and one failing call
	 * doesn't stop the others.
	 *
	 * @param array $call `name` and optional `arguments`.
	 * @return array
	 */
	public static function run_call( array $call ) {
		$name    = self::ability_name( isset( $call['name'] ) ? (string) $call['name'] : '' );
		$ability = $name && function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			return array(
				'ok'    => false,
				'code'  => 'unknown_tool',
				'error' => __( 'Briefing for WooCommerce has no tool by that name.', 'briefing-for-woocommerce' ),
			);
		}

		$arguments = isset( $call['arguments'] ) && is_array( $call['arguments'] ) ? $call['arguments'] : array();
		try {
			$result = $ability->execute( $arguments );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Briefing for WooCommerce call failed: ' . $name . ': ' . $e->getMessage(),
					array( 'source' => 'briefing-for-woocommerce' )
				);
			}
			return array(
				'ok'    => false,
				'code'  => 'tool_failed',
				'error' => __( 'The tool failed.', 'briefing-for-woocommerce' ),
			);
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'    => false,
				'code'  => $result->get_error_code(),
				'error' => $result->get_error_message(),
			);
		}
		return array(
			'ok'   => true,
			'data' => Abilities::without_people( $result ),
		);
	}

	/**
	 * The ability a call names, or null if it isn't one of the briefing's
	 * tools. The app sends the names the MCP server gave them
	 * (`briefing-totals` for `briefing/totals`).
	 *
	 * @param string $name The name in the call.
	 * @return string|null
	 */
	private static function ability_name( $name ) {
		$ability = preg_replace( '/^briefing-/', 'briefing/', $name );
		return in_array( $ability, Abilities::TOOLS, true ) ? $ability : null;
	}

	/**
	 * Authenticate a request with a WooCommerce REST API key sent as HTTP
	 * Basic auth (`ck_…` username, `cs_…` password).
	 *
	 * WooCommerce's own REST auth treats every POST as a write, and the route
	 * is a POST, so a read-only key would be refused. This checks the key
	 * directly instead: `read` and `read_write` keys pass, `write`-only keys
	 * don't. Each ability still requires `manage_woocommerce` of the key's user.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return bool
	 */
	public function authenticate_request( $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) || ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		list( $consumer_key, $consumer_secret ) = self::basic_auth_credential( $request );
		if ( '' === $consumer_key || '' === $consumer_secret ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct lookup against woocommerce_api_keys; no caching surface for auth.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, consumer_secret, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $row || ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			return false;
		}
		if ( ! in_array( (string) $row->permissions, array( 'read', 'read_write' ), true ) ) {
			return false;
		}

		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user ) {
			return false;
		}

		wp_set_current_user( $user->ID );
		return true;
	}

	/**
	 * Pull the Basic auth credential off the request: `PHP_AUTH_USER`/`PHP_AUTH_PW`
	 * where the server populates them, else the `Authorization` header (which WP
	 * also fills from `REDIRECT_HTTP_AUTHORIZATION` behind CGI/FastCGI).
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return array{0:string,1:string} `[username, password]`, or empty strings.
	 */
	private static function basic_auth_credential( $request ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credentials are byte-compared, not interpolated; sanitising would corrupt them.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return array(
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ),
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_PW'] ) ),
			);
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$header = $request->get_header( 'authorization' );
		if ( ! is_string( $header ) || 0 !== stripos( $header, 'Basic ' ) ) {
			return array( '', '' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding HTTP Basic auth per RFC 7617; strict mode rejects invalid input.
		$decoded = base64_decode( substr( $header, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( '', '' );
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );
		return array( trim( $username ), trim( $password ) );
	}

	/**
	 * Don't run application-password auth on our route (see the constructor).
	 *
	 * @param bool $is_api_request What WP would otherwise consider this request.
	 * @return bool
	 */
	public function exclude_route_from_app_password_auth( $is_api_request ) {
		if ( $is_api_request && 0 === strpos( self::current_rest_route(), self::REST_NAMESPACE . '/' . self::REST_ROUTE ) ) {
			return false;
		}
		return $is_api_request;
	}

	/**
	 * The REST route the current request targets, for pretty (`/wp-json/<route>`)
	 * and plain (`?rest_route=/<route>`) permalinks, without a leading slash; '' if
	 * it isn't a REST request.
	 *
	 * @return string
	 */
	private static function current_rest_route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only path inspection; no state change.
		if ( isset( $_GET['rest_route'] ) ) {
			$route = wp_unslash( $_GET['rest_route'] );
			return is_string( $route ) ? ltrim( $route, '/' ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = is_string( $request_uri ) ? strtok( $request_uri, '?#' ) : false;
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$position    = strpos( $path, $rest_prefix );
		return false === $position ? '' : substr( $path, $position + strlen( $rest_prefix ) );
	}

	/**
	 * Delete every key the merchant approved for Briefing. Runs on deactivation.
	 *
	 * @return int Number of keys revoked.
	 */
	public static function revoke_keys() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off delete on deactivation; no caching surface.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s",
				$wpdb->esc_like( self::KEY_APP_NAME ) . '% - API (%'
			)
		);
	}
}
