<?php
/**
 * Plugin Name: IndexLane Broken Link & Redirect Auditor
 * Plugin URI: https://indexlane.dev/plugins/redirect-internal-link-auditor
 * Description: Find broken internal links and migration leftovers, preview and undo link repairs, and schedule checks for new problems. Free, with no account required.
 * Version: 1.1.1
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
	require_once __DIR__ . '/includes/trait-fixes.php';
	require_once __DIR__ . '/includes/trait-issues.php';
	require_once __DIR__ . '/includes/trait-monitor.php';
	require_once __DIR__ . '/includes/trait-cli.php';
	require_once __DIR__ . '/includes/class-cli.php';

	/**
	 * Admin-only internal link and redirect diagnostic helper.
	 */
	final class IndexLane_Redirect_Internal_Link_Auditor {
		use IndexLane_Redirect_Internal_Link_Auditor_Admin;
		use IndexLane_Redirect_Internal_Link_Auditor_Source_Providers;
		use IndexLane_Redirect_Internal_Link_Auditor_Scan;
		use IndexLane_Redirect_Internal_Link_Auditor_Reports;
		use IndexLane_Redirect_Internal_Link_Auditor_Baselines;
		use IndexLane_Redirect_Internal_Link_Auditor_Fixes;
		use IndexLane_Redirect_Internal_Link_Auditor_Issues;
		use IndexLane_Redirect_Internal_Link_Auditor_Monitor;
		use IndexLane_Redirect_Internal_Link_Auditor_CLI;

		private const PLUGIN_FILE                     = __FILE__;
		private const VERSION                         = '1.1.1';
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
		private const FIX_JOURNAL_OPTION              = 'indexlane_rila_fix_journal';
		private const FIX_JOURNAL_MAX_BATCHES         = 25;
		private const FIX_JOURNAL_MAX_BYTES           = 8388608;
		private const FIX_MAX_ITEMS_PER_BATCH         = 500;
		private const FIX_MAX_CANDIDATES_IN_PANEL     = 100;
		private const IGNORED_ISSUES_USER_OPTION      = 'indexlane_rila_ignored_issues';
		private const MAX_IGNORED_ISSUES              = 2000;
		private const MONITOR_OPTION                  = 'indexlane_rila_monitor';
		private const MONITOR_SESSION_OPTION          = 'indexlane_rila_monitor_session';
		private const MONITOR_CRON_HOOK               = 'indexlane_rila_monitor_run';
		private const MONITOR_CONTINUE_HOOK           = 'indexlane_rila_monitor_continue';
		private const MONITOR_RUN_BUDGET_SECONDS      = 20;
		private const MONITOR_MAX_BATCHES_PER_RUN     = 12;
		private const MONITOR_DEFAULT_REQUEST_LIMIT   = 2000;
		private const MONITOR_MAX_TRACKED_ISSUES      = 5000;
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
			add_action( 'admin_init', array( __CLASS__, 'maybe_handle_fix_action' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_handle_issue_action' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_handle_monitor_action' ) );
			add_action( 'wp_ajax_indexlane_rila_start_scan', array( __CLASS__, 'ajax_start_scan' ) );
			add_action( 'wp_ajax_indexlane_rila_run_batch', array( __CLASS__, 'ajax_run_batch' ) );
			add_action( 'wp_ajax_indexlane_rila_control_scan', array( __CLASS__, 'ajax_control_scan' ) );
			add_action( 'init', array( __CLASS__, 'sync_monitor_schedule' ) );
			add_action( self::MONITOR_CRON_HOOK, array( __CLASS__, 'run_scheduled_monitor' ) );
			add_action( self::MONITOR_CONTINUE_HOOK, array( __CLASS__, 'run_scheduled_monitor' ) );
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
			add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );

			if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'IndexLane_RILA_CLI' ) ) {
				WP_CLI::add_command( 'indexlane', 'IndexLane_RILA_CLI' );
			}
		}
	}

	IndexLane_Redirect_Internal_Link_Auditor::init();
}
