<?php
/**
 * Customer, basket, fulfilment and margin totals (InsightsService), and the
 * new revenue and refunds dimensions, through the shared verb tools.
 *
 * Invariants pinned here:
 *   - Customer concentration groups guests by billing email (any case) and
 *     returns totals only.
 *   - Customers by country count new and returning per country.
 *   - Refunds split by new and returning customers carry like-for-like rates.
 *   - Cohorts are customers whose first paid order was placed in the period,
 *     grouped by that order's channel, coupon or products.
 *   - Products bought together count orders, not lines.
 *   - Revenue by state is country-qualified and labelled; by shipping country
 *     reads the shipping address.
 *   - Fulfilment is payment to completion, with automatic completions and
 *     open orders counted apart.
 *   - Margin reads cost of goods where entered and says how much is covered,
 *     or says the feature is off.
 *   - None of it returns an email address or a customer ID.
 *
 * @package WooCommerce\Claude\Tests
 */

use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStatsStore;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility;

/**
 * Tests for the InsightsService subjects.
 */
class Test_Insights extends WP_UnitTestCase {
	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * The week every fixture lands in.
	 */
	const WEEK = array(
		'date_start' => '2026-03-02',
		'date_end'   => '2026-03-08',
		'compare'    => false,
	);

	/**
	 * Clear cached analytics responses between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cache reset.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%woocommerce\\_claude\\_%'" );
		wp_cache_flush();
	}

	/**
	 * Top 10 and top fifth shares, with a guest counted once across two
	 * orders under the same email in different case.
	 */
	public function test_customer_concentration() {
		$spends = array( 1000, 500, 100, 100, 100, 100, 100, 100, 100, 100, 100 );
		foreach ( $spends as $i => $spend ) {
			$this->order( $this->seed_customer(), $spend, '2026-03-0' . ( 2 + $i % 6 ) . ' 10:00:00' );
		}
		$this->guest_order( 'Repeat.Guest@example.test', 60, '2026-03-03 10:00:00' );
		$this->guest_order( 'repeat.guest@example.test', 40, '2026-03-05 10:00:00' );

		$result = $this->totals( 'customer_concentration' );
		$m      = $result['metrics'];

		$this->assertSame( 12, $m['customers_count'], 'The guest counts once.' );
		$this->assertEqualsWithDelta( 2500.0, $m['revenue'], 0.01 );
		$this->assertEqualsWithDelta( 2300.0, $m['top_10_revenue'], 0.01 );
		$this->assertSame( 92.0, $m['top_10_share_percent'] );
		$this->assertSame( 3, $m['top_20_percent_customers_count'] );
		$this->assertSame( 64.0, $m['top_20_percent_share_percent'] );
		$this->assertEqualsWithDelta( 100.0, $m['median_spend_per_customer'], 0.01 );
		$this->assert_no_people( $result );
	}

	/**
	 * Concentration compares against the previous period in percentage points.
	 */
	public function test_customer_concentration_comparison() {
		$this->order( $this->seed_customer(), 100, '2026-02-25 10:00:00' );
		$this->order( $this->seed_customer(), 300, '2026-03-03 10:00:00' );

		$result = AnalyticsTotalsAbility::execute(
			array(
				'subject' => 'customer_concentration',
				'compare' => true,
			) + self::WEEK
		);
		$this->assertSame( 1, $result['comparison']['metrics']['customers_count'] );
		$this->assertSame( 'percentage_points', $result['comparison']['changes']['top_10_share_percent']['unit'] );
		$this->assertNotNull( $result['note'], 'Ten or fewer customers is called out.' );
	}

	/**
	 * Customers by billing country, new and returning.
	 */
	public function test_customers_by_country() {
		$returning = $this->seed_customer();
		$this->order( $returning, 50, '2026-01-10 10:00:00', array( 'billing_country' => 'GB' ) );
		$this->order( $returning, 50, '2026-03-03 10:00:00', array( 'billing_country' => 'GB' ) );
		$this->order( $this->seed_customer(), 70, '2026-03-04 10:00:00', array( 'billing_country' => 'GB' ) );
		$this->order( $this->seed_customer(), 90, '2026-03-05 10:00:00', array( 'billing_country' => 'DE' ) );

		$result = $this->breakdown( 'customers', 'country' );
		$rows   = $this->by_key( $result['top_groups'] );

		$this->assertSame( 'country', $result['dimension'] );
		$this->assertSame( 3, $result['totals']['customers_count'] );
		$this->assertSame( 2, $rows['GB']['customers_count'] );
		$this->assertSame( 1, $rows['GB']['new_customers'] );
		$this->assertSame( 1, $rows['GB']['returning_customers'] );
		$this->assertSame( 'United Kingdom (UK)', $rows['GB']['label'] );
		$this->assertSame( 1, $rows['DE']['new_customers'] );
		$this->assertSame( 66.7, $rows['GB']['share_of_customers_percent'] );
		$this->assert_no_people( $result );
	}

	/**
	 * Refunds by new and returning customers.
	 */
	public function test_refunds_by_customer_type() {
		$product   = $this->seed_simple_product(
			array(
				'name'  => 'Refundable kettle',
				'price' => 40,
			)
		);
		$returning = $this->seed_customer();
		$this->order( $returning, 0, '2026-01-10 10:00:00', array(), array( $product ) );
		$repeat = $this->order( $returning, 0, '2026-03-03 10:00:00', array(), array( $product, $product ) );
		$this->order( $this->seed_customer(), 0, '2026-03-04 10:00:00', array(), array( $product ) );
		$this->seed_refund( $repeat, array( $product => 1 ), array( 'date' => '2026-03-05 10:00:00' ) );

		$result = $this->breakdown( 'refunds', 'customer_type' );
		$rows   = $this->by_key( $result['top_groups'] );

		$this->assertSame( array( 'returning' ), array_keys( $rows ) );
		$this->assertEqualsWithDelta( 40.0, $rows['returning']['refunds_amount'], 0.01 );
		$this->assertEqualsWithDelta( 80.0, $rows['returning']['gross_revenue'], 0.01, 'A partial refund leaves the order paid.' );
		$this->assertEqualsWithDelta( 50.0, $rows['returning']['refund_rate_percent'], 0.01 );
	}

	/**
	 * Cohorts by first-order channel, coupon and product: only customers whose
	 * first paid order was placed in the period, with repeat rate and lifetime
	 * spend.
	 */
	public function test_cohorts() {
		$mug = $this->seed_simple_product(
			array(
				'name'  => 'Cohort mug',
				'price' => 10,
			)
		);
		$tee = $this->seed_simple_product(
			array(
				'name'  => 'Cohort tee',
				'price' => 20,
			)
		);

		$repeat = $this->seed_customer();
		$first  = $this->order( $repeat, 0, '2026-03-03 10:00:00', array(), array( $mug ), array( 'channel' => 'Organic Search' ) );
		$this->order( $repeat, 0, '2026-04-10 10:00:00', array(), array( $tee ) );
		$this->apply_coupon_lookup( $first, 'SPRING10' );

		$this->order( $this->seed_customer(), 0, '2026-03-04 10:00:00', array(), array( $tee ), array( 'channel' => 'Direct' ) );

		$old = $this->seed_customer();
		$this->order( $old, 0, '2026-01-10 10:00:00', array(), array( $mug ), array( 'channel' => 'Direct' ) );
		$this->order( $old, 0, '2026-03-05 10:00:00', array(), array( $mug ), array( 'channel' => 'Direct' ) );

		$channel = $this->breakdown( 'cohorts', 'channel' );
		$this->assertSame( 2, $channel['totals']['customers_count'], 'The customer who first bought in January is not in March\'s cohort.' );
		$this->assertSame( 50.0, $channel['totals']['repeat_rate_percent'] );
		$rows = $this->by_key( $channel['top_groups'] );
		$this->assertSame( 100.0, $rows['Organic Search']['repeat_rate_percent'] );
		$this->assertEqualsWithDelta( 30.0, $rows['Organic Search']['avg_lifetime_spend'], 0.01 );
		$this->assertSame( 0.0, $rows['Direct']['repeat_rate_percent'] );
		$this->assertNotNull( $rows['Direct']['sample_size_caveat'] );

		$coupon = $this->by_key( $this->breakdown( 'cohorts', 'coupon' )['top_groups'] );
		$this->assertSame( 1, $coupon['spring10']['customers_count'] );
		$this->assertSame( 1, $coupon['(No coupon)']['customers_count'] );

		$product = $this->breakdown( 'cohorts', 'product' );
		$by_name = array_column( $product['top_groups'], null, 'label' );
		$this->assertSame( 1, $by_name['Cohort mug']['customers_count'] );
		$this->assertSame( 1, $by_name['Cohort tee']['customers_count'] );
		$this->assert_no_people( $product );
	}

	/**
	 * Products bought together, counted by orders.
	 */
	public function test_product_pairs() {
		$mug     = $this->seed_simple_product(
			array(
				'name'  => 'Pair mug',
				'price' => 10,
			)
		);
		$tee     = $this->seed_simple_product(
			array(
				'name'  => 'Pair tee',
				'price' => 20,
			)
		);
		$socks   = $this->seed_simple_product(
			array(
				'name'  => 'Pair socks',
				'price' => 5,
			)
		);
		$shopper = $this->seed_customer();
		$this->order( $shopper, 0, '2026-03-03 10:00:00', array(), array( $mug, $tee ) );
		$this->order( $shopper, 0, '2026-03-04 10:00:00', array(), array( $mug, $tee, $socks ) );
		$this->order( $shopper, 0, '2026-03-05 10:00:00', array(), array( $mug ) );

		$result = $this->breakdown( 'product_pairs', 'pair' );
		$top    = $result['top_groups'][0];

		$this->assertSame( 3, $result['totals']['orders_count'] );
		$this->assertSame( 2, $result['totals']['multi_product_orders_count'] );
		$this->assertSame( 'Pair mug + Pair tee', $top['label'] );
		$this->assertSame( 2, $top['orders_together'] );
		$this->assertSame( 3, $top['product_a']['orders_count'] );
		$this->assertSame( 66.7, $top['percent_of_a_orders_with_b'] );
		$this->assertSame( 100.0, $top['percent_of_b_orders_with_a'] );
		$this->assertCount( 3, $result['top_groups'] );
	}

	/**
	 * Revenue by billing state and by shipping country.
	 */
	public function test_revenue_by_state_and_shipping_country() {
		$this->order(
			$this->seed_customer(),
			100,
			'2026-03-03 10:00:00',
			array(
				'billing_country'  => 'US',
				'billing_state'    => 'CA',
				'shipping_country' => 'DE',
			)
		);
		$this->order(
			$this->seed_customer(),
			60,
			'2026-03-04 10:00:00',
			array(
				'billing_country'  => 'US',
				'billing_state'    => 'NY',
				'shipping_country' => 'US',
			)
		);

		$state = $this->by_key( $this->breakdown( 'revenue', 'state' )['top_groups'] );
		$this->assertEqualsWithDelta( 100.0, $state['US:CA']['net_revenue'], 0.01 );
		$this->assertSame( 'California, US', $state['US:CA']['label'] );
		$this->assertSame( 'New York, US', $state['US:NY']['label'] );

		$shipping = $this->by_key( $this->breakdown( 'revenue', 'shipping_country' )['top_groups'] );
		$this->assertEqualsWithDelta( 100.0, $shipping['DE']['net_revenue'], 0.01 );
		$this->assertEqualsWithDelta( 60.0, $shipping['US']['net_revenue'], 0.01 );
	}

	/**
	 * Fulfilment: median, p90 and buckets, overall and by shipping method.
	 */
	public function test_fulfilment() {
		$customer = $this->seed_customer();
		$this->fulfilled_order( $customer, '2026-03-03 08:00:00', 2, 'Royal Mail' );
		$this->fulfilled_order( $customer, '2026-03-03 09:00:00', 30, 'Royal Mail' );
		$this->fulfilled_order( $customer, '2026-03-04 09:00:00', 100, 'DPD' );
		$this->fulfilled_order( $customer, '2026-03-05 09:00:00', 0, 'Download' );
		$this->order( $customer, 25, '2026-03-06 09:00:00', array(), array(), array(), 'processing' );

		$m = $this->totals( 'fulfilment' )['metrics'];
		$this->assertSame( 3, $m['fulfilled_orders'] );
		$this->assertSame( 1, $m['automatic_completions'] );
		$this->assertSame( 1, $m['still_processing_orders'] );
		$this->assertSame( 30.0, $m['median_hours'] );
		$this->assertSame( 86.0, $m['p90_hours'] );
		$buckets = array_column( $m['buckets'], 'count', 'key' );
		$this->assertSame( 1, $buckets['within_1_day'] );
		$this->assertSame( 1, $buckets['1_to_2_days'] );
		$this->assertSame( 1, $buckets['3_to_7_days'] );

		$by_method = $this->by_key( $this->breakdown( 'fulfilment', 'shipping_method' )['top_groups'] );
		$this->assertSame( 2, $by_method['Royal Mail']['fulfilled_orders'] );
		$this->assertSame( 16.0, $by_method['Royal Mail']['median_hours'] );
		$this->assertSame( 100.0, $by_method['DPD']['median_hours'] );
	}

	/**
	 * Margin where costs are entered, with coverage; unavailable when the
	 * feature is off.
	 */
	public function test_margin() {
		$mug   = $this->seed_simple_product(
			array(
				'name'  => 'Costed mug',
				'price' => 30,
			)
		);
		$tee   = $this->seed_simple_product(
			array(
				'name'  => 'Uncosted tee',
				'price' => 10,
			)
		);
		$order = $this->order( $this->seed_customer(), 0, '2026-03-03 10:00:00', array(), array( $mug, $tee ) );
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $mug === $item->get_product_id() ) {
				wc_update_order_item_meta( $item_id, '_cogs_value', 12 );
			}
		}

		add_filter( 'pre_option_woocommerce_feature_cost_of_goods_sold_enabled', array( $this, 'no' ) );
		$off = $this->totals( 'margin' );
		remove_filter( 'pre_option_woocommerce_feature_cost_of_goods_sold_enabled', array( $this, 'no' ) );
		$this->assertFalse( $off['available'] );
		$this->assertNull( $off['metrics'] );

		add_filter( 'pre_option_woocommerce_feature_cost_of_goods_sold_enabled', array( $this, 'yes' ) );
		$this->assertTrue( \WooCommerce\CommerceAbilities\Analytics\InsightsService::cogs_enabled(), 'The feature filter should switch cost of goods on.' );
		$m = $this->totals( 'margin' )['metrics'];
		remove_filter( 'pre_option_woocommerce_feature_cost_of_goods_sold_enabled', array( $this, 'yes' ) );

		$this->assertEqualsWithDelta( 40.0, $m['product_revenue'], 0.01 );
		$this->assertEqualsWithDelta( 30.0, $m['revenue_with_costs'], 0.01 );
		$this->assertSame( 75.0, $m['coverage_percent'] );
		$this->assertEqualsWithDelta( 12.0, $m['cost_of_goods'], 0.01 );
		$this->assertEqualsWithDelta( 18.0, $m['gross_margin'], 0.01 );
		$this->assertSame( 60.0, $m['margin_percent'] );
	}

	/**
	 * The shared tools refuse a dimension that doesn't belong to the subject.
	 */
	public function test_dimensions_are_checked() {
		$this->assertWPError(
			AnalyticsBreakdownAbility::execute(
				array(
					'subject'   => 'cohorts',
					'dimension' => 'country',
				) + self::WEEK
			)
		);
		$this->assertWPError(
			AnalyticsBreakdownAbility::execute(
				array(
					'subject'   => 'customers',
					'dimension' => 'state',
				) + self::WEEK
			)
		);
	}

	/**
	 * Option filter: "yes".
	 *
	 * @return string
	 */
	public function yes() {
		return 'yes';
	}

	/**
	 * Option filter: "no".
	 *
	 * @return string
	 */
	public function no() {
		return 'no';
	}

	/**
	 * Run a totals subject over the fixture week.
	 *
	 * @param string $subject Subject.
	 * @return array
	 */
	private function totals( $subject ) {
		$result = AnalyticsTotalsAbility::execute( array( 'subject' => $subject ) + self::WEEK );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : $subject );
		return $result;
	}

	/**
	 * Run a breakdown over the fixture week.
	 *
	 * @param string $subject   Subject.
	 * @param string $dimension Dimension.
	 * @return array
	 */
	private function breakdown( $subject, $dimension ) {
		$result = AnalyticsBreakdownAbility::execute(
			array(
				'subject'   => $subject,
				'dimension' => $dimension,
			) + self::WEEK
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : "{$subject}/{$dimension}" );
		return $result;
	}

	/**
	 * Rows keyed by their key.
	 *
	 * @param array $rows top_groups rows.
	 * @return array
	 */
	private function by_key( $rows ) {
		return array_column( $rows, null, 'key' );
	}

	/**
	 * A paid order.
	 *
	 * @param int    $customer    WP user ID.
	 * @param float  $total       Total (ignored when products are given).
	 * @param string $date        Placed and paid at.
	 * @param array  $address     Address args.
	 * @param array  $products    Product IDs, one of each.
	 * @param array  $attribution Attribution args.
	 * @param string $status      Status.
	 * @return WC_Order
	 */
	private function order( $customer, $total, $date, $address = array(), $products = array(), $attribution = array(), $status = 'completed' ) {
		$items = array();
		foreach ( $products as $product_id ) {
			$items[] = array(
				'product_id' => $product_id,
				'qty'        => 1,
			);
		}
		return $this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => $total,
				'date'        => $date,
				'status'      => $status,
				'items'       => $items,
				'attribution' => $attribution,
			) + $address
		);
	}

	/**
	 * A guest order (no account) with a billing email.
	 *
	 * @param string $email Billing email.
	 * @param float  $total Total.
	 * @param string $date  Placed and paid at.
	 */
	private function guest_order( $email, $total, $date ) {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_total( $total );
		$order->set_status( 'completed' );
		$order->set_date_created( $date );
		$order->set_date_paid( $date );
		$order->set_date_completed( $date );
		$order->save();
		OrdersStatsStore::sync_order( $order->get_id() );
	}

	/**
	 * A completed order whose completion came $hours after payment, with a
	 * shipping line.
	 *
	 * @param int    $customer WP user ID.
	 * @param string $paid     Paid at.
	 * @param float  $hours    Hours to completion.
	 * @param string $method   Shipping line name.
	 */
	private function fulfilled_order( $customer, $paid, $hours, $method ) {
		$order    = $this->order( $customer, 20, $paid );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( $method );
		$shipping->set_total( 0 );
		$order->add_item( $shipping );
		$order->set_date_completed( gmdate( 'Y-m-d H:i:s', strtotime( $paid . ' UTC' ) + (int) round( $hours * HOUR_IN_SECONDS ) ) );
		$order->save();
		OrdersStatsStore::sync_order( $order->get_id() );
	}

	/**
	 * Record a coupon against an order in the coupon lookup table.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $code  Coupon code.
	 */
	private function apply_coupon_lookup( $order, $code ) {
		global $wpdb;
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( 1 );
		$coupon->save();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_coupon_lookup',
			array(
				'order_id'        => $order->get_id(),
				'coupon_id'       => $coupon->get_id(),
				'date_created'    => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
				'discount_amount' => 1,
			)
		);
	}

	/**
	 * No email address, customer ID or pseudonymised customer label.
	 *
	 * @param array $result Tool result.
	 */
	private function assert_no_people( $result ) {
		$json = wp_json_encode( $result );
		$this->assertStringNotContainsString( '@', $json );
		$this->assertStringNotContainsString( 'customer_id', $json );
		$this->assertStringNotContainsString( 'Customer #', $json );
	}
}
