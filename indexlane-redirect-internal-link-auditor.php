<?php
/**
 * Plugin Name: IndexLane Redirect & Internal Link Auditor
 * Plugin URI: https://indexlane.dev/plugins/redirect-internal-link-auditor
 * Description: Find broken links and leftover migration URLs, open their editing locations, and check whether your fixes worked.
 * Version: 0.7.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: indexlane-redirect-internal-link-auditor
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'IndexLane_Redirect_Internal_Link_Auditor' ) ) {
	require_once __DIR__ . '/includes/trait-admin.php';
	require_once __DIR__ . '/includes/trait-source-providers.php';
	require_once __DIR__ . '/includes/trait-scan.php';
	require_once __DIR__ . '/includes/trait-reports.php';
	require_once __DIR__ . '/includes/trait-baselines.php';

	/**
	 * Admin-only internal link and redirect diagnostic helper.
	 */
	final class IndexLane_Redirect_Internal_Link_Auditor {
		use IndexLane_Redirect_Internal_Link_Auditor_Admin;
		use IndexLane_Redirect_Internal_Link_Auditor_Source_Providers;
		use IndexLane_Redirect_Internal_Link_Auditor_Scan;
		use IndexLane_Redirect_Internal_Link_Auditor_Reports;
		use IndexLane_Redirect_Internal_Link_Auditor_Baselines;

		private const PLUGIN_FILE                     = __FILE__;
		private const VERSION                         = '0.7.0';
		private const SLUG                            = 'indexlane-redirect-internal-link-auditor';
		private const CAPABILITY                      = 'manage_options';
		private const NONCE_ACTION                    = 'indexlane_rila_scan_session';
		private const NONCE_NAME                      = 'indexlane_rila_nonce';
		private const SESSION_SCHEMA_VERSION          = 5;
		private const BASELINE_FORMAT                 = 'indexlane-rila-baseline';
		private const BASELINE_SCHEMA_VERSION         = 3;
		private const BASELINE_USER_OPTION            = 'indexlane_rila_baseline';
		private const MAX_BASELINE_FILE_SIZE          = 20971520;
		private const MAX_BASELINE_CONTENT_ITEMS      = 100000;
		private const MAX_BASELINE_RESULTS            = 100000;
		private const SESSION_TRANSIENT_PREFIX        = 'indexlane_rila_session_';
		private const SESSION_LIFETIME                = 86400;
		private const INITIAL_REQUEST_ALLOWANCE       = 250;
		private const REQUEST_ALLOWANCE_INCREMENT     = 250;
		private const MAX_HTTP_REQUESTS_PER_BATCH     = 5;
		private const MAX_SOURCE_ITEMS_PER_BATCH      = 5;
		private const MAX_SESSION_SOURCE_ITEMS        = 100000;
		private const MAX_NUMERIC_CONTENT_ITEMS       = 10000;
		/**
		 * Bounded response body retained per unique destination check.
		 *
		 * The head of the final same-site response must contain the robots meta
		 * tag and the canonical link, and the body is inspected for the fragment
		 * targets of the links that point at that destination. The body is never
		 * stored, and a response that reaches this bound is treated as partial.
		 */
		private const RESPONSE_SIZE_LIMIT              = 262144;
		private const MAX_INTENT_FRAGMENT_TARGETS      = 100;
		private const MAX_INTENT_FRAGMENT_LENGTH       = 200;
		private const MAX_INTENT_DETAIL_LENGTH         = 1000;

		/**
		 * Hook suffix for the plugin's Tools screen.
		 *
		 * @var string
		 */
		private static $admin_page_hook = '';

		/**
		 * Boot the plugin.
		 */
		public static function init(): void {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_handle_baseline_action' ) );
			add_action( 'wp_ajax_indexlane_rila_start_scan', array( __CLASS__, 'ajax_start_scan' ) );
			add_action( 'wp_ajax_indexlane_rila_run_batch', array( __CLASS__, 'ajax_run_batch' ) );
			add_action( 'wp_ajax_indexlane_rila_control_scan', array( __CLASS__, 'ajax_control_scan' ) );
		}
	}

	IndexLane_Redirect_Internal_Link_Auditor::init();
}
