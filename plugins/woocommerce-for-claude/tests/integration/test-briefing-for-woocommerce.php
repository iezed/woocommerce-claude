<?php
/**
 * Briefing for WooCommerce: its MCP server, key check, and the promise on its
 * landing page — "We read totals. Never people."
 *
 * Runs alongside WooCommerce for Claude (both are loaded by the bootstrap), so
 * it also covers the two plugins sharing one store and one commerce-abilities
 * package.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Briefing\Abilities;
use WooCommerce\Briefing\Plugin;

/**
 * Tests for plugins/briefing-for-woocommerce.
 */
class Test_Briefing_For_WooCommerce extends WP_UnitTestCase {
	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Description given to fixture keys, so tear_down removes only ours.
	 */
	const FIXTURE_KEY = 'Briefing test fixture';

	/**
	 * The original $_SERVER, restored in tear_down.
	 *
	 * @var array<string, mixed>
	 */
	private $original_server;

	/**
	 * Start each test with no credentials on the request.
	 */
	public function set_up() {
		parent::set_up();
		rest_get_server();
		$this->original_server = $_SERVER;
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
	}

	/**
	 * Restore $_SERVER and remove fixture keys.
	 */
	public function tear_down() {
		$_SERVER = $this->original_server;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture cleanup.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s", '%' . $wpdb->esc_like( self::FIXTURE_KEY ) . '%' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The server lives at /wp-json/briefing-for-woocommerce/mcp with exactly the
	 * five tools, and no resources or prompts.
	 */
	public function test_server_exposes_exactly_the_five_tools() {
		$server = \WP\MCP\Core\McpAdapter::instance()->get_server( Plugin::MCP_SERVER_ID );
		$this->assertNotNull( $server, 'Briefing MCP server must be registered.' );
		$this->assertSame( 'briefing-for-woocommerce', $server->get_server_route_namespace() );
		$this->assertSame( 'mcp', $server->get_server_route() );

		$names = array();
		foreach ( $server->get_tools() as $tool ) {
			$names[] = $tool->get_name();
		}
		sort( $names );
		$this->assertSame(
			array( 'briefing-breakdown', 'briefing-series', 'briefing-stock-at-risk', 'briefing-store-profile', 'briefing-totals' ),
			$names
		);
		$this->assertCount( 0, $server->get_resources() );
		$this->assertCount( 0, $server->get_prompts() );
	}

	/**
	 * WooCommerce for Claude's server is untouched by the second plugin.
	 */
	public function test_woocommerce_for_claude_server_still_registered() {
		$this->assertNotNull( \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' ) );
	}

	/**
	 * Read and read/write keys authenticate; write-only, wrong-secret and missing
	 * credentials don't.
	 */
	public function test_key_check() {
		$plugin = Plugin::instance();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array(
			'read'       => true,
			'read_write' => true,
			'write'      => false,
		) as $permissions => $expected ) {
			wp_set_current_user( 0 );
			$this->set_basic_auth( $this->insert_key( $admin, $permissions ) );
			$this->assertSame( $expected, $plugin->authenticate_mcp_request( new WP_REST_Request() ), "{$permissions} key" );
		}

		wp_set_current_user( 0 );
		list( $key ) = explode( ':', $this->insert_key( $admin, 'read' ) );
		$this->set_basic_auth( $key . ':cs_wrong' );
		$this->assertFalse( $plugin->authenticate_mcp_request( new WP_REST_Request() ), 'wrong secret' );
		$this->assertSame( 0, get_current_user_id() );

		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$this->assertFalse( $plugin->authenticate_mcp_request( new WP_REST_Request() ), 'no credential' );
	}

	/**
	 * The Authorization header works where PHP_AUTH_* aren't populated.
	 */
	public function test_key_check_reads_authorization_header() {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$request = new WP_REST_Request();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building an HTTP Basic auth header.
		$request->set_header( 'authorization', 'Basic ' . base64_encode( $this->insert_key( $admin, 'read' ) ) );
		$this->assertTrue( Plugin::instance()->authenticate_mcp_request( $request ) );
		$this->assertSame( $admin, get_current_user_id() );
	}

	/**
	 * Every tool needs manage_woocommerce.
	 */
	public function test_tools_require_manage_woocommerce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = wp_get_ability( Abilities::STORE_PROFILE )->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Store profile carries the six facts the briefing uses and nothing else.
	 */
	public function test_store_profile_is_only_six_facts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$profile = wp_get_ability( Abilities::STORE_PROFILE )->execute( array() );

		$this->assertSame( array( 'identity', 'locale', 'address', 'features' ), array_keys( $profile['data'] ) );
		$this->assertSame( array( 'name' ), array_keys( $profile['data']['identity'] ) );
		$this->assertSame( array( 'currency', 'currency_symbol', 'timezone' ), array_keys( $profile['data']['locale'] ) );
		$this->assertSame( array( 'country' ), array_keys( $profile['data']['address'] ) );
		$this->assertSame( array( 'hpos_enabled' ), array_keys( $profile['data']['features'] ) );
		$this->assertStringNotContainsString( '@', wp_json_encode( $profile ), 'No email address in the profile.' );
	}

	/**
	 * Customer value comes back without the pseudonymised top-customer list the
	 * shared totals tool includes.
	 */
	public function test_customer_value_has_no_individual_customers() {
		$this->seed_paid_order(
			array(
				'customer_id' => $this->seed_customer(),
				'total'       => 120,
				'date'        => '2026-03-04 12:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $this->seed_customer(),
				'total'       => 80,
				'date'        => '2026-03-05 12:00:00',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$input  = array(
			'subject'    => 'customer_value',
			'date_start' => '2026-03-02',
			'date_end'   => '2026-03-08',
		);
		$shared = \WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::execute( $input );
		$ours   = wp_get_ability( Abilities::TOTALS )->execute( $input );

		$this->assertStringContainsString( 'Customer #', wp_json_encode( $shared ), 'Fixture should produce a per-customer list to strip.' );
		$this->assertIsArray( $ours );
		$this->assertStringNotContainsString( 'Customer #', wp_json_encode( $ours ) );
		$this->assertStringNotContainsString( 'top_customers', wp_json_encode( $ours ) );
		$this->assertSame( Abilities::without_people( $shared ), $ours, 'Otherwise identical to the shared tool.' );
	}

	/**
	 * Only inputs in the schema reach the shared implementation: no way to ask
	 * totals for a subject outside the list.
	 */
	public function test_totals_refuses_subjects_outside_the_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = wp_get_ability( Abilities::TOTALS )->execute(
			array(
				'subject'    => 'tax',
				'date_start' => '2026-03-02',
				'date_end'   => '2026-03-08',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * Stock at risk lists products that sold in the period and are out of stock
	 * ("out") or at or under the threshold ("low"), and nothing else.
	 */
	public function test_stock_at_risk() {
		$out     = $this->seed_simple_product(
			array(
				'name'         => 'Sold out mug',
				'price'        => 12,
				'stock_status' => 'outofstock',
			)
		);
		$low     = $this->stocked_product( 'Nearly gone tee', 3 );
		$plenty  = $this->stocked_product( 'Plenty of socks', 40 );
		$unsold  = $this->stocked_product( 'Unsold hat', 1 );
		$shopper = $this->seed_customer();
		foreach ( array( $out, $low, $plenty ) as $product_id ) {
			$this->seed_paid_order(
				array(
					'customer_id' => $shopper,
					'total'       => 0,
					'date'        => '2026-03-04 12:00:00',
					'items'       => array(
						array(
							'product_id' => $product_id,
							'qty'        => 2,
						),
					),
				)
			);
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$week = array(
			'date_start' => '2026-03-02',
			'date_end'   => '2026-03-08',
		);
		$this->assertSame( array( $out ), $this->stock_ids( array( 'kind' => 'out' ) + $week ) );
		$this->assertSame( array( $low ), $this->stock_ids( array( 'kind' => 'low' ) + $week ) );
		$this->assertNotContains(
			$unsold,
			$this->stock_ids(
				array(
					'kind'      => 'low',
					'low_stock' => 50,
				) + $week
			),
			'Unsold products are left out.'
		);

		$result = wp_get_ability( Abilities::STOCK_AT_RISK )->execute( array( 'kind' => 'out' ) + $week );
		$this->assertSame( 'products', $result['entity'] );
	}

	/**
	 * Person-shaped keys are removed at any depth; everything else is kept.
	 */
	public function test_without_people_strips_nested_keys() {
		$this->assertSame(
			array(
				'summary' => array( 'customers' => 3 ),
				'rows'    => array( array( 'country' => 'GB' ) ),
			),
			Abilities::without_people(
				array(
					'summary'       => array( 'customers' => 3 ),
					'top_customers' => array( array( 'id' => 'Customer #1' ) ),
					'rows'          => array(
						array(
							'country' => 'GB',
							'email'   => 'a@example.test',
						),
					),
				)
			)
		);
	}

	/**
	 * Deactivation revokes Briefing's keys and leaves every other key alone.
	 */
	public function test_revoke_keys_only_removes_briefing_keys() {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->insert_key( $admin, 'read', 'Briefing for WooCommerce (beta) - API (2026-10-15 09:00:00) ' . self::FIXTURE_KEY );
		$this->insert_key( $admin, 'read', 'Some other app - API (2026-10-15 09:00:00) ' . self::FIXTURE_KEY );

		$this->assertSame( 1, Plugin::revoke_keys() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion.
		$left = $wpdb->get_col( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s", '%' . $wpdb->esc_like( self::FIXTURE_KEY ) ) );
		$this->assertCount( 1, $left );
		$this->assertStringStartsWith( 'Some other app', $left[0] );
	}

	/**
	 * Application-password auth is skipped for our MCP route only.
	 */
	public function test_app_password_auth_skipped_for_mcp_route_only() {
		$plugin = Plugin::instance();

		$_SERVER['REQUEST_URI'] = '/wp-json/briefing-for-woocommerce/mcp';
		$this->assertFalse( $plugin->exclude_mcp_route_from_app_password_auth( true ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
		$this->assertTrue( $plugin->exclude_mcp_route_from_app_password_auth( true ) );
	}

	/**
	 * WordPress's own update check offers a newer release from the Briefing site,
	 * through the Update URI header.
	 */
	public function test_wordpress_offers_newer_release() {
		$this->fake_latest( $this->release( '9.9.9' ) );
		wp_update_plugins();

		$file    = plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE );
		$updates = get_site_transient( 'update_plugins' );
		$this->assertArrayHasKey( $file, $updates->response, 'A newer release shows as an update.' );
		$this->assertSame( '9.9.9', $updates->response[ $file ]->new_version );
		$this->assertSame( $this->release( '9.9.9' )['package'], $updates->response[ $file ]->package );
	}

	/**
	 * The release already installed isn't offered.
	 */
	public function test_wordpress_ignores_same_release() {
		$this->fake_latest( $this->release( BRIEFING_FOR_WOOCOMMERCE_VERSION ) );
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$this->assertArrayNotHasKey( plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE ), $updates->response );
	}

	/**
	 * Only this repo's matching release asset is accepted as a package, and
	 * versions must look like versions.
	 */
	public function test_only_release_assets_are_accepted() {
		$good = $this->release( '1.2.3' );
		$this->assertSame( '1.2.3', \WooCommerce\Briefing\Updater::valid( $good )['version'] );
		foreach ( array(
			array( 'package' => 'https://evil.example/briefing-for-woocommerce.zip' ),
			array( 'package' => \WooCommerce\Briefing\Updater::PACKAGE_PREFIX . '1.2.4/briefing-for-woocommerce.zip' ),
			array( 'package' => 'http://github.com/woocommerce/woocommerce-claude/releases/download/briefing-for-woocommerce-v1.2.3/briefing-for-woocommerce.zip' ),
			array( 'version' => '1.2.3; rm' ),
		) as $bad ) {
			$this->assertNull( \WooCommerce\Briefing\Updater::valid( array_merge( $good, $bad ) ), wp_json_encode( $bad ) );
		}
		$this->assertNull( \WooCommerce\Briefing\Updater::valid( 'nope' ) );
	}

	/**
	 * A failed check offers nothing and is remembered for an hour, so a down
	 * site isn't asked on every page load.
	 */
	public function test_failed_check_is_cached() {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$calls ) {
				if ( \WooCommerce\Briefing\Updater::LATEST_URL !== $url ) {
					return $pre;
				}
				++$calls;
				return new WP_Error( 'http_request_failed', 'down' );
			},
			10,
			3
		);
		delete_site_transient( \WooCommerce\Briefing\Updater::TRANSIENT );

		$this->assertNull( \WooCommerce\Briefing\Updater::latest() );
		$this->assertNull( \WooCommerce\Briefing\Updater::latest() );
		$this->assertSame( 1, $calls );
		$this->assertFalse( \WooCommerce\Briefing\Updater::update( false, array(), plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE ) ) );
	}

	/**
	 * Other plugins' update checks are left alone.
	 */
	public function test_other_plugins_untouched() {
		$this->fake_latest( $this->release( '9.9.9' ) );
		$this->assertSame( 'theirs', \WooCommerce\Briefing\Updater::update( 'theirs', array(), 'someone-else/plugin.php' ) );
		$this->assertSame( 'theirs', \WooCommerce\Briefing\Updater::details( 'theirs', 'plugin_information', (object) array( 'slug' => 'someone-else' ) ) );
	}

	/**
	 * "View details" describes the latest release.
	 */
	public function test_details_box() {
		$this->fake_latest( $this->release( '9.9.9' ) );
		$info = \WooCommerce\Briefing\Updater::details( false, 'plugin_information', (object) array( 'slug' => 'briefing-for-woocommerce' ) );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertSame( $this->release( '9.9.9' )['package'], $info->download_link );
		$this->assertStringContainsString( 'briefing-for-woocommerce-v9.9.9', $info->sections['changelog'] );
	}

	/**
	 * Activation turns auto-updates on, once, keeping other plugins' choices.
	 */
	public function test_activation_turns_on_auto_updates() {
		update_site_option( 'auto_update_plugins', array( 'someone-else/plugin.php' ) );
		\WooCommerce\Briefing\Updater::enable_auto_updates();
		\WooCommerce\Briefing\Updater::enable_auto_updates();
		$this->assertSame(
			array( 'someone-else/plugin.php', plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE ) ),
			get_site_option( 'auto_update_plugins' )
		);
	}

	/**
	 * A release description as the Briefing site sends it.
	 *
	 * @param string $version Version.
	 * @return array
	 */
	private function release( $version ) {
		return array(
			'version'      => $version,
			'package'      => \WooCommerce\Briefing\Updater::PACKAGE_PREFIX . $version . '/briefing-for-woocommerce.zip',
			'requires'     => '6.9',
			'tested'       => '6.9',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Answer the Briefing site's latest-release URL with `$release`, and
	 * WordPress.org's update API with nothing.
	 *
	 * @param array $release Release description.
	 */
	private function fake_latest( array $release ) {
		delete_site_transient( \WooCommerce\Briefing\Updater::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $release ) {
				if ( \WooCommerce\Briefing\Updater::LATEST_URL === $url ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( $release ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				}
				if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'plugins'      => array(),
								'translations' => array(),
								'no_update'    => array(),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Product IDs in a stock-at-risk result.
	 *
	 * @param array $input Tool input.
	 * @return array<int, int>
	 */
	private function stock_ids( array $input ) {
		$result = wp_get_ability( Abilities::STOCK_AT_RISK )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return array_map(
			function ( $row ) {
				return (int) $row['product_id'];
			},
			$result['rows'] ?? array()
		);
	}

	/**
	 * A simple product with managed stock.
	 *
	 * @param string $name     Name.
	 * @param int    $quantity Stock left.
	 * @return int Product ID.
	 */
	private function stocked_product( $name, $quantity ) {
		$id      = $this->seed_simple_product(
			array(
				'name'  => $name,
				'price' => 20,
			)
		);
		$product = wc_get_product( $id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->save();
		return $id;
	}

	/**
	 * Insert a WooCommerce REST API key and return "ck:cs".
	 *
	 * @param int    $user_id     Key owner.
	 * @param string $permissions read, write or read_write.
	 * @param string $description Description (defaults to the fixture marker).
	 * @return string
	 */
	private function insert_key( $user_id, $permissions, $description = self::FIXTURE_KEY ) {
		global $wpdb;
		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => $user_id,
				'description'     => $description,
				'permissions'     => $permissions,
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			)
		);
		return $consumer_key . ':' . $consumer_secret;
	}

	/**
	 * Put a "ck:cs" credential on the request as Basic auth.
	 *
	 * @param string $credential "ck:cs".
	 */
	private function set_basic_auth( $credential ) {
		list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $credential, 2 );
	}
}
