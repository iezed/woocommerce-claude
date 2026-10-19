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
use WooCommerce\CommerceAbilities\Analytics\AnalyticsService;

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
	 * briefing's tools, and no resources or prompts.
	 */
	public function test_server_exposes_exactly_the_briefing_tools() {
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
			array( 'briefing-breakdown', 'briefing-rows', 'briefing-series', 'briefing-stock-at-risk', 'briefing-store-profile', 'briefing-totals' ),
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
				'subject'    => 'top_customers',
				'date_start' => '2026-03-02',
				'date_end'   => '2026-03-08',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * Tax totals and the per-rate breakdown come through, matching the shared
	 * tools.
	 */
	public function test_tax_totals_and_breakdown() {
		$rate = $this->seed_tax_rate(
			array(
				'country' => 'GB',
				'name'    => 'VAT',
				'rate'    => '20.0000',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $this->seed_customer(),
				'total'       => 120,
				'date'        => '2026-03-04 12:00:00',
				'tax_total'   => 20,
				'tax_rate_id' => $rate,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$week = array(
			'date_start' => '2026-03-02',
			'date_end'   => '2026-03-08',
		);

		$totals = wp_get_ability( Abilities::TOTALS )->execute( array( 'subject' => 'tax' ) + $week );
		$this->assertIsArray( $totals, is_wp_error( $totals ) ? $totals->get_error_message() : '' );
		$this->assertSame( 'tax', $totals['subject'] );
		$this->assertSame(
			Abilities::without_people( \WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::execute( array( 'subject' => 'tax' ) + $week ) ),
			$totals
		);

		$breakdown = wp_get_ability( Abilities::BREAKDOWN )->execute(
			array(
				'subject'   => 'tax',
				'dimension' => 'rate',
			) + $week
		);
		$this->assertIsArray( $breakdown, is_wp_error( $breakdown ) ? $breakdown->get_error_message() : '' );
		$this->assertSame( 'rate', $breakdown['dimension'] );
	}

	/**
	 * Orders and customers are aggregate-only on the rows tool; products can be
	 * listed.
	 */
	public function test_rows_lists_products_only() {
		$product = $this->seed_simple_product(
			array(
				'name'  => 'Listed mug',
				'price' => 10,
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $this->seed_customer(),
				'total'       => 0,
				'date'        => $this->days_ago( 2 ) . ' 12:00:00',
				'items'       => array(
					array(
						'product_id' => $product,
						'qty'        => 1,
					),
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$recent = $this->recent_range( 7 );

		foreach ( array( 'orders', 'customers' ) as $entity ) {
			$result = wp_get_ability( Abilities::ROWS )->execute(
				array(
					'entity' => $entity,
					'mode'   => 'rows',
				) + $recent
			);
			$this->assertWPError( $result, $entity );
			$this->assertSame( 'briefing_rows_aggregate_only', $result->get_error_code(), $entity );

			$aggregate = wp_get_ability( Abilities::ROWS )->execute( array( 'entity' => $entity ) + $recent );
			$this->assertIsArray( $aggregate, is_wp_error( $aggregate ) ? $aggregate->get_error_message() : $entity );
			$this->assertSame( 'aggregate', $aggregate['mode'] );
			$this->assertNull( $aggregate['rows'], $entity );
		}

		$products = wp_get_ability( Abilities::ROWS )->execute(
			array(
				'entity' => 'products',
				'mode'   => 'rows',
				'limit'  => 5,
			) + $recent
		);
		$this->assertIsArray( $products, is_wp_error( $products ) ? $products->get_error_message() : '' );
		$this->assertContains( $product, array_map( 'intval', wp_list_pluck( $products['rows'], 'product_id' ) ) );

		$too_many = wp_get_ability( Abilities::ROWS )->execute(
			array(
				'entity' => 'products',
				'mode'   => 'rows',
				'limit'  => Abilities::ROWS_MAX_LIMIT + 1,
			) + $recent
		);
		$this->assertWPError( $too_many, 'Limit above the cap is refused by the schema.' );
	}

	/**
	 * The rows tool's dates: at most 366 days, no more than three years back, no
	 * later than today, and real dates.
	 */
	public function test_rows_date_caps() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$today = new DateTimeImmutable( 'today', wp_timezone() );

		$ok = wp_get_ability( Abilities::ROWS )->execute(
			array(
				'entity'     => 'orders',
				'date_start' => $today->modify( '-365 days' )->format( 'Y-m-d' ),
				'date_end'   => $today->format( 'Y-m-d' ),
			)
		);
		$this->assertIsArray( $ok, '366 days ending today is allowed: ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : '' ) );

		$refused = array(
			'367 days'          => array( $today->modify( '-366 days' ), $today ),
			'ends tomorrow'     => array( $today->modify( '-5 days' ), $today->modify( '+1 day' ) ),
			'over 3 years back' => array( $today->modify( '-3 years -1 day' ), $today->modify( '-3 years +10 days' ) ),
			'start after end'   => array( $today, $today->modify( '-1 day' ) ),
		);
		foreach ( $refused as $label => $range ) {
			$result = wp_get_ability( Abilities::ROWS )->execute(
				array(
					'entity'     => 'orders',
					'date_start' => $range[0]->format( 'Y-m-d' ),
					'date_end'   => $range[1]->format( 'Y-m-d' ),
				)
			);
			$this->assertWPError( $result, $label );
			$this->assertSame( 'briefing_rows_date_range', $result->get_error_code(), $label );
		}

		$this->assertWPError( Abilities::check_rows_dates( '2026-02-30', '2026-03-02' ), 'Not a real date.' );
		$this->assertWPError(
			wp_get_ability( Abilities::ROWS )->execute( array( 'entity' => 'orders' ) ),
			'Dates are required.'
		);
	}

	/**
	 * Filters that can point at one customer are refused.
	 */
	public function test_rows_refuses_identifying_filters() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array(
			'customers' => array( 'customer_id', 'postcode', 'city' ),
			'orders'    => array( 'billing_postcode', 'billing_city' ),
		) as $entity => $fields ) {
			foreach ( $fields as $field ) {
				$result = wp_get_ability( Abilities::ROWS )->execute(
					array(
						'entity'  => $entity,
						'filters' => array(
							array(
								'field'    => $field,
								'operator' => 'is',
								'value'    => 'X',
							),
						),
					) + $this->recent_range( 7 )
				);
				$this->assertWPError( $result, "{$entity}.{$field}" );
				$this->assertSame( 'briefing_rows_identifying_filter', $result->get_error_code() );
			}
		}
	}

	/**
	 * A filter matching fewer than three orders or customers gives the count
	 * alone; three or more give the sums.
	 */
	public function test_rows_withholds_sums_for_small_groups() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$germany = array(
			'entity'  => 'orders',
			'filters' => array(
				array(
					'field'    => 'billing_country',
					'operator' => 'is',
					'value'    => 'DE',
				),
			),
		) + $this->recent_range( 7 );

		for ( $i = 0; $i < 2; $i++ ) {
			$this->seed_paid_order(
				array(
					'customer_id'     => $this->seed_customer(),
					'total'           => 150 + $i,
					'date'            => $this->days_ago( 1 ) . ' 10:00:00',
					'billing_country' => 'DE',
				)
			);
		}
		$small = wp_get_ability( Abilities::ROWS )->execute( $germany );
		$this->assertIsArray( $small, is_wp_error( $small ) ? $small->get_error_message() : '' );
		$this->assertSame( array( 'matched_count' => 2 ), $small['summary'] );
		$this->assertArrayNotHasKey( 'share_of_revenue_percent', $small['share_of_universe'] );
		$this->assertNull( $small['pipeline'] );
		$this->assertNull( $small['admin_equivalent'] );
		$this->assertStringNotContainsString( '150', wp_json_encode( $small['summary'] ) );

		$this->seed_paid_order(
			array(
				'customer_id'     => $this->seed_customer(),
				'total'           => 152,
				'date'            => $this->days_ago( 1 ) . ' 11:00:00',
				'billing_country' => 'DE',
			)
		);
		$this->flush_analytics_cache();
		$enough = wp_get_ability( Abilities::ROWS )->execute( $germany );
		$this->assertSame( 3, $enough['summary']['matched_count'] );
		$this->assertEqualsWithDelta( 453.0, $enough['summary']['net_revenue'], 0.01 );
	}

	/**
	 * A filter that leaves fewer than three orders out of the period gives the
	 * count alone too: its sums, taken from the universe the same response
	 * carries, would give the orders left out.
	 */
	public function test_rows_withholds_sums_when_few_are_left_out() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$germany = array(
			'entity'  => 'orders',
			'filters' => array(
				array(
					'field'    => 'billing_country',
					'operator' => 'is',
					'value'    => 'DE',
				),
			),
		) + $this->recent_range( 7 );

		foreach ( array( 'DE', 'DE', 'DE', 'FR' ) as $i => $country ) {
			$this->seed_paid_order(
				array(
					'customer_id'     => $this->seed_customer(),
					'total'           => 100 + $i,
					'date'            => $this->days_ago( 1 ) . ' 10:00:00',
					'billing_country' => $country,
				)
			);
		}
		$one_left_out = wp_get_ability( Abilities::ROWS )->execute( $germany );
		$this->assertIsArray( $one_left_out, is_wp_error( $one_left_out ) ? $one_left_out->get_error_message() : '' );
		$this->assertSame( array( 'matched_count' => 3 ), $one_left_out['summary'] );
		$this->assertArrayNotHasKey( 'share_of_revenue_percent', $one_left_out['share_of_universe'] );

		foreach ( array( 1, 2 ) as $i ) {
			$this->seed_paid_order(
				array(
					'customer_id'     => $this->seed_customer(),
					'total'           => 110 + $i,
					'date'            => $this->days_ago( 1 ) . ' 11:00:00',
					'billing_country' => 'FR',
				)
			);
		}
		$this->flush_analytics_cache();
		$three_left_out = wp_get_ability( Abilities::ROWS )->execute( $germany );
		$this->assertEqualsWithDelta( 303.0, $three_left_out['summary']['net_revenue'], 0.01 );
	}

	/**
	 * The pipeline and dashboard-matching blocks drop to counts when they, or
	 * what they add to the summary, are one or two orders.
	 */
	public function test_rows_withholds_sibling_sums_that_subtract_to_one_order() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$germany = array(
			'entity'  => 'orders',
			'filters' => array(
				array(
					'field'    => 'billing_country',
					'operator' => 'is',
					'value'    => 'DE',
				),
			),
		) + $this->recent_range( 7 );

		foreach ( array( 'completed', 'completed', 'completed', 'refunded', 'on-hold' ) as $i => $status ) {
			$this->seed_paid_order(
				array(
					'customer_id'     => $this->seed_customer(),
					'total'           => 200 + $i,
					'status'          => $status,
					'date'            => $this->days_ago( 1 ) . ' 10:00:00',
					'billing_country' => 'DE',
				)
			);
		}
		$result = wp_get_ability( Abilities::ROWS )->execute( $germany );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertArrayHasKey( 'net_revenue', $result['summary'], 'Three paid orders, none left out: sums stay.' );
		$this->assertSame( array( 'matched_count' => 1 ), $result['pipeline'], 'One on-hold order: count only.' );
		$this->assertSame( array( 'matched_count' ), array_keys( $result['admin_equivalent'] ), 'Less the summary, it is one refunded order: count only.' );
		$this->assertStringNotContainsString( '203', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( '204', wp_json_encode( $result ) );
	}

	/**
	 * Orders and customers filter only on Briefing's allowlist, and every field
	 * in the shared registries is either allowed or refused as identifying, so
	 * a field added there fails this test until it's been looked at.
	 */
	public function test_rows_filters_are_an_allowlist() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = wp_get_ability( Abilities::ROWS )->execute(
			array(
				'entity'  => 'orders',
				'filters' => array(
					array(
						'field'    => 'billing_email',
						'operator' => 'is',
						'value'    => 'someone@example.com',
					),
				),
			) + $this->recent_range( 7 )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'briefing_rows_filter_not_allowed', $result->get_error_code() );

		foreach ( array(
			'orders'    => 'qa_orders_field_registry',
			'customers' => 'qa_customers_field_registry',
		) as $entity => $method ) {
			$registry = new ReflectionMethod( AnalyticsService::class, $method );
			$registry->setAccessible( true );
			foreach ( array_keys( $registry->invoke( null ) ) as $field ) {
				$this->assertTrue(
					in_array( $field, Abilities::ROWS_FILTER_FIELDS[ $entity ], true ) || in_array( $field, Abilities::IDENTIFYING_FIELDS, true ),
					"{$entity}.{$field} is in the shared registry but neither allowed nor refused by Briefing."
				);
			}
		}
	}

	/**
	 * No tool output, for any subject, carries a person key, an email address or
	 * a pseudonymised customer label.
	 */
	public function test_no_person_keys_in_any_output() {
		$product = $this->seed_simple_product(
			array(
				'name'  => 'Privacy mug',
				'price' => 15,
			)
		);
		foreach ( array( 'GB', 'GB', 'DE', 'FR' ) as $i => $country ) {
			$this->seed_paid_order(
				array(
					'customer_id'      => $this->seed_customer( "shopper{$i}@example.test" ),
					'total'            => 0,
					'date'             => $this->days_ago( $i + 1 ) . ' 12:00:00',
					'billing_country'  => $country,
					'billing_email'    => "shopper{$i}@example.test",
					'shipping_country' => $country,
					'items'            => array(
						array(
							'product_id' => $product,
							'qty'        => 2,
						),
					),
				)
			);
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( $this->every_tool_call() as $label => $call ) {
			$result = wp_get_ability( $call[0] )->execute( $call[1] );
			$this->assertNotWPError( $result, $label . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assert_no_people( $result, $label );
		}
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
	 * Every tool with every subject (and a dimension each), over the last week.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	private function every_tool_call() {
		$week  = $this->recent_range( 7 );
		$calls = array();
		foreach ( array( 'revenue', 'orders', 'customers', 'customer_value', 'tax', 'refunds' ) as $subject ) {
			$calls[ "totals {$subject}" ] = array( Abilities::TOTALS, array( 'subject' => $subject ) + $week );
		}
		foreach ( array(
			'revenue'     => array( 'category', 'country', 'payment_method', 'shipping_method' ),
			'attribution' => array( 'channel' ),
			'products'    => array( 'product', 'variation' ),
			'refunds'     => array( 'product', 'country' ),
			'tax'         => array( 'rate' ),
			'coupons'     => array( 'code' ),
		) as $subject => $dimensions ) {
			foreach ( $dimensions as $dimension ) {
				$calls[ "breakdown {$subject} by {$dimension}" ] = array(
					Abilities::BREAKDOWN,
					array(
						'subject'   => $subject,
						'dimension' => $dimension,
					) + $week,
				);
			}
		}
		foreach ( array( 'customers', 'products' ) as $subject ) {
			$calls[ "series {$subject}" ] = array(
				Abilities::SERIES,
				array(
					'subject'  => $subject,
					'interval' => 'day',
				) + $week,
			);
		}
		foreach ( array( 'orders', 'customers', 'products' ) as $entity ) {
			$calls[ "rows {$entity}" ] = array( Abilities::ROWS, array( 'entity' => $entity ) + $week );
		}
		$calls['rows products listed'] = array(
			Abilities::ROWS,
			array(
				'entity' => 'products',
				'mode'   => 'rows',
			) + $week,
		);
		$calls['stock at risk']        = array( Abilities::STOCK_AT_RISK, array( 'kind' => 'low' ) + $week );
		$calls['store profile']        = array( Abilities::STORE_PROFILE, array() );
		return $calls;
	}

	/**
	 * Assert a tool result has no person key at any depth, no email address and
	 * no pseudonymised customer label.
	 *
	 * @param mixed  $value Tool result.
	 * @param string $label What was called.
	 */
	private function assert_no_people( $value, $label ) {
		$json = wp_json_encode( $value );
		$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $json, "{$label}: email address" );
		$this->assertStringNotContainsString( 'Customer #', $json, "{$label}: customer label" );
		$this->assertSame( array(), $this->person_keys_in( $value ), "{$label}: person keys" );
	}

	/**
	 * Person keys found in a value, at any depth.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string>
	 */
	private function person_keys_in( $value ) {
		$found = array();
		if ( ! is_array( $value ) ) {
			return $found;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( $key, Abilities::PERSON_KEYS, true ) ) {
				$found[] = $key;
			}
			$found = array_merge( $found, $this->person_keys_in( $item ) );
		}
		return $found;
	}

	/**
	 * A date some days before today, in the store's time zone.
	 *
	 * @param int $days Days ago.
	 * @return string YYYY-MM-DD.
	 */
	private function days_ago( $days ) {
		return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( "-{$days} days" )->format( 'Y-m-d' );
	}

	/**
	 * The last $days days, ending today.
	 *
	 * @param int $days Days in the range.
	 * @return array{date_start: string, date_end: string}
	 */
	private function recent_range( $days ) {
		return array(
			'date_start' => $this->days_ago( $days - 1 ),
			'date_end'   => $this->days_ago( 0 ),
		);
	}

	/**
	 * Clear cached analytics responses so a re-read sees new fixtures.
	 */
	private function flush_analytics_cache() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cache reset.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_woocommerce\\_claude\\_%' OR option_name LIKE '\\_transient\\_timeout\\_woocommerce\\_claude\\_%'" );
		wp_cache_flush();
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
