<?php
/**
 * The briefing's read abilities.
 *
 * @package WooCommerce\Briefing
 */

namespace WooCommerce\Briefing;

use WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility;
use WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility;
use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Everything Briefing for WooCommerce can read from a store.
 *
 * The analytics tools wrap the shared `wc-analytics/*` implementations with
 * narrower inputs, and take out anything that names or contacts a person on
 * the way back (the totals tool's top-customer list, for one). The rows tool
 * wraps the shared rows tool: it lists orders, products and customers (as
 * `Customer #N`, never a name), with its dates capped and no filter that
 * points at one person. The catalogue tools wrap the same store knowledge and
 * readiness scoring WooCommerce for Claude and Hey Woo use. Store profile
 * returns only the six facts the briefing uses.
 */
class Abilities {

	const CATEGORY = 'briefing-for-woocommerce';

	const TOTALS        = 'briefing/totals';
	const BREAKDOWN     = 'briefing/breakdown';
	const SERIES        = 'briefing/series';
	const STORE_PROFILE = 'briefing/store-profile';
	const STOCK_AT_RISK = 'briefing/stock-at-risk';
	const ROWS          = 'briefing/rows';

	const CATALOG              = 'briefing/catalog';
	const SEARCH_PRODUCTS      = 'briefing/search-products';
	const PRODUCT_DETAILS      = 'briefing/product-details';
	const READINESS_SCORE      = 'briefing/readiness-score';
	const RECOMMENDATIONS      = 'briefing/recommendations';
	const SUGGEST_IMPROVEMENTS = 'briefing/suggest-improvements';

	/**
	 * The tools the route runs, and nothing else.
	 */
	const TOOLS = array( self::TOTALS, self::BREAKDOWN, self::SERIES, self::STORE_PROFILE, self::STOCK_AT_RISK, self::ROWS, self::CATALOG, self::SEARCH_PRODUCTS, self::PRODUCT_DETAILS, self::READINESS_SCORE, self::RECOMMENDATIONS, self::SUGGEST_IMPROVEMENTS );

	/**
	 * The store knowledge consumer the catalogue tools read as, so their
	 * registry and filters stay apart from WooCommerce for Claude's and Hey Woo's.
	 */
	const KNOWLEDGE_CONSUMER = 'briefing-for-woocommerce';

	/**
	 * The rows tool has no large-range gate, so the plugin caps its dates: a
	 * range of at most ROWS_MAX_DAYS days, starting no more than
	 * ROWS_MAX_YEARS_BACK years ago and ending no later than today.
	 */
	const ROWS_MAX_DAYS       = 366;
	const ROWS_MAX_YEARS_BACK = 3;

	/**
	 * Most rows the rows tool lists, as WooCommerce for Claude's.
	 */
	const ROWS_MAX_LIMIT = 50;

	/**
	 * Rows-tool filter fields that point at one person or household rather
	 * than a group.
	 */
	const IDENTIFYING_FIELDS = array( 'customer_id', 'city', 'postcode', 'billing_city', 'billing_postcode' );

	/**
	 * Rows-tool filter fields allowed for orders and customers. An allowlist, so
	 * a field added to the shared registry stays refused here until it's been
	 * looked at. Products aren't people, so their filters aren't narrowed.
	 * Rows themselves carry no names, emails, phone numbers or addresses.
	 */
	const ROWS_FILTER_FIELDS = array(
		'orders'    => array( 'order_total', 'gross_total', 'num_items_sold', 'tax_total', 'shipping_total', 'discount_amount', 'status', 'date_created', 'returning_customer', 'currency', 'payment_method', 'billing_country', 'billing_state', 'shipping_country', 'shipping_state', 'attribution_channel', 'attribution_source', 'attribution_campaign', 'attribution_device', 'coupon_code', 'product_id' ),
		'customers' => array( 'country', 'state', 'date_registered', 'date_last_active', 'lifetime_orders_count', 'lifetime_spend', 'first_order_date', 'last_order_date' ),
	);

	/**
	 * Keys removed from every response, at any depth: per-person lists and the
	 * identifiers that would name, contact or locate one person. Customer rows
	 * keep country and state; the city and postcode go.
	 */
	const PERSON_KEYS = array( 'top_customers', 'privacy_note', 'customer_id', 'email', 'billing_email', 'first_name', 'last_name', 'phone', 'city', 'postcode', 'billing_city', 'billing_postcode', 'shipping_city', 'shipping_postcode', 'address_1', 'address_2' );

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
				'description' => __( 'Read-only store figures, orders and catalogue for the weekly briefing. Never a customer\'s name, email, phone number or address.', 'briefing-for-woocommerce' ),
			)
		);
	}

	/**
	 * Register the abilities (on `wp_abilities_api_init`).
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
			__( 'Headline totals for a period against the one before: revenue, orders (with statuses), customers (new and returning), customer value (lifetime and repeat buying, no individual customers), tax (collected, refunded and net, by rate), refunds, customer concentration (share of revenue from the top 10 customers and the top fifth, by spend in the period), fulfilment (hours from payment to completion: median, p90, buckets) or margin (from cost of goods, with how much of revenue has costs entered). Can\'t answer: who a customer is; names and contact details stay in the store\'s admin.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'subject', 'date_start', 'date_end' ),
				'properties' => array(
					'subject' => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'orders', 'customers', 'customer_value', 'tax', 'refunds', 'customer_concentration', 'fulfilment', 'margin' ),
					),
				) + $dates,
			),
			array( __CLASS__, 'totals' )
		);

		self::add(
			self::BREAKDOWN,
			__( 'Breakdown', 'briefing-for-woocommerce' ),
			__( 'One subject broken down by one dimension for a period: revenue by category, country, state (billing state or region), shipping_country, payment or shipping method; attribution by channel, source, campaign or device; products by product or variation; refunds by product, country or customer_type (new or returning); tax by rate; coupons by code; customers by country (new and returning); cohorts of first-time customers by the channel, coupon or product of their first order (repeat rate, average lifetime spend); product_pairs by pair (products bought together); fulfilment by shipping_method. Can\'t answer: who a customer is; names and contact details stay in the store\'s admin.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'subject', 'dimension', 'date_start', 'date_end' ),
				'properties' => array(
					'subject'   => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'attribution', 'products', 'refunds', 'tax', 'coupons', 'customers', 'cohorts', 'product_pairs', 'fulfilment' ),
					),
					'dimension' => array(
						'type' => 'string',
						'enum' => array( 'category', 'country', 'state', 'shipping_country', 'payment_method', 'shipping_method', 'channel', 'source', 'medium', 'campaign', 'device', 'channel_source', 'product', 'variation', 'rate', 'code', 'customer_type', 'coupon', 'pair' ),
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

		self::add(
			self::ROWS,
			__( 'Rows', 'briefing-for-woocommerce' ),
			__( 'Filtered questions the other tools can\'t shape, such as "orders over £100 from Germany with a coupon", "products in the men category and what each sold" or "customers who spent over £500 and haven\'t ordered in 90 days". Mode "aggregate" gives counts, sums and shares for everything that matched; mode "rows" lists the top matches (at most 50), each with a link to it in the store\'s admin. Take figures from aggregate, never by adding up rows. Customers are listed as Customer #N, never by name. Products filter on category by its slug. Filters can\'t use customer IDs, cities or postcodes: orders filter on totals, items, tax, shipping, discount, status, date_created, returning_customer, currency, payment_method, billing or shipping country and state, attribution, coupon_code and product_id; customers on country, state, lifetime orders and spend, and their registration, last-active and first and last order dates. Dates: at most 366 days, starting no more than 3 years ago and ending no later than today.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'entity', 'date_start', 'date_end' ),
				'properties' => array(
					'entity'     => array(
						'type' => 'string',
						'enum' => array( 'orders', 'products', 'customers' ),
					),
					'mode'       => array(
						'type'    => 'string',
						'enum'    => array( 'aggregate', 'rows' ),
						'default' => 'aggregate',
					),
					'filters'    => array(
						'type'     => 'array',
						'maxItems' => 10,
						'items'    => array(
							'type'       => 'object',
							'required'   => array( 'field', 'operator' ),
							'properties' => array(
								'field'    => array( 'type' => 'string' ),
								'operator' => array( 'type' => 'string' ),
								'value'    => array( 'type' => array( 'string', 'number', 'boolean', 'array' ) ),
							),
						),
						'default'  => array(),
					),
					'match'      => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'any' ),
						'default' => 'all',
					),
					'limit'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::ROWS_MAX_LIMIT,
						'default' => 25,
					),
					'orderby'    => array( 'type' => 'string' ),
					'order'      => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'DESC',
					),
					'date_start' => $dates['date_start'],
					'date_end'   => $dates['date_end'],
				),
			),
			array( __CLASS__, 'rows' )
		);

		$none = array(
			'type'       => 'object',
			'properties' => (object) array(),
		);

		self::add(
			self::CATALOG,
			__( 'Catalogue', 'briefing-for-woocommerce' ),
			__( 'How the catalogue is organised: the category tree (with each category\'s slug and product count), attribute terms, and how many products of each type. Read it to find the slug the rows and search tools filter categories by.', 'briefing-for-woocommerce' ),
			$none,
			array( __CLASS__, 'catalog' )
		);

		self::add(
			self::SEARCH_PRODUCTS,
			__( 'Search products', 'briefing-for-woocommerce' ),
			__( 'Search the catalogue by name or description, or list a category by its slug, with each product\'s details, completeness score and what it\'s missing. For sales by product, use the breakdown or rows tools.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'query'    => array(
						'type'        => 'string',
						'description' => 'Words to find in product names or descriptions.',
					),
					'category' => array(
						'type'        => 'string',
						'description' => 'A category slug.',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					),
				),
			),
			array( __CLASS__, 'search_products' )
		);

		self::add(
			self::PRODUCT_DETAILS,
			__( 'Product details', 'briefing-for-woocommerce' ),
			__( 'Everything about one product: description, attributes, images, categories, related products and its completeness score.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'required'   => array( 'product_id' ),
				'properties' => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			array( __CLASS__, 'product_details' )
		);

		self::add(
			self::READINESS_SCORE,
			__( 'AI readiness score', 'briefing-for-woocommerce' ),
			__( 'How ready the store is for AI shopping assistants to understand and recommend it, from 0 to 100, with a score for each factor: product completeness, structured data, store policies and content quality.', 'briefing-for-woocommerce' ),
			$none,
			array( __CLASS__, 'readiness_score' )
		);

		self::add(
			self::RECOMMENDATIONS,
			__( 'AI readiness recommendations', 'briefing-for-woocommerce' ),
			__( 'The changes that would raise the AI readiness score most, highest impact first.', 'briefing-for-woocommerce' ),
			$none,
			array( __CLASS__, 'recommendations' )
		);

		self::add(
			self::SUGGEST_IMPROVEMENTS,
			__( 'Suggest improvements', 'briefing-for-woocommerce' ),
			__( 'Specific improvements for one product (give product_id) or the whole store: descriptions, images, SEO or attributes.', 'briefing-for-woocommerce' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'focus'      => array(
						'type'    => 'string',
						'enum'    => array( 'description', 'images', 'seo', 'attributes', 'all' ),
						'default' => 'all',
					),
				),
			),
			array( __CLASS__, 'suggest_improvements' )
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
					// Never on an MCP adapter's default server: the briefing's
					// route is the only way in, behind its key check.
					'mcp'          => array( 'public' => false ),
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
	 * `briefing/rows`: the shared rows tool, with its dates capped and no
	 * identifying filters.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function rows( $input ) {
		$input  = self::pick( $input, array( 'entity', 'mode', 'filters', 'match', 'limit', 'orderby', 'order', 'date_start', 'date_end' ) );
		$entity = $input['entity'] ?? '';
		$mode   = $input['mode'] ?? 'aggregate';

		$dates = self::check_rows_dates( $input['date_start'] ?? '', $input['date_end'] ?? '' );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$filters = is_array( $input['filters'] ?? null ) ? $input['filters'] : array();
		foreach ( $filters as $filter ) {
			$field = is_array( $filter ) ? (string) ( $filter['field'] ?? '' ) : '';
			if ( in_array( $field, self::IDENTIFYING_FIELDS, true ) ) {
				return new \WP_Error(
					'briefing_rows_identifying_filter',
					sprintf(
						/* translators: %s: filter field name. */
						__( 'Briefing for WooCommerce can\'t filter on "%s": it can point at a single customer. Use country or state instead.', 'briefing-for-woocommerce' ),
						$field
					),
					array( 'status' => 400 )
				);
			}
			if ( isset( self::ROWS_FILTER_FIELDS[ $entity ] ) && ! in_array( $field, self::ROWS_FILTER_FIELDS[ $entity ], true ) ) {
				return new \WP_Error(
					'briefing_rows_filter_not_allowed',
					sprintf(
						/* translators: 1: filter field name, 2: orders or customers, 3: allowed field names. */
						__( 'Briefing for WooCommerce can\'t filter %2$s on "%1$s". Fields it can filter on: %3$s.', 'briefing-for-woocommerce' ),
						$field,
						$entity,
						implode( ', ', self::ROWS_FILTER_FIELDS[ $entity ] )
					),
					array( 'status' => 400 )
				);
			}
		}

		return self::without_people(
			AnalyticsRowsAbility::execute(
				array(
					'entity'     => $entity,
					'mode'       => $mode,
					'filters'    => $filters,
					'match'      => $input['match'] ?? 'all',
					'limit'      => min( self::ROWS_MAX_LIMIT, (int) ( $input['limit'] ?? 25 ) ),
					'orderby'    => $input['orderby'] ?? '',
					'order'      => $input['order'] ?? 'DESC',
					'date_start' => $dates['start'],
					'date_end'   => $dates['end'],
				)
			)
		);
	}

	/**
	 * `briefing/catalog`.
	 *
	 * @return array
	 */
	public static function catalog() {
		return self::without_people( StoreKnowledge::get_catalog_schema( self::knowledge() ) );
	}

	/**
	 * `briefing/search-products`.
	 *
	 * @param array $input Validated input.
	 * @return array|null
	 */
	public static function search_products( $input ) {
		$input = is_array( $input ) ? $input : array();
		return self::without_people(
			StoreKnowledge::get_products(
				array(
					'search'   => $input['query'] ?? null,
					'category' => $input['category'] ?? null,
					'page'     => $input['page'] ?? 1,
					'per_page' => $input['per_page'] ?? 20,
				),
				self::knowledge()
			)
		);
	}

	/**
	 * `briefing/product-details`.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error|null
	 */
	public static function product_details( $input ) {
		return self::without_people( StoreKnowledge::get_product( absint( $input['product_id'] ?? 0 ), self::knowledge() ) );
	}

	/**
	 * `briefing/readiness-score`.
	 *
	 * @return array
	 */
	public static function readiness_score() {
		return self::without_people( StoreKnowledge::get_readiness_score( self::knowledge() ) );
	}

	/**
	 * `briefing/recommendations`.
	 *
	 * @return array
	 */
	public static function recommendations() {
		return self::without_people( StoreKnowledge::get_recommendations( self::knowledge() ) );
	}

	/**
	 * `briefing/suggest-improvements`.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function suggest_improvements( $input ) {
		return self::without_people( StoreKnowledge::suggest_improvements( self::pick( $input, array( 'product_id', 'focus' ) ), self::knowledge() ) );
	}

	/**
	 * The store knowledge consumer, with its providers registered (once).
	 *
	 * @return string
	 */
	private static function knowledge() {
		static $registered = false;
		if ( ! $registered ) {
			StoreKnowledge::register_default_providers( self::KNOWLEDGE_CONSUMER );
			$registered = true;
		}
		return self::KNOWLEDGE_CONSUMER;
	}

	/**
	 * Check the rows tool's dates against its caps, in the store's time zone.
	 *
	 * @param string $date_start Start, YYYY-MM-DD.
	 * @param string $date_end   End, YYYY-MM-DD.
	 * @return array|\WP_Error { start, end } or an error saying which cap was hit.
	 */
	public static function check_rows_dates( $date_start, $date_end ) {
		$tz    = wp_timezone();
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_start, $tz );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_end, $tz );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $date_start || $end->format( 'Y-m-d' ) !== $date_end ) {
			return self::rows_date_error( __( 'date_start and date_end must be real dates in YYYY-MM-DD form.', 'briefing-for-woocommerce' ) );
		}

		$today    = new \DateTimeImmutable( 'today', $tz );
		$earliest = $today->modify( '-' . self::ROWS_MAX_YEARS_BACK . ' years' );
		if ( $start > $end ) {
			return self::rows_date_error( __( 'date_start must be on or before date_end.', 'briefing-for-woocommerce' ) );
		}
		if ( $end > $today ) {
			return self::rows_date_error(
				/* translators: %s: today's date. */
				sprintf( __( 'date_end can\'t be later than today (%s).', 'briefing-for-woocommerce' ), $today->format( 'Y-m-d' ) )
			);
		}
		if ( $start < $earliest ) {
			return self::rows_date_error(
				/* translators: 1: number of years, 2: earliest allowed date. */
				sprintf( __( 'date_start can be at most %1$d years ago (%2$s or later).', 'briefing-for-woocommerce' ), self::ROWS_MAX_YEARS_BACK, $earliest->format( 'Y-m-d' ) )
			);
		}
		$days = (int) $start->diff( $end )->days + 1;
		if ( $days > self::ROWS_MAX_DAYS ) {
			return self::rows_date_error(
				/* translators: 1: requested days, 2: maximum days. */
				sprintf( __( 'The range is %1$d days; the most this tool reads at once is %2$d.', 'briefing-for-woocommerce' ), $days, self::ROWS_MAX_DAYS )
			);
		}

		return array(
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * A rows-tool date error.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function rows_date_error( $message ) {
		return new \WP_Error( 'briefing_rows_date_range', $message, array( 'status' => 400 ) );
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
