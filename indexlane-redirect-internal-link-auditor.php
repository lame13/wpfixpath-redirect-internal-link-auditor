<?php
/**
 * Plugin Name: IndexLane Redirect & Internal Link Auditor
 * Plugin URI: https://indexlane.dev/plugins/redirect-internal-link-auditor
 * Description: Audit redirects, broken links, and content link coverage inside WordPress.
 * Version: 0.5.1
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
	require_once __DIR__ . '/includes/trait-scan.php';
	require_once __DIR__ . '/includes/trait-reports.php';
	require_once __DIR__ . '/includes/trait-baselines.php';

	/**
	 * Admin-only internal link and redirect diagnostic helper.
	 */
	final class IndexLane_Redirect_Internal_Link_Auditor {
		use IndexLane_Redirect_Internal_Link_Auditor_Admin;
		use IndexLane_Redirect_Internal_Link_Auditor_Scan;
		use IndexLane_Redirect_Internal_Link_Auditor_Reports;
		use IndexLane_Redirect_Internal_Link_Auditor_Baselines;

		private const PLUGIN_FILE                     = __FILE__;
		private const VERSION                         = '0.5.1';
		private const SLUG                            = 'indexlane-redirect-internal-link-auditor';
		private const CAPABILITY                      = 'manage_options';
		private const NONCE_ACTION                    = 'indexlane_rila_scan_session';
		private const NONCE_NAME                      = 'indexlane_rila_nonce';
		private const SESSION_SCHEMA_VERSION          = 3;
		private const BASELINE_FORMAT                 = 'indexlane-rila-baseline';
		private const BASELINE_SCHEMA_VERSION         = 1;
		private const BASELINE_USER_OPTION            = 'indexlane_rila_baseline';
		private const MAX_BASELINE_FILE_SIZE          = 20971520;
		private const MAX_BASELINE_CONTENT_ITEMS      = 100000;
		private const MAX_BASELINE_RESULTS            = 100000;
		private const SESSION_TRANSIENT_PREFIX        = 'indexlane_rila_session_';
		private const SESSION_LIFETIME                = 86400;
		private const INITIAL_REQUEST_ALLOWANCE       = 250;
		private const REQUEST_ALLOWANCE_INCREMENT     = 250;
		private const MAX_HTTP_REQUESTS_PER_BATCH     = 5;
		private const MAX_CONTENT_ITEMS_PER_BATCH     = 5;
		private const MAX_NUMERIC_CONTENT_ITEMS       = 10000;
		private const RESPONSE_SIZE_LIMIT              = 4096;

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
