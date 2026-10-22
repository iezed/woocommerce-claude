<?php
/**
 * Plugin Name: Briefing for WooCommerce
 * Plugin URI: https://briefing.woocommerce.com/
 * Description: Lets Briefing for WooCommerce read your store's figures, orders and catalogue, with a read-only key you approve. Never a customer's name, email, phone number or address.
 * Version: 0.5.0
 * Author: Automattic
 * Author URI: https://automattic.com/
 * Text Domain: briefing-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 10.6
 * WC tested up to: 10.7
 * Requires Plugins: woocommerce
 * Update URI: https://briefing.woocommerce.com/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WooCommerce\Briefing
 */

defined( 'ABSPATH' ) || exit;

define( 'BRIEFING_FOR_WOOCOMMERCE_VERSION', '0.5.0' );
define( 'BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE', __FILE__ );
define( 'BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

briefing_for_woocommerce_load_commerce_abilities();

// Updates come from the Briefing site (see the Update URI header), with or without WooCommerce active.
require_once BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-updater.php';
\WooCommerce\Briefing\Updater::init();
register_activation_hook( __FILE__, array( \WooCommerce\Briefing\Updater::class, 'enable_auto_updates' ) );

/**
 * Load the shared commerce-abilities classes the briefing reads through.
 *
 * Only the analytics services, the verb-shaped abilities, and the store
 * knowledge and readiness scoring it calls are loaded; the package's Loader is not initialised, so this plugin registers no
 * `wc-analytics/*` abilities of its own. Classes another plugin (WooCommerce for
 * Claude, Hey Woo) has already loaded are skipped, so the plugins can run side
 * by side. Same loading approach as WooCommerce for Claude: Composer's
 * autoloader class name is stable per lock file, so two plugins each requiring
 * vendor/autoload.php can fatal.
 */
function briefing_for_woocommerce_load_commerce_abilities() {
	$package_dirs = array(
		dirname( dirname( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR ) ) . '/php-packages/commerce-abilities/src/',
		BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/',
	);
	$classes      = array(
		\WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::class => 'Abilities/class-large-range-gate.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::class => 'Abilities/class-analytics-totals-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility::class => 'Abilities/class-analytics-breakdown-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility::class => 'Abilities/class-analytics-series-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility::class => 'Abilities/class-analytics-rows-ability.php',
		\WooCommerce\CommerceAbilities\Analytics\AnalyticsService::class => 'Analytics/class-analytics-service.php',
		\WooCommerce\CommerceAbilities\Analytics\InsightsService::class => 'Analytics/class-insights-service.php',
		\WooCommerce\CommerceAbilities\Knowledge\KnowledgeProvider::class => 'Knowledge/interface-knowledge-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\KnowledgeRegistry::class => 'Knowledge/class-knowledge-registry.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\StoreProfileProvider::class => 'Knowledge/providers/class-store-profile-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\CatalogProvider::class => 'Knowledge/providers/class-catalog-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider::class => 'Knowledge/providers/class-product-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\PolicyProvider::class => 'Knowledge/providers/class-policy-provider.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\ProductCompleteness::class => 'Scoring/factors/class-product-completeness.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\SchemaCoverage::class => 'Scoring/factors/class-schema-coverage.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\PolicyCompleteness::class => 'Scoring/factors/class-policy-completeness.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\ContentQuality::class => 'Scoring/factors/class-content-quality.php',
		\WooCommerce\CommerceAbilities\Scoring\ScoringEngine::class => 'Scoring/class-scoring-engine.php',
		\WooCommerce\CommerceAbilities\Store\StoreKnowledge::class => 'Store/class-store-knowledge.php',
	);

	foreach ( $classes as $class_name => $relative_path ) {
		if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) ) {
			continue;
		}
		foreach ( $package_dirs as $package_dir ) {
			if ( file_exists( $package_dir . $relative_path ) ) {
				require_once $package_dir . $relative_path;
				break;
			}
		}
	}
}

/**
 * Declare HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Render the admin notice shown when WooCommerce isn't active.
 */
function briefing_for_woocommerce_wc_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><strong>Briefing for WooCommerce</strong> requires WooCommerce to be installed and active.</p>
	</div>
	<?php
}

/**
 * Initialise the plugin after all plugins have loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'briefing_for_woocommerce_wc_missing_notice' );
			return;
		}
		require_once BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-abilities.php';
		require_once BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-plugin.php';
		\WooCommerce\Briefing\Plugin::instance();
	}
);

/**
 * On deactivation, revoke the keys the merchant approved for Briefing.
 *
 * Turning the plugin off should close the connection: without this, the
 * read-only key would stay valid against WooCommerce's own REST API (and
 * against WooCommerce for Claude's endpoint, if that's installed too).
 */
register_deactivation_hook(
	__FILE__,
	function () {
		require_once BRIEFING_FOR_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-plugin.php';
		\WooCommerce\Briefing\Plugin::revoke_keys();
	}
);
