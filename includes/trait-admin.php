<?php
/**
 * Admin behavior for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Admin {
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
			plugins_url( 'assets/admin.css', self::PLUGIN_FILE ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'indexlane-rila-admin',
			plugins_url( 'assets/admin.js', self::PLUGIN_FILE ),
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
					'confirmVerification' => __( 'Start the verification scan? The current temporary completed scan will be replaced. Save or export it first if you need to keep it.', 'indexlane-redirect-internal-link-auditor' ),
				),
			)
		);
	}

	/**
	 * Export completed evidence before admin page output starts.
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
		if ( ! in_array( $action, array( 'export_details', 'export_impact', 'export_coverage', 'export_comparison', 'export_scan_json', 'export_baseline_json' ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( 'export_baseline_json' === $action ) {
			$baseline = self::get_saved_baseline();
			if ( null === $baseline ) {
				wp_die( esc_html__( 'No valid saved baseline is available to export.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			self::send_baseline_json( $baseline );
		}

		$session_id = isset( $_POST['session_id'] ) && is_scalar( $_POST['session_id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) )
			: '';
		$session    = self::get_scan_session();

		if ( null === $session || ! hash_equals( (string) $session['id'], $session_id ) || 'complete' !== $session['status'] ) {
			wp_die( esc_html__( 'The completed scan is unavailable or has expired. Complete the scan again before exporting.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( 'export_scan_json' === $action ) {
			$baseline = self::build_baseline_from_session( $session );
			if ( is_wp_error( $baseline ) ) {
				wp_die( esc_html( $baseline->get_error_message() ) );
			}

			self::send_baseline_json( $baseline );
		}

		if ( 'export_comparison' === $action ) {
			$comparison = self::get_session_comparison( $session );
			if ( is_wp_error( $comparison ) ) {
				wp_die( esc_html( $comparison->get_error_message() ) );
			}

			self::send_comparison_csv( $comparison );
		}

		if ( 'export_coverage' === $action ) {
			$report_type = 'coverage';
		} elseif ( 'export_impact' === $action ) {
			$report_type = 'impact';
		} else {
			$report_type = 'details';
		}

		self::send_csv( $session['results'], $report_type, isset( $session['content_items'] ) && is_array( $session['content_items'] ) ? $session['content_items'] : array() );
	}

	/**
	 * Save, import, or delete the current administrator's baseline.
	 */
	public static function maybe_handle_baseline_action(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || 'POST' !== self::server_request_method() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}

		$action = isset( $_POST['indexlane_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['indexlane_rila_action'] ) ) : '';
		if ( ! in_array( $action, array( 'save_baseline', 'import_baseline', 'delete_baseline' ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
		$existing_baseline = self::get_saved_baseline();
		$session           = self::get_scan_session();
		$active_session    = is_array( $session ) && in_array( $session['status'], array( 'running', 'paused', 'limit_reached' ), true );

		if ( in_array( $action, array( 'import_baseline', 'delete_baseline' ), true ) && $active_session ) {
			self::redirect_after_baseline_action( 'active_scan' );
		}

		if ( 'save_baseline' === $action ) {
			$session_id = isset( $_POST['session_id'] ) && is_scalar( $_POST['session_id'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) )
				: '';
			if ( ! is_array( $session ) || 'complete' !== $session['status'] || ! hash_equals( (string) $session['id'], $session_id ) ) {
				self::redirect_after_baseline_action( 'missing_scan' );
			}

			if ( is_array( $existing_baseline ) && (string) $existing_baseline['scan_id'] !== (string) $session['id'] && ! self::posted_confirmation( 'confirm_replace' ) ) {
				self::redirect_after_baseline_action( 'confirm_replace' );
			}

			$baseline = self::build_baseline_from_session( $session );
			if ( is_wp_error( $baseline ) ) {
				self::redirect_after_baseline_action( self::baseline_error_notice_code( $baseline ) );
			}

			if ( ! self::save_baseline( $baseline ) ) {
				self::redirect_after_baseline_action( 'storage_error' );
			}

			self::redirect_after_baseline_action( 'saved' );
		}

		if ( 'import_baseline' === $action ) {
			if ( is_array( $existing_baseline ) && ! self::posted_confirmation( 'confirm_replace' ) ) {
				self::redirect_after_baseline_action( 'confirm_replace' );
			}

			$baseline = self::read_uploaded_baseline();
			if ( is_wp_error( $baseline ) ) {
				self::redirect_after_baseline_action( self::baseline_error_notice_code( $baseline ) );
			}

			if ( ! self::save_baseline( $baseline ) ) {
				self::redirect_after_baseline_action( 'storage_error' );
			}

			self::redirect_after_baseline_action( 'imported' );
		}

		if ( ! self::posted_confirmation( 'confirm_delete' ) ) {
			self::redirect_after_baseline_action( 'confirm_delete' );
		}

		delete_user_option( get_current_user_id(), self::BASELINE_USER_OPTION, false );
		self::redirect_after_baseline_action( 'deleted' );
	}

	/**
	 * Start a per-user scan session through authenticated AJAX.
	 */
	public static function ajax_start_scan(): void {
		if ( ! self::verify_ajax_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The authenticated AJAX nonce is verified immediately above.
		$post_data       = wp_unslash( $_POST );
		$post_data       = is_array( $post_data ) ? $post_data : array();
		$is_verification = isset( $post_data['verification'] ) && is_scalar( $post_data['verification'] ) && '1' === (string) $post_data['verification'];
		$baseline_id     = '';
		$fingerprint     = '';

		if ( $is_verification ) {
			$baseline = self::get_saved_baseline();
			if ( null === $baseline ) {
				wp_send_json_error( array( 'message' => __( 'The saved baseline is unavailable or invalid. Save or import a valid baseline before verification.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
				return;
			}

			$settings = self::verification_settings_from_baseline( $baseline );
			if ( is_wp_error( $settings ) ) {
				wp_send_json_error( array( 'message' => $settings->get_error_message() ), 409 );
				return;
			}

			$baseline_id = (string) $baseline['baseline_id'];
			$fingerprint = self::baseline_fingerprint( $baseline );
		} else {
			$settings = self::get_request_settings( $post_data );
		}

		if ( empty( $settings['post_types'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one public content type.', 'indexlane-redirect-internal-link-auditor' ) ), 400 );
			return;
		}

		$existing = self::get_scan_session();
		if ( is_array( $existing ) && in_array( $existing['status'], array( 'running', 'paused', 'limit_reached' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'A scan is already in progress. Continue or cancel it before starting another.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
			return;
		}

		$session = self::create_scan_session( $settings, $is_verification ? 'verification' : 'standard', $baseline_id, $fingerprint );
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
				$session['request_allowance_extensions'] = isset( $session['request_allowance_extensions'] )
					? (int) $session['request_allowance_extensions'] + 1
					: 1;
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

		$session   = self::get_scan_session();
		$baseline  = self::get_saved_baseline();
		$settings  = is_array( $session ) && isset( $session['settings'] ) && is_array( $session['settings'] )
			? $session['settings']
			: self::default_settings();

		?>
		<div class="wrap indexlane-rila-wrap">
			<h1><?php esc_html_e( 'Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ); ?></h1>

			<p>
				<?php esc_html_e( 'Find broken, redirected, old-domain, and staging-domain links, review internal-link coverage, and verify fixes against an explicitly saved baseline.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<?php self::render_baseline_notice(); ?>

			<?php self::render_baseline_panel( $baseline, $session ); ?>

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
	 * Render a persisted result for a baseline management action.
	 */
	private static function render_baseline_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This displays a fixed message after a nonce-protected redirect.
		$code = isset( $_GET['baseline_notice'] ) ? sanitize_key( wp_unslash( $_GET['baseline_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'saved'           => array( 'success', __( 'The completed scan was saved as this administrator’s baseline.', 'indexlane-redirect-internal-link-auditor' ) ),
			'imported'        => array( 'success', __( 'The validated JSON evidence was imported as this administrator’s baseline.', 'indexlane-redirect-internal-link-auditor' ) ),
			'deleted'         => array( 'success', __( 'The saved baseline was deleted. Temporary scan evidence was not changed.', 'indexlane-redirect-internal-link-auditor' ) ),
			'active_scan'     => array( 'error', __( 'Pause or finish the active scan before replacing or deleting its baseline.', 'indexlane-redirect-internal-link-auditor' ) ),
			'missing_scan'    => array( 'error', __( 'The completed scan is unavailable or has expired. Complete another scan before saving a baseline.', 'indexlane-redirect-internal-link-auditor' ) ),
			'confirm_replace' => array( 'error', __( 'Confirm that the existing baseline may be replaced.', 'indexlane-redirect-internal-link-auditor' ) ),
			'confirm_delete'  => array( 'error', __( 'Confirm that the saved baseline may be permanently deleted.', 'indexlane-redirect-internal-link-auditor' ) ),
			'missing_file'    => array( 'error', __( 'Choose a JSON baseline file to import.', 'indexlane-redirect-internal-link-auditor' ) ),
			'file_too_large'  => array( 'error', __( 'The baseline file exceeds the 20 MiB import limit.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_json'    => array( 'error', __( 'The selected file is not valid baseline JSON.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_schema'  => array( 'error', __( 'The selected file does not match the supported baseline evidence schema.', 'indexlane-redirect-internal-link-auditor' ) ),
			'wrong_site'      => array( 'error', __( 'The baseline belongs to a different site URL and was not imported.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_evidence' => array( 'error', __( 'The baseline contains invalid or inconsistent evidence and was not imported.', 'indexlane-redirect-internal-link-auditor' ) ),
			'storage_error'   => array( 'error', __( 'WordPress could not save the baseline. Check database storage and try again.', 'indexlane-redirect-internal-link-auditor' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$type = 'success' === $messages[ $code ][0] ? 'success' : 'error';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> inline"><p><?php echo esc_html( $messages[ $code ][1] ); ?></p></div>
		<?php
	}

	/**
	 * Render baseline state, portable evidence controls, and verification action.
	 *
	 * @param array<string,mixed>|null $baseline Saved baseline.
	 * @param array<string,mixed>|null $session  Current scan session.
	 */
	private static function render_baseline_panel( ?array $baseline, ?array $session ): void {
		$active_session = is_array( $session ) && in_array( $session['status'], array( 'running', 'paused', 'limit_reached' ), true );
		?>
		<section id="indexlane-rila-baseline" class="indexlane-rila-baseline" aria-labelledby="indexlane-rila-baseline-heading">
			<div class="indexlane-rila-baseline-heading">
				<div>
					<h2 id="indexlane-rila-baseline-heading"><?php esc_html_e( 'Baseline and fix verification', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
					<p><?php esc_html_e( 'Keep one opt-in evidence baseline, rerun its exact scope, and compare destination behavior without changing content.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				</div>
				<span class="indexlane-rila-baseline-state is-<?php echo is_array( $baseline ) ? 'ready' : 'empty'; ?>">
					<?php echo is_array( $baseline ) ? esc_html__( 'Baseline ready', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'No baseline saved', 'indexlane-redirect-internal-link-auditor' ); ?>
				</span>
			</div>

			<?php if ( is_array( $baseline ) ) : ?>
				<dl class="indexlane-rila-baseline-metadata">
					<div><dt><?php esc_html_e( 'UTC scan time', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['scan_utc'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Site', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['site_url'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Scope', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( self::baseline_scope_label( $baseline ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Completion', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( self::baseline_completion_label( $baseline ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Evidence', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( self::baseline_evidence_label( $baseline ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Evidence schema', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['schema_version'] ); ?></dd></div>
				</dl>

				<div class="indexlane-rila-baseline-actions">
					<form id="indexlane-rila-verification-form" method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>">
						<input type="hidden" name="verification" value="1" />
						<button id="indexlane-rila-start-verification" type="submit" class="button button-primary" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Run verification scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<button type="submit" name="indexlane_rila_action" value="export_baseline_json" class="button"><?php esc_html_e( 'Export baseline JSON', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</form>
				</div>
				<p class="description"><?php esc_html_e( 'Verification uses the saved post types, content scope, old domains, timeout, and redirect limit. Starting it replaces only the current temporary scan session.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Complete a scan and explicitly save it here, or import compatible JSON evidence from this same site.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php endif; ?>

			<details class="indexlane-rila-baseline-secondary">
				<summary><?php esc_html_e( 'Import or delete baseline', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-import-baseline">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<label for="indexlane-rila-baseline-file"><?php esc_html_e( 'Baseline JSON file', 'indexlane-redirect-internal-link-auditor' ); ?></label>
					<input id="indexlane-rila-baseline-file" type="file" name="baseline_file" accept=".json,application/json" required <?php disabled( $active_session ); ?> />
					<?php if ( is_array( $baseline ) ) : ?>
						<label class="indexlane-rila-confirmation"><input type="checkbox" name="confirm_replace" value="1" required <?php disabled( $active_session ); ?> /> <?php esc_html_e( 'Replace the current saved baseline after the file passes validation.', 'indexlane-redirect-internal-link-auditor' ); ?></label>
					<?php endif; ?>
					<button type="submit" name="indexlane_rila_action" value="import_baseline" class="button" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Import baseline', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					<p class="description"><?php esc_html_e( 'Imports are limited to 20 MiB and require the exact supported schema and current site URL.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				</form>

				<?php if ( is_array( $baseline ) ) : ?>
					<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-delete-baseline">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<label class="indexlane-rila-confirmation"><input type="checkbox" name="confirm_delete" value="1" required <?php disabled( $active_session ); ?> /> <?php esc_html_e( 'Permanently delete this saved baseline. The current temporary scan is not deleted.', 'indexlane-redirect-internal-link-auditor' ); ?></label>
						<button type="submit" name="indexlane_rila_action" value="delete_baseline" class="button button-link-delete" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Delete saved baseline', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</form>
				<?php endif; ?>
			</details>
		</section>
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
					<h2><?php echo $has_session && isset( $summary['scan_mode'] ) && 'verification' === $summary['scan_mode'] ? esc_html__( 'Verification scan', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Scan session', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
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
		$stats          = $scan['stats'];
		$results        = $scan['results'];
		$content_items  = isset( $scan['content_items'] ) && is_array( $scan['content_items'] ) ? $scan['content_items'] : array();
		$coverage_rows  = self::build_content_link_coverage( $content_items, $results );
		$coverage_filter = self::current_coverage_filter();
		$coverage_target = self::current_coverage_target();
		$baseline        = self::get_saved_baseline();
		$is_verification = isset( $scan['scan_mode'] ) && 'verification' === $scan['scan_mode'];
		$comparison      = $is_verification ? self::get_session_comparison( $scan ) : null;
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
				<button type="submit" name="indexlane_rila_action" value="export_coverage" class="button"><?php esc_html_e( 'Export target coverage as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<button type="submit" name="indexlane_rila_action" value="export_details" class="button"><?php esc_html_e( 'Export detailed rows as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<button type="submit" name="indexlane_rila_action" value="export_impact" class="button"><?php esc_html_e( 'Export destination impact as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<?php if ( is_array( $comparison ) ) : ?>
					<button type="submit" name="indexlane_rila_action" value="export_comparison" class="button"><?php esc_html_e( 'Export comparison as CSV', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<?php endif; ?>
				<button type="submit" name="indexlane_rila_action" value="export_scan_json" class="button"><?php esc_html_e( 'Export this scan as JSON', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<span class="description"><?php esc_html_e( 'All exports use this exact completed session without additional HTTP requests. The temporary session expires after 24 hours of inactivity.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
			</form>

			<?php if ( is_array( $baseline ) && (string) $baseline['scan_id'] === (string) $scan['id'] ) : ?>
				<p class="indexlane-rila-baseline-current"><?php esc_html_e( 'This completed scan is the saved baseline.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-save-baseline">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
					<?php if ( is_array( $baseline ) ) : ?>
						<label>
							<input type="checkbox" name="confirm_replace" value="1" required />
							<?php esc_html_e( 'Replace the current saved baseline with this completed scan.', 'indexlane-redirect-internal-link-auditor' ); ?>
						</label>
					<?php endif; ?>
					<button type="submit" name="indexlane_rila_action" value="save_baseline" class="button button-primary">
						<?php echo is_array( $baseline ) ? esc_html__( 'Replace saved baseline', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Save this scan as baseline', 'indexlane-redirect-internal-link-auditor' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'One baseline is stored for this administrator. Saving is always explicit and never changes site content.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
				</form>
			<?php endif; ?>

			<?php if ( $is_verification ) : ?>
				<?php if ( is_wp_error( $comparison ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $comparison->get_error_message() ); ?></p></div>
				<?php else : ?>
					<?php self::render_comparison( $comparison ); ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php self::render_content_link_coverage( $coverage_rows, $coverage_filter, $coverage_target ); ?>

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
	 * Render baseline-versus-verification destination evidence.
	 *
	 * @param array<string,mixed> $comparison Comparison data.
	 */
	private static function render_comparison( array $comparison ): void {
		$summary = $comparison['summary'];
		?>
		<section id="indexlane-rila-comparison" class="indexlane-rila-comparison" aria-labelledby="indexlane-rila-comparison-heading">
			<h2 id="indexlane-rila-comparison-heading"><?php esc_html_e( 'Verification comparison', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Destinations are matched by normalized linked URL. Every value below is derived from the saved baseline and this completed verification scan.', 'indexlane-redirect-internal-link-auditor' ); ?></p>

			<div class="indexlane-rila-comparison-summary">
				<div class="is-new"><strong><?php echo esc_html( (string) $summary['new'] ); ?></strong><span><?php esc_html_e( 'New issues', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-changed"><strong><?php echo esc_html( (string) $summary['changed'] ); ?></strong><span><?php esc_html_e( 'Worsened or changed', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-resolved"><strong><?php echo esc_html( (string) $summary['resolved'] ); ?></strong><span><?php esc_html_e( 'Resolved', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-still"><strong><?php echo esc_html( (string) $summary['still'] ); ?></strong><span><?php esc_html_e( 'Still present', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
			</div>

			<?php if ( empty( $comparison['rows'] ) ) : ?>
				<p><?php esc_html_e( 'No baseline issues remain and no new issues were detected in this verification scan.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			</section>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Verification comparison', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-comparison-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Destination', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Category', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Change', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'HTTP status chain', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Redirect count', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Final URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result severity', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Occurrences', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Affected content items', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $comparison['rows'] as $row ) : ?>
							<tr>
								<td>
									<?php if ( '' !== esc_url( $row['destination_url'] ) ) : ?>
										<a href="<?php echo esc_url( $row['destination_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['destination_url'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['destination_url'] ); ?>
									<?php endif; ?>
								</td>
								<td><span class="indexlane-rila-comparison-category is-<?php echo esc_attr( $row['category'] ); ?>"><?php echo esc_html( $row['category_label'] ); ?></span></td>
								<td><?php echo esc_html( $row['direction_label'] ); ?><?php if ( ! empty( $row['changed_labels'] ) ) : ?><span class="indexlane-rila-comparison-fields"><?php echo esc_html( implode( ', ', $row['changed_labels'] ) ); ?></span><?php endif; ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'http_status_chain' ); ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'redirect_count' ); ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'final_url' ); ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'result_severity' ); ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'occurrence_count' ); ?></td>
								<td><?php self::render_comparison_value( $row['old'], $row['new'], 'affected_source_count' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Render one old/new evidence pair with explicit labels.
	 *
	 * @param array<string,mixed>|null $old   Baseline evidence.
	 * @param array<string,mixed>|null $new   Verification evidence.
	 * @param string                   $field Evidence field.
	 */
	private static function render_comparison_value( ?array $old, ?array $new, string $field ): void {
		$missing = __( 'Not present', 'indexlane-redirect-internal-link-auditor' );
		$empty   = __( 'None', 'indexlane-redirect-internal-link-auditor' );
		$old_value = is_array( $old ) && array_key_exists( $field, $old ) ? (string) $old[ $field ] : $missing;
		$new_value = is_array( $new ) && array_key_exists( $field, $new ) ? (string) $new[ $field ] : $missing;
		$old_value = '' === $old_value ? $empty : $old_value;
		$new_value = '' === $new_value ? $empty : $new_value;
		?>
		<span class="indexlane-rila-comparison-old"><span><?php esc_html_e( 'Baseline', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $old_value ); ?></span>
		<span class="indexlane-rila-comparison-new"><span><?php esc_html_e( 'Verification', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $new_value ); ?></span>
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
	 * Render the destination-oriented coverage report for the scanned corpus.
	 *
	 * @param array<int,array<string,mixed>> $coverage_rows Coverage rows.
	 * @param string                         $filter        Coverage filter.
	 * @param int                            $target_id     Selected target detail ID.
	 */
	private static function render_content_link_coverage( array $coverage_rows, string $filter, int $target_id ): void {
		$filtered_rows = array_values(
			array_filter(
				$coverage_rows,
				static function ( array $row ) use ( $filter ): bool {
					return 'attention' !== $filter || (int) $row['linking_source_count'] <= 1;
				}
			)
		);
		$target_detail = null;
		foreach ( $coverage_rows as $coverage_row ) {
			if ( (int) $coverage_row['target_id'] === $target_id ) {
				$target_detail = $coverage_row;
				break;
			}
		}
		?>
		<section class="indexlane-rila-coverage" aria-labelledby="indexlane-rila-coverage-heading">
			<h2 id="indexlane-rila-coverage-heading"><?php esc_html_e( 'Content link coverage', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'One row per scanned published content item, derived from the saved occurrence evidence. Redirects to a published WordPress URL count toward the final item while retaining redirect evidence.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>
			<p class="indexlane-rila-evidence-scope">
				<?php esc_html_e( 'Coverage includes links found in scanned post content. It does not include menus, templates, widgets, shortcode output, or rendered page-builder content.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<?php if ( is_array( $target_detail ) ) : ?>
				<?php self::render_content_link_coverage_detail( $target_detail, $filter ); ?>
			<?php endif; ?>

			<?php if ( empty( $coverage_rows ) ) : ?>
				<p><?php esc_html_e( 'No published content items were included in this completed scan.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			</section>
				<?php return; ?>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="indexlane-rila-coverage-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="indexlane-rila-coverage-filter">
					<?php esc_html_e( 'Show', 'indexlane-redirect-internal-link-auditor' ); ?>
				</label>
				<select id="indexlane-rila-coverage-filter" name="coverage_filter">
					<option value="all" <?php selected( 'all', $filter ); ?>><?php esc_html_e( 'All scanned content', 'indexlane-redirect-internal-link-auditor' ); ?></option>
					<option value="attention" <?php selected( 'attention', $filter ); ?>><?php esc_html_e( 'Zero or one linking source', 'indexlane-redirect-internal-link-auditor' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply filter', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: visible content item count, 2: total scanned content item count */
							__( 'Showing %1$d of %2$d content items.', 'indexlane-redirect-internal-link-auditor' ),
							count( $filtered_rows ),
							count( $coverage_rows )
						)
					);
					?>
				</span>
			</form>

			<?php if ( empty( $filtered_rows ) ) : ?>
				<p><?php esc_html_e( 'No content items match this coverage filter.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			</section>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Content link coverage', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-coverage-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Target content', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Incoming occurrences', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Linking content items', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outgoing occurrences', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Internal destinations', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Anchor-text variants', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Self-links', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Direct incoming', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Redirected incoming', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $filtered_rows as $row ) : ?>
							<tr>
								<td class="indexlane-rila-target-cell">
									<strong>
										<?php if ( '' !== $row['target_edit_url'] ) : ?>
											<a href="<?php echo esc_url( $row['target_edit_url'] ); ?>"><?php echo esc_html( $row['target_title'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['target_title'] ); ?>
										<?php endif; ?>
									</strong>
									<?php if ( '' !== $row['target_type'] ) : ?>
										<span class="indexlane-rila-target-type"><?php echo esc_html( $row['target_type'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $row['target_url'] ) : ?>
										<a class="indexlane-rila-target-url" href="<?php echo esc_url( $row['target_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['target_url'] ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $row['incoming_occurrences'] ); ?></td>
								<td><?php echo esc_html( (string) $row['linking_source_count'] ); ?></td>
								<td><?php echo esc_html( (string) $row['outgoing_internal_occurrences'] ); ?></td>
								<td><?php echo esc_html( (string) $row['distinct_internal_destinations'] ); ?></td>
								<td><?php self::render_anchor_text_variants( $row['anchor_text_variants'] ); ?></td>
								<td><?php echo esc_html( (string) $row['self_link_count'] ); ?></td>
								<td><?php echo esc_html( (string) $row['direct_incoming'] ); ?></td>
								<td><?php echo esc_html( (string) $row['redirected_incoming'] ); ?></td>
								<td><span class="indexlane-rila-coverage-status is-<?php echo esc_attr( $row['status_code'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( self::coverage_report_url( $filter, (int) $row['target_id'] ) . '#indexlane-rila-target-detail' ); ?>">
										<?php esc_html_e( 'View sources and anchors', 'indexlane-redirect-internal-link-auditor' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Render all saved incoming-link evidence for one selected target.
	 *
	 * @param array<string,mixed> $target Coverage target row.
	 * @param string              $filter Active coverage filter.
	 */
	private static function render_content_link_coverage_detail( array $target, string $filter ): void {
		?>
		<section id="indexlane-rila-target-detail" class="indexlane-rila-target-detail" aria-labelledby="indexlane-rila-target-detail-heading" tabindex="-1">
			<div class="indexlane-rila-target-detail-heading">
				<div>
					<h3 id="indexlane-rila-target-detail-heading">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: target content title */
								__( 'Incoming link details: %s', 'indexlane-redirect-internal-link-auditor' ),
								$target['target_title']
							)
						);
						?>
					</h3>
					<?php if ( '' !== $target['target_url'] ) : ?>
						<a href="<?php echo esc_url( $target['target_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $target['target_url'] ); ?></a>
					<?php endif; ?>
				</div>
				<a class="button" href="<?php echo esc_url( self::coverage_report_url( $filter ) . '#indexlane-rila-coverage-heading' ); ?>"><?php esc_html_e( 'Close target details', 'indexlane-redirect-internal-link-auditor' ); ?></a>
			</div>

			<p><span class="indexlane-rila-coverage-status is-<?php echo esc_attr( $target['status_code'] ); ?>"><?php echo esc_html( $target['status'] ); ?></span></p>

			<?php if ( empty( $target['incoming_details'] ) ) : ?>
				<p><?php esc_html_e( 'No incoming links detected in scanned content.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				<p class="description"><?php esc_html_e( 'This is limited to links found in the scanned content fields; other sitewide link sources may exist.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
		</section>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Incoming source and anchor details', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-target-detail-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Source content', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Anchor text', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connection', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'HTTP status evidence', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Final URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $target['incoming_details'] as $detail ) : ?>
							<tr>
								<td>
									<?php if ( '' !== $detail['source_edit_url'] ) : ?>
										<a href="<?php echo esc_url( $detail['source_edit_url'] ); ?>"><?php echo esc_html( $detail['source_title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $detail['source_title'] ); ?>
									<?php endif; ?>
									<?php if ( '' !== $detail['source_url'] ) : ?>
										<a class="indexlane-rila-target-url" href="<?php echo esc_url( $detail['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['source_url'] ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $detail['anchor_text'] ); ?></td>
								<td>
									<?php echo esc_html( self::coverage_link_kind_label( $detail['link_kind_code'] ) ); ?>
									<?php if ( ! empty( $detail['is_self_link'] ) ) : ?>
										<span class="indexlane-rila-self-link"><?php esc_html_e( 'Self-link', 'indexlane-redirect-internal-link-auditor' ); ?></span>
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $detail['linked_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['linked_url'] ); ?></a></td>
								<td><?php echo '' !== $detail['http_status'] ? esc_html( $detail['http_status'] ) : '&mdash;'; ?></td>
								<td>
									<?php if ( 'redirected' === $detail['link_kind_code'] && '' !== $detail['final_url'] ) : ?>
										<a href="<?php echo esc_url( $detail['final_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['final_url'] ); ?></a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the distinct anchor-text evidence stored for a target row.
	 *
	 * @param array<int,string> $variants Anchor variants.
	 */
	private static function render_anchor_text_variants( array $variants ): void {
		if ( empty( $variants ) ) {
			echo '&mdash;';
			return;
		}

		if ( 1 === count( $variants ) ) {
			echo esc_html( $variants[0] );
			return;
		}
		?>
		<details class="indexlane-rila-anchor-variants">
			<summary>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of distinct anchor-text variants */
						__( '%d anchor variants', 'indexlane-redirect-internal-link-auditor' ),
						count( $variants )
					)
				);
				?>
			</summary>
			<ul>
				<?php foreach ( $variants as $variant ) : ?>
					<li><?php echo esc_html( $variant ); ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Read the active read-only coverage filter.
	 */
	private static function current_coverage_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only filters a saved read-only report.
		$filter = isset( $_GET['coverage_filter'] ) ? sanitize_key( wp_unslash( $_GET['coverage_filter'] ) ) : '';

		return 'attention' === $filter ? 'attention' : 'all';
	}

	/**
	 * Read the selected read-only target detail ID.
	 */
	private static function current_coverage_target(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects detail from a saved read-only report.
		return isset( $_GET['coverage_target'] ) ? absint( wp_unslash( $_GET['coverage_target'] ) ) : 0;
	}

	/**
	 * Build a URL for the coverage filter and optional target detail.
	 */
	private static function coverage_report_url( string $filter, int $target_id = 0 ): string {
		$args = array();
		if ( 'attention' === $filter ) {
			$args['coverage_filter'] = 'attention';
		}
		if ( $target_id > 0 ) {
			$args['coverage_target'] = $target_id;
		}

		return empty( $args ) ? self::admin_page_url() : add_query_arg( $args, self::admin_page_url() );
	}

	/**
	 * Translate the stable direct-versus-redirected evidence code.
	 */
	private static function coverage_link_kind_label( string $code ): string {
		return 'redirected' === $code
			? __( 'Redirected', 'indexlane-redirect-internal-link-auditor' )
			: __( 'Direct', 'indexlane-redirect-internal-link-auditor' );
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
}
