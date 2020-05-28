<?php
/*******************************************************************************
 * Copyright (c) 2019, Code Atlantic LLC
 ******************************************************************************/

if ( ! defined( 'ABSPATH' ) ) {
	// Exit if accessed directly
	exit;
}

/**
 * Our telemetry class.
 *
 * Handles sending usage data back to our servers for those who have opted into our telemetry.
 *
 * @since 1.11.0
 */
class PUM_Telemetry {

	public static function init() {
		add_action( 'pum_daily_scheduled_events', array( __CLASS__, 'track_check' ) );
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			add_filter( 'pum_alert_list', array( __CLASS__, 'optin_alert' ) );
			add_action( 'init', array( __CLASS__, 'optin_alert_check' ) );
		}
	}

	/**
	 * Prepares and sends data, if it is time to do so
	 * @since 1.11.0
	 */
	public static function track_check() {
		if ( self::is_time_to_send() ) {
			$data = self::setup_data();
			self::send_data( $data );
			set_transient( 'pum_tracking_last_send', true, 6 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Prepares telemetry data to be sent
	 * @return array
	 * @since 1.11.0
	 */
	public static function setup_data() {
		global $wpdb;

		// Retrieve current theme info
		$theme_data = wp_get_theme();
		$theme      = $theme_data->Name . ' ' . $theme_data->Version;

		// Retrieve current plugin information
		if ( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$plugins        = array_keys( get_plugins() );
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $plugins as $key => $plugin ) {
			if ( in_array( $plugin, $active_plugins ) ) {
				// Remove active plugins from list so we can show active and inactive separately
				unset( $plugins[ $key ] );
			}
		}

		$popups = 0;
		foreach ( wp_count_posts( 'popup' ) as $status ) {
			$popups += $status;
		}

		$popup_themes = 0;
		foreach ( wp_count_posts( 'popup_theme' ) as $status ) {
			$popup_themes += $status;
		}

		// Aggregates important settings across all popups.
		$all_popups = pum_get_all_popups();
		$triggers   = array();
		$cookies    = array();
		$conditions = array();
		$location   = array();
		$sizes      = array();
		$sounds     = array();

		// Cycle through each popup
		foreach ( $all_popups as $popup ) {
			$settings = $popup->get_settings();

			// Cycle through each trigger to count the number of unique triggers.
			foreach ( $settings['triggers'] as $trigger ) {
				if ( isset( $triggers[ $trigger['type'] ] ) ) {
					$triggers[ $trigger['type'] ] += 1;
				} else {
					$triggers[ $trigger['type'] ] = 1;
				}
			}

			// Cycle through each cookie to count the number of unique cookie.
			foreach ( $settings['cookies'] as $cookie ) {
				if ( isset( $cookies[ $cookie['event'] ] ) ) {
					$cookies[ $cookie['event'] ] += 1;
				} else {
					$cookies[ $cookie['event'] ] = 1;
				}
			}

			// Cycle through each condition to count the number of unique condition.
			foreach ( $settings['conditions'] as $condition ) {
				foreach ( $condition as $target ) {
					if ( isset( $conditions[ $target['target'] ] ) ) {
						$conditions[ $target['target'] ] += 1;
					} else {
						$conditions[ $target['target'] ] = 1;
					}
				}
			}

			// Add locations setting.
			if ( isset( $location[ $settings['location'] ] ) ) {
				$location[ $settings['location'] ] += 1;
			} else {
				$location[ $settings['location'] ] = 1;
			}

			// Add size setting.
			if ( isset( $sizes[ $settings['size'] ] ) ) {
				$sizes[ $settings['size'] ] += 1;
			} else {
				$sizes[ $settings['size'] ] = 1;
			}

			// Add opening sound setting.
			if ( isset( $sounds[ $settings['open_sound'] ] ) ) {
				$sounds[ $settings['open_sound'] ] += 1;
			} else {
				$sounds[ $settings['open_sound'] ] = 1;
			}
		}

		$args = array(
			// UID
			'uid'              => self::get_uuid(),

			// Language Info
			'language'         => get_bloginfo( 'language' ), // Language
			'charset'          => get_bloginfo( 'charset' ), // Character Set

			// Server Info
			'php_version'      => phpversion(),
			'mysql_version'    => $wpdb->db_version(),
			'is_localhost'     => self::is_localhost(),

			// WP Install Info
			'url'              => get_site_url(),
			'version'          => Popup_Maker::$VER, // Plugin Version
			'wp_version'       => get_bloginfo( 'version' ), // WP Version
			'theme'            => $theme,
			'active_plugins'   => $active_plugins,
			'inactive_plugins' => array_values( $plugins ),

			// Popup Metrics
			'popups'           => $popups,
			'popup_themes'     => $popup_themes,
			'open_count'       => get_option( 'pum_total_open_count', 0 ),

			// Popup Maker Settings
			'block_editor_enabled'   => pum_get_option( 'gutenberg_support_enabled' ),
			'bypass_ad_blockers'     => pum_get_option( 'bypass_adblockers' ),
			'disable_taxonimies'     => pum_get_option( 'disable_popup_category_tag' ),
			'disable_asset_cache'    => pum_get_option( 'disable_asset_caching' ),
			'disable_open_tracking'  => pum_get_option( 'disable_popup_open_tracking' ),
			'default_email_provider' => pum_get_option( 'newsletter_default_provider', 'none' ),

			// Aggregate Popup Settings
			'triggers'   => $triggers,
			'cookies'    => $cookies,
			'conditions' => $conditions,
			'locations'  => $location,
			'sizes'      => $sizes,
			'sounds'     => $sounds,
		);

		return $args;
	}

	/**
	 * Sends check_in data
	 *
	 * @param array $data Telemetry data to send.
	 * @since 1.11.0
	 */
	public static function send_data( $data = array() ) {
		self::api_call( 'check_in', $data );
	}

	/**
	 * Makes HTTP request to our API endpoint
	 *
	 * @param string $action The specific endpoint in our API.
	 * @param array $data Any data to send in the body.
	 * @return array|bool False if WP Error. Otherwise, array response from wp_remote_post.
	 * @since 1.11.0
	 */
	public static function api_call( $action = '', $data = array() ) {
		$response = wp_remote_post( 'https://api.wppopupmaker.com/wp-json/pmapi/v2/' . $action, array(
			'method'      => 'POST',
			'timeout'     => 20,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking'    => false,
			'body'        => $data,
			'user-agent'  => 'POPMAKE/' . Popup_Maker::$VER . '; ' . get_site_url(),
		));

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			PUM_Utils_Logging::instance()->log( sprintf( 'Cannot send telemetry data. Error received was: %s', esc_html( $error_message ) ) );
			return false;
		}

		return $response;
	}

	/**
	 * Adds admin notice if we haven't asked before.
	 *
	 * @param array $alerts The alerts currently in the alert system.
	 * @return array Alerts for the alert system.
	 * @since 1.11.0
	 */
	public static function optin_alert( $alerts ) {
		if ( ! self::should_show_alert() ) {
			return $alerts;
		}

		$optin_url = add_query_arg( 'pum_optin_check', 'optin' );

		ob_start();
		?>
		<ul>
			<li><a href="<?php echo esc_attr( $optin_url ); ?>"><strong><?php esc_html_e( 'Allow', 'popup-maker' ); ?></strong></a></li>
			<li><a href="#" class="pum-dismiss"><?php esc_html_e( 'Do not allow', 'popup-maker' ); ?></a></li>
			<li><a href="https://docs.wppopupmaker.com/article/528-the-data-the-popup-maker-plugin-collects" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'Learn more', 'popup-maker' ); ?></a></li>
		</ul>
		<?php
		$html = ob_get_clean();
		$alerts[] = array(
			'code'        => 'pum_telemetry_notice',
			'type'        => 'info',
			'message'     => esc_html__( "Allow Popup Maker to track this plugin's usage and help us make this plugin better? No user data is sent to our servers. No sensitive data is tracked.", 'popup-maker' ),
			'html'        => $html,
			'priority'    => 10,
			'dismissible' => true,
			'global'      => false,
		);
		return $alerts;
	}

	/**
	 * Checks if any options have been clicked from admin notices.
	 *
	 * @since 1.11.0
	 */
	public static function optin_alert_check() {
		if ( isset( $_GET['pum_optin_check'] ) ) {
			if ( 'optin' === $_GET['pum_optin_check'] ) {
				pum_update_option( 'telemetry', true );
			}
		}
	}

	/**
	 * Whether or not we should show optin alert
	 *
	 * @since 1.11.0
	 * @return bool True if alert should be shown
	 */
	public static function should_show_alert() {
		return false === self::has_opted_in() && current_user_can( 'manage_options' ) && false == get_option( '_pum_telemetry_notice_dismissed', false );
	}

	/**
	 * Determines if it is time to send telemetry data.
	 * @return bool True if it is time.
	 * @since 1.11.0
	 */
	public static function is_time_to_send() {

		// Only send if admin has opted in.
		if ( ! self::has_opted_in() ) {
			return false;
		}

		// Send a maximum of once per week.
		if ( get_transient( 'pum_tracking_last_send' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Wrapper to check if site has opted into telemetry
	 *
	 * @return bool True if has opted into telemetry
	 * @since 1.11.0
	 */
	public static function has_opted_in() {
		return false !== pum_get_option( 'telemetry', false );
	}

	/**
	 * Determines if the site is in a local environment
	 * @return bool True for local
	 * @since 1.11.0
	 */
	public static function is_localhost() {
		$url = network_site_url( '/' );
		return stristr( $url, 'dev' ) !== false || stristr( $url, 'localhost' ) !== false || stristr( $url, ':8888' ) !== false;

	}

	/**
	 * Generates a new UUID for this site.
	 *
	 * @return string
	 * @since 1.11.0
	 */
	public static function add_uuid() {
		$uuid = wp_generate_uuid4();
		update_option( 'pum_site_uuid', $uuid );
		return $uuid;
	}

	/**
	 * Retrieves the site UUID
	 *
	 * @return string
	 * @since 1.11.0
	 */
	public static function get_uuid() {
		$uuid = get_option( 'pum_site_uuid', false );
		if ( false === $uuid || ! wp_is_uuid( $uuid ) ) {
			$uuid = self::add_uuid();
		}
		return $uuid;
	}
}
