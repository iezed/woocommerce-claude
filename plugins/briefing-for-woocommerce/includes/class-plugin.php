<?php
/**
 * Main plugin class.
 *
 * @package WooCommerce\Briefing
 */

namespace WooCommerce\Briefing;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the briefing's abilities to its own MCP server at
 * `/wp-json/briefing-for-woocommerce/mcp`, authenticated by the read-only key
 * the merchant approved on WooCommerce's own screen.
 */
class Plugin {

	/**
	 * Server identity. The endpoint resolves to `/wp-json/<namespace>/<route>`.
	 */
	const MCP_SERVER_ID    = 'briefing-for-woocommerce';
	const MCP_SERVER_NS    = 'briefing-for-woocommerce';
	const MCP_SERVER_ROUTE = 'mcp';

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

		// WooCommerce only boots its vendored MCP adapter behind the `mcp_integration`
		// feature flag; boot it ourselves. `instance()` is idempotent, so this is
		// safe alongside WooCommerce or WooCommerce for Claude doing the same.
		add_action( 'plugins_loaded', array( $this, 'bootstrap_mcp_adapter' ), 20 );

		// The adapter's default server authorises any logged-in user with `read`,
		// outside our key check. Same reasoning as WooCommerce for Claude.
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );

		// The `ck_…` consumer key isn't a WordPress login, so WordPress's
		// application-password check would reject it with a 401 before our own
		// check runs, on any site where an application password exists.
		add_filter( 'application_password_is_api_request', array( $this, 'exclude_mcp_route_from_app_password_auth' ) );
	}

	/**
	 * Boot the WordPress MCP adapter vendored inside WooCommerce.
	 */
	public function bootstrap_mcp_adapter() {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	}

	/**
	 * Register the briefing's MCP server: the five read tools, no resources, no prompts.
	 *
	 * @param object $adapter The McpAdapter instance.
	 */
	public function register_mcp_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) || ! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ) {
			return;
		}

		try {
			$adapter->create_server(
				self::MCP_SERVER_ID,
				self::MCP_SERVER_NS,
				self::MCP_SERVER_ROUTE,
				__( 'Briefing for WooCommerce', 'briefing-for-woocommerce' ),
				'Read-only store totals for Briefing for WooCommerce\'s weekly briefing. Aggregates only: no orders, customers, names or emails.',
				BRIEFING_FOR_WOOCOMMERCE_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
				Abilities::TOOLS,
				array(),
				array(),
				array( $this, 'authenticate_mcp_request' )
			);
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Briefing for WooCommerce MCP server initialisation failed: ' . $e->getMessage(),
					array( 'source' => 'briefing-for-woocommerce' )
				);
			}
		}
	}

	/**
	 * Authenticate an MCP request with a WooCommerce REST API key sent as HTTP
	 * Basic auth (`ck_…` username, `cs_…` password).
	 *
	 * WooCommerce's own REST auth treats every POST as a write, and MCP posts
	 * everything, so a read-only key would be refused. This checks the key
	 * directly instead: `read` and `read_write` keys pass, `write`-only keys
	 * don't. Each ability still requires `manage_woocommerce` of the key's user.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return bool
	 */
	public function authenticate_mcp_request( $request ) {
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
	 * Don't run application-password auth on our MCP route (see the constructor).
	 *
	 * @param bool $is_api_request What WP would otherwise consider this request.
	 * @return bool
	 */
	public function exclude_mcp_route_from_app_password_auth( $is_api_request ) {
		if ( $is_api_request && 0 === strpos( self::current_rest_route(), self::MCP_SERVER_NS . '/' . self::MCP_SERVER_ROUTE ) ) {
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
