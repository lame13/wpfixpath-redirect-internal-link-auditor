<?php
/**
 * WP-CLI entry points for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_CLI {
	/**
	 * Translate WP-CLI arguments into sanitized scan settings.
	 *
	 * @param array<string,mixed> $assoc_args Associative CLI arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function cli_scan_settings( array $assoc_args ) {
		$source_types = array();
		if ( isset( $assoc_args['source-types'] ) ) {
			$source_types = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['source-types'] ) ) );
		}

		$post_types = array();
		if ( isset( $assoc_args['post-types'] ) ) {
			$post_types = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post-types'] ) ) );
		}

		$request = array(
			'source_types_present' => 1,
			'source_types'         => $source_types,
			'post_types'           => $post_types,
			'content_scope'        => isset( $assoc_args['content-scope'] ) ? (string) $assoc_args['content-scope'] : 'all',
		);

		foreach ( array( 'max-posts' => 'max_posts', 'old-domains' => 'old_domains', 'timeout' => 'timeout', 'max-redirects' => 'max_redirects' ) as $cli_key => $request_key ) {
			if ( isset( $assoc_args[ $cli_key ] ) && '' !== (string) $assoc_args[ $cli_key ] ) {
				$request[ $request_key ] = (string) $assoc_args[ $cli_key ];
			}
		}

		$settings = self::get_request_settings( $request );

		if ( ! isset( $assoc_args['source-types'] ) ) {
			$settings['source_types'] = array_keys( self::get_available_source_types() );
		}

		if ( ! isset( $assoc_args['post-types'] ) ) {
			$settings['post_types'] = array_keys( self::get_available_post_types() );
		}

		if ( empty( $settings['source_types'] ) ) {
			return new WP_Error( 'cli_no_sources', __( 'No valid source type was selected.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( in_array( 'content', $settings['source_types'], true ) && empty( $settings['post_types'] ) ) {
			return new WP_Error( 'cli_no_post_types', __( 'Select at least one public content type when Content is included.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return $settings;
	}

	/**
	 * Run one complete scan without a browser.
	 *
	 * @param array<string,mixed> $settings      Sanitized scan settings.
	 * @param int                 $request_limit Maximum outbound requests.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function cli_run_scan( array $settings, int $request_limit ) {
		$session = self::create_scan_session( $settings, 'standard' );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session['request_limit'] = min( 20000, max( 1, $request_limit ) );
		$batches                  = 0;

		while ( 'running' === $session['status'] && $batches < 5000 ) {
			$session = self::process_scan_batch( $session );
			$batches++;
		}

		return $session;
	}

	/**
	 * Return the actionable rows of one scan as display-ready arrays.
	 *
	 * @param array<string,mixed> $session Completed scan session.
	 * @param int                 $limit   Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cli_issue_rows( array $session, int $limit ): array {
		$issues = array();
		$limit = max( 1, $limit );
		$acknowledged = self::get_acknowledged_issues();

		foreach ( self::build_destination_impact( $session['results'], true ) as $row ) {
			$issues[] = array(
				'url'          => (string) $row['destination_url'],
				'outcome'      => (string) $row['result'],
				'acknowledged' => isset( $acknowledged[ self::issue_key_for_destination( (string) $row['destination_url'], self::result_code_from_label( (string) $row['result'] ) ) ] ) ? 'yes' : 'no',
				'impact'       => (string) $row['impact'],
				'occurrences'  => (int) $row['occurrences'],
				'sources'      => (int) $row['affected_sources'],
				'http'         => (string) $row['http_status_evidence'],
				'final_url'    => (string) $row['effective_final_url'],
				'page_intent'  => (string) $row['intent_evidence'],
			);

			if ( count( $issues ) >= $limit ) {
				break;
			}
		}

		return $issues;
	}

	/**
	 * Run a scan for a WP-CLI command.
	 *
	 * @param array<string,mixed> $assoc_args Associative CLI arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function cli_scan( array $assoc_args ) {
		$settings = self::cli_scan_settings( $assoc_args );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$request_limit = isset( $assoc_args['request-limit'] ) ? absint( $assoc_args['request-limit'] ) : self::MONITOR_DEFAULT_REQUEST_LIMIT;
		$session       = self::cli_run_scan( $settings, $request_limit );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return array(
			'status'         => 'complete' === $session['status'] ? 'complete' : 'partial',
			'sources'        => (int) $session['stats']['sources_processed'],
			'links'          => (int) $session['stats']['links_audited'],
			'requests'       => (int) $session['stats']['http_requests'],
			'issues'         => self::issue_url_counts( $session['results'] ),
			'problem_urls'   => self::cli_issue_rows( $session, isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 200 ),
			'session'        => $session,
		);
	}

	/**
	 * Apply or preview one link replacement from WP-CLI.
	 *
	 * @param string              $from_url  Stored URL to replace.
	 * @param string              $to_url    Replacement URL.
	 * @param bool                $dry_run    Whether to skip writing.
	 * @param array<string,mixed> $assoc_args Associative CLI arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function cli_fix( string $from_url, string $to_url, bool $dry_run, array $assoc_args ) {
		$settings = self::cli_scan_settings( $assoc_args );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$request_limit = isset( $assoc_args['request-limit'] ) ? absint( $assoc_args['request-limit'] ) : self::MONITOR_DEFAULT_REQUEST_LIMIT;
		$session       = self::cli_run_scan( $settings, $request_limit );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( 'complete' !== $session['status'] ) {
			return new WP_Error( 'cli_partial_scan', __( 'The scan is incomplete. Increase the request limit or narrow the scope before repairing links.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$plan = self::build_fix_plan( $session, $from_url, $to_url );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$summary = array(
			'from'          => (string) $plan['from_url'],
			'to'            => (string) $plan['to_url'],
			'sources'       => (int) $plan['sources'],
			'occurrences'   => (int) $plan['occurrences'],
			'items'         => array(),
			'skipped'       => (array) $plan['skipped'],
			'applied'       => false,
			'batch_id'      => '',
		);

		foreach ( $plan['items'] as $item ) {
			$summary['items'][] = array(
				'source'      => (string) $item['source_title'],
				'surface'     => (string) $item['source_type'],
				'storage'     => (string) $item['storage'],
				'occurrences' => (int) $item['occurrences'],
			);
		}

		if ( $dry_run ) {
			return $summary;
		}

		$result = self::apply_fix_plan( $plan );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$summary['applied']  = true;
		$summary['batch_id'] = (string) $result['batch_id'];
		$summary['sources']  = (int) $result['sources'];
		$summary['occurrences'] = (int) $result['occurrences'];
		$summary['skipped']  = array_merge( $summary['skipped'], (array) $result['skipped'] );

		return $summary;
	}

	/**
	 * Undo one recorded repair batch.
	 *
	 * @param string $batch_id Journal batch ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function cli_undo( string $batch_id ) {
		$journal = self::get_fix_journal();

		if ( '' === $batch_id ) {
			$applied = array_values(
				array_filter(
					$journal['batches'],
					static function ( array $batch ): bool {
						return ! isset( $batch['status'] ) || 'undone' !== $batch['status'];
					}
				)
			);

			if ( empty( $applied ) ) {
				return new WP_Error( 'cli_no_batch', __( 'There is no applied repair to undo.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$batch_id = (string) $applied[0]['batch_id'];
		}

		return self::undo_fix_batch( $batch_id );
	}

	/**
	 * List the recorded repair batches.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function cli_repairs(): array {
		$rows = array();

		foreach ( self::get_fix_journal()['batches'] as $batch ) {
			$rows[] = array(
				'batch_id'   => (string) $batch['batch_id'],
				'created'    => gmdate( 'c', (int) $batch['created_at'] ),
				'from'       => (string) $batch['from_url'],
				'to'         => (string) $batch['to_url'],
				'sources'    => count( (array) $batch['items'] ),
				'status'     => isset( $batch['status'] ) ? (string) $batch['status'] : 'applied',
			);
		}

		return $rows;
	}
}
