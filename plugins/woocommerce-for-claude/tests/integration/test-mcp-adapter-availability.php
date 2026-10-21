<?php
/**
 * MCP Adapter availability detection and the setup-screen notice.
 *
 * WooCommerce 11.3 stops bundling the WordPress MCP adapter, so the
 * `/wp-json/woocommerce-claude/mcp` endpoint only exists while the MCP
 * Adapter plugin is active. These tests pin how the plugin tells the
 * three cases apart and what the merchant is shown for each.
 *
 * The "missing" case can't be simulated in-process (the adapter classes
 * are already loaded), so it is covered by manual testing against
 * WooCommerce 11.3 without the adapter plugin.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Plugin;
use WooCommerce\Claude\Setup\SetupPage;

/**
 * Integration tests for MCP Adapter detection.
 */
class Test_MCP_Adapter_Availability extends WP_UnitTestCase {

	/**
	 * Snapshot of the `wp_mcp_init` action count, restored after each test.
	 *
	 * @var int|null
	 */
	private $saved_wp_mcp_init = null;

	/**
	 * Snapshot `wp_mcp_init` so tests can hide it.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_actions;
		$this->saved_wp_mcp_init = $wp_actions['wp_mcp_init'] ?? null;
	}

	/**
	 * Restore `wp_mcp_init`.
	 */
	public function tear_down() {
		global $wp_actions;
		if ( null !== $this->saved_wp_mcp_init ) {
			$wp_actions['wp_mcp_init'] = $this->saved_wp_mcp_init; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the snapshot taken in set_up().
		}
		parent::tear_down();
	}

	/**
	 * Make the adapter look like WooCommerce's bundled library copy: the
	 * classes exist, but the plugin's main file never fired `wp_mcp_init`.
	 */
	private function simulate_bundled_adapter() {
		global $wp_actions;
		unset( $wp_actions['wp_mcp_init'] );
	}

	/**
	 * The tests environment runs the MCP Adapter plugin, as a WooCommerce
	 * 11.3 store must.
	 */
	public function test_adapter_plugin_is_detected() {
		$this->assertSame( 'plugin', Plugin::mcp_adapter_source() );
	}

	/**
	 * No notice when the adapter plugin is active.
	 */
	public function test_no_notice_with_adapter_plugin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertNull( SetupPage::mcp_adapter_notice() );
	}

	/**
	 * A bundled copy still works today, so the notice is a warning ahead
	 * of the WooCommerce 11.3 update rather than an error.
	 */
	public function test_bundled_adapter_shows_upgrade_warning() {
		$this->simulate_bundled_adapter();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'bundled', Plugin::mcp_adapter_source() );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertIsArray( $notice );
		$this->assertSame( 'warning', $notice['kind'] );
		$this->assertStringContainsString( '11.3', $notice['title'] );
	}

	/**
	 * An administrator gets a one-click install or activate link, with
	 * the core plugin-action nonce.
	 */
	public function test_administrator_gets_plugin_action_link() {
		$this->simulate_bundled_adapter();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertNotSame( '', $notice['action_url'] );
		$this->assertContains(
			$notice['action_label'],
			array( 'Install MCP Adapter', 'Activate MCP Adapter' )
		);

		$query = array();
		// wp_nonce_url() returns an HTML-escaped URL for use in href.
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $notice['action_url'] ), PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( '_wpnonce', $query );
	}

	/**
	 * A shop manager can open the setup screen but can't install or
	 * activate plugins, so they are told who can rather than shown a
	 * link that would fail.
	 */
	public function test_shop_manager_is_pointed_at_an_administrator() {
		$this->simulate_bundled_adapter();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertSame( '', $notice['action_url'] );
		$this->assertStringContainsString( 'Ask a site administrator', $notice['body'] );
	}
}
