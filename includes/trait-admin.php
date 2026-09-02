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
					'confirmCancel' => __( 'Cancel this scan and delete its saved progress? Your site content will not change.', 'indexlane-redirect-internal-link-auditor' ),
					'confirmVerification' => __( 'Start checking your fixes? The current unsaved scan results will be deleted. Save or download them first if you need them.', 'indexlane-redirect-internal-link-auditor' ),
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
				wp_die( esc_html__( 'No valid saved scan is available to download.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			self::send_baseline_json( $baseline );
		}

		$session_id = isset( $_POST['session_id'] ) && is_scalar( $_POST['session_id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) )
			: '';
		$session    = self::get_scan_session();

		if ( null === $session || ! hash_equals( (string) $session['id'], $session_id ) || 'complete' !== $session['status'] ) {
			wp_die( esc_html__( 'These scan results are unavailable or have expired. Complete the scan again before downloading.', 'indexlane-redirect-internal-link-auditor' ) );
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
				wp_send_json_error( array( 'message' => __( 'The saved scan is unavailable or invalid. Save or upload a valid scan before checking fixes.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
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

		if ( empty( $settings['source_types'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one place to check for links.', 'indexlane-redirect-internal-link-auditor' ) ), 400 );
			return;
		}

		if ( in_array( 'content', $settings['source_types'], true ) && empty( $settings['post_types'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one public content type.', 'indexlane-redirect-internal-link-auditor' ) ), 400 );
			return;
		}

		$existing = self::get_scan_session();
		if ( is_array( $existing ) && in_array( $existing['status'], array( 'running', 'paused', 'limit_reached' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'A scan is already in progress. Continue or cancel it before starting another.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
			return;
		}

		$session = self::create_scan_session( $settings, $is_verification ? 'verification' : 'standard', $baseline_id, $fingerprint );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 400 );
			return;
		}
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
					wp_send_json_error( array( 'message' => __( 'The request limit can be increased only after the current limit is reached.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
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
						'message' => __( 'The scan was canceled and its saved progress was deleted. Site content was not changed.', 'indexlane-redirect-internal-link-auditor' ),
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
			wp_send_json_error( array( 'message' => __( 'A newer scan has replaced this one. Reload the page to see it.', 'indexlane-redirect-internal-link-auditor' ) ), 409 );
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

		$session      = self::get_scan_session();
		$baseline     = self::get_saved_baseline();
		$has_session  = is_array( $session );
		$settings     = $has_session && isset( $session['settings'] ) && is_array( $session['settings'] )
			? $session['settings']
			: self::default_settings();

		?>
		<div class="wrap indexlane-rila-wrap">
			<h1><?php esc_html_e( 'Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ); ?></h1>

			<p>
				<?php esc_html_e( 'Find broken, redirected, old-site, and staging links. See where links appear and check whether your fixes worked.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<?php self::render_baseline_notice(); ?>

			<?php if ( ! $has_session && is_array( $baseline ) ) : ?>
				<?php self::render_baseline_panel( $baseline, $session ); ?>
			<?php endif; ?>

			<?php self::render_session_panel( $has_session ? self::build_session_summary( $session ) : null ); ?>

			<?php if ( $has_session && 'complete' === $session['status'] ) : ?>
				<?php self::render_results( $session ); ?>
			<?php endif; ?>

			<form id="indexlane-rila-scan-form" method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-form" <?php echo $has_session ? 'hidden' : ''; ?>>
				<input type="hidden" name="source_types_present" value="1" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where to check for links', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset" aria-describedby="indexlane-rila-source-help">
									<legend class="screen-reader-text"><?php esc_html_e( 'Where to check for links', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
									<div class="indexlane-rila-source-options">
										<?php foreach ( self::get_available_source_types() as $source_type => $source_definition ) : ?>
											<label class="indexlane-rila-source-option">
												<input
													type="checkbox"
													name="source_types[]"
													value="<?php echo esc_attr( $source_type ); ?>"
													<?php checked( in_array( $source_type, $settings['source_types'], true ) ); ?>
												/>
												<span><strong><?php echo esc_html( $source_definition['label'] ); ?></strong><span class="description"><?php echo esc_html( $source_definition['description'] ); ?></span></span>
											</label>
										<?php endforeach; ?>
									</div>
									<p id="indexlane-rila-source-help" class="description"><?php esc_html_e( 'Menus, templates, and other site-wide areas are checked once where you edit them. The scan reads saved WordPress data only; it does not run shortcodes or inspect rendered pages.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr data-indexlane-rila-content-setting>
							<th scope="row"><?php esc_html_e( 'Content types', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset" aria-describedby="indexlane-rila-content-types-help">
									<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
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
									<p id="indexlane-rila-content-types-help" class="description"><?php esc_html_e( 'Only used when Content is selected above.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="indexlane-rila-old-domains"><?php esc_html_e( 'Old sites', 'indexlane-redirect-internal-link-auditor' ); ?></label>
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
									<?php esc_html_e( 'Optional. Add one previous site address per line. Matching links are listed without contacting the other site.', 'indexlane-redirect-internal-link-auditor' ); ?>
								</p>
							</td>
						</tr>
						<tr data-indexlane-rila-content-setting>
							<th scope="row"><?php esc_html_e( 'How much content', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset" aria-describedby="indexlane-rila-content-amount-help">
									<legend class="screen-reader-text"><?php esc_html_e( 'How much content', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
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
									<p id="indexlane-rila-content-amount-help" class="description"><?php esc_html_e( 'This setting applies only to Content. Every other selected area is checked in full. You can pause and continue later.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Advanced settings', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<details class="indexlane-rila-advanced-settings">
									<summary><?php esc_html_e( 'Request settings', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
									<div>
										<label for="indexlane-rila-timeout" class="indexlane-rila-inline-field">
											<?php esc_html_e( 'Request timeout in seconds', 'indexlane-redirect-internal-link-auditor' ); ?>
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
									</div>
								</details>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status checks', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<p><?php esc_html_e( 'Links on this site are checked. Links to old, staging, or development sites are listed without contacting those sites.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
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

			<?php if ( $has_session || ! is_array( $baseline ) ) : ?>
				<?php self::render_baseline_panel( $baseline, $session ); ?>
			<?php endif; ?>

			<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'This scan requires JavaScript. Enable JavaScript in your browser and reload the page.', 'indexlane-redirect-internal-link-auditor' ); ?></p></div></noscript>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'This scan reads only the saved WordPress areas selected above. It does not run shortcodes, scan custom fields or page-builder data, or crawl pages as visitors see them.', 'indexlane-redirect-internal-link-auditor' ); ?>
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
			'saved'           => array( 'success', __( 'The scan results were saved for comparison.', 'indexlane-redirect-internal-link-auditor' ) ),
			'imported'        => array( 'success', __( 'The saved-scan file was uploaded and is ready to use.', 'indexlane-redirect-internal-link-auditor' ) ),
			'deleted'         => array( 'success', __( 'The saved scan was deleted. Current scan results were not changed.', 'indexlane-redirect-internal-link-auditor' ) ),
			'active_scan'     => array( 'error', __( 'Pause or finish the current scan before replacing or deleting the saved scan.', 'indexlane-redirect-internal-link-auditor' ) ),
			'missing_scan'    => array( 'error', __( 'These scan results are unavailable or have expired. Complete another scan before saving results for comparison.', 'indexlane-redirect-internal-link-auditor' ) ),
			'confirm_replace' => array( 'error', __( 'Confirm that the current saved scan may be replaced.', 'indexlane-redirect-internal-link-auditor' ) ),
			'confirm_delete'  => array( 'error', __( 'Confirm that the saved scan may be permanently deleted.', 'indexlane-redirect-internal-link-auditor' ) ),
			'missing_file'    => array( 'error', __( 'Choose a saved-scan file to upload.', 'indexlane-redirect-internal-link-auditor' ) ),
			'file_too_large'  => array( 'error', __( 'The saved-scan file is larger than 20 MB.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_json'    => array( 'error', __( 'The selected file is not a valid saved-scan file.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_schema'  => array( 'error', __( 'The selected file was not created in a supported saved-scan format.', 'indexlane-redirect-internal-link-auditor' ) ),
			'wrong_site'      => array( 'error', __( 'This saved scan belongs to another site and was not uploaded.', 'indexlane-redirect-internal-link-auditor' ) ),
			'invalid_evidence' => array( 'error', __( 'The saved-scan file contains incomplete or inconsistent results and was not uploaded.', 'indexlane-redirect-internal-link-auditor' ) ),
			'storage_error'   => array( 'error', __( 'WordPress could not save these results. Check database storage and try again.', 'indexlane-redirect-internal-link-auditor' ) ),
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
		$issue_url_count = is_array( $baseline ) ? self::baseline_issue_url_count( $baseline ) : 0;
		?>
		<section id="indexlane-rila-baseline" class="indexlane-rila-baseline" aria-labelledby="indexlane-rila-baseline-heading">
			<div class="indexlane-rila-baseline-heading">
				<div>
					<h2 id="indexlane-rila-baseline-heading"><?php esc_html_e( 'Save results and check fixes', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
					<p><?php esc_html_e( 'Save a completed scan, fix the links, then run the same checks again to see what changed. The plugin never edits your content.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				</div>
				<span class="indexlane-rila-baseline-state is-<?php echo is_array( $baseline ) ? 'ready' : 'empty'; ?>">
					<?php echo is_array( $baseline ) ? esc_html__( 'Saved scan ready', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'No saved scan', 'indexlane-redirect-internal-link-auditor' ); ?>
				</span>
			</div>

			<?php if ( is_array( $baseline ) ) : ?>
				<dl class="indexlane-rila-baseline-metadata">
					<div><dt><?php esc_html_e( 'Scanned on', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( self::baseline_scan_date_label( $baseline ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Sources checked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['stats']['sources_processed'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Links checked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['stats']['links_audited'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'URLs needing attention', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $issue_url_count ); ?></dd></div>
				</dl>

				<div class="indexlane-rila-baseline-actions">
					<form id="indexlane-rila-verification-form" method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>">
						<input type="hidden" name="verification" value="1" />
						<button id="indexlane-rila-start-verification" type="submit" class="button button-primary" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Check fixes against saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<button type="submit" name="indexlane_rila_action" value="export_baseline_json" class="button"><?php esc_html_e( 'Download saved scan (.json)', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					</form>
				</div>
				<p class="description"><?php esc_html_e( 'The fix check repeats the saved source scope, content scope, and link settings. Starting it replaces the current unsaved scan results.', 'indexlane-redirect-internal-link-auditor' ); ?></p>

				<details class="indexlane-rila-technical-details">
					<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
					<dl class="indexlane-rila-technical-metadata">
						<div><dt><?php esc_html_e( 'Site URL', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['site_url'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Content scope', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( self::baseline_scope_label( $baseline ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Old sites', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo '' !== trim( (string) $baseline['settings']['old_domains'] ) ? esc_html( (string) $baseline['settings']['old_domains'] ) : esc_html__( 'None', 'indexlane-redirect-internal-link-auditor' ); ?></dd></div>
						<div><dt><?php esc_html_e( 'HTTP requests', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['completion']['http_requests'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Request limit', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['completion']['request_limit'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Request limit increases', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['completion']['request_allowance_extensions'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Request timeout in seconds', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['settings']['timeout'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Maximum redirects', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['settings']['max_redirects'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'File format version', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $baseline['schema_version'] ); ?></dd></div>
					</dl>
				</details>
			<?php else : ?>
				<p><?php esc_html_e( 'Complete a scan and save its results for comparison, or upload a saved-scan file from this site.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php endif; ?>

			<details class="indexlane-rila-baseline-secondary">
				<summary><?php esc_html_e( 'Upload or delete saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-import-baseline">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<label for="indexlane-rila-baseline-file"><?php esc_html_e( 'Saved-scan file (.json)', 'indexlane-redirect-internal-link-auditor' ); ?></label>
					<input id="indexlane-rila-baseline-file" type="file" name="baseline_file" accept=".json,application/json" required <?php disabled( $active_session ); ?> />
					<?php if ( is_array( $baseline ) ) : ?>
						<label class="indexlane-rila-confirmation"><input type="checkbox" name="confirm_replace" value="1" required <?php disabled( $active_session ); ?> /> <?php esc_html_e( 'Replace the current saved scan after the file passes validation.', 'indexlane-redirect-internal-link-auditor' ); ?></label>
					<?php endif; ?>
					<button type="submit" name="indexlane_rila_action" value="import_baseline" class="button" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Upload saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
					<p class="description"><?php esc_html_e( 'Upload a saved-scan file exported by this plugin for this site. Maximum file size: 20 MB.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				</form>

				<?php if ( is_array( $baseline ) ) : ?>
					<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-delete-baseline">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<label class="indexlane-rila-confirmation"><input type="checkbox" name="confirm_delete" value="1" required <?php disabled( $active_session ); ?> /> <?php esc_html_e( 'Permanently delete this saved scan. Current scan results will not be deleted.', 'indexlane-redirect-internal-link-auditor' ); ?></label>
						<button type="submit" name="indexlane_rila_action" value="delete_baseline" class="button button-link-delete" <?php disabled( $active_session ); ?>><?php esc_html_e( 'Delete saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></button>
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
		$processed   = min( $total, (int) $stats['sources_processed'] );
		$request_limit = $has_session ? max( 0, (int) $summary['request_limit'] ) : 0;
		$request_remaining = $has_session ? max( 0, (int) $summary['request_allowance_remaining'] ) : 0;
		?>
		<section id="indexlane-rila-session" class="indexlane-rila-session" <?php echo $has_session ? '' : 'hidden'; ?>>
			<div class="indexlane-rila-session-heading">
				<div>
					<h2><?php echo $has_session && isset( $summary['scan_mode'] ) && 'verification' === $summary['scan_mode'] ? esc_html__( 'Fix check', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Current scan', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
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
									/* translators: %d: amount added to the outbound HTTP request limit */
									__( 'Increase request limit by %d', 'indexlane-redirect-internal-link-auditor' ),
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
				<div><strong id="indexlane-rila-stat-sources"><?php echo esc_html( (string) $stats['sources_processed'] ); ?></strong><span><?php esc_html_e( 'Sources checked', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div><strong id="indexlane-rila-stat-links"><?php echo esc_html( (string) $stats['links_extracted'] ); ?></strong><span><?php esc_html_e( 'Links found', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div><strong id="indexlane-rila-stat-destinations"><?php echo esc_html( (string) $stats['unique_destinations_checked'] ); ?></strong><span><?php esc_html_e( 'Unique URLs checked', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div><strong id="indexlane-rila-stat-issues"><?php echo esc_html( (string) $stats['actionable_issues'] ); ?></strong><span><?php esc_html_e( 'Links needing attention', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
			</div>

			<details class="indexlane-rila-technical-details indexlane-rila-session-technical">
				<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
				<dl class="indexlane-rila-technical-metadata">
					<div><dt><?php esc_html_e( 'HTTP requests', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd id="indexlane-rila-stat-requests"><?php echo esc_html( (string) $stats['http_requests'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Request limit', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd id="indexlane-rila-request-limit"><?php echo esc_html( (string) $request_limit ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Requests remaining', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd id="indexlane-rila-requests-remaining"><?php echo esc_html( (string) $request_remaining ); ?></dd></div>
				</dl>
			</details>
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
		$impact_rows    = self::build_destination_impact( $results );
		$coverage_filter = self::current_coverage_filter();
		$coverage_target = self::current_coverage_target();
		$baseline        = self::get_saved_baseline();
		$is_verification = isset( $scan['scan_mode'] ) && 'verification' === $scan['scan_mode'];
		$comparison      = $is_verification ? self::get_session_comparison( $scan ) : null;
		$sources_checked = (int) $stats['sources_processed'];
		$links_checked   = (int) $stats['links_audited'];
		$problem_urls    = self::issue_url_count( $results );
		/* translators: %d: number of stored link sources checked */
		$source_summary = sprintf( _n( '%d stored source checked.', '%d stored sources checked.', $sources_checked, 'indexlane-redirect-internal-link-auditor' ), $sources_checked );
		/* translators: %d: number of links checked */
		$link_summary = sprintf( _n( '%d link checked.', '%d links checked.', $links_checked, 'indexlane-redirect-internal-link-auditor' ), $links_checked );
		/* translators: %d: number of URLs needing attention */
		$url_summary = sprintf( _n( '%d URL needs attention.', '%d URLs need attention.', $problem_urls, 'indexlane-redirect-internal-link-auditor' ), $problem_urls );
		?>
		<div id="indexlane-rila-results" class="indexlane-rila-results">
			<h2><?php esc_html_e( 'Scan results', 'indexlane-redirect-internal-link-auditor' ); ?></h2>

			<p class="indexlane-rila-results-summary"><?php echo esc_html( $source_summary . ' ' . $link_summary . ' ' . $url_summary ); ?></p>

			<details class="indexlane-rila-technical-details indexlane-rila-results-technical">
				<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
				<dl class="indexlane-rila-technical-metadata">
					<div><dt><?php esc_html_e( 'Published content checked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $stats['content_items_processed'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Links found', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $stats['links_extracted'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'External links skipped', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $stats['skipped_external'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Unique URLs checked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $stats['unique_destinations_checked'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'HTTP requests', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $stats['http_requests'] ); ?></dd></div>
				</dl>
			</details>

			<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-export-actions">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
				<button type="submit" name="indexlane_rila_action" value="export_coverage" class="button <?php echo is_array( $comparison ) ? '' : 'button-primary'; ?>"><?php esc_html_e( 'Download content coverage', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<button type="submit" name="indexlane_rila_action" value="export_impact" class="button"><?php esc_html_e( 'Download problem URLs', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<button type="submit" name="indexlane_rila_action" value="export_details" class="button"><?php esc_html_e( 'Download link details', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<?php if ( is_array( $comparison ) ) : ?>
					<button type="submit" name="indexlane_rila_action" value="export_comparison" class="button button-primary"><?php esc_html_e( 'Download comparison', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				<?php endif; ?>
				<details class="indexlane-rila-technical-downloads">
					<summary><?php esc_html_e( 'Technical downloads', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
					<button type="submit" name="indexlane_rila_action" value="export_scan_json" class="button"><?php esc_html_e( 'Download scan data (.json)', 'indexlane-redirect-internal-link-auditor' ); ?></button>
				</details>
				<span class="description"><?php esc_html_e( 'Downloads use these exact scan results without checking the links again. Unsaved results expire after 24 hours of inactivity.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
			</form>

			<?php if ( is_array( $baseline ) && (string) $baseline['scan_id'] === (string) $scan['id'] ) : ?>
				<p class="indexlane-rila-baseline-current"><?php esc_html_e( 'These results are saved for comparison.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-save-baseline">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
					<?php if ( is_array( $baseline ) ) : ?>
						<label>
							<input type="checkbox" name="confirm_replace" value="1" required />
							<?php esc_html_e( 'Replace the current saved scan with these results.', 'indexlane-redirect-internal-link-auditor' ); ?>
						</label>
					<?php endif; ?>
					<button type="submit" name="indexlane_rila_action" value="save_baseline" class="button button-primary">
						<?php echo is_array( $baseline ) ? esc_html__( 'Replace saved scan with these results', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Save these results for comparison', 'indexlane-redirect-internal-link-auditor' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'One saved scan is kept for your WordPress account. Saving results never changes site content.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
				</form>
			<?php endif; ?>

			<?php if ( $is_verification ) : ?>
				<?php if ( is_wp_error( $comparison ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $comparison->get_error_message() ); ?></p></div>
				<?php else : ?>
					<?php self::render_comparison( $comparison ); ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php self::render_content_link_coverage( $coverage_rows, $coverage_filter, $coverage_target, isset( $scan['settings']['source_types'] ) && is_array( $scan['settings']['source_types'] ) ? $scan['settings']['source_types'] : array( 'content' ) ); ?>

			<?php if ( empty( $results ) ) : ?>
				<p><?php esc_html_e( 'No internal, old-site, staging, or development links were found in the selected stored sources.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php else : ?>
				<?php self::render_destination_impact( $impact_rows ); ?>

				<h2><?php esc_html_e( 'Link details', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Use this full list when you need the exact editable source, linked URL, link text, and HTTP result.', 'indexlane-redirect-internal-link-auditor' ); ?>
				</p>
				<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Link details', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-occurrence-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Surface', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Scope', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'HTTP status', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Redirects', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Final URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Warning', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Link text', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></th>
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
									<?php if ( ! empty( $row['source_url'] ) ) : ?>
										<a class="indexlane-rila-target-url" href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['source_url'] ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['source_type'] ); ?></td>
								<td><?php echo esc_html( self::source_context_label( isset( $row['source_context'] ) ? (string) $row['source_context'] : 'contextual' ) ); ?></td>
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
			<h2 id="indexlane-rila-comparison-heading"><?php esc_html_e( 'What changed since the saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each row compares the saved scan with the latest scan. Open technical details for the HTTP and redirect results.', 'indexlane-redirect-internal-link-auditor' ); ?></p>

			<div class="indexlane-rila-comparison-summary">
				<div class="is-new"><strong><?php echo esc_html( (string) $summary['new'] ); ?></strong><span><?php esc_html_e( 'New issues', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-changed"><strong><?php echo esc_html( (string) $summary['changed'] ); ?></strong><span><?php esc_html_e( 'Changed issues', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-resolved"><strong><?php echo esc_html( (string) $summary['resolved'] ); ?></strong><span><?php esc_html_e( 'Resolved', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
				<div class="is-still"><strong><?php echo esc_html( (string) $summary['still'] ); ?></strong><span><?php esc_html_e( 'Still present', 'indexlane-redirect-internal-link-auditor' ); ?></span></div>
			</div>

			<?php if ( empty( $comparison['rows'] ) ) : ?>
				<p><?php esc_html_e( 'No saved-scan issues remain, and the latest scan found no new issues.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			</section>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'What changed since the saved scan', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-comparison-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What changed', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where it appears', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'indexlane-redirect-internal-link-auditor' ); ?></th>
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
							<td><?php self::render_comparison_content_counts( $row['old'], $row['new'] ); ?></td>
							<td>
								<details class="indexlane-rila-row-details">
									<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
									<dl class="indexlane-rila-row-detail-list">
										<div><dt><?php esc_html_e( 'HTTP status', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_comparison_value( $row['old'], $row['new'], 'http_status_chain' ); ?></dd></div>
										<div><dt><?php esc_html_e( 'Redirects', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_comparison_value( $row['old'], $row['new'], 'redirect_count' ); ?></dd></div>
										<div><dt><?php esc_html_e( 'Final URL', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_comparison_value( $row['old'], $row['new'], 'final_url' ); ?></dd></div>
										<div><dt><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_comparison_value( $row['old'], $row['new'], 'result_severity' ); ?></dd></div>
										<div><dt><?php esc_html_e( 'Times linked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_comparison_value( $row['old'], $row['new'], 'occurrence_count' ); ?></dd></div>
									</dl>
								</details>
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
		<span class="indexlane-rila-comparison-old"><span><?php esc_html_e( 'Saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $old_value ); ?></span>
		<span class="indexlane-rila-comparison-new"><span><?php esc_html_e( 'Latest scan', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $new_value ); ?></span>
		<?php
	}

	/**
	 * Render compact saved/latest content counts for a comparison row.
	 *
	 * @param array<string,mixed>|null $old Saved-scan results.
	 * @param array<string,mixed>|null $new Latest-scan results.
	 */
	private static function render_comparison_content_counts( ?array $old, ?array $new ): void {
		$old_count = is_array( $old ) ? max( 0, (int) $old['affected_source_count'] ) : null;
		$new_count = is_array( $new ) ? max( 0, (int) $new['affected_source_count'] ) : null;
		$missing   = __( 'Not present', 'indexlane-redirect-internal-link-auditor' );
		/* translators: %d: number of editable sources containing the URL */
		$old_label = null === $old_count ? $missing : sprintf( _n( '%d editable source', '%d editable sources', $old_count, 'indexlane-redirect-internal-link-auditor' ), $old_count );
		/* translators: %d: number of editable sources containing the URL */
		$new_label = null === $new_count ? $missing : sprintf( _n( '%d editable source', '%d editable sources', $new_count, 'indexlane-redirect-internal-link-auditor' ), $new_count );
		?>
		<span class="indexlane-rila-comparison-source-count"><span><?php esc_html_e( 'Saved scan', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $old_label ); ?></span>
		<span class="indexlane-rila-comparison-source-count"><span><?php esc_html_e( 'Latest scan', 'indexlane-redirect-internal-link-auditor' ); ?></span><?php echo esc_html( $new_label ); ?></span>
		<?php
	}

	/**
	 * Render one row per actionable destination before the occurrence detail.
	 *
	 * @param array<int,array<string,mixed>> $impact_rows Destination impact rows.
	 */
	private static function render_destination_impact( array $impact_rows ): void {
		?>
		<h2><?php esc_html_e( 'Problem URLs', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Broken and redirected URLs are grouped so you can see the problem and how widely it appears.', 'indexlane-redirect-internal-link-auditor' ); ?>
		</p>

		<?php if ( empty( $impact_rows ) ) : ?>
			<p><?php esc_html_e( 'No broken or redirected URLs were found.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Problem URLs', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
		<table class="widefat striped indexlane-rila-impact-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Where it appears', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Details', 'indexlane-redirect-internal-link-auditor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $impact_rows as $row ) : ?>
					<?php
					/* translators: %d: number of times a URL or content item is linked */
					$link_count_label = sprintf( _n( '%d time linked', '%d times linked', (int) $row['occurrences'], 'indexlane-redirect-internal-link-auditor' ), (int) $row['occurrences'] );
					?>
					<tr>
						<td>
							<?php if ( '' !== esc_url( $row['destination_url'] ) ) : ?>
								<a href="<?php echo esc_url( $row['destination_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['destination_url'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['destination_url'] ); ?>
							<?php endif; ?>
						</td>
						<td><strong><?php echo esc_html( $row['impact'] ); ?></strong><span class="indexlane-rila-cell-note"><?php echo esc_html( $row['result'] ); ?></span></td>
						<td><?php self::render_impact_source_details( $row, $link_count_label ); ?></td>
						<td>
							<details class="indexlane-rila-row-details">
								<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
								<dl class="indexlane-rila-row-detail-list">
									<div><dt><?php esc_html_e( 'HTTP status', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( $row['http_status_evidence'] ); ?></dd></div>
									<div><dt><?php esc_html_e( 'Maximum redirects', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['max_redirect_count'] ); ?></dd></div>
									<div><dt><?php esc_html_e( 'Final URLs', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( $row['effective_final_url'] ); ?></dd></div>
									<div><dt><?php esc_html_e( 'Warnings', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( $row['warning_evidence'] ); ?></dd></div>
								</dl>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render the exact editable sources behind one grouped problem URL.
	 *
	 * @param array<string,mixed> $row              Impact row.
	 * @param string              $link_count_label Total occurrence label.
	 */
	private static function render_impact_source_details( array $row, string $link_count_label ): void {
		$sources = isset( $row['affected_source_details'] ) && is_array( $row['affected_source_details'] ) ? $row['affected_source_details'] : array();
		if ( empty( $sources ) ) {
			echo '<strong>' . esc_html( $link_count_label ) . '</strong>';
			return;
		}

		if ( 1 === count( $sources ) ) {
			$source = $sources[0];
			?>
			<strong>
				<?php if ( '' !== $source['edit_url'] ) : ?>
					<a href="<?php echo esc_url( $source['edit_url'] ); ?>"><?php echo esc_html( $source['title'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $source['title'] ); ?>
				<?php endif; ?>
			</strong>
			<span class="indexlane-rila-cell-note"><?php echo esc_html( $source['type'] . ' · ' . self::source_context_label( $source['context'] ) ); ?></span>
			<span class="indexlane-rila-cell-note"><?php echo esc_html( $link_count_label ); ?></span>
			<?php
			return;
		}

		/* translators: %d: number of editable sources containing a URL */
		$source_count_label = sprintf( _n( '%d editable source affected', '%d editable sources affected', count( $sources ), 'indexlane-redirect-internal-link-auditor' ), count( $sources ) );
		?>
		<strong><?php echo esc_html( $source_count_label ); ?></strong>
		<span class="indexlane-rila-cell-note"><?php echo esc_html( $link_count_label ); ?></span>
		<details class="indexlane-rila-row-details indexlane-rila-source-details">
			<summary><?php esc_html_e( 'View affected sources', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
			<ul>
				<?php foreach ( $sources as $source ) : ?>
					<li>
						<?php if ( '' !== $source['edit_url'] ) : ?>
							<a href="<?php echo esc_url( $source['edit_url'] ); ?>"><?php echo esc_html( $source['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $source['title'] ); ?>
						<?php endif; ?>
						<?php
						/* translators: %d: number of link occurrences in one editable source */
						$source_occurrences = sprintf( _n( '%d link', '%d links', (int) $source['occurrences'], 'indexlane-redirect-internal-link-auditor' ), (int) $source['occurrences'] );
						?>
						<span><?php echo esc_html( $source['type'] . ' · ' . self::source_context_label( $source['context'] ) . ' · ' . $source_occurrences ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Render the destination-oriented coverage report for the scanned corpus.
	 *
	 * @param array<int,array<string,mixed>> $coverage_rows Coverage rows.
	 * @param string                         $filter        Coverage filter.
	 * @param int                            $target_id     Selected target detail ID.
	 * @param array<int,string>              $source_types Selected source provider IDs.
	 */
	private static function render_content_link_coverage( array $coverage_rows, string $filter, int $target_id, array $source_types ): void {
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
				<?php esc_html_e( 'See which posts, pages, and other content receive links from individual content or site-wide areas. Redirected links count toward the final WordPress URL.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>
			<p class="indexlane-rila-evidence-scope">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated selected stored source labels */
						__( 'Checked areas: %s. This report uses saved WordPress data only; it does not include shortcode output, custom fields, page-builder data, or rendered pages.', 'indexlane-redirect-internal-link-auditor' ),
						implode( ', ', self::source_type_labels( $source_types ) )
					)
				);
				?>
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
					<option value="attention" <?php selected( 'attention', $filter ); ?>><?php esc_html_e( 'No links or links from one place', 'indexlane-redirect-internal-link-auditor' ); ?></option>
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
							<th scope="col"><?php esc_html_e( 'Content', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where it appears', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $filtered_rows as $row ) : ?>
							<?php
							/* translators: %d: number of contextual incoming link occurrences */
							$contextual_label = sprintf( _n( '%d link from content', '%d links from content', (int) $row['contextual_incoming'], 'indexlane-redirect-internal-link-auditor' ), (int) $row['contextual_incoming'] );
							/* translators: %d: number of navigation or shared incoming link occurrences */
							$shared_label = sprintf( _n( '%d link from site-wide areas', '%d links from site-wide areas', (int) $row['shared_incoming'], 'indexlane-redirect-internal-link-auditor' ), (int) $row['shared_incoming'] );
							/* translators: %d: number of editable sources containing links to the target */
							$source_count_label = sprintf( _n( 'stored in %d editable place', 'stored in %d editable places', (int) $row['editable_source_count'], 'indexlane-redirect-internal-link-auditor' ), (int) $row['editable_source_count'] );
							?>
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
								<td><span class="indexlane-rila-coverage-status is-<?php echo esc_attr( $row['status_code'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
								<td><strong><?php echo esc_html( $contextual_label ); ?></strong><span class="indexlane-rila-cell-note"><?php echo esc_html( $shared_label ); ?></span><span class="indexlane-rila-cell-note"><?php echo esc_html( $source_count_label ); ?></span></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( self::coverage_report_url( $filter, (int) $row['target_id'] ) . '#indexlane-rila-target-detail' ); ?>">
										<?php esc_html_e( 'View link details', 'indexlane-redirect-internal-link-auditor' ); ?>
									</a>
									<details class="indexlane-rila-row-details">
										<summary><?php esc_html_e( 'Technical details', 'indexlane-redirect-internal-link-auditor' ); ?></summary>
										<dl class="indexlane-rila-row-detail-list">
											<div><dt><?php esc_html_e( 'All incoming links', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['incoming_occurrences'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Individual content sources', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['contextual_source_count'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Site-wide sources', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['shared_source_count'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Links from this content', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['outgoing_internal_occurrences'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Unique URLs linked', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['distinct_internal_destinations'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Link text', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php self::render_anchor_text_variants( $row['anchor_text_variants'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Self-links', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['self_link_count'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Direct links here', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['direct_incoming'] ); ?></dd></div>
											<div><dt><?php esc_html_e( 'Redirected links here', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) $row['redirected_incoming'] ); ?></dd></div>
										</dl>
									</details>
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
									__( 'Link details for: %s', 'indexlane-redirect-internal-link-auditor' ),
								$target['target_title']
							)
						);
						?>
					</h3>
					<?php if ( '' !== $target['target_url'] ) : ?>
						<a href="<?php echo esc_url( $target['target_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $target['target_url'] ); ?></a>
					<?php endif; ?>
				</div>
				<a class="button" href="<?php echo esc_url( self::coverage_report_url( $filter ) . '#indexlane-rila-coverage-heading' ); ?>"><?php esc_html_e( 'Close link details', 'indexlane-redirect-internal-link-auditor' ); ?></a>
			</div>

			<p><span class="indexlane-rila-coverage-status is-<?php echo esc_attr( $target['status_code'] ); ?>"><?php echo esc_html( $target['status'] ); ?></span></p>

			<?php if ( empty( $target['incoming_details'] ) ) : ?>
				<p><?php esc_html_e( 'No incoming links detected in selected sources.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
				<p class="description"><?php esc_html_e( 'This covers only the saved WordPress areas selected for this scan. Shortcode output, custom fields, page-builder data, and rendered pages may still contain links.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
		</section>
				<?php return; ?>
			<?php endif; ?>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Incoming link details', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-target-detail-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Where to edit', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Link text', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connection', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'HTTP status', 'indexlane-redirect-internal-link-auditor' ); ?></th>
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
									<span class="indexlane-rila-cell-note"><?php echo esc_html( $detail['source_type'] . ' · ' . self::source_context_label( $detail['source_context'] ) ); ?></span>
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
						/* translators: %d: number of distinct link-text variants */
						__( '%d link-text options', 'indexlane-redirect-internal-link-auditor' ),
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
