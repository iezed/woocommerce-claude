<?php
/**
 * `initialize` protocol-version alignment on the MCP route.
 *
 * MCP Adapter 0.7.0 rejects requests whose `MCP-Protocol-Version` header
 * doesn't match the version negotiated at `initialize`. The
 * `@automattic/mcp-wordpress-remote` bridge up to 0.4.x always sends
 * `2025-06-18` in the header while forwarding the Claude client's newer
 * `protocolVersion`, so without alignment every session after
 * `initialize` fails. See Plugin::align_initialize_protocol_version().
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Plugin;

/**
 * Integration tests for MCP protocol-version alignment.
 */
class Test_MCP_Protocol_Version_Alignment extends WP_UnitTestCase {

	/**
	 * Build an `initialize` request.
	 *
	 * @param string      $client_version `params.protocolVersion`.
	 * @param string|null $header_version `MCP-Protocol-Version` header, or null for none.
	 * @param string      $route          REST route.
	 * @return WP_REST_Request
	 */
	private function initialize_request( $client_version, $header_version, $route = '/woocommerce-claude/mcp' ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $header_version ) {
			$request->set_header( 'MCP-Protocol-Version', $header_version );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => $client_version,
						'capabilities'    => new stdClass(),
						'clientInfo'      => array(
							'name'    => 'test',
							'version' => '0',
						),
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Run the filter and return the decoded body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return stdClass
	 */
	private function dispatch_filter( WP_REST_Request $request ) {
		$result = Plugin::instance()->align_initialize_protocol_version( null, rest_get_server(), $request );
		$this->assertNull( $result, 'The filter must never short-circuit dispatch.' );

		return json_decode( $request->get_body() );
	}

	/**
	 * The bridge case: newer client version, `2025-06-18` header.
	 */
	public function test_bridge_initialize_negotiates_header_version() {
		$body = $this->dispatch_filter( $this->initialize_request( '2025-11-25', '2025-06-18' ) );

		$this->assertSame( '2025-06-18', $body->params->protocolVersion ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire field.
		$this->assertInstanceOf( stdClass::class, $body->params->capabilities, 'Empty capabilities must stay a JSON object.' );
	}

	/**
	 * A spec-following client sends no header on `initialize`.
	 */
	public function test_initialize_without_header_is_untouched() {
		$body = $this->dispatch_filter( $this->initialize_request( '2025-11-25', null ) );

		$this->assertSame( '2025-11-25', $body->params->protocolVersion ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire field.
	}

	/**
	 * A header the adapter can't negotiate is left for the adapter to reject.
	 */
	public function test_unknown_header_version_is_untouched() {
		$body = $this->dispatch_filter( $this->initialize_request( '2025-11-25', '1999-01-01' ) );

		$this->assertSame( '2025-11-25', $body->params->protocolVersion ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire field.
	}

	/**
	 * Other MCP servers on the site (e.g. the adapter's default server) are not ours to change.
	 */
	public function test_other_routes_are_untouched() {
		$body = $this->dispatch_filter( $this->initialize_request( '2025-11-25', '2025-06-18', '/mcp/mcp-adapter-default-server' ) );

		$this->assertSame( '2025-11-25', $body->params->protocolVersion ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire field.
	}

	/**
	 * Only `initialize` is rewritten; later requests carry no protocolVersion to align.
	 */
	public function test_non_initialize_requests_are_untouched() {
		$request = new WP_REST_Request( 'POST', '/woocommerce-claude/mcp' );
		$request->set_header( 'MCP-Protocol-Version', '2025-06-18' );
		$raw = '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}';
		$request->set_body( $raw );

		Plugin::instance()->align_initialize_protocol_version( null, rest_get_server(), $request );

		$this->assertSame( $raw, $request->get_body() );
	}
}
