<?php
/**
 * The briefing's five read abilities.
 *
 * @package WooCommerce\Briefing
 */

namespace WooCommerce\Briefing;

use WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Everything Briefing for WooCommerce can read from a store.
 *
 * The three analytics tools wrap the shared `wc-analytics/*` implementations
 * with narrower inputs, and take out anything about an individual person on
 * the way back (the totals tool's pseudonymised top customers, for one). Stock
 * is a fixed question rather than the shared rows tool, which can list orders
 * and customers. Store profile returns only the six facts the briefing uses.
 */
class Abilities {

	const CATEGORY = 'briefing-for-woocommerce';

	const TOTALS        = 'briefing/totals';
	const BREAKDOWN     = 'briefing/breakdown';
	const SERIES        = 'briefing/series';
	const STORE_PROFILE = 'briefing/store-profile';
	const STOCK_AT_RISK = 'briefing/stock-at-risk';

	/**
	 * The tools on the MCP server, and nothing else.
	 */
	const TOOLS = array( self::TOTALS, self::BREAKDOWN, self::SERIES, self::STORE_PROFILE, self::STOCK_AT_RISK );

	/**
	 * Keys removed from every analytics response, at any depth: per-person
	 * lists and the identifiers that would point at one person.
	 */
	const PERSON_KEYS = array( 'top_customers', 'privacy_note', 'customer_id', 'email', 'billing_email', 'first_name', 'last_name', 'phone' );

	/**
	 * Register the ability category (on `wp_abilities_api_categories_init`).
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Briefing for WooCommerce', 'briefing-for-woocommerce' ),
				'description' => __( 'Read-only store totals for the weekly briefing. Aggregates only: no orders, customers, names or emails.', 'briefing-for-woocommerce' ),
			)
		);
	}

	/**
	 * Register the five abilities (on `wp_abilities_api_init`).
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$dates = array(
			'date_start' => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
			),
			'date_end'   => array(
				'type'    => 'string',
				'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
			),
			'compare'    => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);

		self::add(
			self::TOTALS,
			__( 'Totals', 'briefing-for-woocommerce' ),
			__( 'Headline totals for a period against the one before: revenue, orders (with statuses), customers (new and returning), customer value (lifetime and repeat buying, no individual customers) or refunds.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'subject', 'date_start', 'date_end' ),
				'properties' => array(
					'subject' => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'orders', 'customers', 'customer_value', 'refunds' ),
					),
				) + $dates,
			),
			array( __CLASS__, 'totals' )
		);

		self::add(
			self::BREAKDOWN,
			__( 'Breakdown', 'briefing-for-woocommerce' ),
			__( 'One subject broken down by one dimension for a period against the one before: sales by category, country, payment or shipping method; attribution by channel, source, campaign or device; products by product or variation; refunds by product or country; coupons by code.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'subject', 'dimension', 'date_start', 'date_end' ),
				'properties' => array(
					'subject'   => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'attribution', 'products', 'refunds', 'coupons' ),
					),
					'dimension' => array(
						'type' => 'string',
						'enum' => array( 'category', 'country', 'payment_method', 'shipping_method', 'channel', 'source', 'medium', 'campaign', 'device', 'channel_source', 'product', 'variation', 'code' ),
					),
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 10,
					),
				) + $dates,
			),
			array( __CLASS__, 'breakdown' )
		);

		self::add(
			self::SERIES,
			__( 'Series', 'briefing-for-woocommerce' ),
			__( 'Customers or top products bucketed by day, week or month across a period, for trends such as "third week running".', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'subject', 'interval', 'date_start', 'date_end' ),
				'properties' => array(
					'subject'  => array(
						'type' => 'string',
						'enum' => array( 'customers', 'products' ),
					),
					'interval' => array(
						'type' => 'string',
						'enum' => array( 'day', 'week', 'month', 'auto' ),
					),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 10,
					),
				) + $dates,
			),
			array( __CLASS__, 'series' )
		);

		self::add(
			self::STORE_PROFILE,
			__( 'Store profile', 'briefing-for-woocommerce' ),
			__( "The store's name, currency, time zone and country, and whether it uses WooCommerce's order tables.", 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'properties' => (object) array(),
			),
			array( __CLASS__, 'store_profile' )
		);

		self::add(
			self::STOCK_AT_RISK,
			__( 'Stock at risk', 'briefing-for-woocommerce' ),
			__( 'Products that sold in the period and are now out of stock or on backorder ("out"), or in stock with at most low_stock left ("low"), best sellers first.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'kind', 'date_start', 'date_end' ),
				'properties' => array(
					'kind'       => array(
						'type' => 'string',
						'enum' => array( 'out', 'low' ),
					),
					'low_stock'  => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 1000,
						'default' => 5,
					),
					'limit'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 25,
					),
					'date_start' => $dates['date_start'],
					'date_end'   => $dates['date_end'],
				),
			),
			array( __CLASS__, 'stock_at_risk' )
		);
	}

	/**
	 * Register one read-only ability.
	 *
	 * @param string   $name         Ability ID.
	 * @param string   $label        Label.
	 * @param string   $description  Description.
	 * @param array    $input_schema Input schema.
	 * @param callable $execute      Execute callback.
	 */
	private static function add( $name, $label, $description, $input_schema, $execute ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => $execute,
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Same capability as WooCommerce's own analytics screens.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input = null ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * `briefing/totals`.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function totals( $input ) {
		return self::without_people( AnalyticsTotalsAbility::execute( self::pick( $input, array( 'subject', 'date_start', 'date_end', 'compare' ) ) ) );
	}

	/**
	 * `briefing/breakdown`.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function breakdown( $input ) {
		return self::without_people( AnalyticsBreakdownAbility::execute( self::pick( $input, array( 'subject', 'dimension', 'limit', 'date_start', 'date_end', 'compare' ) ) ) );
	}

	/**
	 * `briefing/series`.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function series( $input ) {
		return self::without_people( AnalyticsSeriesAbility::execute( self::pick( $input, array( 'subject', 'interval', 'limit', 'date_start', 'date_end', 'compare' ) ) ) );
	}

	/**
	 * `briefing/store-profile`: the six facts the briefing uses, in the same shape
	 * as WooCommerce for Claude's store profile, and nothing else (no admin email,
	 * address, payment or shipping set-up).
	 *
	 * @return array
	 */
	public static function store_profile() {
		return array(
			'version' => BRIEFING_FOR_WOOCOMMERCE_VERSION,
			'data'    => array(
				'identity' => array( 'name' => get_bloginfo( 'name' ) ),
				'locale'   => array(
					'currency'        => get_woocommerce_currency(),
					'currency_symbol' => get_woocommerce_currency_symbol(),
					'timezone'        => wp_timezone_string(),
				),
				'address'  => array( 'country' => WC()->countries->get_base_country() ),
				'features' => array(
					'hpos_enabled' => class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
						&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
				),
			),
		);
	}

	/**
	 * `briefing/stock-at-risk`: a fixed question over the shared rows engine,
	 * always about products.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function stock_at_risk( $input ) {
		$input = is_array( $input ) ? $input : array();
		$sold  = array(
			'field'    => 'units_sold_in_period',
			'operator' => 'greater_than',
			'value'    => 0,
		);
		$stock = 'low' === ( $input['kind'] ?? '' )
			? array(
				array(
					'field'    => 'stock_status',
					'operator' => 'is',
					'value'    => 'instock',
				),
				array(
					'field'    => 'stock_quantity',
					'operator' => 'less_than_or_equal',
					'value'    => (int) ( $input['low_stock'] ?? 5 ),
				),
			)
			: array(
				array(
					'field'    => 'stock_status',
					'operator' => 'is_in',
					'value'    => array( 'outofstock', 'onbackorder' ),
				),
			);

		return self::without_people(
			AnalyticsRowsAbility::execute(
				array(
					'entity'     => 'products',
					'mode'       => 'rows',
					'match'      => 'all',
					'filters'    => array_merge( $stock, array( $sold ) ),
					'limit'      => (int) ( $input['limit'] ?? 25 ),
					'orderby'    => 'revenue_in_period',
					'order'      => 'DESC',
					'date_start' => $input['date_start'] ?? null,
					'date_end'   => $input['date_end'] ?? null,
				)
			)
		);
	}

	/**
	 * Only the listed keys of the input, so nothing outside the schema reaches
	 * the shared implementation.
	 *
	 * @param mixed $input Validated input.
	 * @param array $keys  Keys to keep.
	 * @return array
	 */
	private static function pick( $input, $keys ) {
		return is_array( $input ) ? array_intersect_key( $input, array_flip( $keys ) ) : array();
	}

	/**
	 * Remove PERSON_KEYS from a response, at any depth.
	 *
	 * @param mixed $value Analytics response (or a WP_Error, passed through).
	 * @return mixed
	 */
	public static function without_people( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( $key, self::PERSON_KEYS, true ) ) {
				unset( $value[ $key ] );
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = self::without_people( $item );
			}
		}
		return $value;
	}
}
