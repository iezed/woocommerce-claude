<?php
/**
 * Updates from the Briefing site.
 *
 * @package WooCommerce\Briefing
 */

namespace WooCommerce\Briefing;

defined( 'ABSPATH' ) || exit;

/**
 * Lets WordPress update the plugin like one from WordPress.org.
 *
 * The plugin header's `Update URI` points WordPress's update check at
 * `update_plugins_briefing.woocommerce.com`; this answers it with the latest
 * release the Briefing site reports, and WordPress compares versions, shows
 * the update and (with auto-updates on, the default) installs it. The zip
 * itself is the GitHub release asset, and only release assets from this repo
 * are accepted, whatever the site says.
 */
class Updater {

	/**
	 * Where the latest release is described: `{ version, package, requires, tested, requires_php }`.
	 */
	const LATEST_URL = 'https://briefing.woocommerce.com/api/plugin/update';

	/**
	 * Packages must be this repo's Briefing release assets.
	 */
	const PACKAGE_PREFIX = 'https://github.com/woocommerce/woocommerce-claude/releases/download/briefing-for-woocommerce-v';

	const SLUG      = 'briefing-for-woocommerce';
	const TRANSIENT = 'briefing_for_woocommerce_latest';

	/**
	 * Hook into WordPress's update check and the plugin details screen.
	 */
	public static function init() {
		add_filter( 'update_plugins_briefing.woocommerce.com', array( __CLASS__, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file, relative to the plugins folder.
	 * @return array|false
	 */
	public static function update( $update, $plugin_data, $plugin_file ) {
		unset( $plugin_data );
		if ( plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$latest = self::latest();
		if ( ! $latest ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $latest['version'],
			'package'      => $latest['package'],
			'url'          => 'https://briefing.woocommerce.com/',
			'requires'     => $latest['requires'],
			'tested'       => $latest['tested'],
			'requires_php' => $latest['requires_php'],
		);
	}

	/**
	 * The "View details" box on the Plugins screen.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action The plugins_api action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$latest = self::latest();
		if ( ! $latest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Briefing for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $latest['version'],
			'author'        => '<a href="https://automattic.com/">Automattic</a>',
			'homepage'      => 'https://briefing.woocommerce.com/',
			'requires'      => $latest['requires'],
			'tested'        => $latest['tested'],
			'requires_php'  => $latest['requires_php'],
			'download_link' => $latest['package'],
			'sections'      => array(
				'description' => __( 'Lets Briefing for WooCommerce read your store\'s weekly totals, with a read-only key you approve. Only totals and breakdowns ever leave the store — never orders, customers, names or emails.', 'briefing-for-woocommerce' ),
				'changelog'   => sprintf(
					'<a href="%s">%s</a>',
					esc_url( 'https://github.com/woocommerce/woocommerce-claude/releases/tag/briefing-for-woocommerce-v' . $latest['version'] ),
					esc_html__( 'Release notes on GitHub', 'briefing-for-woocommerce' )
				),
			),
		);
	}

	/**
	 * The latest release, as the Briefing site reports it: cached for six hours,
	 * and a failed check for one so a down site isn't asked on every page load.
	 *
	 * @return array{version:string,package:string,requires:string,tested:string,requires_php:string}|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['latest'];
		}

		$latest   = null;
		$response = wp_remote_get(
			/**
			 * Where the plugin asks for its latest release.
			 *
			 * @since 0.1.0
			 *
			 * @param string $url The latest-release URL.
			 */
			apply_filters( 'briefing_for_woocommerce_latest_url', self::LATEST_URL ),
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$latest = self::valid( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		set_site_transient( self::TRANSIENT, array( 'latest' => $latest ), $latest ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $latest;
	}

	/**
	 * A release description, or null unless the version looks like one and the
	 * package is this repo's matching release asset.
	 *
	 * @param mixed $data Decoded response.
	 * @return array|null
	 */
	public static function valid( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['version'], $data['package'] ) || ! is_string( $data['version'] ) || ! is_string( $data['package'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] ) ) {
			return null;
		}
		if ( self::PACKAGE_PREFIX . $data['version'] . '/briefing-for-woocommerce.zip' !== $data['package'] ) {
			return null;
		}

		$text = function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : '';
		};
		return array(
			'version'      => $data['version'],
			'package'      => $data['package'],
			'requires'     => $text( 'requires' ),
			'tested'       => $text( 'tested' ),
			'requires_php' => $text( 'requires_php' ),
		);
	}

	/**
	 * Turn on auto-updates for this plugin (on activation). The merchant can
	 * turn them off on the Plugins screen like any other plugin's.
	 */
	public static function enable_auto_updates() {
		$plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$file    = plugin_basename( BRIEFING_FOR_WOOCOMMERCE_PLUGIN_FILE );
		if ( ! in_array( $file, $plugins, true ) ) {
			$plugins[] = $file;
			update_site_option( 'auto_update_plugins', $plugins );
		}
	}
}
