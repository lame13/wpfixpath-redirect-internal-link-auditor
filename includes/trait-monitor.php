<?php
/**
 * Scheduled monitoring and notification for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Monitor {
	/**
	 * Notice code recorded by a monitor settings request.
	 *
	 * @var string
	 */
	private static $monitor_notice_code = '';

	/**
	 * Read the stored monitor configuration.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_monitor_config(): array {
		$stored = get_option( self::MONITOR_OPTION, array() );
		$config = array(
			'enabled'       => false,
			'schedule'      => 'daily',
			'recipients'    => '',
			'request_limit' => self::MONITOR_DEFAULT_REQUEST_LIMIT,
			'settings'      => null,
			'last_run'      => array(),
			'issues'        => array(),
			'has_baseline'  => false,
		);

		if ( ! is_array( $stored ) ) {
			return $config;
		}

		$config['enabled']       = ! empty( $stored['enabled'] );
		$config['schedule']      = self::sanitize_monitor_schedule( isset( $stored['schedule'] ) ? (string) $stored['schedule'] : 'daily' );
		$config['recipients']    = isset( $stored['recipients'] ) && is_string( $stored['recipients'] ) ? $stored['recipients'] : '';
		$config['request_limit'] = isset( $stored['request_limit'] )
			? min( 20000, max( self::INITIAL_REQUEST_ALLOWANCE, (int) $stored['request_limit'] ) )
			: self::MONITOR_DEFAULT_REQUEST_LIMIT;
		$config['settings']      = isset( $stored['settings'] ) && is_array( $stored['settings'] ) ? $stored['settings'] : null;
		$config['last_run']      = isset( $stored['last_run'] ) && is_array( $stored['last_run'] ) ? $stored['last_run'] : array();
		$config['has_baseline']  = ! empty( $stored['has_baseline'] );

		$issues = array();
		if ( isset( $stored['issues'] ) && is_array( $stored['issues'] ) ) {
			foreach ( $stored['issues'] as $key ) {
				if ( is_string( $key ) && 40 === strlen( $key ) ) {
					$issues[] = $key;
				}
			}
		}
		$config['issues'] = array_values( array_unique( $issues ) );

		return $config;
	}

	/**
	 * Keep only a supported monitor recurrence.
	 *
	 * @param string $schedule Requested recurrence.
	 */
	private static function sanitize_monitor_schedule( string $schedule ): string {
		return in_array( $schedule, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $schedule : 'daily';
	}

	/**
	 * Persist a monitor configuration.
	 *
	 * @param array<string,mixed> $config Monitor configuration.
	 */
	private static function save_monitor_config( array $config ): bool {
		$config['issues'] = array_slice( (array) $config['issues'], 0, self::MONITOR_MAX_TRACKED_ISSUES );

		return update_option( self::MONITOR_OPTION, $config, false );
	}

	/**
	 * Parse the notification recipient list.
	 *
	 * @param string $recipients Raw recipient list.
	 * @return array<int,string>
	 */
	private static function parse_monitor_recipients( string $recipients ): array {
		$recipients = trim( $recipients );
		if ( '' === $recipients ) {
			$default = get_option( 'admin_email' );

			return is_string( $default ) && is_email( $default ) ? array( $default ) : array();
		}

		$emails = array();
		foreach ( preg_split( '/[\s,;]+/', $recipients ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_email( $candidate ) ) {
				$emails[] = $candidate;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Settings used by the scheduled scan.
	 *
	 * The monitor repeats the most recent saved scope when one exists so its
	 * notifications always describe the same stored sources.
	 *
	 * @param array<string,mixed> $config Monitor configuration.
	 * @return array<string,mixed>
	 */
	private static function monitor_scan_settings( array $config ): array {
		if ( is_array( $config['settings'] ) ) {
			return $config['settings'];
		}

		$settings                  = self::default_settings();
		$settings['content_scope'] = 'all';

		return $settings;
	}

	/**
	 * Ensure the scheduled event matches the stored configuration.
	 */
	public static function sync_monitor_schedule(): void {
		$config = self::get_monitor_config();

		if ( empty( $config['enabled'] ) ) {
			if ( wp_next_scheduled( self::MONITOR_CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::MONITOR_CRON_HOOK );
			}
			if ( wp_next_scheduled( self::MONITOR_CONTINUE_HOOK ) ) {
				wp_clear_scheduled_hook( self::MONITOR_CONTINUE_HOOK );
			}

			return;
		}

		$scheduled = wp_next_scheduled( self::MONITOR_CRON_HOOK );
		if ( $scheduled ) {
			$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::MONITOR_CRON_HOOK ) : null;
			$current = $event && isset( $event->schedule ) ? (string) $event->schedule : '';
			if ( $current === (string) $config['schedule'] ) {
				return;
			}
			wp_clear_scheduled_hook( self::MONITOR_CRON_HOOK );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) $config['schedule'], self::MONITOR_CRON_HOOK );
	}

	/**
	 * Run one bounded slice of the scheduled monitor.
	 */
	public static function run_scheduled_monitor(): void {
		self::run_monitor_slice();
	}

	/**
	 * Run the monitor until it completes, is reported partial, or must continue.
	 *
	 * @return array<string,mixed> Summary of this run.
	 */
	private static function run_monitor_slice(): array {
		$lock = self::MONITOR_SESSION_OPTION . '_lock';
		$existing = get_option( $lock );
		if ( is_numeric( $existing ) && (int) $existing < time() - HOUR_IN_SECONDS ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return array( 'status' => 'running' );
		}
		try {
			return self::run_monitor_slice_locked();
		} finally {
			delete_option( $lock );
		}
	}

	/** Run a slice while holding the monitor session lock. */
	private static function run_monitor_slice_locked(): array {
		$config = self::get_monitor_config();

		if ( empty( $config['enabled'] ) ) {
			self::clear_monitor_session();

			return array( 'status' => 'disabled' );
		}

		$settings = self::monitor_scan_settings( $config );
		if ( empty( $settings['source_types'] ) ) {
			self::record_monitor_failure( __( 'The saved scan scope no longer selects any place to check links.', 'indexlane-redirect-internal-link-auditor' ) );

			return array( 'status' => 'failed' );
		}

		$session = self::get_monitor_session();
		if ( null === $session ) {
			$session = self::create_scan_session( $settings, 'standard' );
			if ( is_wp_error( $session ) ) {
				self::record_monitor_failure( $session->get_error_message() );

				return array( 'status' => 'failed' );
			}

			$session['request_limit'] = max( self::INITIAL_REQUEST_ALLOWANCE, (int) $config['request_limit'] );
			$session['monitor']       = true;
		}

		$started_at = microtime( true );
		$batches    = 0;

		while ( 'running' === $session['status'] && $batches < self::MONITOR_MAX_BATCHES_PER_RUN ) {
			if ( ( microtime( true ) - $started_at ) > self::MONITOR_RUN_BUDGET_SECONDS ) {
				break;
			}

			$session = self::process_scan_batch( $session );
			$batches++;
		}

		if ( 'running' === $session['status'] ) {
			self::save_monitor_session( $session );
			self::schedule_monitor_continuation();

			return array(
				'status'      => 'running',
				'sources'     => (int) $session['stats']['sources_processed'],
				'http_requests' => (int) $session['stats']['http_requests'],
			);
		}

		self::clear_monitor_session();
		self::evaluate_monitor_results( $config, $session );

		return array(
			'status'  => (string) self::get_monitor_config()['last_run']['status'],
			'sources' => (int) $session['stats']['sources_processed'],
		);
	}

	/**
	 * Store the in-progress scheduled scan.
	 *
	 * @param array<string,mixed> $session Scan session.
	 */
	private static function save_monitor_session( array $session ): void {
		$session['updated_at'] = time();
		update_option( self::MONITOR_SESSION_OPTION, $session, false );
	}

	/**
	 * Read an in-progress scheduled scan, discarding a stale one.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function get_monitor_session(): ?array {
		$session = get_option( self::MONITOR_SESSION_OPTION );

		if (
			! is_array( $session ) ||
			! isset( $session['schema_version'], $session['status'], $session['updated_at'] ) ||
			self::SESSION_SCHEMA_VERSION !== (int) $session['schema_version']
		) {
			return null;
		}

		if ( (int) $session['updated_at'] < ( time() - ( 6 * HOUR_IN_SECONDS ) ) ) {
			self::clear_monitor_session();

			return null;
		}

		return $session;
	}

	/**
	 * Discard the in-progress scheduled scan.
	 */
	private static function clear_monitor_session(): void {
		delete_option( self::MONITOR_SESSION_OPTION );
	}

	/**
	 * Schedule the next bounded slice of a long-running monitor scan.
	 */
	private static function schedule_monitor_continuation(): void {
		if ( ! wp_next_scheduled( self::MONITOR_CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::MONITOR_CONTINUE_HOOK );
		}
	}

	/**
	 * Record a scheduled-scan failure for display.
	 *
	 * @param string $message Failure message.
	 */
	private static function record_monitor_failure( string $message ): void {
		$config             = self::get_monitor_config();
		$config['last_run'] = array(
			'at'      => time(),
			'status'  => 'failed',
			'message' => $message,
		);

		self::save_monitor_config( $config );
	}

	/**
	 * Compare a finished scheduled scan with the previous notification set.
	 *
	 * @param array<string,mixed> $config  Monitor configuration.
	 * @param array<string,mixed> $session Completed scan session.
	 */
	private static function evaluate_monitor_results( array $config, array $session ): void {
		$results  = isset( $session['results'] ) && is_array( $session['results'] ) ? $session['results'] : array();
		$acknowledged = self::get_acknowledged_issues();
		$tracked      = array();
		$current      = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = self::issue_key_for_row( $row );
			if ( '' === $key ) {
				continue;
			}

			$entry = array(
				'url'  => self::normalize_url_for_compare( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' ),
				'code' => self::row_result_code( $row ),
			);

			$tracked[ $key ] = $entry;
			if ( ! isset( $acknowledged[ $key ] ) ) {
				$current[ $key ] = $entry;
			}
		}

		// An incomplete scan cannot establish that an unseen issue was fixed.
		if ( 'complete' !== $session['status'] || count( $tracked ) > self::MONITOR_MAX_TRACKED_ISSUES ) {
			$counts = self::issue_url_counts( $results );
			$config['last_run'] = array(
				'at' => time(), 'status' => 'partial',
				'total' => $counts['total'], 'active' => $counts['active'],
				'acknowledged' => $counts['acknowledged'], 'new' => 0, 'resolved' => 0,
				'sources' => (int) $session['stats']['sources_processed'], 'notified' => false,
			);
			self::save_monitor_config( $config );
			return;
		}

		$previous = array_fill_keys( (array) $config['issues'], true );
		$new      = array();
		$resolved = 0;

		foreach ( $current as $key => $entry ) {
			if ( ! isset( $previous[ $key ] ) ) {
				$new[] = $entry;
			}
		}

		// An acknowledged URL is still broken, so it must not read as resolved.
		foreach ( $config['issues'] as $key ) {
			if ( ! isset( $tracked[ $key ] ) && ! isset( $acknowledged[ $key ] ) ) {
				$resolved++;
			}
		}

		$is_first_run = empty( $config['has_baseline'] );
		$notified     = false;

		if ( $is_first_run || ! empty( $new ) || $resolved > 0 ) {
			$notified = self::send_monitor_notification( $config, $session, $current, $new, $resolved, $is_first_run );
		}

		$counts             = self::issue_url_counts( $results );
		$config['issues']   = array_slice( array_keys( $tracked ), 0, self::MONITOR_MAX_TRACKED_ISSUES );
		$config['has_baseline'] = true;
		$config['last_run'] = array(
			'at'           => time(),
			'status'       => 'complete',
			'total'        => (int) $counts['total'],
			'active'       => (int) $counts['active'],
			'acknowledged' => (int) $counts['acknowledged'],
			'new'          => count( $new ),
			'resolved'     => $resolved,
			'sources'      => (int) $session['stats']['sources_processed'],
			'notified'     => $notified,
		);

		self::save_monitor_config( $config );
	}

	/**
	 * Email the administrator when the scheduled scan result changed.
	 *
	 * @param array<string,mixed>                     $config       Monitor configuration.
	 * @param array<string,mixed>                     $session      Completed scan session.
	 * @param array<string,array<string,string>>      $current      Current unacknowledged issues.
	 * @param array<int,array<string,string>>         $new_issues   Newly detected issues.
	 * @param int                                     $resolved     Number of resolved issues.
	 * @param bool                                    $is_first_run Whether this is the first run.
	 */
	private static function send_monitor_notification( array $config, array $session, array $current, array $new_issues, int $resolved, bool $is_first_run ): bool {
		$recipients = self::parse_monitor_recipients( (string) $config['recipients'] );
		if ( empty( $recipients ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		if ( $is_first_run ) {
			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Internal link check is now running', 'indexlane-redirect-internal-link-auditor' ),
				$site_name
			);
		} else {
			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Internal link check found changes', 'indexlane-redirect-internal-link-auditor' ),
				$site_name
			);
		}

		$lines = array();
		$lines[] = sprintf(
			/* translators: %s: site name */
			__( 'The scheduled internal link check for %s finished.', 'indexlane-redirect-internal-link-auditor' ),
			$site_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of URLs needing attention */
			_n( '%d URL needs attention.', '%d URLs need attention.', count( $current ), 'indexlane-redirect-internal-link-auditor' ),
			count( $current )
		);
		$lines[] = sprintf(
			/* translators: %d: number of newly detected URLs */
			_n( '%d new URL since the previous check.', '%d new URLs since the previous check.', count( $new_issues ), 'indexlane-redirect-internal-link-auditor' ),
			count( $new_issues )
		);
		$lines[] = sprintf(
			/* translators: %d: number of resolved URLs */
			_n( '%d resolved since the previous check.', '%d resolved since the previous check.', $resolved, 'indexlane-redirect-internal-link-auditor' ),
			$resolved
		);

		if ( isset( $session['stats']['sources_processed'] ) ) {
			$lines[] = sprintf(
				/* translators: %d: number of stored link sources checked */
				_n( '%d stored source checked.', '%d stored sources checked.', (int) $session['stats']['sources_processed'], 'indexlane-redirect-internal-link-auditor' ),
				(int) $session['stats']['sources_processed']
			);
		}

		if ( ! empty( $new_issues ) ) {
			$lines[] = '';
			$lines[] = __( 'New URLs:', 'indexlane-redirect-internal-link-auditor' );
			foreach ( array_slice( $new_issues, 0, 20 ) as $entry ) {
				$lines[] = '  ' . (string) $entry['url'];
			}
		}

		$lines[] = '';
		$lines[] = __( 'Review, acknowledge, or fix these links:', 'indexlane-redirect-internal-link-auditor' );
		$lines[] = admin_url( 'tools.php?page=' . self::SLUG );
		$lines[] = '';
		$lines[] = __( 'Acknowledged URLs are excluded from these notifications. Turn the schedule off on the same page.', 'indexlane-redirect-internal-link-auditor' );

		$sent = wp_mail( $recipients, $subject, implode( "\n", $lines ) );

		return (bool) $sent;
	}

	/**
	 * Describe the current monitor state for the administrator.
	 *
	 * @param array<string,mixed> $config Monitor configuration.
	 */
	private static function monitor_status_label( array $config ): string {
		if ( empty( $config['enabled'] ) ) {
			return __( 'Off', 'indexlane-redirect-internal-link-auditor' );
		}

		$last_run = $config['last_run'];
		if ( empty( $last_run ) || empty( $last_run['at'] ) ) {
			return __( 'On, first check pending', 'indexlane-redirect-internal-link-auditor' );
		}

		if ( isset( $last_run['status'] ) && 'partial' === $last_run['status'] ) {
			return __( 'On, the last check was partial; no fix comparison was made', 'indexlane-redirect-internal-link-auditor' );
		}

		if ( isset( $last_run['status'] ) && 'failed' === $last_run['status'] ) {
			return __( 'On, the last check could not finish', 'indexlane-redirect-internal-link-auditor' );
		}

		return sprintf(
			/* translators: %s: human time difference, for example "2 hours" */
			__( 'On, last check %s ago', 'indexlane-redirect-internal-link-auditor' ),
			human_time_diff( (int) $last_run['at'], time() )
		);
	}

	/**
	 * Handle monitor settings and manual run requests before page output.
	 */
	public static function maybe_handle_monitor_action(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || 'POST' !== self::server_request_method() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}

		$action = isset( $_POST['indexlane_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['indexlane_rila_action'] ) ) : '';
		if ( ! in_array( $action, array( 'save_monitor', 'disable_monitor', 'run_monitor_now' ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$config = self::get_monitor_config();

		if ( 'disable_monitor' === $action ) {
			$config['enabled'] = false;
			self::save_monitor_config( $config );
			self::clear_monitor_session();
			wp_clear_scheduled_hook( self::MONITOR_CRON_HOOK );
			wp_clear_scheduled_hook( self::MONITOR_CONTINUE_HOOK );
			self::redirect_after_monitor_action( 'disabled' );
		}


		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The action nonce is verified before the settings are read.
		$settings = self::get_request_settings( wp_unslash( $_POST ) );
		$schedule = isset( $_POST['monitor_schedule'] ) && is_scalar( $_POST['monitor_schedule'] )
			? self::sanitize_monitor_schedule( sanitize_key( wp_unslash( (string) $_POST['monitor_schedule'] ) ) )
			: 'daily';
		$recipients = isset( $_POST['monitor_recipients'] ) && is_scalar( $_POST['monitor_recipients'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['monitor_recipients'] ) )
			: '';
		$request_limit = isset( $_POST['monitor_request_limit'] ) && is_scalar( $_POST['monitor_request_limit'] )
			? min( 20000, max( self::INITIAL_REQUEST_ALLOWANCE, absint( $_POST['monitor_request_limit'] ) ) )
			: self::MONITOR_DEFAULT_REQUEST_LIMIT;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $settings['source_types'] ) || ( in_array( 'content', $settings['source_types'], true ) && empty( $settings['post_types'] ) ) ) {
			self::redirect_after_monitor_action( 'invalid_scope' );
		}

		if ( $config['settings'] !== $settings || $config['request_limit'] !== $request_limit ) {
			self::clear_monitor_session();
			wp_clear_scheduled_hook( self::MONITOR_CONTINUE_HOOK );
			$config['issues'] = array();
			$config['has_baseline'] = false;
		}

		$config['enabled']       = true;
		$config['schedule']      = $schedule;
		$config['recipients']    = $recipients;
		$config['request_limit'] = $request_limit;
		$config['settings']      = $settings;

		self::save_monitor_config( $config );
		self::sync_monitor_schedule();
		if ( 'run_monitor_now' === $action ) {
			$result = self::run_monitor_slice();
			self::redirect_after_monitor_action( 'ran_' . ( isset( $result['status'] ) ? (string) $result['status'] : 'unknown' ) );
		}
		self::redirect_after_monitor_action( 'saved' );
	}

	/**
	 * Redirect after a monitor action so a reload cannot repeat it.
	 *
	 * @param string $code Notice code.
	 */
	private static function redirect_after_monitor_action( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::SLUG,
					'monitor_notice' => $code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the monitor notice for the current request.
	 */
	private static function render_monitor_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This displays a fixed message after a nonce-protected redirect.
		$code = isset( $_GET['monitor_notice'] ) ? sanitize_key( wp_unslash( $_GET['monitor_notice'] ) ) : '';

		switch ( $code ) {
			case 'saved':
				$type    = 'success';
				$message = __( 'The scheduled link check was saved and is now on.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'disabled':
				$type    = 'success';
				$message = __( 'The scheduled link check was turned off. Existing results were kept.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'invalid_scope':
				$type    = 'error';
				$message = __( 'Choose at least one place to check, and at least one content type when Content is selected.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'ran_complete':
				$type    = 'success';
				$message = __( 'The scheduled check ran now. Results are shown below.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'ran_running':
				$type    = 'info';
				$message = __( 'The scheduled check started and is continuing in the background.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'ran_partial':
				$type    = 'warning';
				$message = __( 'The scheduled check stopped at its request limit and reported a partial result. Increase the request limit to finish a full check.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'ran_failed':
				$type    = 'error';
				$message = __( 'The scheduled check could not read the selected link sources.', 'indexlane-redirect-internal-link-auditor' );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Render the scheduled-check settings and status.
	 */
	private static function render_monitor_panel(): void {
		$config   = self::get_monitor_config();
		$settings = self::monitor_scan_settings( $config );
		$last_run = is_array( $config['last_run'] ) ? $config['last_run'] : array();
		?>
		<section id="indexlane-rila-monitor" class="indexlane-rila-monitor" aria-labelledby="indexlane-rila-monitor-heading">
			<h2 id="indexlane-rila-monitor-heading"><?php esc_html_e( 'Keep it clean', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Get a summary after the first complete check, then emails when new problems appear or previous ones are resolved. Acknowledged URLs do not trigger change notifications.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<p class="indexlane-rila-monitor-status">
				<strong><?php esc_html_e( 'Status:', 'indexlane-redirect-internal-link-auditor' ); ?></strong>
				<?php echo esc_html( self::monitor_status_label( $config ) ); ?>
			</p>

			<?php if ( ! empty( $last_run ) && isset( $last_run['at'] ) && ! empty( $last_run['status'] ) && 'failed' === $last_run['status'] && ! empty( $last_run['message'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $last_run['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $last_run ) && isset( $last_run['at'] ) && empty( $last_run['status'] ) === false && 'failed' !== (string) $last_run['status'] ) : ?>
				<dl class="indexlane-rila-monitor-summary">
					<div><dt><?php esc_html_e( 'URLs needing attention', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) (int) $last_run['active'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Acknowledged', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) (int) $last_run['acknowledged'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'New since previous check', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) (int) $last_run['new'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Resolved since previous check', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><?php echo esc_html( (string) (int) $last_run['resolved'] ); ?></dd></div>
				</dl>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-monitor-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="source_types_present" value="1" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'How often', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<select name="monitor_schedule">
									<option value="hourly" <?php selected( 'hourly', $config['schedule'] ); ?>><?php esc_html_e( 'Hourly', 'indexlane-redirect-internal-link-auditor' ); ?></option>
									<option value="twicedaily" <?php selected( 'twicedaily', $config['schedule'] ); ?>><?php esc_html_e( 'Twice daily', 'indexlane-redirect-internal-link-auditor' ); ?></option>
									<option value="daily" <?php selected( 'daily', $config['schedule'] ); ?>><?php esc_html_e( 'Daily', 'indexlane-redirect-internal-link-auditor' ); ?></option>
									<option value="weekly" <?php selected( 'weekly', $config['schedule'] ); ?>><?php esc_html_e( 'Weekly', 'indexlane-redirect-internal-link-auditor' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Scheduled checks use WP-Cron, so they run on the next request after the scheduled time.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="indexlane-rila-monitor-recipients"><?php esc_html_e( 'Email changes to', 'indexlane-redirect-internal-link-auditor' ); ?></label>
							</th>
							<td>
								<input
									id="indexlane-rila-monitor-recipients"
									type="text"
									name="monitor_recipients"
									class="large-text"
									value="<?php echo esc_attr( (string) $config['recipients'] ); ?>"
									placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'One or more addresses, separated by commas. Leave blank to use the site administration email.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where to check for links', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset">
									<legend class="screen-reader-text"><?php esc_html_e( 'Where to check for links', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
									<div class="indexlane-rila-source-options">
										<?php foreach ( self::get_available_source_types() as $source_type => $source_definition ) : ?>
											<label class="indexlane-rila-source-option">
												<input
													type="checkbox"
													name="source_types[]"
													value="<?php echo esc_attr( $source_type ); ?>"
													<?php checked( in_array( $source_type, (array) $settings['source_types'], true ) ); ?>
												/>
												<span><strong><?php echo esc_html( $source_definition['label'] ); ?></strong><span class="description"><?php echo esc_html( $source_definition['description'] ); ?></span></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							</td>
						</tr>
						<tr data-indexlane-rila-content-setting>
							<th scope="row"><?php esc_html_e( 'Content types', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset">
									<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
									<?php foreach ( self::get_available_post_types() as $post_type => $label ) : ?>
										<label class="indexlane-rila-checkbox">
											<input
												type="checkbox"
												name="post_types[]"
												value="<?php echo esc_attr( $post_type ); ?>"
												<?php checked( in_array( $post_type, (array) $settings['post_types'], true ) ); ?>
											/>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr data-indexlane-rila-content-setting>
							<th scope="row"><?php esc_html_e( 'How much content', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<td>
								<fieldset class="indexlane-rila-fieldset">
									<legend class="screen-reader-text"><?php esc_html_e( 'How much content', 'indexlane-redirect-internal-link-auditor' ); ?></legend>
									<label class="indexlane-rila-choice">
										<input type="radio" name="content_scope" value="all" <?php checked( 'all', $settings['content_scope'] ); ?> />
										<?php esc_html_e( 'All published content', 'indexlane-redirect-internal-link-auditor' ); ?>
									</label>
									<label class="indexlane-rila-choice">
										<input type="radio" name="content_scope" value="limit" <?php checked( 'limit', $settings['content_scope'] ); ?> />
										<?php esc_html_e( 'Newest', 'indexlane-redirect-internal-link-auditor' ); ?>
										<input type="number" name="max_posts" min="1" max="<?php echo esc_attr( (string) self::MAX_NUMERIC_CONTENT_ITEMS ); ?>" value="<?php echo esc_attr( (string) $settings['max_posts'] ); ?>" />
										<?php esc_html_e( 'content items', 'indexlane-redirect-internal-link-auditor' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="indexlane-rila-monitor-old-domains"><?php esc_html_e( 'Old sites', 'indexlane-redirect-internal-link-auditor' ); ?></label>
							</th>
							<td>
								<textarea id="indexlane-rila-monitor-old-domains" name="old_domains" rows="3" class="large-text code"><?php echo esc_textarea( (string) $settings['old_domains'] ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="indexlane-rila-monitor-request-limit"><?php esc_html_e( 'Request limit', 'indexlane-redirect-internal-link-auditor' ); ?></label>
							</th>
							<td>
								<input
									id="indexlane-rila-monitor-request-limit"
									type="number"
									name="monitor_request_limit"
									min="<?php echo esc_attr( (string) self::INITIAL_REQUEST_ALLOWANCE ); ?>"
									max="20000"
									value="<?php echo esc_attr( (string) $config['request_limit'] ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'The scheduled check stops at this limit and reports a partial result instead of running forever.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" name="indexlane_rila_action" value="save_monitor" class="button button-primary">
						<?php echo empty( $config['enabled'] ) ? esc_html__( 'Turn on the scheduled check', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Save the scheduled check', 'indexlane-redirect-internal-link-auditor' ); ?>
					</button>
					<button type="submit" name="indexlane_rila_action" value="run_monitor_now" class="button">
						<?php esc_html_e( 'Run it now', 'indexlane-redirect-internal-link-auditor' ); ?>
					</button>
					<?php if ( ! empty( $config['enabled'] ) ) : ?>
						<button type="submit" name="indexlane_rila_action" value="disable_monitor" class="button-link" data-indexlane-rila-confirm="<?php esc_attr_e( 'Turn off the scheduled link check?', 'indexlane-redirect-internal-link-auditor' ); ?>">
							<?php esc_html_e( 'Turn it off', 'indexlane-redirect-internal-link-auditor' ); ?>
						</button>
					<?php endif; ?>
				</p>

				<p class="description">
					<?php esc_html_e( 'The scheduled check reads stored WordPress data and requests same-site URLs, exactly like a manual scan. It never edits content and never fetches other sites.', 'indexlane-redirect-internal-link-auditor' ); ?>
				</p>
			</form>
		</section>
		<?php
	}

	/**
	 * Register the dashboard status widget.
	 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget( 'indexlane_rila_status', __( 'Internal link check', 'indexlane-redirect-internal-link-auditor' ), array( __CLASS__, 'render_dashboard_widget' ) );
	}

	/**
	 * Render the dashboard status widget.
	 */
	public static function render_dashboard_widget(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$config   = self::get_monitor_config();
		$last_run = is_array( $config['last_run'] ) ? $config['last_run'] : array();
		?>
		<p><strong><?php echo esc_html( self::monitor_status_label( $config ) ); ?></strong></p>
		<?php if ( ! empty( $last_run ) && isset( $last_run['at'] ) && 'failed' !== (string) $last_run['status'] ) : ?>
			<ul>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of URLs needing attention */
							_n( '%d URL needs attention.', '%d URLs need attention.', (int) $last_run['active'], 'indexlane-redirect-internal-link-auditor' ),
							(int) $last_run['active']
						)
					);
					?>
				</li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of newly detected URLs */
							_n( '%d new since the previous check.', '%d new since the previous check.', (int) $last_run['new'], 'indexlane-redirect-internal-link-auditor' ),
							(int) $last_run['new']
						)
					);
					?>
				</li>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No scheduled check has finished yet.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Open Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' ); ?></a></p>
		<?php
	}

	/**
	 * Register the Site Health check.
	 *
	 * @param array<string,mixed> $tests Registered Site Health tests.
	 * @return array<string,mixed>
	 */
	public static function register_site_health_test( array $tests ): array {
		$tests['direct']['indexlane_rila_monitor'] = array(
			'label' => __( 'Scheduled internal link check', 'indexlane-redirect-internal-link-auditor' ),
			'test'  => array( __CLASS__, 'site_health_monitor_test' ),
		);

		return $tests;
	}

	/**
	 * Report scheduled-check health.
	 *
	 * @return array<string,mixed>
	 */
	public static function site_health_monitor_test(): array {
		$config   = self::get_monitor_config();
		$last_run = is_array( $config['last_run'] ) ? $config['last_run'] : array();
		$label    = __( 'The scheduled internal link check is not running.', 'indexlane-redirect-internal-link-auditor' );
		$status   = 'recommended';
		$description = __( 'Turn on the scheduled check to be told when a link breaks, redirects, or starts pointing somewhere else.', 'indexlane-redirect-internal-link-auditor' );

		if ( ! empty( $config['enabled'] ) && ! empty( $last_run['at'] ) && 'complete' === (string) $last_run['status'] ) {
			$label  = __( 'The scheduled internal link check is running.', 'indexlane-redirect-internal-link-auditor' );
			$status = 'good';
			$description = sprintf(
				/* translators: 1: human time difference, 2: number of URLs that need attention */
				__( 'The last check ran %1$s ago. %2$d URLs in the current scan scope need attention.', 'indexlane-redirect-internal-link-auditor' ),
				human_time_diff( (int) $last_run['at'], time() ),
				(int) $last_run['active']
			);
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'IndexLane', 'indexlane-redirect-internal-link-auditor' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ),
				esc_html__( 'Open Redirect & Internal Link Auditor', 'indexlane-redirect-internal-link-auditor' )
			),
			'test'        => 'indexlane_rila_monitor',
		);
	}
}
