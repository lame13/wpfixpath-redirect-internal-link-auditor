<?php
/**
 * Plugin Name: IndexLane Redirect & Internal Link Auditor
 * Plugin URI: https://indexlane.dev/plugins/redirect-internal-link-auditor
 * Description: Find broken, redirected, old-domain, and staging-domain links inside WordPress content.
 * Version: 0.3.0
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
	/**
	 * Admin-only internal link and redirect diagnostic helper.
	 */
	final class IndexLane_Redirect_Internal_Link_Auditor {
		private const VERSION                         = '0.3.0';
		private const SLUG                            = 'indexlane-redirect-internal-link-auditor';
		private const CAPABILITY                      = 'manage_options';
		private const NONCE_ACTION                    = 'indexlane_rila_scan_session';
		private const NONCE_NAME                      = 'indexlane_rila_nonce';
		private const SESSION_SCHEMA_VERSION          = 1;
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
			add_action( 'wp_ajax_indexlane_rila_start_scan', array( __CLASS__, 'ajax_start_scan' ) );
			add_action( 'wp_ajax_indexlane_rila_run_batch', array( __CLASS__, 'ajax_run_batch' ) );
			add_action( 'wp_ajax_indexlane_rila_control_scan', array( __CLASS__, 'ajax_control_scan' ) );
		}

		/**
		 * Register Tools admin page.
		 */
		public static function register_admin_page(): void {
			$hook_suffix = add_management_page(
				__( 'Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ),
				__( 'Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ),
				self::CAPABILITY,
				self::SLUG,
				array( __CLASS__, 'render_admin_page' )
			);

			self::$admin_page_hook = is_string( $hook_suffix ) ? $hook_suffix : '';
		}

		/**
		 * Enqueue styles only on the plugin's Tools screen.
		 */
		public static function enqueue_admin_assets( string $hook_suffix ): void {
			if ( '' === self::$admin_page_hook || self::$admin_page_hook !== $hook_suffix ) {
				return;
			}

			wp_enqueue_style(
				'indexlane-rila-admin',
				plugins_url( 'assets/admin.css', __FILE__ ),
				array(),
				self::VERSION
			);

			wp_enqueue_script(
				'indexlane-rila-admin',
				plugins_url( 'assets/admin.js', __FILE__ ),
				array(),
				self::VERSION,
				true
			);

			wp_localize_script(
				'indexlane-rila-admin',
				'IndexLaneRila',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
					'initialSession' => self::get_scan_session_summary(),
					'strings'        => array(
						'networkError' => __( 'The scan request failed. Check your connection, then continue the scan.', 'indexlane-redirect-internal-link-auditor' ),
						'pausing'      => __( 'Pausing after the current batch…', 'indexlane-redirect-internal-link-auditor' ),
						'canceling'    => __( 'Canceling after the current batch…', 'indexlane-redirect-internal-link-auditor' ),
						'interrupted'  => __( 'Scan connection interrupted', 'indexlane-redirect-internal-link-auditor' ),
						'confirmCancel' => __( 'Cancel this scan? Its accumulated temporary evidence will be removed.', 'indexlane-redirect-internal-link-auditor' ),
					),
				)
			);
		}

		/**
		 * Export a CSV before admin page output starts.
		 */
		public static function maybe_export_csv(): void {
			if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
				return;
			}

			if ( 'POST' !== self::server_request_method() ) {
				return;
			}

			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( self::SLUG !== $page ) {
				return;
			}

			$action = isset( $_POST['indexlane_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['indexlane_rila_action'] ) ) : '';
			if ( ! in_array( $action, array( 'export_details', 'export_impact' ), true ) ) {
				return;
			}

			check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

			$session_id = isset( $_POST['session_id'] ) && is_scalar( $_POST['session_id'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) )
				: '';
			$session    = self::get_scan_session();

			if ( null === $session || ! hash_equals( (string) $session['id'], $session_id ) || 'complete' !== $session['status'] ) {
				wp_die( esc_html__( 'The completed scan is unavailable or has expired. Complete the scan again before exporting.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$report_type = 'export_impact' === $action ? 'impact' : 'details';
			self::send_csv( $session['results'], $report_type );
		}

		/**
		 * Start a per-user scan session through authenticated AJAX.
		 */
		public static function ajax_start_scan(): void {
			if ( ! self::verify_ajax_request() ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The authenticated AJAX nonce is verified immediately above.
			$post_data = wp_unslash( $_POST );
			$settings  = self::get_request_settings( is_array( $post_data ) ? $post_data : array() );
			if ( empty( $settings['post_types'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Select at least one public content type.', 'indexlane-redirect-internal-link-auditor' ) ), 400 );
				return;
			}

			$existing = self::get_scan_session();
			if ( is_array( $existing ) && in_array( $existing['status'], array( 'running', 'paused', 'limit_reached' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'A scan is already in progress. Continue or cancel it before starting another.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
				return;
			}

			$session = self::create_scan_session( $settings );
			if ( ! self::save_scan_session( $session ) ) {
				wp_send_json_error( array( 'message' => __( 'WordPress could not save the scan session. Check the site cache or database and try again.', 'indexlane-redirect-internal-link-auditor' ) ), 500 );
				return;
			}

			wp_send_json_success( array( 'session' => self::build_session_summary( $session ) ) );
		}

		/**
		 * Process one bounded scan batch through authenticated AJAX.
		 */
		public static function ajax_run_batch(): void {
			if ( ! self::verify_ajax_request() ) {
				return;
			}

			$session = self::get_requested_scan_session();
			if ( null === $session ) {
				return;
			}

			if ( 'running' === $session['status'] ) {
				$session = self::process_scan_batch( $session );
				if ( ! self::save_scan_session( $session ) ) {
					wp_send_json_error( array( 'message' => __( 'WordPress could not save scan progress. The previous saved batch remains available.', 'indexlane-redirect-internal-link-auditor' ) ), 500 );
					return;
				}
			}

			wp_send_json_success( array( 'session' => self::build_session_summary( $session ) ) );
		}

		/**
		 * Pause, resume, cancel, or extend a scan session.
		 */
		public static function ajax_control_scan(): void {
			if ( ! self::verify_ajax_request() ) {
				return;
			}

			$session = self::get_requested_scan_session();
			if ( null === $session ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- The authenticated AJAX nonce is verified before session controls are read.
			$command = isset( $_POST['command'] ) && is_scalar( $_POST['command'] )
				? sanitize_key( wp_unslash( (string) $_POST['command'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			switch ( $command ) {
				case 'pause':
					if ( 'running' === $session['status'] ) {
						$session['status'] = 'paused';
					}
					break;
				case 'resume':
					if ( in_array( $session['status'], array( 'paused', 'running' ), true ) ) {
						$session['status'] = 'running';
					}
					break;
				case 'extend':
					if ( 'limit_reached' !== $session['status'] ) {
						wp_send_json_error( array( 'message' => __( 'Another request allowance is available only after the current allowance is reached.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
						return;
					}
					$session['request_limit'] += self::REQUEST_ALLOWANCE_INCREMENT;
					$session['status']         = 'running';
					break;
				case 'cancel':
					self::delete_scan_session();
					wp_send_json_success(
						array(
							'session' => null,
							'message' => __( 'The scan was canceled and its temporary evidence was removed.', 'indexlane-redirect-internal-link-auditor' ),
						)
					);
					return;
				default:
					wp_send_json_error( array( 'message' => __( 'Unknown scan action.', 'indexlane-redirect-internal-link-auditor' ) ), 400 );
					return;
			}

			if ( ! self::save_scan_session( $session ) ) {
				wp_send_json_error( array( 'message' => __( 'WordPress could not save the scan state.', 'indexlane-redirect-internal-link-auditor' ) ), 500 );
				return;
			}

			wp_send_json_success( array( 'session' => self::build_session_summary( $session ) ) );
		}

		/**
		 * Validate the AJAX nonce and administrator capability.
		 */
		private static function verify_ajax_request(): bool {
			if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'The scan request expired. Reload this page and try again.', 'indexlane-redirect-internal-link-auditor' ) ), 403 );
				return false;
			}

			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to run this scan.', 'indexlane-redirect-internal-link-auditor' ) ), 403 );
				return false;
			}

			return true;
		}

		/**
		 * Load and validate the session named by an AJAX request.
		 *
		 * @return array<string,mixed>|null
		 */
		private static function get_requested_scan_session(): ?array {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every caller verifies the authenticated AJAX nonce before this helper runs.
			$session_id = isset( $_POST['session_id'] ) && is_scalar( $_POST['session_id'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$session    = self::get_scan_session();

			if ( null === $session ) {
				wp_send_json_error( array( 'message' => __( 'This scan session expired or is no longer available. Start a new scan.', 'indexlane-redirect-internal-link-auditor' ) ), 410 );
				return null;
			}

			if ( '' === $session_id || ! hash_equals( (string) $session['id'], $session_id ) ) {
				wp_send_json_error( array( 'message' => __( 'This browser is referring to an older scan session. Reload the page to see the current scan.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
				return null;
			}

			return $session;
		}

		/**
		 * Render the admin UI.
		 */
		public static function render_admin_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$session  = self::get_scan_session();
			$settings = is_array( $session ) && isset( $session['settings'] ) && is_array( $session['settings'] )
				? $session['settings']
				: self::default_settings();

			?>
			<div class="wrap indexlane-rila-wrap">
				<h1><?php esc_html_e( 'Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ); ?></h1>

				<p>
					<?php esc_html_e( 'Find internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains.', 'indexlane-redirect-internal-link-auditor' ); ?>
				</p>

				<?php self::render_session_panel( is_array( $session ) ? self::build_session_summary( $session ) : null ); ?>

				<?php if ( is_array( $session ) && 'complete' === $session['status'] ) : ?>
					<?php self::render_results( $session ); ?>
				<?php endif; ?>

				<form id="indexlane-rila-scan-form" method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-form">

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Content types', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<td>
									<?php foreach ( self::get_available_post_types() as $post_type => $label ) : ?>
										<label class="indexlane-rila-checkbox">
											<input
												type="checkbox"
												name="post_types[]"
												value="<?php echo esc_attr( $post_type ); ?>"
												<?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>
											/>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Every selected public post type is scanned using its published post content.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="indexlane-rila-old-domains"><?php esc_html_e( 'Old domains', 'indexlane-redirect-internal-link-auditor' ); ?></label>
								</th>
								<td>
					<textarea
										id="indexlane-rila-old-domains"
										name="old_domains"
										rows="4"
										class="large-text code"
										placeholder="<?php esc_attr_e( "old-example.com\nstaging.example.com", 'indexlane-redirect-internal-link-auditor' ); ?>"
									><?php echo esc_textarea( $settings['old_domains'] ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'Optional. Add one old or migration domain per line. Matching links are flagged even when status checks are limited to the current site.', 'indexlane-redirect-internal-link-auditor' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Content scope', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<td>
									<label class="indexlane-rila-choice">
										<input type="radio" name="content_scope" value="all" <?php checked( 'all', $settings['content_scope'] ); ?> />
										<?php esc_html_e( 'All published content', 'indexlane-redirect-internal-link-auditor' ); ?>
									</label>
									<label for="indexlane-rila-max-posts" class="indexlane-rila-choice">
										<input type="radio" name="content_scope" value="limit" <?php checked( 'limit', $settings['content_scope'] ); ?> />
										<?php esc_html_e( 'Newest', 'indexlane-redirect-internal-link-auditor' ); ?>
										<input
											id="indexlane-rila-max-posts"
											type="number"
											name="max_posts"
											min="1"
											max="<?php echo esc_attr( (string) self::MAX_NUMERIC_CONTENT_ITEMS ); ?>"
											value="<?php echo esc_attr( (string) $settings['max_posts'] ); ?>"
										/>
										<?php esc_html_e( 'content items', 'indexlane-redirect-internal-link-auditor' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'A complete scan uses small browser-driven batches and can be paused or resumed.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Request settings', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<td>
									<label for="indexlane-rila-timeout" class="indexlane-rila-inline-field">
										<?php esc_html_e( 'Request timeout', 'indexlane-redirect-internal-link-auditor' ); ?>
										<input
											id="indexlane-rila-timeout"
											type="number"
											name="timeout"
											min="1"
											max="15"
											step="0.5"
											value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
										/>
									</label>
									<label for="indexlane-rila-max-redirects" class="indexlane-rila-inline-field">
										<?php esc_html_e( 'Maximum redirects', 'indexlane-redirect-internal-link-auditor' ); ?>
										<input
											id="indexlane-rila-max-redirects"
											type="number"
											name="max_redirects"
											min="0"
											max="10"
											value="<?php echo esc_attr( (string) $settings['max_redirects'] ); ?>"
										/>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Status checks', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<td>
									<p><?php esc_html_e( 'Same-site link targets are checked. Old, staging, or development-domain links are flagged but not fetched.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button id="indexlane-rila-start" type="submit" class="button button-primary">
							<?php esc_html_e( 'Start scan', 'indexlane-redirect-internal-link-auditor' ); ?>
						</button>
					</p>
				</form>
				<div id="indexlane-rila-request-error" class="notice notice-error inline" hidden><p></p></div>

				<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'JavaScript is required because scan batches run through authenticated WordPress AJAX requests.', 'indexlane-redirect-internal-link-auditor' ); ?></p></div></noscript>

				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Scope: scans selected WordPress content and checks same-site link targets. Old or staging-domain links are flagged for review.', 'indexlane-redirect-internal-link-auditor' ); ?>
					</p>
				</div>

			</div>
			<?php
		}

		/**
		 * Render the persisted session state used and updated by the browser controller.
		 *
		 * @param array<string,mixed>|null $summary Session summary.
		 */
		private static function render_session_panel( ?array $summary ): void {
			$has_session = is_array( $summary );
			$stats       = $has_session ? $summary['stats'] : self::empty_stats();
			$total       = $has_session ? max( 0, (int) $summary['total_items'] ) : 0;
			$processed   = min( $total, (int) $stats['content_items_processed'] );
			?>
			<section id="indexlane-rila-session" class="indexlane-rila-session" <?php echo $has_session ? '' : 'hidden'; ?>>
				<div class="indexlane-rila-session-heading">
					<div>
						<h2><?php esc_html_e( 'Scan session', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
						<p id="indexlane-rila-state" class="indexlane-rila-state" aria-live="polite">
							<?php echo $has_session ? esc_html( (string) $summary['state_label'] ) : ''; ?>
						</p>
					</div>
					<div id="indexlane-rila-controls" class="indexlane-rila-controls">
						<button id="indexlane-rila-pause" type="button" class="button" <?php echo ! $has_session || 'running' !== $summary['status'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Pause', 'indexlane-redirect-internal-link-auditor' ); ?></button>
						<button id="indexlane-rila-resume" type="button" class="button button-primary" <?php echo ! $has_session || 'paused' !== $summary['status'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Continue scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
						<button id="indexlane-rila-extend" type="button" class="button button-primary" <?php echo ! $has_session || 'limit_reached' !== $summary['status'] ? 'hidden' : ''; ?>><?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of additional outbound HTTP requests granted */
									__( 'Continue with %d more requests', 'indexlane-redirect-internal-link-auditor' ),
									self::REQUEST_ALLOWANCE_INCREMENT
								)
							);
						?></button>
						<button id="indexlane-rila-cancel" type="button" class="button button-link-delete" <?php echo ! $has_session || 'complete' === $summary['status'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Cancel scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
						<button id="indexlane-rila-new-scan" type="button" class="button button-primary" <?php echo ! $has_session || 'complete' !== $summary['status'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Start another scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</div>
				</div>

				<progress id="indexlane-rila-progress" max="<?php echo esc_attr( (string) max( 1, $total ) ); ?>" value="<?php echo esc_attr( (string) $processed ); ?>"></progress>
				<p id="indexlane-rila-message" class="indexlane-rila-session-message" aria-live="polite"><?php echo $has_session ? esc_html( (string) $summary['message'] ) : ''; ?></p>

				<div class="indexlane-rila-metrics">
					<div><strong id="indexlane-rila-stat-content"><?php echo esc_html( (string) $stats['content_items_processed'] ); ?></strong><span><?php esc_html_e( 'Content processed', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
					<div><strong id="indexlane-rila-stat-links"><?php echo esc_html( (string) $stats['links_extracted'] ); ?></strong><span><?php esc_html_e( 'Links extracted', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
					<div><strong id="indexlane-rila-stat-destinations"><?php echo esc_html( (string) $stats['unique_destinations_checked'] ); ?></strong><span><?php esc_html_e( 'Unique destinations checked', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
					<div><strong id="indexlane-rila-stat-requests"><?php echo esc_html( (string) $stats['http_requests'] ); ?></strong><span><?php esc_html_e( 'HTTP requests made', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
					<div><strong id="indexlane-rila-stat-issues"><?php echo esc_html( (string) $stats['actionable_issues'] ); ?></strong><span><?php esc_html_e( 'Actionable issues found', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				</div>
			</section>
			<?php
		}

		/**
		 * Render scan results.
		 *
		 * @param array<string,mixed> $scan Scan data.
		 */
		private static function render_results( array $scan ): void {
			$stats   = $scan['stats'];
			$results = $scan['results'];
			?>
			<div id="indexlane-rila-results" class="indexlane-rila-results">
				<h2><?php esc_html_e( 'Completed scan evidence', 'indexlane-redirect-internal-link-auditor' ); ?></h2>

				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: content count, 2: link count, 3: audited count, 4: skipped count, 5: unique destination count, 6: HTTP request count */
							__( 'Processed %1$d content items, extracted %2$d links, audited %3$d relevant link occurrences, skipped %4$d unrelated external links, checked %5$d unique destinations, and made %6$d HTTP requests.', 'indexlane-redirect-internal-link-auditor' ),
							(int) $stats['content_items_processed'],
							(int) $stats['links_extracted'],
							(int) $stats['links_audited'],
							(int) $stats['skipped_external'],
							(int) $stats['unique_destinations_checked'],
							(int) $stats['http_requests']
						)
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-export-actions">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
					<button type="submit" name="indexlane_rila_action" value="export_details" class="button"><?php esc_html_e( 'Export detailed rows as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					<button type="submit" name="indexlane_rila_action" value="export_impact" class="button"><?php esc_html_e( 'Export destination impact as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					<span class="description"><?php esc_html_e( 'Both exports use this exact completed session without additional HTTP requests. The temporary session expires after 24 hours of inactivity.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
				</form>

				<?php if ( empty( $results ) ) : ?>
					<p><?php esc_html_e( 'No internal, old-domain, or staging/development-domain content links were found in the scanned content.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				<?php else : ?>
					<?php self::render_destination_impact( self::build_destination_impact( $results ) ); ?>

					<h2><?php esc_html_e( 'Link occurrences', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Every audited link occurrence remains available below for source-by-source cleanup.', 'indexlane-redirect-internal-link-auditor' ); ?>
					</p>
					<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Link occurrences', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
					<table class="widefat striped indexlane-rila-occurrence-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source Post/Page', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Source Type', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Source URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'HTTP Status', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Redirect Count', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Final URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Warning', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Anchor Text', 'indexlane-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Result', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $results as $row ) : ?>
								<tr>
									<td>
										<?php if ( ! empty( $row['source_edit_url'] ) ) : ?>
											<a href="<?php echo esc_url( $row['source_edit_url'] ); ?>"><?php echo esc_html( $row['source_title'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['source_title'] ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['source_type'] ); ?></td>
									<td><a href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['source_url'] ); ?></a></td>
									<td><a href="<?php echo esc_url( $row['linked_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['linked_url'] ); ?></a></td>
									<td><?php echo esc_html( $row['http_status'] ); ?></td>
									<td><?php echo esc_html( (string) $row['redirect_count'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['final_url'] ) ) : ?>
											<a href="<?php echo esc_url( $row['final_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['final_url'] ); ?></a>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['warning'] ); ?></td>
									<td><?php echo esc_html( $row['anchor_text'] ); ?></td>
									<td><?php echo esc_html( $row['result'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render one row per actionable destination before the occurrence detail.
		 *
		 * @param array<int,array<string,mixed>> $impact_rows Destination impact rows.
		 */
		private static function render_destination_impact( array $impact_rows ): void {
			?>
			<h2><?php esc_html_e( 'Destination impact', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Broken/error and redirected targets are grouped by normalized destination. Repeated links in one content item increase occurrences but count as one affected content item.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<?php if ( empty( $impact_rows ) ) : ?>
				<p><?php esc_html_e( 'No broken/error or redirected destinations were found.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Destination impact', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
			<table class="widefat striped indexlane-rila-impact-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Destination', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Impact', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Occurrences', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Affected Content Items', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Result', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'HTTP Status Evidence', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Maximum Observed Redirects', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Observed Final URLs', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Warning Evidence', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $impact_rows as $row ) : ?>
						<tr>
							<td>
								<?php if ( '' !== esc_url( $row['destination_url'] ) ) : ?>
									<a href="<?php echo esc_url( $row['destination_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['destination_url'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['destination_url'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['impact'] ); ?></td>
							<td><?php echo esc_html( (string) $row['occurrences'] ); ?></td>
							<td><?php echo esc_html( (string) $row['affected_sources'] ); ?></td>
							<td><?php echo esc_html( $row['result'] ); ?></td>
							<td><?php echo esc_html( $row['http_status_evidence'] ); ?></td>
							<td><?php echo esc_html( (string) $row['max_redirect_count'] ); ?></td>
							<td><?php echo esc_html( $row['effective_final_url'] ); ?></td>
							<td><?php echo esc_html( $row['warning_evidence'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
		}

		/**
		 * Build the admin page URL.
		 */
		private static function admin_page_url(): string {
			return add_query_arg(
				array( 'page' => self::SLUG ),
				admin_url( 'tools.php' )
			);
		}

		/**
		 * Get every public post type that can contain published content.
		 *
		 * @return array<string,string>
		 */
		private static function get_available_post_types(): array {
			$labels  = array();
			$objects = get_post_types( array( 'public' => true ), 'objects' );

			foreach ( $objects as $post_type => $post_type_object ) {
				if ( 'attachment' === $post_type ) {
					continue;
				}

				$labels[ $post_type ] = isset( $post_type_object->labels->name )
					? (string) $post_type_object->labels->name
					: (string) $post_type;
			}

			natcasesort( $labels );

			return $labels;
		}

		/**
		 * Default scan settings.
		 *
		 * @return array<string,mixed>
		 */
		private static function default_settings(): array {
			return array(
				'post_types'       => array_keys( self::get_available_post_types() ),
				'old_domains'      => '',
				'old_domain_hosts' => array(),
				'content_scope'    => 'limit',
				'max_posts'        => 100,
				'timeout'          => 5.0,
				'max_redirects'    => 5,
			);
		}

		/**
		 * Sanitize submitted scan settings.
		 *
		 * @param array<string,mixed> $post_data Unslashed and nonce-verified request data.
		 * @return array<string,mixed>
		 */
		private static function get_request_settings( array $post_data ): array {
			$settings        = self::default_settings();
			$available_types = array_keys( self::get_available_post_types() );

			$post_types = isset( $post_data['post_types'] ) && is_array( $post_data['post_types'] ) ? $post_data['post_types'] : array();
			$post_types = array_filter( $post_types, 'is_scalar' );
			$post_types = array_map( 'sanitize_key', $post_types );
			$post_types = array_values( array_intersect( $post_types, $available_types ) );

			$settings['post_types'] = $post_types;

			if ( isset( $post_data['old_domains'] ) && is_scalar( $post_data['old_domains'] ) ) {
				$settings['old_domains'] = sanitize_textarea_field( (string) $post_data['old_domains'] );
			}

			if ( isset( $post_data['max_posts'] ) && is_scalar( $post_data['max_posts'] ) ) {
				$settings['max_posts'] = min( self::MAX_NUMERIC_CONTENT_ITEMS, max( 1, absint( $post_data['max_posts'] ) ) );
			}

			$scope                     = isset( $post_data['content_scope'] ) && is_scalar( $post_data['content_scope'] )
				? sanitize_key( (string) $post_data['content_scope'] )
				: '';
			$settings['content_scope'] = 'all' === $scope ? 'all' : 'limit';

			if ( isset( $post_data['timeout'] ) && is_scalar( $post_data['timeout'] ) ) {
				$timeout             = (float) $post_data['timeout'];
				$settings['timeout'] = min( 15, max( 1, round( $timeout, 1 ) ) );
			}

			if ( isset( $post_data['max_redirects'] ) && is_scalar( $post_data['max_redirects'] ) ) {
				$settings['max_redirects'] = min( 10, max( 0, absint( $post_data['max_redirects'] ) ) );
			}

			$settings['old_domain_hosts'] = self::parse_domain_hosts( $settings['old_domains'] );

			return $settings;
		}

		/**
		 * Create a stable, resumable scan session.
		 *
		 * @param array<string,mixed> $settings Sanitized settings.
		 * @return array<string,mixed>
		 */
		private static function create_scan_session( array $settings ): array {
			$snapshot = self::get_content_snapshot( $settings['post_types'] );
			$total    = 'all' === $settings['content_scope']
				? $snapshot['total_items']
				: min( $snapshot['total_items'], (int) $settings['max_posts'] );
			$now      = time();

			return array(
				'schema_version'     => self::SESSION_SCHEMA_VERSION,
				'id'                 => wp_generate_uuid4(),
				'status'             => 0 === $total ? 'complete' : 'running',
				'created_at'         => $now,
				'updated_at'         => $now,
				'expires_at'         => $now + self::SESSION_LIFETIME,
				'settings'           => $settings,
				'total_items'        => $total,
				'snapshot_max_id'    => $snapshot['max_id'],
				'cursor_before_id'   => $snapshot['max_id'] + 1,
				'content_done'       => 0 === $total,
				'request_limit'      => self::INITIAL_REQUEST_ALLOWANCE,
				'stats'              => self::empty_stats(),
				'results'            => array(),
				'checked_urls'       => array(),
				'pending_checks'      => array(),
			);
		}

		/**
		 * Count the selected published corpus and record its highest post ID.
		 *
		 * @param array<int,string> $post_types Post types.
		 * @return array{total_items:int,max_id:int}
		 */
		private static function get_content_snapshot( array $post_types ): array {
			$query = new WP_Query(
				array(
					'post_type'           => $post_types,
					'post_status'         => 'publish',
					'posts_per_page'      => 1,
					'fields'              => 'ids',
					'orderby'             => 'ID',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => false,
				)
			);

			return array(
				'total_items' => max( 0, (int) $query->found_posts ),
				'max_id'      => ! empty( $query->posts ) ? max( 0, (int) $query->posts[0] ) : 0,
			);
		}

		/**
		 * Process a bounded amount of content and HTTP work.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @return array<string,mixed>
		 */
		private static function process_scan_batch( array $session ): array {
			$batch_request_start = (int) $session['stats']['http_requests'];
			$batch_content_count = 0;

			while ( 'running' === $session['status'] ) {
				if ( ! empty( $session['pending_checks'] ) ) {
					if ( (int) $session['stats']['http_requests'] >= (int) $session['request_limit'] ) {
						$session['status'] = 'limit_reached';
						break;
					}

					if ( (int) $session['stats']['http_requests'] - $batch_request_start >= self::MAX_HTTP_REQUESTS_PER_BATCH ) {
						break;
					}

					$session = self::process_pending_check_step( $session );
					continue;
				}

				if ( ! empty( $session['content_done'] ) ) {
					$session['status'] = 'complete';
					break;
				}

				if ( $batch_content_count >= self::MAX_CONTENT_ITEMS_PER_BATCH ) {
					break;
				}

				$posts = self::get_next_scan_posts( $session, 1 );
				if ( empty( $posts ) ) {
					$session['content_done'] = true;
					$session['total_items']  = (int) $session['stats']['content_items_processed'];
					continue;
				}

				$post                        = $posts[0];
				$session['cursor_before_id'] = (int) $post->ID;
				$session                     = self::process_content_item( $session, $post );
				$batch_content_count++;

				if ( (int) $session['stats']['content_items_processed'] >= (int) $session['total_items'] ) {
					$session['content_done'] = true;
				}
			}

			if ( 'running' === $session['status'] && ! empty( $session['content_done'] ) && empty( $session['pending_checks'] ) ) {
				$session['status'] = 'complete';
			}

			return $session;
		}

		/**
		 * Fetch the next published posts below the persisted keyset cursor.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @param int                 $limit   Maximum posts.
		 * @return array<int,WP_Post>
		 */
		private static function get_next_scan_posts( array $session, int $limit ): array {
			add_filter( 'posts_where', array( __CLASS__, 'filter_scan_cursor_where' ), 10, 2 );
			$query = new WP_Query(
				array(
					'post_type'                   => $session['settings']['post_types'],
					'post_status'                 => 'publish',
					'posts_per_page'              => max( 1, $limit ),
					'orderby'                     => 'ID',
					'order'                       => 'DESC',
					'ignore_sticky_posts'         => true,
					'no_found_rows'               => true,
					'indexlane_rila_before_post_id' => (int) $session['cursor_before_id'],
				)
			);
			remove_filter( 'posts_where', array( __CLASS__, 'filter_scan_cursor_where' ), 10 );

			return is_array( $query->posts ) ? $query->posts : array();
		}

		/**
		 * Apply the internal keyset cursor to scan-only WP_Query calls.
		 *
		 * @param string   $where SQL WHERE fragment.
		 * @param WP_Query $query Query object.
		 */
		public static function filter_scan_cursor_where( string $where, $query ): string {
			$before_id = absint( $query->get( 'indexlane_rila_before_post_id' ) );
			if ( $before_id <= 0 ) {
				return $where;
			}

			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before_id );
		}

		/**
		 * Extract and queue every relevant occurrence from one content item.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @param WP_Post             $post    Content item.
		 * @return array<string,mixed>
		 */
		private static function process_content_item( array $session, $post ): array {
			$session['stats']['content_items_processed']++;
			$source_url = get_permalink( $post );
			if ( ! $source_url ) {
				return $session;
			}

			$source = array(
				'id'       => (int) $post->ID,
				'title'    => get_the_title( $post ),
				'type'     => self::get_post_type_label( (string) $post->post_type ),
				'url'      => (string) $source_url,
				'edit_url' => get_edit_post_link( $post->ID, '' ),
			);
			$links  = self::extract_links( (string) $post->post_content );
			$session['stats']['links_extracted'] += count( $links );

			$current_host = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			foreach ( $links as $link ) {
				$prepared = self::prepare_link_occurrence( $link, $source, $session['settings'], $current_host );
				if ( 'skip' === $prepared['type'] ) {
					$session['stats']['skipped_external']++;
					continue;
				}

				$session['stats']['links_audited']++;
				if ( 'row' === $prepared['type'] ) {
					$session = self::append_result_row( $session, $prepared['row'] );
					continue;
				}

				$cache_key = $prepared['cache_key'];
				if ( isset( $session['checked_urls'][ $cache_key ] ) ) {
					$row     = self::build_checked_result_row( $prepared['occurrence'], $session['checked_urls'][ $cache_key ] );
					$session = self::append_result_row( $session, $row );
					continue;
				}

				if ( ! isset( $session['pending_checks'][ $cache_key ] ) ) {
					$session['pending_checks'][ $cache_key ] = array(
						'url'         => $prepared['url'],
						'occurrences' => array(),
						'check_state' => self::initial_check_state( $prepared['url'] ),
					);
				}
				$session['pending_checks'][ $cache_key ]['occurrences'][] = $prepared['occurrence'];
			}

			return $session;
		}

		/**
		 * Process one HTTP step from the oldest queued unique destination.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @return array<string,mixed>
		 */
		private static function process_pending_check_step( array $session ): array {
			reset( $session['pending_checks'] );
			$cache_key = key( $session['pending_checks'] );
			if ( ! is_string( $cache_key ) || ! isset( $session['pending_checks'][ $cache_key ] ) ) {
				return $session;
			}

			$pending = $session['pending_checks'][ $cache_key ];
			$step    = self::advance_check_state(
				$pending['check_state'],
				(float) $session['settings']['timeout'],
				(int) $session['settings']['max_redirects']
			);

			if ( ! empty( $step['request_made'] ) ) {
				$session['stats']['http_requests']++;
			}

			if ( empty( $step['complete'] ) ) {
				$session['pending_checks'][ $cache_key ]['check_state'] = $step['state'];
				return $session;
			}

			$check                                  = $step['check'];
			$session['checked_urls'][ $cache_key ]  = $check;
			$session['stats']['unique_destinations_checked'] = count( $session['checked_urls'] );
			foreach ( $pending['occurrences'] as $occurrence ) {
				$session = self::append_result_row( $session, self::build_checked_result_row( $occurrence, $check ) );
			}
			unset( $session['pending_checks'][ $cache_key ] );

			return $session;
		}

		/**
		 * Append evidence and update the actionable-occurrence count.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @param array<string,mixed> $row     Evidence row.
		 * @return array<string,mixed>
		 */
		private static function append_result_row( array $session, array $row ): array {
			$session['results'][] = $row;
			if ( ! isset( $row['result_code'] ) || 'ok' !== $row['result_code'] ) {
				$session['stats']['actionable_issues']++;
			}

			return $session;
		}

		/**
		 * Build zeroed scan progress counters.
		 *
		 * @return array<string,int>
		 */
		private static function empty_stats(): array {
			return array(
				'content_items_processed'      => 0,
				'links_extracted'               => 0,
				'links_audited'                 => 0,
				'skipped_external'              => 0,
				'unique_destinations_checked'   => 0,
				'http_requests'                 => 0,
				'actionable_issues'             => 0,
			);
		}

		/**
		 * Extract links from post content.
		 *
		 * @param string $content Post content.
		 * @return array<int,array{href:string,anchor:string}>
		 */
		private static function extract_links( string $content ): array {
			if ( '' === trim( $content ) ) {
				return array();
			}

			if ( class_exists( 'DOMDocument' ) ) {
				return self::extract_links_with_dom( $content );
			}

			return self::extract_links_with_regex( $content );
		}

		/**
		 * Extract links using DOMDocument.
		 *
		 * @param string $content Post content.
		 * @return array<int,array{href:string,anchor:string}>
		 */
		private static function extract_links_with_dom( string $content ): array {
			$links    = array();
			$document = new DOMDocument();
			$previous = libxml_use_internal_errors( true );

			$loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $content );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded ) {
				return self::extract_links_with_regex( $content );
			}

			foreach ( $document->getElementsByTagName( 'a' ) as $node ) {
				$href = trim( (string) $node->getAttribute( 'href' ) );
				if ( self::should_ignore_href( $href ) ) {
					continue;
				}

				$links[] = array(
					'href'   => $href,
					'anchor' => self::normalize_anchor_text( (string) $node->textContent ),
				);
			}

			return $links;
		}

		/**
		 * Extract links using a small fallback regex.
		 *
		 * @param string $content Post content.
		 * @return array<int,array{href:string,anchor:string}>
		 */
		private static function extract_links_with_regex( string $content ): array {
			$links = array();

			if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*([\'"])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
				return $links;
			}

			foreach ( $matches as $match ) {
				$href = trim( wp_specialchars_decode( $match[2], ENT_QUOTES ) );
				if ( self::should_ignore_href( $href ) ) {
					continue;
				}

				$links[] = array(
					'href'   => $href,
					'anchor' => self::normalize_anchor_text( wp_strip_all_tags( $match[3] ) ),
				);
			}

			return $links;
		}

		/**
		 * Classify an occurrence before any HTTP work is scheduled.
		 *
		 * @param array{href:string,anchor:string} $link         Extracted link.
		 * @param array<string,mixed>              $source       Source post data.
		 * @param array<string,mixed>              $settings     Sanitized settings.
		 * @param string                           $current_host Normalized current site host.
		 * @return array<string,mixed>
		 */
		private static function prepare_link_occurrence( array $link, array $source, array $settings, string $current_host ): array {
			$linked_url = self::normalize_link_url( $link['href'], (string) $source['url'] );

			if ( '' === $linked_url ) {
				return array(
					'type' => 'row',
					'row'  => self::build_result_row(
						$source,
						$link,
						$link['href'],
						'',
						'',
						'',
						__( 'Invalid URL', 'indexlane-redirect-internal-link-auditor' ),
						__( 'Error', 'indexlane-redirect-internal-link-auditor' ),
						'error'
					),
				);
			}

			$linked_host = self::normalize_host( (string) wp_parse_url( $linked_url, PHP_URL_HOST ) );
			$is_current  = self::hosts_match( $linked_host, $current_host );
			$is_old      = in_array( $linked_host, $settings['old_domain_hosts'], true );
			$is_staging  = ! $is_current && self::is_staging_or_dev_host( $linked_host );

			if ( ! $is_current && ! $is_old && ! $is_staging ) {
				return array( 'type' => 'skip' );
			}

			$warnings = array();
			if ( $is_old ) {
				$warnings[] = __( 'Old domain', 'indexlane-redirect-internal-link-auditor' );
			}
			if ( $is_staging ) {
				$warnings[] = __( 'Staging/dev domain', 'indexlane-redirect-internal-link-auditor' );
			}

			$should_request = $is_current;

			if ( ! $should_request ) {
				$warnings[] = __( 'Status check skipped by same-site scope', 'indexlane-redirect-internal-link-auditor' );

				return array(
					'type' => 'row',
					'row'  => self::build_result_row(
						$source,
						$link,
						$linked_url,
						'',
						'',
						'',
						implode( '; ', $warnings ),
						__( 'Needs review', 'indexlane-redirect-internal-link-auditor' ),
						'needs_review'
					),
				);
			}

			if ( ! self::is_valid_http_url( $linked_url ) ) {
				$warnings[] = __( 'Invalid HTTP URL', 'indexlane-redirect-internal-link-auditor' );

				return array(
					'type' => 'row',
					'row'  => self::build_result_row(
						$source,
						$link,
						$linked_url,
						'',
						'',
						'',
						implode( '; ', $warnings ),
						__( 'Error', 'indexlane-redirect-internal-link-auditor' ),
						'error'
					),
				);
			}

			$cache_key = self::normalize_url_for_compare( $linked_url );

			return array(
				'type'       => 'check',
				'cache_key'  => $cache_key,
				'url'        => $linked_url,
				'occurrence' => array(
					'source'     => $source,
					'link'       => $link,
					'linked_url' => $linked_url,
					'warnings'   => $warnings,
					'is_old'     => $is_old,
					'is_staging' => $is_staging,
				),
			);
		}

		/**
		 * Convert a completed unique-destination check into occurrence evidence.
		 *
		 * @param array<string,mixed> $occurrence Prepared occurrence.
		 * @param array<string,mixed> $check      Completed check.
		 * @return array<string,mixed>
		 */
		private static function build_checked_result_row( array $occurrence, array $check ): array {
			$warnings = $occurrence['warnings'];

			if ( ! $check['ok'] ) {
				if ( ! empty( $check['error'] ) ) {
					$warnings[] = $check['error'];
				}

				return self::build_result_row(
					$occurrence['source'],
					$occurrence['link'],
					$occurrence['linked_url'],
					implode( ' -> ', $check['statuses'] ),
					$check['redirect_count'],
					$check['final_url'],
					self::format_warning_text( $warnings ),
					__( 'Error', 'indexlane-redirect-internal-link-auditor' ),
					'error'
				);
			}

			$redirect_codes = $check['redirect_codes'];
			if ( count( $redirect_codes ) > 1 ) {
				$warnings[] = __( 'Redirect chain', 'indexlane-redirect-internal-link-auditor' );
			} elseif ( 1 === count( $redirect_codes ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP redirect status code */
					__( 'Redirect (%d)', 'indexlane-redirect-internal-link-auditor' ),
					(int) $redirect_codes[0]
				);
			}

			if ( ! empty( $check['redirect_limit_reached'] ) ) {
				$warnings[] = __( 'Redirect limit reached', 'indexlane-redirect-internal-link-auditor' );
			}

			if ( ! empty( $check['redirect_loop'] ) ) {
				$warnings[] = __( 'Redirect loop', 'indexlane-redirect-internal-link-auditor' );
			}

			if ( ! empty( $check['redirect_left_site'] ) ) {
				$warnings[] = __( 'Redirect leaves site; external target was not fetched.', 'indexlane-redirect-internal-link-auditor' );
			}

			$final_status = (int) $check['final_status'];
			if ( $final_status <= 0 ) {
				$warnings[] = __( 'No HTTP status returned', 'indexlane-redirect-internal-link-auditor' );
			} elseif ( in_array( $final_status, array( 404, 410 ), true ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Broken link (%d)', 'indexlane-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( in_array( $final_status, array( 401, 403, 429 ), true ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Blocked or rate limited (%d)', 'indexlane-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( $final_status >= 400 ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP error (%d)', 'indexlane-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( $final_status >= 300 && $final_status < 400 ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Redirect without final target (%d)', 'indexlane-redirect-internal-link-auditor' ),
					$final_status
				);
			}

			$result_code = self::result_code_for_check(
				$warnings,
				$final_status,
				(int) $check['redirect_count'],
				(bool) $occurrence['is_old'],
				(bool) $occurrence['is_staging']
			);
			$result      = self::result_label_for_code( $result_code );

			return self::build_result_row(
				$occurrence['source'],
				$occurrence['link'],
				$occurrence['linked_url'],
				implode( ' -> ', $check['statuses'] ),
				$check['redirect_count'],
				$check['final_url'],
				self::format_warning_text( $warnings ),
				$result,
				$result_code
			);
		}

		/**
		 * Create the serializable state for one redirect-aware URL check.
		 *
		 * @return array<string,mixed>
		 */
		private static function initial_check_state( string $url ): array {
			return array(
				'current_url'            => $url,
				'statuses'               => array(),
				'redirect_codes'         => array(),
				'visited'                => array(),
				'redirect_count'         => 0,
				'redirect_limit_reached' => false,
				'redirect_loop'          => false,
				'redirect_left_site'     => false,
			);
		}

		/**
		 * Advance a URL check by at most one actual outbound request.
		 *
		 * @param array<string,mixed> $state         Persisted check state.
		 * @param float               $timeout       Request timeout.
		 * @param int                 $max_redirects Maximum redirects.
		 * @return array<string,mixed>
		 */
		private static function advance_check_state( array $state, float $timeout, int $max_redirects ): array {
			$current_url = (string) $state['current_url'];
			$visited_key = self::normalize_url_for_compare( $current_url );
			if ( isset( $state['visited'][ $visited_key ] ) ) {
				$state['redirect_loop'] = true;
				return array(
					'complete'     => true,
					'request_made' => false,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			$state['visited'][ $visited_key ] = true;
			$response = self::request_url_without_redirects( $current_url, $timeout );
			if ( is_wp_error( $response ) ) {
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, false, $response->get_error_message() ),
				);
			}

			$status              = (int) wp_remote_retrieve_response_code( $response );
			$state['statuses'][] = (string) $status;
			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_array( $location ) ) {
				$location = reset( $location );
			}
			$location = is_string( $location ) ? trim( $location ) : '';
			if ( '' === $location ) {
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			$state['redirect_count']++;
			$state['redirect_codes'][] = $status;
			if ( (int) $state['redirect_count'] > $max_redirects ) {
				$state['redirect_limit_reached'] = true;
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			$next_url = self::make_absolute_url( $location, $current_url );
			$state['current_url'] = $next_url;
			if ( ! self::is_valid_http_url( $next_url ) ) {
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, false, __( 'Invalid redirect target', 'indexlane-redirect-internal-link-auditor' ) ),
				);
			}

			$current_host = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$next_host    = self::normalize_host( (string) wp_parse_url( $next_url, PHP_URL_HOST ) );
			if ( ! self::hosts_match( $next_host, $current_host ) ) {
				$state['redirect_left_site'] = true;
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			$next_key = self::normalize_url_for_compare( $next_url );
			if ( isset( $state['visited'][ $next_key ] ) ) {
				$state['redirect_loop'] = true;
				return array(
					'complete'     => true,
					'request_made' => true,
					'state'        => $state,
					'check'        => self::finished_check_from_state( $state, true, '' ),
				);
			}

			return array(
				'complete'     => false,
				'request_made' => true,
				'state'        => $state,
				'check'        => null,
			);
		}

		/**
		 * Turn serializable redirect state into the existing evidence contract.
		 *
		 * @param array<string,mixed> $state Check state.
		 * @return array<string,mixed>
		 */
		private static function finished_check_from_state( array $state, bool $ok, string $error ): array {
			$statuses = $state['statuses'];
			return array(
				'ok'                     => $ok,
				'error'                  => $error,
				'statuses'               => $statuses,
				'redirect_count'         => (int) $state['redirect_count'],
				'redirect_codes'         => $state['redirect_codes'],
				'final_status'           => count( $statuses ) ? (int) end( $statuses ) : 0,
				'final_url'              => (string) $state['current_url'],
				'redirect_limit_reached' => (bool) $state['redirect_limit_reached'],
				'redirect_loop'          => (bool) $state['redirect_loop'],
				'redirect_left_site'     => (bool) $state['redirect_left_site'],
				'budget_exhausted'       => false,
			);
		}

		/**
		 * Synchronous compatibility wrapper used by focused low-level tests.
		 *
		 * Production sessions call advance_check_state() and persist between steps.
		 *
		 * @param string $url           URL to check.
		 * @param float  $timeout       Request timeout.
		 * @param int    $max_redirects Maximum redirects.
		 * @param int    $request_count Existing request count.
		 * @return array<string,mixed>
		 */
		private static function check_url( string $url, float $timeout, int $max_redirects, int &$request_count ): array {
			$state = self::initial_check_state( $url );

			while ( true ) {
				if ( $request_count >= self::INITIAL_REQUEST_ALLOWANCE ) {
					$check                     = self::finished_check_from_state(
						$state,
						false,
						sprintf(
							/* translators: %d: initial outbound HTTP request allowance */
							__( 'Status check incomplete because the %d-request allowance was reached', 'indexlane-redirect-internal-link-auditor' ),
							self::INITIAL_REQUEST_ALLOWANCE
						)
					);
					$check['budget_exhausted'] = true;
					return $check;
				}

				$step = self::advance_check_state( $state, $timeout, $max_redirects );
				if ( ! empty( $step['request_made'] ) ) {
					$request_count++;
				}
				if ( ! empty( $step['complete'] ) ) {
					return $step['check'];
				}
				$state = $step['state'];
			}
		}

		/**
		 * Make one HTTP request without automatic redirects.
		 *
		 * @param string $url     URL to check.
		 * @param float  $timeout Timeout.
		 * @return array<string,mixed>|WP_Error
		 */
		private static function request_url_without_redirects( string $url, float $timeout ) {
			$args = array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::RESPONSE_SIZE_LIMIT,
				'user-agent'          => 'IndexLane Redirect & Internal Link Auditor/' . self::VERSION . '; ' . home_url( '/' ),
			);

			return wp_safe_remote_get( $url, $args );
		}

		/**
		 * Build a result row.
		 *
		 * @param array<string,mixed>              $source         Source post data.
		 * @param array{href:string,anchor:string} $link           Extracted link.
		 * @param string                           $linked_url     Linked URL.
		 * @param string                           $http_status    Status chain text.
		 * @param int|string                       $redirect_count Redirect count.
		 * @param string                           $final_url      Final URL.
		 * @param string                           $warning        Warning.
		 * @param string                           $result         Result label.
		 * @param string                           $result_code    Stable result code.
		 * @return array<string,mixed>
		 */
		private static function build_result_row( array $source, array $link, string $linked_url, string $http_status, $redirect_count, string $final_url, string $warning, string $result, string $result_code = '' ): array {
			if ( '' === $result_code ) {
				$result_code = self::result_code_from_label( $result );
			}

			return array(
				'source_title'    => $source['title'],
				'source_type'     => $source['type'],
				'source_url'      => $source['url'],
				'source_edit_url' => $source['edit_url'],
				'linked_url'      => $linked_url,
				'http_status'     => $http_status,
				'redirect_count'  => $redirect_count,
				'final_url'       => $final_url,
				'warning'         => $warning,
				'anchor_text'     => $link['anchor'],
				'result'          => $result,
				'result_code'     => $result_code,
			);
		}

		/**
		 * Get a conservative, language-independent result code.
		 *
		 * @param array<int,string> $warnings       Warning texts.
		 * @param int               $final_status   Final HTTP status.
		 * @param int               $redirect_count Redirect count.
		 * @param bool              $is_old         Whether link uses old domain.
		 * @param bool              $is_staging     Whether link uses staging/dev host.
		 */
		private static function result_code_for_check( array $warnings, int $final_status, int $redirect_count, bool $is_old, bool $is_staging ): string {
			if ( $final_status <= 0 ) {
				return 'needs_review';
			}

			if ( in_array( $final_status, array( 401, 403, 429 ), true ) ) {
				return 'blocked';
			}

			if ( in_array( $final_status, array( 404, 410 ), true ) || $final_status >= 500 ) {
				return 'error';
			}

			if ( $final_status >= 400 ) {
				return 'needs_review';
			}

			if ( $redirect_count > 0 ) {
				return 'warning';
			}

			if ( $is_old || $is_staging || ! empty( $warnings ) ) {
				return 'needs_review';
			}

			return 'ok';
		}

		/**
		 * Translate a stable result code for display and export.
		 */
		private static function result_label_for_code( string $result_code ): string {
			switch ( $result_code ) {
				case 'error':
					return __( 'Error', 'indexlane-redirect-internal-link-auditor' );
				case 'blocked':
					return __( 'Blocked', 'indexlane-redirect-internal-link-auditor' );
				case 'warning':
					return __( 'Warning', 'indexlane-redirect-internal-link-auditor' );
				case 'ok':
					return __( 'OK', 'indexlane-redirect-internal-link-auditor' );
				default:
					return __( 'Needs review', 'indexlane-redirect-internal-link-auditor' );
			}
		}

		/**
		 * Infer a stable code for compatibility with rows built from display labels.
		 */
		private static function result_code_from_label( string $result ): string {
			foreach ( array( 'Error' => 'error', 'Blocked' => 'blocked', 'Warning' => 'warning', 'OK' => 'ok' ) as $label => $code ) {
				if ( self::result_label_matches( $result, $label ) ) {
					return $code;
				}
			}

			return 'needs_review';
		}

		/**
		 * Format warning text.
		 *
		 * @param array<int,string> $warnings Warning texts.
		 */
		private static function format_warning_text( array $warnings ): string {
			$warnings = array_filter( array_map( 'trim', $warnings ) );

			return empty( $warnings ) ? __( 'None', 'indexlane-redirect-internal-link-auditor' ) : implode( '; ', array_unique( $warnings ) );
		}

		/**
		 * Group broken and redirected link occurrences by normalized destination.
		 *
		 * This is a read-only projection of completed result rows. It never issues
		 * requests and therefore always represents the same scan as the detail view.
		 *
		 * @param array<int,array<string,mixed>> $results Result rows.
		 * @return array<int,array<string,mixed>>
		 */
		private static function build_destination_impact( array $results ): array {
			$groups = array();

			foreach ( $results as $row ) {
				$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] )
					? max( 0, (int) $row['redirect_count'] )
					: 0;
				$final_status   = self::final_status_from_evidence( isset( $row['http_status'] ) ? (string) $row['http_status'] : '' );
				$warning        = isset( $row['warning'] ) ? (string) $row['warning'] : '';
				$result         = isset( $row['result'] ) ? trim( (string) $row['result'] ) : '';
				$is_broken      = self::result_label_matches( $result, 'Error' ) || in_array( $final_status, array( 404, 410 ), true );
				$is_redirected  = $redirect_count > 0 || ( $final_status >= 300 && $final_status < 400 );

				if ( ! $is_broken && ! $is_redirected ) {
					continue;
				}

				$raw_destination = isset( $row['linked_url'] ) ? trim( (string) $row['linked_url'] ) : '';
				$destination     = self::normalize_destination_for_impact( $raw_destination );
				$group_key       = '' !== $destination ? 'url:' . $destination : 'raw:' . $raw_destination;
				if ( '' === $raw_destination ) {
					continue;
				}
				if ( '' === $destination ) {
					$destination = $raw_destination;
				}

				if ( ! isset( $groups[ $group_key ] ) ) {
					$groups[ $group_key ] = array(
						'destination_url'       => $destination,
						'occurrences'            => 0,
						'source_keys'            => array(),
						'result'                 => '',
						'result_rank'            => -1,
						'has_broken'             => false,
						'has_redirect'           => false,
						'http_statuses'          => array(),
						'max_redirect_count'     => 0,
						'effective_final_urls'   => array(),
						'warnings'               => array(),
					);
				}

				$groups[ $group_key ]['occurrences']++;
				$groups[ $group_key ]['has_broken']   = $groups[ $group_key ]['has_broken'] || $is_broken;
				$groups[ $group_key ]['has_redirect'] = $groups[ $group_key ]['has_redirect'] || $is_redirected;
				$groups[ $group_key ]['max_redirect_count'] = max( $groups[ $group_key ]['max_redirect_count'], $redirect_count );

				$source_key = self::normalize_destination_for_impact( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );
				if ( '' === $source_key ) {
					$source_key = isset( $row['source_url'] ) ? (string) $row['source_url'] : '';
				}
				if ( '' !== $source_key ) {
					$groups[ $group_key ]['source_keys'][ $source_key ] = true;
				}

				$status_evidence = isset( $row['http_status'] ) ? trim( (string) $row['http_status'] ) : '';
				if ( '' !== $status_evidence ) {
					$groups[ $group_key ]['http_statuses'][ $status_evidence ] = true;
				}

				$raw_final_url = isset( $row['final_url'] ) ? trim( (string) $row['final_url'] ) : '';
				$final_url     = self::normalize_destination_for_impact( $raw_final_url );
				if ( '' === $final_url ) {
					$final_url = $raw_final_url;
				}
				if ( '' !== $final_url ) {
					$groups[ $group_key ]['effective_final_urls'][ $final_url ] = true;
				}

				if ( '' !== trim( $warning ) && ! self::result_label_matches( trim( $warning ), 'None' ) ) {
					$groups[ $group_key ]['warnings'][ trim( $warning ) ] = true;
				}

				$result_rank = self::result_impact_rank( $result );
				if (
					$result_rank > $groups[ $group_key ]['result_rank'] ||
					( $result_rank === $groups[ $group_key ]['result_rank'] && ( '' === $groups[ $group_key ]['result'] || strcmp( $result, $groups[ $group_key ]['result'] ) < 0 ) )
				) {
					$groups[ $group_key ]['result']      = $result;
					$groups[ $group_key ]['result_rank'] = $result_rank;
				}
			}

			$impact_rows = array();
			foreach ( $groups as $group ) {
				$http_statuses = array_keys( $group['http_statuses'] );
				$final_urls    = array_keys( $group['effective_final_urls'] );
				$warnings      = array_keys( $group['warnings'] );
				sort( $http_statuses, SORT_STRING );
				sort( $final_urls, SORT_STRING );
				sort( $warnings, SORT_STRING );

				if ( $group['has_broken'] && $group['has_redirect'] ) {
					$impact = __( 'Broken/error after redirect', 'indexlane-redirect-internal-link-auditor' );
				} elseif ( $group['has_broken'] ) {
					$impact = __( 'Broken/error', 'indexlane-redirect-internal-link-auditor' );
				} else {
					$impact = __( 'Redirect', 'indexlane-redirect-internal-link-auditor' );
				}

				$impact_rows[] = array(
					'destination_url'       => $group['destination_url'],
					'impact'                => $impact,
					'occurrences'           => $group['occurrences'],
					'affected_sources'      => count( $group['source_keys'] ),
					'result'                => '' !== $group['result'] ? $group['result'] : __( 'Needs review', 'indexlane-redirect-internal-link-auditor' ),
					'result_rank'           => $group['result_rank'],
					'http_status_evidence'  => implode( ' | ', $http_statuses ),
					'max_redirect_count'    => $group['max_redirect_count'],
					'effective_final_url'   => implode( ' | ', $final_urls ),
					'warning_evidence'      => implode( ' | ', $warnings ),
				);
			}

			usort(
				$impact_rows,
				static function ( array $left, array $right ): int {
					if ( $left['result_rank'] !== $right['result_rank'] ) {
						return $right['result_rank'] <=> $left['result_rank'];
					}
					if ( $left['affected_sources'] !== $right['affected_sources'] ) {
						return $right['affected_sources'] <=> $left['affected_sources'];
					}
					if ( $left['occurrences'] !== $right['occurrences'] ) {
						return $right['occurrences'] <=> $left['occurrences'];
					}

					return strcmp( $left['destination_url'], $right['destination_url'] );
				}
			);

			foreach ( $impact_rows as &$impact_row ) {
				unset( $impact_row['result_rank'] );
			}
			unset( $impact_row );

			return $impact_rows;
		}

		/**
		 * Normalize a URL used as an impact-group key.
		 *
		 * Scheme and host case and fragments do not create separate groups. Paths,
		 * query strings, schemes, non-default ports, and trailing slashes remain
		 * distinct because they can produce different HTTP behavior.
		 */
		private static function normalize_destination_for_impact( string $url ): string {
			$normalized = self::normalize_url_for_compare( $url );
			$parts      = wp_parse_url( $normalized );

			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return '';
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			$host   = strtolower( trim( (string) $parts['host'], " \t\n\r\0\x0B." ) );
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
			if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
				$port = 0;
			}

			$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
			$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

			return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' ) . $path . $query;
		}

		/**
		 * Read the last HTTP status from a displayed status chain.
		 */
		private static function final_status_from_evidence( string $evidence ): int {
			if ( ! preg_match_all( '/\b([1-5][0-9]{2})\b/', $evidence, $matches ) || empty( $matches[1] ) ) {
				return 0;
			}

			return (int) end( $matches[1] );
		}

		/**
		 * Sort result labels from most actionable to least actionable.
		 */
		private static function result_impact_rank( string $result ): int {
			$ranks = array(
				'Error'        => 5,
				'Blocked'      => 4,
				'Needs review' => 3,
				'Warning'      => 2,
				'OK'           => 1,
			);

			foreach ( $ranks as $label => $rank ) {
				if ( self::result_label_matches( $result, $label ) ) {
					return $rank;
				}
			}

			return 0;
		}

		/**
		 * Compare a display label against its English and translated forms.
		 */
		private static function result_label_matches( string $value, string $english_label ): bool {
			return in_array(
				$value,
				array(
					$english_label,
					self::translated_result_label( $english_label ),
				),
				true
			);
		}

		/**
		 * Return the translated form of a known result label.
		 */
		private static function translated_result_label( string $english_label ): string {
			switch ( $english_label ) {
				case 'Error':
					return __( 'Error', 'indexlane-redirect-internal-link-auditor' );
				case 'Blocked':
					return __( 'Blocked', 'indexlane-redirect-internal-link-auditor' );
				case 'Needs review':
					return __( 'Needs review', 'indexlane-redirect-internal-link-auditor' );
				case 'Warning':
					return __( 'Warning', 'indexlane-redirect-internal-link-auditor' );
				case 'OK':
					return __( 'OK', 'indexlane-redirect-internal-link-auditor' );
				case 'None':
					return __( 'None', 'indexlane-redirect-internal-link-auditor' );
				default:
					return $english_label;
			}
		}

		/**
		 * Send CSV response and terminate.
		 *
		 * @param array<int,array<string,mixed>> $results Result rows.
		 * @param string                         $report_type Report type: details or impact.
		 */
		private static function send_csv( array $results, string $report_type ): void {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			$report_type = 'impact' === $report_type ? 'impact' : 'details';
			header( 'Content-Disposition: attachment; filename=indexlane-redirect-internal-link-auditor-' . $report_type . '-' . gmdate( 'Y-m-d-His' ) . '.csv' );

			$output = fopen( 'php://output', 'w' );
			if ( false === $output ) {
				exit;
			}

			foreach ( self::build_csv_rows( $results, $report_type ) as $csv_row ) {
				fputcsv( $output, $csv_row, ',', '"', '' );
			}

			exit;
		}

		/**
		 * Build safe CSV rows, including the header, for a report type.
		 *
		 * @param array<int,array<string,mixed>> $results Result rows.
		 * @param string                         $report_type Report type: details or impact.
		 * @return array<int,array<int,string>>
		 */
		private static function build_csv_rows( array $results, string $report_type ): array {
			if ( 'impact' === $report_type ) {
				$rows = array(
					array(
						self::csv_safe( __( 'Destination', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Impact', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Occurrences', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Affected Content Items', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Result', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'HTTP Status Evidence', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Maximum Observed Redirects', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Observed Final URLs', 'indexlane-redirect-internal-link-auditor' ) ),
						self::csv_safe( __( 'Warning Evidence', 'indexlane-redirect-internal-link-auditor' ) ),
					),
				);

				foreach ( self::build_destination_impact( $results ) as $row ) {
					$rows[] = array(
						self::csv_safe( (string) $row['destination_url'] ),
						self::csv_safe( (string) $row['impact'] ),
						self::csv_safe( (string) $row['occurrences'] ),
						self::csv_safe( (string) $row['affected_sources'] ),
						self::csv_safe( (string) $row['result'] ),
						self::csv_safe( (string) $row['http_status_evidence'] ),
						self::csv_safe( (string) $row['max_redirect_count'] ),
						self::csv_safe( (string) $row['effective_final_url'] ),
						self::csv_safe( (string) $row['warning_evidence'] ),
					);
				}

				return $rows;
			}

			$rows = array(
				array(
					self::csv_safe( __( 'Source Post/Page', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Source Type', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Source URL', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'HTTP Status', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Redirect Count', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Final URL', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Warning', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Anchor Text', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Result', 'indexlane-redirect-internal-link-auditor' ) ),
				),
			);

			foreach ( $results as $row ) {
				$rows[] = array(
					self::csv_safe( (string) $row['source_title'] ),
					self::csv_safe( (string) $row['source_type'] ),
					self::csv_safe( (string) $row['source_url'] ),
					self::csv_safe( (string) $row['linked_url'] ),
					self::csv_safe( (string) $row['http_status'] ),
					self::csv_safe( (string) $row['redirect_count'] ),
					self::csv_safe( (string) $row['final_url'] ),
					self::csv_safe( (string) $row['warning'] ),
					self::csv_safe( (string) $row['anchor_text'] ),
					self::csv_safe( (string) $row['result'] ),
				);
			}

			return $rows;
		}

		/**
		 * Save the current user's session with a sliding abandonment expiry.
		 *
		 * @param array<string,mixed> $session Scan session.
		 */
		private static function save_scan_session( array $session ): bool {
			$session['updated_at'] = time();
			$session['expires_at'] = time() + self::SESSION_LIFETIME;

			return set_transient( self::scan_session_transient_key(), $session, self::SESSION_LIFETIME );
		}

		/**
		 * Load the current user's unexpired session.
		 *
		 * @return array<string,mixed>|null
		 */
		private static function get_scan_session(): ?array {
			$session = get_transient( self::scan_session_transient_key() );

			if (
				! is_array( $session ) ||
				! isset( $session['schema_version'], $session['id'], $session['status'], $session['expires_at'] ) ||
				self::SESSION_SCHEMA_VERSION !== (int) $session['schema_version']
			) {
				return null;
			}

			if ( (int) $session['expires_at'] <= time() ) {
				self::delete_scan_session();
				return null;
			}

			return $session;
		}

		/**
		 * Remove all temporary evidence for the current user's session.
		 */
		private static function delete_scan_session(): void {
			delete_transient( self::scan_session_transient_key() );
		}

		/**
		 * Build the single transient key scoped to the current user.
		 */
		private static function scan_session_transient_key(): string {
			return self::SESSION_TRANSIENT_PREFIX . get_current_user_id();
		}

		/**
		 * Return a browser-safe summary of the current session, if any.
		 *
		 * @return array<string,mixed>|null
		 */
		private static function get_scan_session_summary(): ?array {
			$session = self::get_scan_session();
			return is_array( $session ) ? self::build_session_summary( $session ) : null;
		}

		/**
		 * Build progress data without exposing accumulated evidence in page scripts.
		 *
		 * @param array<string,mixed> $session Scan session.
		 * @return array<string,mixed>
		 */
		private static function build_session_summary( array $session ): array {
			$request_limit = max( 0, (int) $session['request_limit'] );
			$requests      = max( 0, (int) $session['stats']['http_requests'] );

			return array(
				'id'                          => (string) $session['id'],
				'status'                      => (string) $session['status'],
				'state_label'                 => self::scan_state_label( (string) $session['status'] ),
				'message'                     => self::scan_state_message( (string) $session['status'] ),
				'total_items'                 => max( 0, (int) $session['total_items'] ),
				'request_limit'               => $request_limit,
				'request_allowance_remaining' => max( 0, $request_limit - $requests ),
				'stats'                       => $session['stats'],
				'can_export'                  => 'complete' === $session['status'],
			);
		}

		/**
		 * Human-readable state label.
		 */
		private static function scan_state_label( string $status ): string {
			switch ( $status ) {
				case 'running':
					return __( 'Scanning', 'indexlane-redirect-internal-link-auditor' );
				case 'paused':
					return __( 'Paused', 'indexlane-redirect-internal-link-auditor' );
				case 'limit_reached':
					return __( 'Request allowance reached', 'indexlane-redirect-internal-link-auditor' );
				case 'complete':
					return __( 'Complete', 'indexlane-redirect-internal-link-auditor' );
				default:
					return __( 'Unavailable', 'indexlane-redirect-internal-link-auditor' );
			}
		}

		/**
		 * Plain-language state consequence and next action.
		 */
		private static function scan_state_message( string $status ): string {
			switch ( $status ) {
				case 'running':
					return __( 'Keep this page open while the browser requests the next small batch. You can pause safely after the current batch.', 'indexlane-redirect-internal-link-auditor' );
				case 'paused':
					return __( 'Progress and accumulated evidence are saved. Continue here now or after reloading the page.', 'indexlane-redirect-internal-link-auditor' );
				case 'limit_reached':
					return sprintf(
						/* translators: %d: number of requests granted by the continue action */
						__( 'Progress is saved before any incomplete destination evidence is recorded. Continue to allow up to %d more outbound requests.', 'indexlane-redirect-internal-link-auditor' ),
						self::REQUEST_ALLOWANCE_INCREMENT
					);
				case 'complete':
					return __( 'Every selected content item and queued same-site destination has been processed. The CSV exports use this exact evidence.', 'indexlane-redirect-internal-link-auditor' );
				default:
					return '';
			}
		}

		/**
		 * Avoid spreadsheet formula execution on CSV open.
		 */
		private static function csv_safe( string $value ): string {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

			if ( '' !== $value && preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
				return "'" . $value;
			}

			return $value;
		}

		/**
		 * Parse old-domain hostnames.
		 *
		 * @param string $domains Raw textarea value.
		 * @return array<int,string>
		 */
		private static function parse_domain_hosts( string $domains ): array {
			$hosts  = array();
			$tokens = preg_split( '/[\s,]+/', $domains );

			if ( ! is_array( $tokens ) ) {
				return array();
			}

			foreach ( $tokens as $token ) {
				$token = trim( sanitize_text_field( $token ) );
				if ( '' === $token ) {
					continue;
				}

				$url  = preg_match( '#^https?://#i', $token ) ? $token : 'http://' . $token;
				$host = self::normalize_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );

				if ( '' !== $host ) {
					$hosts[ $host ] = $host;
				}
			}

			return array_values( $hosts );
		}

		/**
		 * Normalize a URL for per-run request cache and redirect-loop keys.
		 *
		 * Paths remain byte-for-byte distinct, including a trailing slash.
		 */
		private static function normalize_url_for_compare( string $url ): string {
			$url   = preg_replace( '/#.*/', '', $url );
			$url   = is_string( $url ) ? $url : '';
			$parts = wp_parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return $url;
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			$host   = strtolower( trim( (string) $parts['host'], " \t\n\r\0\x0B." ) );
			$port   = empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'];
			$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
			$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

			return $scheme . '://' . $host . $port . $path . $query;
		}

		/**
		 * Normalize a raw href into an absolute URL.
		 */
		private static function normalize_link_url( string $href, string $base_url ): string {
			$href = trim( wp_specialchars_decode( $href, ENT_QUOTES ) );

			if ( self::should_ignore_href( $href ) ) {
				return '';
			}

			$url = self::make_absolute_url( $href, $base_url );
			$url = preg_replace( '/#.*/', '', $url );

			return is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
		}

		/**
		 * Make a URL absolute against a base URL.
		 */
		private static function make_absolute_url( string $url, string $base_url ): string {
			$url = trim( $url );

			if ( '' === $url ) {
				return '';
			}

			if ( class_exists( 'WP_Http' ) && is_callable( array( 'WP_Http', 'make_absolute_url' ) ) ) {
				$absolute = WP_Http::make_absolute_url( $url, $base_url );
				return is_string( $absolute ) ? $absolute : '';
			}

			$base_parts = wp_parse_url( $base_url );
			if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
				return '';
			}

			if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $url ) ) {
				return $url;
			}

			$scheme = $base_parts['scheme'];
			$host   = $base_parts['host'];
			$port   = isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';

			if ( 0 === strpos( $url, '//' ) ) {
				return $scheme . ':' . $url;
			}

			$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
			$path      = '';

			if ( 0 === strpos( $url, '/' ) ) {
				$path = $url;
			} elseif ( 0 === strpos( $url, '?' ) ) {
				$path = $base_path . $url;
			} else {
				$base_dir = preg_replace( '#/[^/]*$#', '/', $base_path );
				$path     = $base_dir . $url;
			}

			return $scheme . '://' . $host . $port . self::normalize_path_segments( $path );
		}

		/**
		 * Normalize dot segments in a URL path.
		 */
		private static function normalize_path_segments( string $path ): string {
			$query = '';

			if ( false !== strpos( $path, '?' ) ) {
				list( $path, $query ) = explode( '?', $path, 2 );
				$query               = '?' . $query;
			}

			$has_trailing_slash = strlen( $path ) > 1 && '/' === substr( $path, -1 );

			$segments = explode( '/', $path );
			$output   = array();

			foreach ( $segments as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}

				if ( '..' === $segment ) {
					array_pop( $output );
					continue;
				}

				$output[] = $segment;
			}

			$normalized = '/' . implode( '/', $output );
			if ( $has_trailing_slash && '/' !== $normalized ) {
				$normalized .= '/';
			}

			return $normalized . $query;
		}

		/**
		 * Whether href should be ignored instead of audited.
		 */
		private static function should_ignore_href( string $href ): bool {
			$href = trim( $href );

			if ( '' === $href || 0 === strpos( $href, '#' ) ) {
				return true;
			}

			return (bool) preg_match( '#^(mailto|tel|sms|javascript|data|blob|file):#i', $href );
		}

		/**
		 * Validate a URL before requesting it.
		 */
		private static function is_valid_http_url( string $url ): bool {
			if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20]/', $url ) ) {
				return false;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return false;
			}

			if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return false;
			}

			return '' !== esc_url_raw( $url, array( 'http', 'https' ) );
		}

		/**
		 * Normalize hostname for comparisons.
		 */
		private static function normalize_host( string $host ): string {
			$host = strtolower( trim( $host ) );
			$host = trim( $host, " \t\n\r\0\x0B." );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
		}

		/**
		 * Compare normalized hostnames.
		 */
		private static function hosts_match( string $left, string $right ): bool {
			return '' !== $left && '' !== $right && self::normalize_host( $left ) === self::normalize_host( $right );
		}

		/**
		 * Detect common staging or development hostnames conservatively.
		 */
		private static function is_staging_or_dev_host( string $host ): bool {
			if ( '' === $host ) {
				return false;
			}

			if ( in_array( $host, array( 'localhost' ), true ) ) {
				return true;
			}

			if ( preg_match( '/\.(local|localhost|test)$/i', $host ) ) {
				return true;
			}

			if ( preg_match( '/(^|[.\-])(staging|stage|dev|development|test|testing|uat|sandbox|preview|local)([.\-]|$)/i', $host ) ) {
				return true;
			}

			return (bool) preg_match( '/(pantheonsite\.io|wpenginepowered\.com|flywheelsites\.com|cloudwaysapps\.com|myftpupload\.com)$/i', $host );
		}

		/**
		 * Normalize anchor text for display/export.
		 */
		private static function normalize_anchor_text( string $text ): string {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

			if ( '' === $text ) {
				return __( '(empty anchor)', 'indexlane-redirect-internal-link-auditor' );
			}

			return $text;
		}

		/**
		 * Post type display label.
		 */
		private static function get_post_type_label( string $post_type ): string {
			$post_type_object = get_post_type_object( $post_type );

			return $post_type_object && isset( $post_type_object->labels->singular_name )
				? (string) $post_type_object->labels->singular_name
				: $post_type;
		}

		/**
		 * Server request method wrapper for safer direct access.
		 */
		private static function server_request_method(): string {
			return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		}
	}

	IndexLane_Redirect_Internal_Link_Auditor::init();
}
