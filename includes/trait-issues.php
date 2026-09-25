<?php
/**
 * Acknowledged-issue tracking for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Issues {
	/**
	 * Notice code recorded by an acknowledgement request.
	 *
	 * @var string
	 */
	private static $issue_notice_code = '';

	/**
	 * Stable identity for one URL problem.
	 *
	 * Acknowledging is bound to the URL and the outcome class, so a URL that
	 * becomes broken again after being acknowledged has a new identity and
	 * returns to the actionable list.
	 *
	 * @param string $url         Linked URL.
	 * @param string $result_code Stable result code.
	 */
	private static function issue_key_for_destination( string $url, string $result_code ): string {
		$url = trim( $url );
		if ( '' === $url || 'ok' === $result_code ) {
			return '';
		}

		return sha1( self::normalize_url_for_compare( $url ) . '|' . $result_code );
	}

	/**
	 * Stable identity for one stored result row.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function issue_key_for_row( array $row ): string {
		return self::issue_key_for_destination(
			isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '',
			self::row_result_code( $row )
		);
	}

	/**
	 * Read the site's acknowledged issues.
	 *
	 * Acknowledged problems are shared site knowledge rather than one
	 * administrator's view, so they are stored once per site and are also
	 * available to the scheduled check, which runs without a current user.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_acknowledged_issues(): array {
		$stored = get_option( self::IGNORED_ISSUES_USER_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['items'] ) || ! is_array( $stored['items'] ) ) {
			return array();
		}

		$items = array();
		foreach ( $stored['items'] as $key => $item ) {
			if ( is_string( $key ) && 40 === strlen( $key ) && is_array( $item ) ) {
				$items[ $key ] = $item;
			}
		}

		return $items;
	}

	/**
	 * Persist acknowledged issues, keeping the newest bounded entries.
	 *
	 * @param array<string,array<string,mixed>> $items Acknowledged issues.
	 */
	private static function save_acknowledged_issues( array $items ): bool {
		uasort(
			$items,
			static function ( array $left, array $right ): int {
				$left_time  = isset( $left['at'] ) ? (int) $left['at'] : 0;
				$right_time = isset( $right['at'] ) ? (int) $right['at'] : 0;

				return $right_time <=> $left_time;
			}
		);

		$items = array_slice( $items, 0, self::MAX_IGNORED_ISSUES, true );

		return (bool) update_option( self::IGNORED_ISSUES_USER_OPTION, array( 'items' => $items ), false );
	}

	/**
	 * Whether one stored row is currently acknowledged.
	 *
	 * @param array<string,mixed>            $row    Result row.
	 * @param array<string,array<string,mixed>> $issues Acknowledged issues.
	 */
	private static function is_row_acknowledged( array $row, array $issues ): bool {
		$key = self::issue_key_for_row( $row );

		return '' !== $key && isset( $issues[ $key ] );
	}

	/**
	 * Count actionable and acknowledged URLs for one result set.
	 *
	 * @param array<int,array<string,mixed>> $results Result rows.
	 * @return array{total:int,active:int,acknowledged:int}
	 */
	private static function issue_url_counts( array $results ): array {
		$issues       = self::get_acknowledged_issues();
		$destinations = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$result_code = self::row_result_code( $row );
			if ( 'ok' === $result_code ) {
				continue;
			}

			$destination = self::normalize_url_for_compare( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' );
			if ( '' === $destination ) {
				$destination = isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '';
			}
			if ( '' === $destination ) {
				continue;
			}

			$key = self::issue_key_for_row( $row );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $destinations[ $key ] ) ) {
				$destinations[ $key ] = array(
					'acknowledged' => isset( $issues[ $key ] ),
				);
			}
		}

		$acknowledged = 0;
		foreach ( $destinations as $entry ) {
			if ( $entry['acknowledged'] ) {
				$acknowledged++;
			}
		}

		return array(
			'total'        => count( $destinations ),
			'active'       => count( $destinations ) - $acknowledged,
			'acknowledged' => $acknowledged,
		);
	}

	/**
	 * Handle an acknowledge or restore request before page output.
	 */
	public static function maybe_handle_issue_action(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || 'POST' !== self::server_request_method() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}

		$action = isset( $_POST['indexlane_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['indexlane_rila_action'] ) ) : '';
		if ( ! in_array( $action, array( 'acknowledge_issue', 'restore_issue' ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The action nonce is verified immediately above.
		$url       = isset( $_POST['issue_url'] ) && is_scalar( $_POST['issue_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['issue_url'] ) ) ) : '';
		$code      = isset( $_POST['issue_code'] ) && is_scalar( $_POST['issue_code'] ) ? sanitize_key( wp_unslash( (string) $_POST['issue_code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $code, self::repairable_result_codes(), true ) ) {
			$code = 'needs_review';
		}

		$key = self::issue_key_for_destination( $url, $code );
		if ( '' === $key ) {
			self::redirect_after_issue_action( 'issue_failed' );
		}

		$issues = self::get_acknowledged_issues();

		if ( 'restore_issue' === $action ) {
			unset( $issues[ $key ] );
			if ( ! self::save_acknowledged_issues( $issues ) ) {
				self::redirect_after_issue_action( 'issue_failed' );
			}

			self::redirect_after_issue_action( 'restored' );
		}

		$issues[ $key ] = array(
			'url'     => $url,
			'code'    => $code,
			'at'      => time(),
			'user_id' => get_current_user_id(),
		);

		if ( ! self::save_acknowledged_issues( $issues ) ) {
			self::redirect_after_issue_action( 'issue_failed' );
		}

		self::redirect_after_issue_action( 'acknowledged' );
	}

	/**
	 * Redirect after an acknowledgement action so a reload cannot repeat it.
	 *
	 * @param string $code Notice code.
	 */
	private static function redirect_after_issue_action( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'issue_notice' => $code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the acknowledgement notice for the current request.
	 */
	private static function render_issue_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This displays a fixed message after a nonce-protected redirect.
		$code = isset( $_GET['issue_notice'] ) ? sanitize_key( wp_unslash( $_GET['issue_notice'] ) ) : '';

		switch ( $code ) {
			case 'acknowledged':
				$type    = 'success';
				$message = __( 'This URL is now acknowledged. It stays in the evidence and exports, but it no longer counts as a new problem.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'restored':
				$type    = 'success';
				$message = __( 'This URL is no longer acknowledged and counts as a problem again.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'issue_failed':
				$type    = 'error';
				$message = __( 'The acknowledgement could not be saved.', 'indexlane-redirect-internal-link-auditor' );
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
	 * Render the acknowledgement control for one grouped problem URL.
	 *
	 * @param array<string,mixed> $row          Impact row.
	 * @param bool                $acknowledged Whether the row is acknowledged.
	 */
	private static function render_issue_action_control( array $row, bool $acknowledged ): void {
		$destination = isset( $row['destination_url'] ) ? (string) $row['destination_url'] : '';
		$code        = self::result_code_from_label( isset( $row['result'] ) ? (string) $row['result'] : '' );
		?>
		<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-issue-form">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="issue_url" value="<?php echo esc_attr( $destination ); ?>" />
			<input type="hidden" name="issue_code" value="<?php echo esc_attr( $code ); ?>" />
			<button type="submit" name="indexlane_rila_action" value="<?php echo $acknowledged ? 'restore_issue' : 'acknowledge_issue'; ?>" class="button-link">
				<?php echo $acknowledged ? esc_html__( 'Restore', 'indexlane-redirect-internal-link-auditor' ) : esc_html__( 'Acknowledge', 'indexlane-redirect-internal-link-auditor' ); ?>
			</button>
		</form>
		<?php
	}
}
