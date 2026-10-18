<?php
/**
 * Customer, basket, fulfilment and margin totals.
 *
 * Sits beside AnalyticsService and uses its public date and status helpers.
 * Every method returns aggregates only: no customer IDs, order IDs, names or
 * emails leave a query. Where a customer has to be told apart from another
 * (concentration, cohorts), the grouping happens inside SQL and only the
 * per-group totals come back.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Analytics;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- SQL here interpolates only table names, the escaped paid-status list from paid_in(), integer IDs, and the report range from AnalyticsService::get_report_date_range_sql(), which arrives with its dates already bound. Every other value goes through $wpdb->prepare().

/**
 * Additive analytics subjects for the verb-shaped tools.
 */
class InsightsService {

	/**
	 * Most lines an order can have and still count towards products bought
	 * together. Larger (usually trade) orders would add hundreds of pairs each
	 * and swamp the result, so they're counted and left out.
	 */
	const PAIRS_MAX_LINES = 30;

	/**
	 * Fulfilment time buckets: key => [ label, upper bound in hours (exclusive), or null ].
	 */
	const FULFILMENT_BUCKETS = array(
		'within_1_day' => array( 'Within 1 day', 24 ),
		'1_to_2_days'  => array( '1–2 days', 48 ),
		'2_to_3_days'  => array( '2–3 days', 72 ),
		'3_to_7_days'  => array( '3–7 days', 168 ),
		'over_7_days'  => array( 'Over 7 days', null ),
	);

	/**
	 * Completions this soon after payment (minutes) count as automatic: the
	 * store completes the order itself (virtual or downloadable products).
	 */
	const AUTOMATIC_COMPLETION_MINUTES = 1;

	/**
	 * Customer concentration: how much of the period's revenue came from the
	 * biggest spenders in the period.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start (YYYY-MM-DD).
	 * @param string|null $date_end   Custom end (YYYY-MM-DD).
	 * @param bool        $compare    Include the previous period.
	 * @return array
	 */
	public static function fetch_customer_concentration( $period, $date_start, $date_end, $compare ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );

		return self::cached(
			'concentration',
			array( $dates['start'], $dates['end'], $compare ),
			function () use ( $dates, $compare ) {
				$metrics = self::concentration_metrics( $dates['start'], $dates['end'] );
				$result  = array(
					'period'     => self::period( $dates ),
					'currency'   => get_woocommerce_currency(),
					'metrics'    => $metrics,
					'definition' => 'Customers are told apart by account, and guests by billing email, inside the query; only the spend totals leave it. Spend is each customer\'s paid orders in this period (not lifetime), before refunds. top_10_share_percent is the share of the period\'s revenue from the 10 biggest spenders; top_20_percent_share_percent is the share from the biggest-spending fifth of customers.',
					'comparison' => null,
					'note'       => null,
				);

				if ( 0 === $metrics['customers_count'] ) {
					$result['note'] = 'No paid orders in this period.';
				} elseif ( $metrics['customers_count'] <= 10 ) {
					$result['note'] = 'With ' . $metrics['customers_count'] . ' customers, the top 10 is everyone, so the top-10 share is 100%. The top-fifth share is the more useful figure here.';
				}

				if ( $compare ) {
					$prev                 = AnalyticsService::get_previous_period( $dates['start'], $dates['end'] );
					$prev_metrics         = self::concentration_metrics( $prev['start'], $prev['end'] );
					$result['comparison'] = array(
						'period'  => $prev,
						'metrics' => $prev_metrics,
						'changes' => self::point_changes( $metrics, $prev_metrics, array( 'customers_count', 'revenue', 'top_10_share_percent', 'top_20_percent_share_percent' ) ),
					);
				}

				return $result;
			}
		);
	}

	/**
	 * Concentration metrics for one period.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array
	 */
	private static function concentration_metrics( $date_start, $date_end ) {
		global $wpdb;

		$os    = $wpdb->prefix . 'wc_order_stats';
		$cl    = $wpdb->prefix . 'wc_customer_lookup';
		$range = AnalyticsService::get_report_date_range_sql( 'os.', $date_start, $date_end );
		$paid  = self::paid_in();

		// The customer key (account, else lower-cased billing email, else the
		// order itself) is only grouped on, never selected.
		$spends = $wpdb->get_col(
			"SELECT SUM(os.net_total) AS spend
			FROM {$os} os
			LEFT JOIN {$cl} cl ON cl.customer_id = os.customer_id
			WHERE os.parent_id = 0 AND os.status IN ({$paid}) AND {$range}
			GROUP BY CASE
				WHEN cl.user_id IS NOT NULL AND cl.user_id > 0 THEN CONCAT('u', cl.user_id)
				WHEN cl.email IS NOT NULL AND cl.email != '' THEN CONCAT('e', LOWER(cl.email))
				WHEN os.customer_id IS NOT NULL AND os.customer_id > 0 THEN CONCAT('c', os.customer_id)
				ELSE CONCAT('o', os.order_id)
			END
			ORDER BY spend DESC"
		);

		$spends  = array_map( 'floatval', (array) $spends );
		$count   = count( $spends );
		$revenue = array_sum( $spends );
		$top_n   = (int) ceil( $count * 0.2 );
		$top_10  = array_sum( array_slice( $spends, 0, 10 ) );
		$top_20  = array_sum( array_slice( $spends, 0, $top_n ) );

		return array(
			'customers_count'                => $count,
			'revenue'                        => round( $revenue, 2 ),
			'avg_spend_per_customer'         => $count ? round( $revenue / $count, 2 ) : 0.0,
			'median_spend_per_customer'      => round( self::percentile( array_reverse( $spends ), 50 ), 2 ),
			'top_10_customers_count'         => min( 10, $count ),
			'top_10_revenue'                 => round( $top_10, 2 ),
			'top_10_share_percent'           => self::percent( $top_10, $revenue ),
			'top_20_percent_customers_count' => $top_n,
			'top_20_percent_revenue'         => round( $top_20, 2 ),
			'top_20_percent_share_percent'   => self::percent( $top_20, $revenue ),
		);
	}

	/**
	 * Customers by billing country, new and returning.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param bool        $compare    Include previous-period totals.
	 * @param int         $limit      Top N countries.
	 * @return array
	 */
	public static function fetch_customers_by_country( $period, $date_start, $date_end, $compare, $limit ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		$limit = max( 1, min( 50, (int) $limit ) );

		return self::cached(
			'customers_country',
			array( $dates['start'], $dates['end'], $compare, $limit ),
			function () use ( $dates, $compare, $limit ) {
				$groups = self::customers_by_country_groups( $dates['start'], $dates['end'] );
				$totals = self::customers_totals( $dates['start'], $dates['end'] );

				$rows = array();
				foreach ( array_slice( $groups, 0, $limit ) as $row ) {
					$customers = (int) $row['customers'];
					$rows[]    = array(
						'key'                        => $row['country'],
						'label'                      => self::country_label( $row['country'] ),
						'customers_count'            => $customers,
						'new_customers'              => (int) $row['new_customers'],
						'returning_customers'        => (int) $row['returning_customers'],
						'new_customer_share_percent' => self::percent( (int) $row['new_customers'], $customers ),
						'orders_count'               => (int) $row['orders'],
						'net_revenue'                => round( (float) $row['net_revenue'], 2 ),
						'share_of_customers_percent' => self::percent( $customers, $totals['customers_count'] ),
					);
				}

				$result = array(
					'period'     => self::period( $dates ),
					'currency'   => get_woocommerce_currency(),
					'totals'     => $totals + array( 'countries_count' => count( $groups ) ),
					'top_groups' => $rows,
					'definition' => 'Customers with a paid order in the period, by the billing country on those orders. New and returning follow each order\'s returning-customer flag, set when the order was placed, so a customer whose first and second orders both fall in the period counts as both; a customer who ordered with two billing countries counts in each.',
					'comparison' => null,
					'note'       => empty( $rows ) ? 'No paid orders in this period.' : null,
				);

				if ( $compare ) {
					$prev                 = AnalyticsService::get_previous_period( $dates['start'], $dates['end'] );
					$prev_totals          = self::customers_totals( $prev['start'], $prev['end'] );
					$result['comparison'] = array(
						'period'  => $prev,
						'totals'  => $prev_totals,
						'changes' => self::point_changes( $totals, $prev_totals, array( 'customers_count', 'new_customers', 'returning_customers' ) ),
					);
				}

				return $result;
			}
		);
	}

	/**
	 * Per-country customer counts.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array
	 */
	private static function customers_by_country_groups( $date_start, $date_end ) {
		global $wpdb;

		$os      = $wpdb->prefix . 'wc_order_stats';
		$range   = AnalyticsService::get_report_date_range_sql( 'os.', $date_start, $date_end );
		$paid    = self::paid_in();
		$address = self::address_join( 'os.order_id', 'billing', 'country' );

		return (array) $wpdb->get_results(
			"SELECT
				COALESCE(NULLIF({$address['expr']}, ''), '(Unassigned)') AS country,
				COUNT(DISTINCT os.customer_id) AS customers,
				COUNT(DISTINCT CASE WHEN os.returning_customer = 0 THEN os.customer_id END) AS new_customers,
				COUNT(DISTINCT CASE WHEN os.returning_customer = 1 THEN os.customer_id END) AS returning_customers,
				COUNT(*) AS orders,
				SUM(os.net_total) AS net_revenue
			FROM {$os} os
			{$address['join']}
			WHERE os.parent_id = 0 AND os.status IN ({$paid}) AND {$range}
			GROUP BY country
			ORDER BY customers DESC, net_revenue DESC",
			ARRAY_A
		);
	}

	/**
	 * Store-wide customer counts for a period.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array
	 */
	private static function customers_totals( $date_start, $date_end ) {
		global $wpdb;

		$os    = $wpdb->prefix . 'wc_order_stats';
		$range = AnalyticsService::get_report_date_range_sql( '', $date_start, $date_end );
		$paid  = self::paid_in();

		$row = $wpdb->get_row(
			"SELECT
				COUNT(DISTINCT customer_id) AS customers,
				COUNT(DISTINCT CASE WHEN returning_customer = 0 THEN customer_id END) AS new_customers,
				COUNT(DISTINCT CASE WHEN returning_customer = 1 THEN customer_id END) AS returning_customers
			FROM {$os}
			WHERE parent_id = 0 AND status IN ({$paid}) AND {$range}",
			ARRAY_A
		);

		return array(
			'customers_count'     => (int) ( $row['customers'] ?? 0 ),
			'new_customers'       => (int) ( $row['new_customers'] ?? 0 ),
			'returning_customers' => (int) ( $row['returning_customers'] ?? 0 ),
		);
	}

	/**
	 * Acquisition cohorts by what the first order looked like: its channel,
	 * its first coupon, or its products.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param string      $dimension  channel | coupon | product.
	 * @param int         $limit      Top N cohorts.
	 * @return array
	 */
	public static function fetch_cohorts( $period, $date_start, $date_end, $dimension, $limit ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		$limit = max( 1, min( 50, (int) $limit ) );

		return self::cached(
			'cohorts',
			array( $dates['start'], $dates['end'], $dimension, $limit ),
			function () use ( $dates, $dimension, $limit ) {
				global $wpdb;

				$cohort = self::cohort_sql( $dates['start'], $dates['end'] );
				$group  = self::cohort_group_sql( $dimension );

				$totals = $wpdb->get_row(
					"SELECT COUNT(*) AS customers, SUM(c.orders > 1) AS repeat_customers, AVG(c.spend) AS avg_spend, AVG(c.orders) AS avg_orders
					FROM ({$cohort}) c",
					ARRAY_A
				);
				$rows   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$group['expr']} AS group_key,
							COUNT(DISTINCT c.customer_id) AS customers,
							COUNT(DISTINCT CASE WHEN c.orders > 1 THEN c.customer_id END) AS repeat_customers,
							SUM(c.spend) / COUNT(*) AS avg_spend,
							SUM(c.orders) / COUNT(*) AS avg_orders
						FROM ({$cohort}) c
						{$group['join']}
						GROUP BY group_key
						ORDER BY customers DESC, avg_spend DESC
						LIMIT %d",
						$limit
					),
					ARRAY_A
				);

				$cohort_size = (int) ( $totals['customers'] ?? 0 );
				$top         = array();
				foreach ( (array) $rows as $row ) {
					$customers = (int) $row['customers'];
					$key       = ( null === $row['group_key'] || '' === $row['group_key'] ) ? $group['empty'] : (string) $row['group_key'];
					$top[]     = array(
						'key'                     => $key,
						'label'                   => self::cohort_label( $dimension, $key ),
						'customers_count'         => $customers,
						'share_of_cohort_percent' => self::percent( $customers, $cohort_size ),
						'repeat_customers'        => (int) $row['repeat_customers'],
						'repeat_rate_percent'     => self::percent( (int) $row['repeat_customers'], $customers ),
						'avg_lifetime_spend'      => round( (float) $row['avg_spend'], 2 ),
						'avg_lifetime_orders'     => round( (float) $row['avg_orders'], 2 ),
						'sample_size_caveat'      => $customers <= 5 ? 'Only ' . $customers . ' customers: one more repeat buyer would move the rate a lot.' : null,
					);
				}

				$definitions = array(
					'channel' => 'the channel their first order came through (order attribution)',
					'coupon'  => 'the first coupon on their first order, or none',
					'product' => 'the products on their first order (a customer whose first order had three products counts in all three, so cohorts overlap)',
				);

				return array(
					'period'     => self::period( $dates ),
					'currency'   => get_woocommerce_currency(),
					'totals'     => array(
						'customers_count'     => $cohort_size,
						'repeat_customers'    => (int) ( $totals['repeat_customers'] ?? 0 ),
						'repeat_rate_percent' => self::percent( (int) ( $totals['repeat_customers'] ?? 0 ), $cohort_size ),
						'avg_lifetime_spend'  => round( (float) ( $totals['avg_spend'] ?? 0 ), 2 ),
						'avg_lifetime_orders' => round( (float) ( $totals['avg_orders'] ?? 0 ), 2 ),
					),
					'top_groups' => $top,
					'definition' => 'Customers whose first paid order was placed in this period, grouped by ' . $definitions[ $dimension ] . '. Repeat rate is the share who have placed another paid order since, up to today; lifetime spend and orders run from the first order to today, before refunds. Recent cohorts have had less time to come back, so compare cohorts from the same period.',
					'note'       => 0 === $cohort_size ? 'No customers placed their first paid order in this period.' : null,
				);
			}
		);
	}

	/**
	 * SQL for the cohort: one row per customer whose first paid order was
	 * placed in the period, with their lifetime orders, spend and the first
	 * order's ID (used only to join the first order's channel, coupon or
	 * products; never selected out).
	 *
	 * Starts from customers with a paid order in the period (date index),
	 * then reads each one's history through the customer_id index, so it
	 * doesn't scan the whole order table.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return string
	 */
	private static function cohort_sql( $date_start, $date_end ) {
		global $wpdb;

		$os    = $wpdb->prefix . 'wc_order_stats';
		$paid  = self::paid_in();
		$start = esc_sql( $date_start . ' 00:00:00' );
		$end   = esc_sql( $date_end . ' 23:59:59' );

		return "SELECT h.customer_id,
				COUNT(*) AS orders,
				SUM(h.net_total) AS spend,
				CAST(SUBSTRING_INDEX(GROUP_CONCAT(h.order_id ORDER BY h.date_created, h.order_id), ',', 1) AS UNSIGNED) AS first_order_id
			FROM {$os} h
			INNER JOIN (
				SELECT DISTINCT customer_id FROM {$os}
				WHERE parent_id = 0 AND status IN ({$paid})
					AND date_created >= '{$start}' AND date_created <= '{$end}'
					AND customer_id IS NOT NULL AND customer_id > 0
			) active ON active.customer_id = h.customer_id
			WHERE h.parent_id = 0 AND h.status IN ({$paid})
			GROUP BY h.customer_id
			HAVING MIN(h.date_created) >= '{$start}' AND MIN(h.date_created) <= '{$end}'";
	}

	/**
	 * JOIN and group expression for a cohort dimension, keyed on c.first_order_id.
	 *
	 * @param string $dimension channel | coupon | product.
	 * @return array { join, expr, empty }
	 */
	private static function cohort_group_sql( $dimension ) {
		global $wpdb;

		switch ( $dimension ) {
			case 'coupon':
				$lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
				return array(
					'join'  => "LEFT JOIN (
							SELECT order_id, MIN(coupon_id) AS coupon_id FROM {$lookup} GROUP BY order_id
						) fc ON fc.order_id = c.first_order_id
						LEFT JOIN {$wpdb->posts} cp ON cp.ID = fc.coupon_id",
					'expr'  => "CASE WHEN fc.coupon_id IS NULL THEN '' ELSE LOWER(COALESCE(cp.post_title, CONCAT('#', fc.coupon_id))) END",
					'empty' => '(No coupon)',
				);

			case 'product':
				$lookup = $wpdb->prefix . 'wc_order_product_lookup';
				return array(
					'join'  => "INNER JOIN {$lookup} fp ON fp.order_id = c.first_order_id",
					'expr'  => 'fp.product_id',
					'empty' => '(Unknown product)',
				);

			case 'channel':
			default:
				$meta = AnalyticsService::get_order_meta_source();
				return array(
					'join'  => "LEFT JOIN {$meta['table']} fch ON fch.{$meta['id_column']} = c.first_order_id AND fch.meta_key = '_wc_order_attribution_origin'",
					'expr'  => 'fch.meta_value',
					'empty' => '(Unassigned)',
				);
		}
	}

	/**
	 * Label for a cohort key.
	 *
	 * @param string $dimension Cohort dimension.
	 * @param string $key       Group key.
	 * @return string
	 */
	private static function cohort_label( $dimension, $key ) {
		if ( 'product' === $dimension && ctype_digit( (string) $key ) ) {
			$title = get_the_title( (int) $key );
			return '' !== $title ? html_entity_decode( $title, ENT_QUOTES ) : 'Product #' . $key;
		}
		return (string) $key;
	}

	/**
	 * Products bought together: the product pairs that appear in the most
	 * orders in the period.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param int         $limit      Top N pairs.
	 * @return array
	 */
	public static function fetch_product_pairs( $period, $date_start, $date_end, $limit ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		$limit = max( 1, min( 50, (int) $limit ) );

		return self::cached(
			'product_pairs',
			array( $dates['start'], $dates['end'], $limit ),
			function () use ( $dates, $limit ) {
				global $wpdb;

				$os    = $wpdb->prefix . 'wc_order_stats';
				$opl   = $wpdb->prefix . 'wc_order_product_lookup';
				$range = AnalyticsService::get_report_date_range_sql( 'os.', $dates['start'], $dates['end'] );
				$paid  = self::paid_in();

				// Orders in the period by how many distinct products they hold.
				$baskets = "SELECT opl.order_id, COUNT(DISTINCT opl.product_id) AS products
					FROM {$os} os
					INNER JOIN {$opl} opl ON opl.order_id = os.order_id
					WHERE os.parent_id = 0 AND os.status IN ({$paid}) AND {$range}
					GROUP BY opl.order_id";

				$counts = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS orders,
							SUM(b.products >= 2) AS multi_product_orders,
							SUM(b.products > %d) AS large_orders_excluded
						FROM ({$baskets}) b",
						self::PAIRS_MAX_LINES
					),
					ARRAY_A
				);
				$pairs  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT a.product_id AS product_a, b.product_id AS product_b, COUNT(DISTINCT a.order_id) AS orders_together
						FROM (
							SELECT order_id FROM ({$baskets}) bk WHERE bk.products BETWEEN 2 AND %d
						) eligible
						INNER JOIN {$opl} a ON a.order_id = eligible.order_id
						INNER JOIN {$opl} b ON b.order_id = eligible.order_id AND a.product_id < b.product_id
						GROUP BY a.product_id, b.product_id
						ORDER BY orders_together DESC, a.product_id, b.product_id
						LIMIT %d",
						self::PAIRS_MAX_LINES,
						$limit
					),
					ARRAY_A
				);

				$product_orders = self::orders_per_product( $dates['start'], $dates['end'], $pairs );
				$multi          = (int) ( $counts['multi_product_orders'] ?? 0 );

				$rows = array();
				foreach ( (array) $pairs as $pair ) {
					$a        = (int) $pair['product_a'];
					$b        = (int) $pair['product_b'];
					$together = (int) $pair['orders_together'];
					$a_orders = $product_orders[ $a ] ?? 0;
					$b_orders = $product_orders[ $b ] ?? 0;
					$rows[]   = array(
						'key'                        => $a . '+' . $b,
						'label'                      => self::product_name( $a ) . ' + ' . self::product_name( $b ),
						'product_a'                  => array(
							'product_id'   => $a,
							'name'         => self::product_name( $a ),
							'orders_count' => $a_orders,
						),
						'product_b'                  => array(
							'product_id'   => $b,
							'name'         => self::product_name( $b ),
							'orders_count' => $b_orders,
						),
						'orders_together'            => $together,
						'share_of_multi_product_orders_percent' => self::percent( $together, $multi ),
						'percent_of_a_orders_with_b' => self::percent( $together, $a_orders ),
						'percent_of_b_orders_with_a' => self::percent( $together, $b_orders ),
					);
				}

				return array(
					'period'     => self::period( $dates ),
					'totals'     => array(
						'orders_count'                => (int) ( $counts['orders'] ?? 0 ),
						'multi_product_orders_count'  => $multi,
						'multi_product_share_percent' => self::percent( $multi, (int) ( $counts['orders'] ?? 0 ) ),
						'large_orders_excluded'       => (int) ( $counts['large_orders_excluded'] ?? 0 ),
					),
					'top_groups' => $rows,
					'definition' => 'Pairs of products that appeared in the same paid order in the period, by number of orders containing both. Variations count as their parent product. percent_of_a_orders_with_b is the share of orders containing the first product that also contained the second. Orders with more than ' . self::PAIRS_MAX_LINES . ' different products are left out (counted in large_orders_excluded).',
					'note'       => empty( $rows ) ? 'No orders in this period had two or more different products.' : null,
				);
			}
		);
	}

	/**
	 * Paid orders in the period containing each product in the given pairs.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @param array  $pairs      Pair rows with product_a / product_b.
	 * @return array<int, int>
	 */
	private static function orders_per_product( $date_start, $date_end, $pairs ) {
		global $wpdb;

		$ids = array();
		foreach ( (array) $pairs as $pair ) {
			$ids[] = (int) $pair['product_a'];
			$ids[] = (int) $pair['product_b'];
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$os    = $wpdb->prefix . 'wc_order_stats';
		$opl   = $wpdb->prefix . 'wc_order_product_lookup';
		$range = AnalyticsService::get_report_date_range_sql( 'os.', $date_start, $date_end );
		$paid  = self::paid_in();
		$in    = implode( ',', $ids );

		$rows = $wpdb->get_results(
			"SELECT opl.product_id, COUNT(DISTINCT opl.order_id) AS orders
			FROM {$os} os
			INNER JOIN {$opl} opl ON opl.order_id = os.order_id
			WHERE os.parent_id = 0 AND os.status IN ({$paid}) AND {$range} AND opl.product_id IN ({$in})
			GROUP BY opl.product_id",
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['product_id'] ] = (int) $row['orders'];
		}
		return $counts;
	}

	/**
	 * Fulfilment time — paid to completed — for orders paid in the period.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param bool        $compare    Include the previous period.
	 * @return array
	 */
	public static function fetch_fulfilment( $period, $date_start, $date_end, $compare ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );

		return self::cached(
			'fulfilment',
			array( $dates['start'], $dates['end'], $compare ),
			function () use ( $dates, $compare ) {
				$metrics = self::fulfilment_stats( self::fulfilment_minutes( $dates['start'], $dates['end'] ) );
				$metrics = $metrics + self::fulfilment_open( $dates['start'], $dates['end'] );

				$result = array(
					'period'     => self::period( $dates ),
					'metrics'    => $metrics,
					'definition' => self::fulfilment_definition(),
					'comparison' => null,
					'note'       => 0 === $metrics['fulfilled_orders'] ? 'No order paid in this period has been completed yet (or every completion was automatic).' : null,
				);

				if ( $compare ) {
					$prev                 = AnalyticsService::get_previous_period( $dates['start'], $dates['end'] );
					$prev_metrics         = self::fulfilment_stats( self::fulfilment_minutes( $prev['start'], $prev['end'] ) );
					$result['comparison'] = array(
						'period'  => $prev,
						'metrics' => $prev_metrics,
						'changes' => self::point_changes( $metrics, $prev_metrics, array( 'fulfilled_orders', 'median_hours', 'p90_hours' ) ),
					);
				}

				return $result;
			}
		);
	}

	/**
	 * Fulfilment time by shipping method.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param int         $limit      Top N methods by fulfilled orders.
	 * @return array
	 */
	public static function fetch_fulfilment_by_shipping_method( $period, $date_start, $date_end, $limit ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		$limit = max( 1, min( 50, (int) $limit ) );

		return self::cached(
			'fulfilment_shipping',
			array( $dates['start'], $dates['end'], $limit ),
			function () use ( $dates, $limit ) {
				$by_method = array();
				foreach ( self::fulfilment_minutes( $dates['start'], $dates['end'], true ) as $row ) {
					$method                 = ( null === $row['method'] || '' === $row['method'] ) ? '(No shipping)' : (string) $row['method'];
					$by_method[ $method ][] = (float) $row['minutes'];
				}

				$rows = array();
				foreach ( $by_method as $method => $minutes ) {
					$rows[] = array(
						'key'   => $method,
						'label' => $method,
					) + self::fulfilment_stats( $minutes );
				}
				usort(
					$rows,
					function ( $a, $b ) {
						return $b['fulfilled_orders'] <=> $a['fulfilled_orders'];
					}
				);

				$all = array();
				foreach ( $by_method as $minutes ) {
					$all = array_merge( $all, $minutes );
				}

				return array(
					'period'     => self::period( $dates ),
					'totals'     => self::fulfilment_stats( $all ) + self::fulfilment_open( $dates['start'], $dates['end'] ),
					'top_groups' => array_slice( $rows, 0, $limit ),
					'definition' => self::fulfilment_definition() . ' Orders are grouped by their shipping line\'s name (the first, if there are several); orders with no shipping line are "(No shipping)".',
					'note'       => empty( $rows ) ? 'No order paid in this period has been completed yet.' : null,
				);
			}
		);
	}

	/**
	 * Minutes from payment to completion for orders paid in the period and
	 * completed since.
	 *
	 * @param string $date_start  YYYY-MM-DD.
	 * @param string $date_end    YYYY-MM-DD.
	 * @param bool   $with_method Also return each order's shipping method.
	 * @return array Minutes, or rows of { minutes, method }.
	 */
	private static function fulfilment_minutes( $date_start, $date_end, $with_method = false ) {
		global $wpdb;

		$os    = $wpdb->prefix . 'wc_order_stats';
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$start = $date_start . ' 00:00:00';
		$end   = $date_end . ' 23:59:59';

		if ( $with_method ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT TIMESTAMPDIFF(MINUTE, os.date_paid, os.date_completed) AS minutes,
						(SELECT MIN(oi.order_item_name) FROM {$items} oi WHERE oi.order_id = os.order_id AND oi.order_item_type = 'shipping') AS method
					FROM {$os} os
					WHERE os.parent_id = 0 AND os.status = 'wc-completed'
						AND os.date_paid >= %s AND os.date_paid <= %s
						AND os.date_completed IS NOT NULL AND os.date_completed >= os.date_paid",
					$start,
					$end
				),
				ARRAY_A
			);
		}

		return array_map(
			'floatval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT TIMESTAMPDIFF(MINUTE, date_paid, date_completed)
					FROM {$os}
					WHERE parent_id = 0 AND status = 'wc-completed'
						AND date_paid >= %s AND date_paid <= %s
						AND date_completed IS NOT NULL AND date_completed >= date_paid",
					$start,
					$end
				)
			)
		);
	}

	/**
	 * Orders paid in the period that are still processing.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array { still_processing_orders }
	 */
	private static function fulfilment_open( $date_start, $date_end ) {
		global $wpdb;

		$os   = $wpdb->prefix . 'wc_order_stats';
		$open = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$os}
				WHERE parent_id = 0 AND status = 'wc-processing' AND date_paid >= %s AND date_paid <= %s",
				$date_start . ' 00:00:00',
				$date_end . ' 23:59:59'
			)
		);
		return array( 'still_processing_orders' => (int) $open );
	}

	/**
	 * Median, p90 and buckets for a list of fulfilment times in minutes.
	 * Automatic completions (within a minute of payment) are counted apart
	 * and left out of the times.
	 *
	 * @param array $minutes Minutes per order.
	 * @return array
	 */
	private static function fulfilment_stats( array $minutes ) {
		$automatic = 0;
		$hours     = array();
		foreach ( $minutes as $value ) {
			if ( $value < self::AUTOMATIC_COMPLETION_MINUTES ) {
				++$automatic;
			} else {
				$hours[] = $value / 60;
			}
		}
		sort( $hours );
		$count = count( $hours );

		$buckets = array();
		foreach ( self::FULFILMENT_BUCKETS as $key => $bucket ) {
			$buckets[ $key ] = array(
				'key'   => $key,
				'label' => $bucket[0],
				'count' => 0,
			);
		}
		foreach ( $hours as $value ) {
			foreach ( self::FULFILMENT_BUCKETS as $key => $bucket ) {
				if ( null === $bucket[1] || $value < $bucket[1] ) {
					++$buckets[ $key ]['count'];
					break;
				}
			}
		}
		foreach ( $buckets as $key => $bucket ) {
			$buckets[ $key ]['share_percent'] = self::percent( $bucket['count'], $count );
		}

		return array(
			'fulfilled_orders'      => $count,
			'automatic_completions' => $automatic,
			'median_hours'          => $count ? round( self::percentile( $hours, 50 ), 1 ) : null,
			'p90_hours'             => $count ? round( self::percentile( $hours, 90 ), 1 ) : null,
			'avg_hours'             => $count ? round( array_sum( $hours ) / $count, 1 ) : null,
			'buckets'               => array_values( $buckets ),
			'sample_size_caveat'    => ( $count > 0 && $count <= 5 ) ? 'Only ' . $count . ' fulfilled orders: the median and p90 will move a lot with one more.' : null,
		);
	}

	/**
	 * Shared fulfilment definition.
	 *
	 * @return string
	 */
	private static function fulfilment_definition() {
		return 'Hours from payment to the order being marked completed, for orders paid in this period that are completed now. Orders completed within a minute of payment are counted as automatic completions (usually virtual or downloadable products) and left out of the median, p90 and buckets. Orders paid in the period that are still processing are counted separately, so a recent period can look faster than it will end up. Marking an order completed usually means it was dispatched, but stores use the status differently.';
	}

	/**
	 * Margin from WooCommerce's cost of goods, where costs are entered.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start.
	 * @param string|null $date_end   Custom end.
	 * @param bool        $compare    Include the previous period.
	 * @return array
	 */
	public static function fetch_margin( $period, $date_start, $date_end, $compare ) {
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );

		if ( ! self::cogs_enabled() ) {
			return array(
				'period'     => self::period( $dates ),
				'currency'   => get_woocommerce_currency(),
				'available'  => false,
				'metrics'    => null,
				'definition' => self::margin_definition(),
				'comparison' => null,
				'note'       => 'Cost of goods is turned off in this store, so there are no costs to work margin out from. The merchant can turn it on in WooCommerce > Settings > Advanced > Features, then enter a cost on each product; orders placed after that carry costs.',
			);
		}

		return self::cached(
			'margin',
			array( $dates['start'], $dates['end'], $compare ),
			function () use ( $dates, $compare ) {
				$metrics = self::margin_metrics( $dates['start'], $dates['end'] );
				$result  = array(
					'period'     => self::period( $dates ),
					'currency'   => get_woocommerce_currency(),
					'available'  => true,
					'metrics'    => $metrics,
					'definition' => self::margin_definition(),
					'comparison' => null,
					'note'       => null,
				);

				if ( 0.0 === (float) $metrics['product_revenue'] ) {
					$result['note'] = 'No product sales in this period.';
				} elseif ( $metrics['coverage_percent'] < 50 ) {
					$result['note'] = 'Costs are entered for only ' . $metrics['coverage_percent'] . '% of product revenue, so the margin describes that part of the catalogue, not the whole store.';
				}

				if ( $compare ) {
					$prev                 = AnalyticsService::get_previous_period( $dates['start'], $dates['end'] );
					$prev_metrics         = self::margin_metrics( $prev['start'], $prev['end'] );
					$result['comparison'] = array(
						'period'  => $prev,
						'metrics' => $prev_metrics,
						'changes' => self::point_changes( $metrics, $prev_metrics, array( 'gross_margin', 'margin_percent', 'coverage_percent' ) ),
					);
				}

				return $result;
			}
		);
	}

	/**
	 * Margin metrics for one period.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array
	 */
	private static function margin_metrics( $date_start, $date_end ) {
		global $wpdb;

		$os    = $wpdb->prefix . 'wc_order_stats';
		$opl   = $wpdb->prefix . 'wc_order_product_lookup';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$range = AnalyticsService::get_report_date_range_sql( 'os.', $date_start, $date_end );
		$paid  = self::paid_in();

		$row = $wpdb->get_row(
			"SELECT
				SUM(opl.product_net_revenue) AS product_revenue,
				SUM(CASE WHEN cogs.meta_value IS NOT NULL THEN opl.product_net_revenue ELSE 0 END) AS covered_revenue,
				SUM(CASE WHEN cogs.meta_value IS NOT NULL THEN CAST(cogs.meta_value AS DECIMAL(26,8)) ELSE 0 END) AS cost,
				COUNT(*) AS line_count,
				SUM(cogs.meta_value IS NOT NULL) AS covered_lines
			FROM {$os} os
			INNER JOIN {$opl} opl ON opl.order_id = os.order_id
			LEFT JOIN {$meta} cogs ON cogs.order_item_id = opl.order_item_id AND cogs.meta_key = '_cogs_value'
			WHERE os.parent_id = 0 AND os.status IN ({$paid}) AND {$range}",
			ARRAY_A
		);

		$revenue = (float) ( $row['product_revenue'] ?? 0 );
		$covered = (float) ( $row['covered_revenue'] ?? 0 );
		$cost    = (float) ( $row['cost'] ?? 0 );
		$margin  = $covered - $cost;

		return array(
			'product_revenue'       => round( $revenue, 2 ),
			'revenue_with_costs'    => round( $covered, 2 ),
			'coverage_percent'      => self::percent( $covered, $revenue ),
			'cost_of_goods'         => round( $cost, 2 ),
			'gross_margin'          => round( $margin, 2 ),
			'margin_percent'        => $covered > 0 ? round( $margin / $covered * 100, 1 ) : null,
			'line_items'            => (int) ( $row['line_count'] ?? 0 ),
			'line_items_with_costs' => (int) ( $row['covered_lines'] ?? 0 ),
		);
	}

	/**
	 * Shared margin definition.
	 *
	 * @return string
	 */
	private static function margin_definition() {
		return 'Gross margin from WooCommerce\'s cost of goods: product revenue (after discounts, before tax, shipping and refunds) minus the cost recorded on each order line when the order was placed. Only lines with a cost count towards the margin; coverage_percent is the share of product revenue those lines make up. Lines for products with no cost entered (or a cost of zero) are left out rather than counted as free.';
	}

	/**
	 * Whether WooCommerce's cost of goods feature is on.
	 *
	 * @return bool
	 */
	public static function cogs_enabled() {
		$util = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		return class_exists( $util ) && $util::feature_is_enabled( 'cost_of_goods_sold' );
	}

	/**
	 * Address JOIN and expression for an order ID expression, HPOS-aware.
	 *
	 * @param string $order_id_expr SQL expression for the order ID.
	 * @param string $type          billing | shipping.
	 * @param string $field         country | state.
	 * @return array { join, expr }
	 */
	private static function address_join( $order_id_expr, $type, $field ) {
		global $wpdb;

		$source = AnalyticsService::get_order_addresses_source();
		$alias  = "addr_{$type}_{$field}";
		if ( 'hpos' === $source['mode'] ) {
			return array(
				'join' => "LEFT JOIN {$source['table']} {$alias} ON {$alias}.order_id = {$order_id_expr} AND {$alias}.address_type = '{$type}'",
				'expr' => "{$alias}.{$field}",
			);
		}
		return array(
			'join' => "LEFT JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = {$order_id_expr} AND {$alias}.meta_key = '_{$type}_{$field}'",
			'expr' => "{$alias}.meta_value",
		);
	}

	/**
	 * Paid statuses as an escaped SQL list.
	 *
	 * @return string
	 */
	private static function paid_in() {
		return "'" . implode( "', '", array_map( 'esc_sql', AnalyticsService::get_paid_statuses() ) ) . "'";
	}

	/**
	 * Cache a result in a transient keyed on its inputs, the date basis and
	 * the paid statuses.
	 *
	 * @param string   $name    Result name.
	 * @param array    $parts   Inputs (strings, numbers, booleans).
	 * @param callable $compute Builds the result.
	 * @return array
	 */
	private static function cached( $name, array $parts, callable $compute ) {
		$key = 'woocommerce_claude_insights_' . $name . '_' . md5(
			wp_json_encode( $parts ) . '|' . AnalyticsService::get_date_column() . '|' . implode( ',', AnalyticsService::get_paid_statuses() ) . '|' . ( self::cogs_enabled() ? 1 : 0 )
		);
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		$result = $compute();
		set_transient( $key, $result, AnalyticsService::CACHE_TTL );
		return $result;
	}

	/**
	 * Period block.
	 *
	 * @param array $dates resolve_dates() output.
	 * @return array
	 */
	private static function period( $dates ) {
		return array(
			'start' => $dates['start'],
			'end'   => $dates['end'],
			'label' => $dates['label'] ?? 'Custom range',
		);
	}

	/**
	 * A share as a percentage to one decimal place; 0.0 with no denominator.
	 *
	 * @param float $part  Part.
	 * @param float $whole Whole.
	 * @return float
	 */
	private static function percent( $part, $whole ) {
		return $whole > 0 ? round( $part / $whole * 100, 1 ) : 0.0;
	}

	/**
	 * Linear-interpolated percentile of an ascending list.
	 *
	 * @param array $sorted Ascending values.
	 * @param int   $p      Percentile (0–100).
	 * @return float
	 */
	private static function percentile( array $sorted, $p ) {
		$n = count( $sorted );
		if ( 0 === $n ) {
			return 0.0;
		}
		$sorted = array_values( $sorted );
		$rank   = ( $p / 100 ) * ( $n - 1 );
		$low    = (int) floor( $rank );
		$high   = (int) ceil( $rank );
		return (float) ( $sorted[ $low ] + ( $sorted[ $high ] - $sorted[ $low ] ) * ( $rank - $low ) );
	}

	/**
	 * Changes between two metric blocks: the difference, and for values that
	 * aren't already percentages, the percentage change.
	 *
	 * @param array $current  Current metrics.
	 * @param array $previous Previous metrics.
	 * @param array $keys     Keys to compare.
	 * @return array
	 */
	private static function point_changes( $current, $previous, $keys ) {
		$changes = array();
		foreach ( $keys as $key ) {
			$now  = $current[ $key ] ?? null;
			$then = $previous[ $key ] ?? null;
			if ( null === $now || null === $then ) {
				$changes[ $key ] = null;
				continue;
			}
			$is_percent      = '_percent' === substr( $key, -8 );
			$difference      = round( (float) $now - (float) $then, 2 );
			$changes[ $key ] = array(
				'difference'     => $difference,
				'unit'           => $is_percent ? 'percentage_points' : null,
				'percent_change' => ( ! $is_percent && 0.0 !== (float) $then ) ? round( $difference / abs( (float) $then ) * 100, 1 ) : null,
				'direction'      => $difference > 0 ? 'up' : ( $difference < 0 ? 'down' : 'flat' ),
			);
		}
		return $changes;
	}

	/**
	 * Country name for a code, or the code.
	 *
	 * @param string $code Country code.
	 * @return string
	 */
	private static function country_label( $code ) {
		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		return isset( $countries[ $code ] ) ? html_entity_decode( $countries[ $code ], ENT_QUOTES ) : (string) $code;
	}

	/**
	 * Product name, or "Product #ID" when it has gone.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function product_name( $product_id ) {
		$title = get_the_title( (int) $product_id );
		return '' !== $title ? html_entity_decode( $title, ENT_QUOTES ) : 'Product #' . (int) $product_id;
	}
}
