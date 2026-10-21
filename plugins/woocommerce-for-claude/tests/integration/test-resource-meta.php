<?php
/**
 * Resource abilities carry their MCP metadata in both places adapters read it,
 * and run without input.
 *
 * Newer MCP adapters (0.5.0+) read `mcp.uri` / `mcp.annotations` and log a
 * deprecation warning on every server build when they fall back to the
 * top-level keys (issue #117). The adapter vendored in WooCommerce still reads
 * only the top-level keys. Both must be set, with the same values.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Abilities\CatalogSchemaAbility;
use WooCommerce\Claude\Abilities\StorePoliciesAbility;
use WooCommerce\Claude\Abilities\StoreProfileAbility;

/**
 * Tests for AbilitiesBootstrap::resource_meta() and the three resource abilities.
 */
class Test_Resource_Meta extends WP_UnitTestCase {

	/**
	 * Ability name => resource URI.
	 *
	 * @return array<string, string>
	 */
	private function resources() {
		return array(
			StoreProfileAbility::ABILITY_NAME  => StoreProfileAbility::RESOURCE_URI,
			CatalogSchemaAbility::ABILITY_NAME => CatalogSchemaAbility::RESOURCE_URI,
			StorePoliciesAbility::ABILITY_NAME => StorePoliciesAbility::RESOURCE_URI,
		);
	}

	/**
	 * `mcp.uri` and `mcp.annotations` are set and match the top-level keys.
	 */
	public function test_resources_set_mcp_and_top_level_meta() {
		foreach ( $this->resources() as $name => $uri ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "{$name} must be registered." );
			$meta = $ability->get_meta();

			$this->assertSame( $uri, $meta['mcp']['uri'] ?? null, "{$name}: mcp.uri" );
			$this->assertSame( $uri, $meta['uri'] ?? null, "{$name}: top-level uri, for WooCommerce's vendored adapter" );
			// Core fills top-level annotations with null defaults (`destructive`); compare what's set.
			$set = function ( $value ) {
				return null !== $value;
			};
			$this->assertEquals( array_filter( $meta['annotations'], $set ), $meta['mcp']['annotations'] ?? null, "{$name}: mcp.annotations" );
			$this->assertTrue( $meta['mcp']['annotations']['readonly'] );
			$this->assertTrue( $meta['show_in_rest'] );
		}
	}

	/**
	 * Resources run with no input, as MCP adapter 0.7.0 calls them, instead of
	 * failing `{ type: object }` validation on `null`.
	 */
	public function test_resources_run_without_input() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array_keys( $this->resources() ) as $name ) {
			$result = wp_get_ability( $name )->execute( null );
			$this->assertNotWPError( $result, is_wp_error( $result ) ? "{$name}: " . $result->get_error_message() : '' );
			$this->assertIsArray( $result, $name );
		}
	}

	/**
	 * The server still exposes the three resources at their URIs.
	 */
	public function test_server_exposes_resources_at_their_uris() {
		rest_get_server();
		$server = \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' );
		$uris   = array();
		foreach ( woocommerce_claude_tests_mcp_components( $server, 'resources' ) as $resource ) {
			$uris[] = $resource['uri'];
		}
		sort( $uris );

		$expected = array_values( $this->resources() );
		sort( $expected );
		$this->assertSame( $expected, $uris );
	}
}
