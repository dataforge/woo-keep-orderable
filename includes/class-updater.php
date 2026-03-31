<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Keep_Orderable_Updater {

	const GITHUB_REPO = 'dataforge/woo-keep-orderable';
	const SLUG        = 'woo-keep-orderable';
	const CACHE_KEY   = 'woo_keep_orderable_github_release';
	const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'upgrader_install_package_result', array( __CLASS__, 'fix_directory' ), 10, 2 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'admin_post_woo_keep_orderable_check_updates', array( __CLASS__, 'handle_check_updates' ) );
	}

	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( WOO_KEEP_ORDERABLE_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $update;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );

		if ( version_compare( WOO_KEEP_ORDERABLE_VERSION, $remote_version, '>=' ) ) {
			return $update;
		}

		return array(
			'slug'    => self::SLUG,
			'version' => $remote_version,
			'url'     => $release->html_url,
			'package' => self::get_asset_url( $release ),
		);
	}

	public static function fix_directory( $result, $options ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $options['plugin'] ) || plugin_basename( WOO_KEEP_ORDERABLE_FILE ) !== $options['plugin'] ) {
			return $result;
		}

		global $wp_filesystem;

		$expected_dir = trailingslashit( WP_PLUGIN_DIR ) . self::SLUG;
		$actual_dir   = isset( $result['destination'] ) ? rtrim( $result['destination'], '/' ) : '';

		if ( $actual_dir === $expected_dir ) {
			return $result;
		}

		if ( $wp_filesystem->move( $actual_dir, $expected_dir, true ) ) {
			$result['destination']        = $expected_dir;
			$result['destination_name']   = self::SLUG;
			$result['remote_destination'] = $expected_dir;
		}

		return $result;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );

		$info               = new stdClass();
		$info->name         = 'Woo Keep Orderable';
		$info->slug         = self::SLUG;
		$info->version      = $remote_version;
		$info->author       = '<a href="https://github.com/dataforge">Dataforge</a>';
		$info->homepage     = 'https://github.com/' . self::GITHUB_REPO;
		$info->requires     = '5.8';
		$info->requires_php = '7.2';
		$info->download_link = self::get_asset_url( $release );
		$info->sections     = array(
			'description' => 'Keeps all products in selected WooCommerce categories always available to order, even if out of stock.',
			'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
		);

		return $info;
	}

	public static function handle_check_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'woo_keep_orderable_check_updates' );

		delete_transient( self::CACHE_KEY );
		wp_clean_plugins_cache( true );
		wp_update_plugins();

		$release = self::fetch_latest_release();
		$status  = 'up_to_date';
		if ( $release ) {
			$remote_version = ltrim( $release->tag_name, 'v' );
			if ( version_compare( WOO_KEEP_ORDERABLE_VERSION, $remote_version, '<' ) ) {
				$status = 'update_available';
			}
		}

		wp_safe_redirect( add_query_arg( array( 'update_check' => $status ), admin_url( 'admin.php?page=woo_keep_orderable&tab=settings' ) ) );
		exit;
	}

	public static function is_update_available() {
		$release = get_transient( self::CACHE_KEY );
		if ( ! $release || empty( $release->tag_name ) ) {
			return false;
		}
		$remote_version = ltrim( $release->tag_name, 'v' );
		return version_compare( WOO_KEEP_ORDERABLE_VERSION, $remote_version, '<' );
	}

	private static function get_asset_url( $release ) {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( '.zip' === substr( $asset->name, -4 ) ) {
					return $asset->browser_download_url;
				}
			}
		}
		return $release->zipball_url;
	}

	private static function fetch_latest_release() {
		$force = isset( $_GET['force-check'] ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return 'error' === $cached ? false : $cached;
			}
		}

		$url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'error', 5 * MINUTE_IN_SECONDS );
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! $release || empty( $release->tag_name ) ) {
			set_transient( self::CACHE_KEY, 'error', 5 * MINUTE_IN_SECONDS );
			return false;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}
}
