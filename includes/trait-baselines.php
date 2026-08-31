<?php
/**
 * Baselines behavior for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Baselines {
	/**
	 * Build one portable baseline document from an explicitly selected scan.
	 *
	 * @param array<string,mixed> $session Completed scan session.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build_baseline_from_session( array $session ) {
		if (
			! isset( $session['id'], $session['status'], $session['created_at'], $session['settings'], $session['total_items'], $session['content_done'], $session['request_limit'], $session['stats'], $session['content_items'], $session['results'] ) ||
			'complete' !== $session['status'] ||
			! is_array( $session['settings'] ) ||
			! is_array( $session['stats'] ) ||
			! is_array( $session['content_items'] ) ||
			! is_array( $session['results'] )
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'Only complete, internally consistent scan results can be saved for comparison.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$settings = array(
			'post_types'    => isset( $session['settings']['post_types'] ) && is_array( $session['settings']['post_types'] ) ? array_values( $session['settings']['post_types'] ) : array(),
			'old_domains'   => isset( $session['settings']['old_domains'] ) ? (string) $session['settings']['old_domains'] : '',
			'content_scope' => isset( $session['settings']['content_scope'] ) ? (string) $session['settings']['content_scope'] : '',
			'max_posts'     => isset( $session['settings']['max_posts'] ) ? (int) $session['settings']['max_posts'] : 0,
			'timeout'       => isset( $session['settings']['timeout'] ) ? (float) $session['settings']['timeout'] : 0.0,
			'max_redirects' => isset( $session['settings']['max_redirects'] ) ? (int) $session['settings']['max_redirects'] : -1,
		);
		$stats    = array();
		foreach ( array_keys( self::empty_stats() ) as $stat_key ) {
			$stats[ $stat_key ] = isset( $session['stats'][ $stat_key ] ) ? (int) $session['stats'][ $stat_key ] : -1;
		}

		$request_limit = max( 0, (int) $session['request_limit'] );
		$requests      = max( 0, (int) $stats['http_requests'] );
		$extensions    = isset( $session['request_allowance_extensions'] ) ? max( 0, (int) $session['request_allowance_extensions'] ) : 0;
		$created_at    = max( 0, (int) $session['created_at'] );
		$baseline      = array(
			'format'         => self::BASELINE_FORMAT,
			'schema_version' => self::BASELINE_SCHEMA_VERSION,
			'baseline_id'    => wp_generate_uuid4(),
			'plugin_version' => self::VERSION,
			'site_url'       => self::normalized_site_url(),
			'scan_id'        => (string) $session['id'],
			'scan_utc'       => gmdate( 'Y-m-d\TH:i:s\Z', $created_at ),
			'saved_utc'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'settings'       => $settings,
			'scope'          => array(
				'post_types'    => $settings['post_types'],
				'content_scope' => $settings['content_scope'],
				'content_limit' => 'all' === $settings['content_scope'] ? null : $settings['max_posts'],
				'total_items'   => max( 0, (int) $session['total_items'] ),
			),
			'completion'     => array(
				'status'                       => 'complete',
				'complete'                     => true,
				'content_done'                 => (bool) $session['content_done'],
				'request_limit'                => $request_limit,
				'http_requests'                => $requests,
				'request_allowance_remaining'  => max( 0, $request_limit - $requests ),
				'request_allowance_extensions' => $extensions,
			),
			'stats'          => $stats,
			'content_items'  => array_values( $session['content_items'] ),
			'results'        => array_values( $session['results'] ),
		);

		$validated = self::validate_baseline( $baseline, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$encoded = wp_json_encode( $validated, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BASELINE_FILE_SIZE ) {
			return new WP_Error( 'baseline_too_large', __( 'This scan is too large to save. The saved-scan limit is 20 MB.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return $validated;
	}

	/**
	 * Validate and normalize a baseline document.
	 *
	 * @param array<string,mixed> $baseline      Decoded baseline.
	 * @param bool                $validate_site Whether the site URL must match this site.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_baseline( array $baseline, bool $validate_site = true ) {
		$top_keys = array( 'format', 'schema_version', 'baseline_id', 'plugin_version', 'site_url', 'scan_id', 'scan_utc', 'saved_utc', 'settings', 'scope', 'completion', 'stats', 'content_items', 'results' );
		if ( ! self::array_has_exact_keys( $baseline, $top_keys ) || self::BASELINE_FORMAT !== $baseline['format'] || self::BASELINE_SCHEMA_VERSION !== $baseline['schema_version'] ) {
			return new WP_Error( 'baseline_invalid_schema', __( 'This saved-scan file format is not supported.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if (
			! self::is_uuid_string( $baseline['baseline_id'] ) ||
			! self::is_uuid_string( $baseline['scan_id'] ) ||
			! is_string( $baseline['plugin_version'] ) ||
			! preg_match( '/^[0-9A-Za-z.+-]{1,32}$/', $baseline['plugin_version'] ) ||
			! self::is_utc_timestamp( $baseline['scan_utc'] ) ||
			! self::is_utc_timestamp( $baseline['saved_utc'] ) ||
			! is_string( $baseline['site_url'] ) ||
			strlen( $baseline['site_url'] ) > 2048
		) {
			return new WP_Error( 'baseline_invalid_schema', __( 'The saved-scan file details are invalid.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$site_url = self::normalize_site_url_value( $baseline['site_url'] );
		if ( '' === $site_url ) {
			return new WP_Error( 'baseline_invalid_schema', __( 'The saved-scan site URL is invalid.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		if ( $validate_site && ! hash_equals( self::normalized_site_url(), $site_url ) ) {
			return new WP_Error( 'baseline_wrong_site', __( 'This saved scan belongs to a different WordPress site.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$settings = self::validate_baseline_settings( $baseline['settings'] );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$scope_keys = array( 'post_types', 'content_scope', 'content_limit', 'total_items' );
		$scope      = $baseline['scope'];
		if (
			! is_array( $scope ) ||
			! self::array_has_exact_keys( $scope, $scope_keys ) ||
			! is_array( $scope['post_types'] ) ||
			$scope['post_types'] !== $settings['post_types'] ||
			$scope['content_scope'] !== $settings['content_scope'] ||
			! is_int( $scope['total_items'] ) ||
			$scope['total_items'] < 0 ||
			$scope['total_items'] > self::MAX_BASELINE_CONTENT_ITEMS
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved content scope does not match the scan settings.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		$expected_limit = 'all' === $settings['content_scope'] ? null : $settings['max_posts'];
		if ( $expected_limit !== $scope['content_limit'] ) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved content limit does not match the scan scope.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$stats = self::validate_baseline_stats( $baseline['stats'] );
		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$completion_keys = array( 'status', 'complete', 'content_done', 'request_limit', 'http_requests', 'request_allowance_remaining', 'request_allowance_extensions' );
		$completion      = $baseline['completion'];
		if (
			! is_array( $completion ) ||
			! self::array_has_exact_keys( $completion, $completion_keys ) ||
			'complete' !== $completion['status'] ||
			true !== $completion['complete'] ||
			true !== $completion['content_done'] ||
			! is_int( $completion['request_limit'] ) ||
			! is_int( $completion['http_requests'] ) ||
			! is_int( $completion['request_allowance_remaining'] ) ||
			! is_int( $completion['request_allowance_extensions'] ) ||
			$completion['request_limit'] < self::INITIAL_REQUEST_ALLOWANCE ||
			$completion['http_requests'] < 0 ||
			$completion['http_requests'] > $completion['request_limit'] ||
			$completion['request_allowance_extensions'] < 0 ||
			$completion['request_limit'] !== self::INITIAL_REQUEST_ALLOWANCE + ( self::REQUEST_ALLOWANCE_INCREMENT * $completion['request_allowance_extensions'] ) ||
			$completion['request_allowance_remaining'] !== $completion['request_limit'] - $completion['http_requests'] ||
			$completion['http_requests'] !== $stats['http_requests']
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved completion or request-limit results are inconsistent.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if (
			! is_array( $baseline['content_items'] ) ||
			! self::is_list_array( $baseline['content_items'] ) ||
			count( $baseline['content_items'] ) > self::MAX_BASELINE_CONTENT_ITEMS ||
			! is_array( $baseline['results'] ) ||
			! self::is_list_array( $baseline['results'] ) ||
			count( $baseline['results'] ) > self::MAX_BASELINE_RESULTS
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved results are invalid or exceed their limits.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$content_items = array();
		foreach ( $baseline['content_items'] as $item ) {
			$validated_item = self::validate_baseline_content_item( $item );
			if ( is_wp_error( $validated_item ) ) {
				return $validated_item;
			}
			$content_items[] = $validated_item;
		}

		$results          = array();
		$actionable_count = 0;
		foreach ( $baseline['results'] as $row ) {
			$validated_row = self::validate_baseline_result_row( $row );
			if ( is_wp_error( $validated_row ) ) {
				return $validated_row;
			}
			if ( 'ok' !== $validated_row['result_code'] ) {
				$actionable_count++;
			}
			$results[] = $validated_row;
		}

		if (
			count( $content_items ) !== $stats['content_items_processed'] ||
			$scope['total_items'] !== $stats['content_items_processed'] ||
			count( $results ) !== $stats['links_audited'] ||
			$actionable_count !== $stats['actionable_issues']
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved totals do not match the saved link results.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return array(
			'format'         => self::BASELINE_FORMAT,
			'schema_version' => self::BASELINE_SCHEMA_VERSION,
			'baseline_id'    => (string) $baseline['baseline_id'],
			'plugin_version' => (string) $baseline['plugin_version'],
			'site_url'       => $site_url,
			'scan_id'        => (string) $baseline['scan_id'],
			'scan_utc'       => (string) $baseline['scan_utc'],
			'saved_utc'      => (string) $baseline['saved_utc'],
			'settings'       => $settings,
			'scope'          => array(
				'post_types'    => $settings['post_types'],
				'content_scope' => (string) $scope['content_scope'],
				'content_limit' => $scope['content_limit'],
				'total_items'   => (int) $scope['total_items'],
			),
			'completion'     => array(
				'status'                       => 'complete',
				'complete'                     => true,
				'content_done'                 => true,
				'request_limit'                => (int) $completion['request_limit'],
				'http_requests'                => (int) $completion['http_requests'],
				'request_allowance_remaining'  => (int) $completion['request_allowance_remaining'],
				'request_allowance_extensions' => (int) $completion['request_allowance_extensions'],
			),
			'stats'          => $stats,
			'content_items'  => $content_items,
			'results'        => $results,
		);
	}

	/**
	 * Validate baseline scan settings without consulting current post types.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_baseline_settings( $settings ) {
		$keys = array( 'post_types', 'old_domains', 'content_scope', 'max_posts', 'timeout', 'max_redirects' );
		if ( ! is_array( $settings ) || ! self::array_has_exact_keys( $settings, $keys ) || ! is_array( $settings['post_types'] ) || ! self::is_list_array( $settings['post_types'] ) ) {
			return new WP_Error( 'baseline_invalid_schema', __( 'The saved scan settings are invalid.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$post_types = array();
		foreach ( $settings['post_types'] as $post_type ) {
			if ( ! is_string( $post_type ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $post_type ) || in_array( $post_type, $post_types, true ) ) {
				return new WP_Error( 'baseline_invalid_evidence', __( 'The saved scan contains an invalid or duplicate content type.', 'indexlane-redirect-internal-link-auditor' ) );
			}
			$post_types[] = $post_type;
		}

		if (
			empty( $post_types ) ||
			! is_string( $settings['old_domains'] ) ||
			strlen( $settings['old_domains'] ) > 20000 ||
			! in_array( $settings['content_scope'], array( 'all', 'limit' ), true ) ||
			! is_int( $settings['max_posts'] ) ||
			$settings['max_posts'] < 1 ||
			$settings['max_posts'] > self::MAX_NUMERIC_CONTENT_ITEMS ||
			! is_numeric( $settings['timeout'] ) ||
			(float) $settings['timeout'] < 1 ||
			(float) $settings['timeout'] > 15 ||
			! is_int( $settings['max_redirects'] ) ||
			$settings['max_redirects'] < 0 ||
			$settings['max_redirects'] > 10
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved scan settings contain invalid values.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return array(
			'post_types'    => $post_types,
			'old_domains'   => (string) $settings['old_domains'],
			'content_scope' => (string) $settings['content_scope'],
			'max_posts'     => (int) $settings['max_posts'],
			'timeout'       => (float) $settings['timeout'],
			'max_redirects' => (int) $settings['max_redirects'],
		);
	}

	/**
	 * Validate baseline counters.
	 *
	 * @param mixed $stats Raw counters.
	 * @return array<string,int>|WP_Error
	 */
	private static function validate_baseline_stats( $stats ) {
		$keys = array_keys( self::empty_stats() );
		if ( ! is_array( $stats ) || ! self::array_has_exact_keys( $stats, $keys ) ) {
			return new WP_Error( 'baseline_invalid_schema', __( 'The saved scan totals are invalid.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$normalized = array();
		foreach ( $keys as $key ) {
			if ( ! is_int( $stats[ $key ] ) || $stats[ $key ] < 0 || $stats[ $key ] > 1000000000 ) {
				return new WP_Error( 'baseline_invalid_evidence', __( 'The saved scan contains an invalid total.', 'indexlane-redirect-internal-link-auditor' ) );
			}
			$normalized[ $key ] = (int) $stats[ $key ];
		}

		return $normalized;
	}

	/**
	 * Validate one saved content item.
	 *
	 * @param mixed $item Raw item.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_baseline_content_item( $item ) {
		$keys = array( 'id', 'title', 'type', 'url', 'edit_url' );
		if (
			! is_array( $item ) ||
			! self::array_has_exact_keys( $item, $keys ) ||
			! is_int( $item['id'] ) ||
			$item['id'] <= 0 ||
			! self::is_bounded_string( $item['title'], 1000 ) ||
			! self::is_bounded_string( $item['type'], 200 ) ||
			! self::is_bounded_string( $item['url'], 2048 ) ||
			! self::is_bounded_string( $item['edit_url'], 2048 )
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved scan contains an invalid content item.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return array(
			'id'       => (int) $item['id'],
			'title'    => (string) $item['title'],
			'type'     => (string) $item['type'],
			'url'      => (string) $item['url'],
			'edit_url' => (string) $item['edit_url'],
		);
	}

	/**
	 * Validate one occurrence row.
	 *
	 * @param mixed $row Raw row.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_baseline_result_row( $row ) {
		$keys = array( 'source_id', 'source_title', 'source_type', 'source_url', 'source_edit_url', 'linked_url', 'http_status', 'redirect_count', 'final_url', 'warning', 'anchor_text', 'result', 'result_code', 'is_same_site', 'direct_target_id', 'final_target_id', 'coverage_target_id', 'link_kind_code' );
		if ( ! is_array( $row ) || ! self::array_has_exact_keys( $row, $keys ) ) {
			return new WP_Error( 'baseline_invalid_schema', __( 'A saved link result contains unsupported fields.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		foreach ( array( 'source_id', 'redirect_count', 'direct_target_id', 'final_target_id', 'coverage_target_id' ) as $integer_key ) {
			if ( ! is_int( $row[ $integer_key ] ) || $row[ $integer_key ] < 0 ) {
				return new WP_Error( 'baseline_invalid_evidence', __( 'A saved link result contains an invalid number.', 'indexlane-redirect-internal-link-auditor' ) );
			}
		}

		$string_limits = array(
			'source_title'    => 1000,
			'source_type'     => 200,
			'source_url'      => 2048,
			'source_edit_url' => 2048,
			'linked_url'      => 2048,
			'http_status'     => 512,
			'final_url'       => 2048,
			'warning'         => 4096,
			'anchor_text'     => 2000,
			'result'          => 200,
		);
		foreach ( $string_limits as $string_key => $limit ) {
			if ( ! self::is_bounded_string( $row[ $string_key ], $limit ) ) {
				return new WP_Error( 'baseline_invalid_evidence', __( 'A saved link result contains invalid text.', 'indexlane-redirect-internal-link-auditor' ) );
			}
		}

		if (
			( '' !== $row['http_status'] && ! preg_match( '/^[1-5][0-9]{2}(?: -> [1-5][0-9]{2})*$/', $row['http_status'] ) ) ||
			! is_string( $row['result_code'] ) ||
			! in_array( $row['result_code'], array( 'ok', 'warning', 'needs_review', 'blocked', 'error' ), true ) ||
			! is_bool( $row['is_same_site'] ) ||
			! is_string( $row['link_kind_code'] ) ||
			! in_array( $row['link_kind_code'], array( 'direct', 'redirected' ), true ) ||
			( $row['redirect_count'] > 0 ? 'redirected' : 'direct' ) !== $row['link_kind_code']
		) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'A saved link result contains inconsistent status or outcome data.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$normalized = array();
		foreach ( $keys as $key ) {
			$normalized[ $key ] = $row[ $key ];
		}

		return $normalized;
	}

	/**
	 * Decode and validate imported JSON.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function parse_baseline_json( string $json ) {
		if ( '' === $json || strlen( $json ) > self::MAX_BASELINE_FILE_SIZE ) {
			return new WP_Error( 'baseline_too_large', __( 'The saved-scan file is empty or larger than 20 MB.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$decoded = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error( 'baseline_invalid_json', __( 'The uploaded saved-scan file is not valid JSON.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return self::validate_baseline( $decoded, true );
	}

	/**
	 * Read one uploaded JSON baseline without retaining the upload.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function read_uploaded_baseline() {
		if ( ! isset( $_FILES['baseline_file'] ) || ! is_array( $_FILES['baseline_file'] ) ) {
			return new WP_Error( 'baseline_missing_file', __( 'No saved-scan file was uploaded.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$file = $_FILES['baseline_file'];
		if (
			! isset( $file['error'], $file['size'], $file['name'], $file['tmp_name'] ) ||
			UPLOAD_ERR_OK !== (int) $file['error'] ||
			! is_scalar( $file['name'] ) ||
			! is_scalar( $file['tmp_name'] )
		) {
			return new WP_Error( 'baseline_missing_file', __( 'The saved-scan upload did not complete.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$size     = (int) $file['size'];
		$name     = (string) $file['name'];
		$tmp_name = (string) $file['tmp_name'];
		if ( $size <= 0 || $size > self::MAX_BASELINE_FILE_SIZE ) {
			return new WP_Error( 'baseline_too_large', __( 'The saved-scan upload is empty or larger than 20 MB.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		if ( 'json' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'baseline_invalid_json', __( 'The selected upload is not a valid JSON file.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$json = file_get_contents( $tmp_name );
		if ( ! is_string( $json ) || strlen( $json ) !== $size ) {
			return new WP_Error( 'baseline_invalid_json', __( 'WordPress could not read the complete saved-scan upload.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return self::parse_baseline_json( $json );
	}

	/**
	 * Load the current administrator's valid site-specific baseline.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function get_saved_baseline(): ?array {
		$baseline = get_user_option( self::BASELINE_USER_OPTION, get_current_user_id() );
		if ( ! is_array( $baseline ) ) {
			return null;
		}

		$validated = self::validate_baseline( $baseline, true );
		return is_array( $validated ) ? $validated : null;
	}

	/**
	 * Persist one validated baseline for the current administrator.
	 *
	 * @param array<string,mixed> $baseline Validated baseline.
	 */
	private static function save_baseline( array $baseline ): bool {
		$validated = self::validate_baseline( $baseline, true );
		if ( ! is_array( $validated ) ) {
			return false;
		}

		$current = get_user_option( self::BASELINE_USER_OPTION, get_current_user_id() );
		if ( $current === $validated ) {
			return true;
		}

		return false !== update_user_option( get_current_user_id(), self::BASELINE_USER_OPTION, $validated, false );
	}

	/**
	 * Revalidate saved settings against post types currently available on this site.
	 *
	 * @param array<string,mixed> $baseline Valid baseline.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function verification_settings_from_baseline( array $baseline ) {
		$available = array_keys( self::get_available_post_types() );
		$missing   = array_values( array_diff( $baseline['settings']['post_types'], $available ) );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'baseline_missing_post_type',
				sprintf(
					/* translators: %s: comma-separated post type slugs unavailable on the current site */
					__( 'The fix check cannot use the saved scope because these public content types are unavailable: %s.', 'indexlane-redirect-internal-link-auditor' ),
					implode( ', ', $missing )
				)
			);
		}

		$settings = self::get_request_settings( $baseline['settings'] );
		if ( $settings['post_types'] !== $baseline['settings']['post_types'] ) {
			return new WP_Error( 'baseline_invalid_evidence', __( 'The saved scan scope can no longer be reproduced exactly.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return $settings;
	}

	/**
	 * Return a deterministic fingerprint for one canonical baseline.
	 *
	 * @param array<string,mixed> $baseline Valid baseline.
	 */
	private static function baseline_fingerprint( array $baseline ): string {
		$encoded = wp_json_encode( $baseline, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	/**
	 * Normalize the current home URL for strict baseline ownership.
	 */
	private static function normalized_site_url(): string {
		return self::normalize_site_url_value( home_url( '/' ) );
	}

	/**
	 * Normalize a site URL while preserving scheme, non-default port, and subdirectory.
	 */
	private static function normalize_site_url_value( string $url ): string {
		$normalized = self::normalize_destination_for_impact( $url );
		return '/' === substr( $normalized, -1 ) ? rtrim( $normalized, '/' ) : $normalized;
	}

	/**
	 * Whether an array contains exactly the allowed keys.
	 *
	 * @param array<mixed>      $value Array to inspect.
	 * @param array<int,string> $keys  Required keys.
	 */
	private static function array_has_exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );

		return $actual === $keys;
	}

	/**
	 * PHP 7.4-compatible list-array check.
	 */
	private static function is_list_array( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value;
	}

	/**
	 * Validate one bounded JSON string value.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_bounded_string( $value, int $maximum_length ): bool {
		return is_string( $value ) && strlen( $value ) <= $maximum_length && false === strpos( $value, "\0" );
	}

	/**
	 * Validate an RFC 4122-style UUID used by scan and baseline identity.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_uuid_string( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Validate a canonical UTC timestamp.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_utc_timestamp( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) {
			return false;
		}

		$timestamp = strtotime( $value );
		return false !== $timestamp && gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) === $value;
	}

	/**
	 * Build a comparison for one completed verification session.
	 *
	 * @param array<string,mixed> $session Completed verification session.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function get_session_comparison( array $session ) {
		if ( 'complete' !== $session['status'] || ! isset( $session['scan_mode'] ) || 'verification' !== $session['scan_mode'] ) {
			return new WP_Error( 'comparison_unavailable', __( 'A comparison is available only after a fix check completes.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$baseline = self::get_saved_baseline();
		if ( null === $baseline ) {
			return new WP_Error( 'comparison_unavailable', __( 'The saved scan used by this fix check is no longer available.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$baseline_id  = isset( $session['baseline_id'] ) ? (string) $session['baseline_id'] : '';
		$fingerprint  = isset( $session['baseline_fingerprint'] ) ? (string) $session['baseline_fingerprint'] : '';
		$current_hash = self::baseline_fingerprint( $baseline );
		if (
			'' === $baseline_id ||
			'' === $fingerprint ||
			'' === $current_hash ||
			! hash_equals( (string) $baseline['baseline_id'], $baseline_id ) ||
			! hash_equals( $current_hash, $fingerprint )
		) {
			return new WP_Error( 'comparison_unavailable', __( 'The saved scan changed after this fix check started, so the plugin will not show an inaccurate comparison.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return self::build_scan_comparison( $baseline['results'], $session['results'] );
	}

	/**
	 * Compare baseline and verification occurrence evidence by destination.
	 *
	 * @param array<int,array<string,mixed>> $baseline_results     Baseline rows.
	 * @param array<int,array<string,mixed>> $verification_results Verification rows.
	 * @return array<string,mixed>
	 */
	private static function build_scan_comparison( array $baseline_results, array $verification_results ): array {
		$old_by_destination = self::build_destination_comparison_evidence( $baseline_results );
		$new_by_destination = self::build_destination_comparison_evidence( $verification_results );
		$destination_keys   = array_unique( array_merge( array_keys( $old_by_destination ), array_keys( $new_by_destination ) ) );
		$rows               = array();
		$summary            = array(
			'new'      => 0,
			'changed'  => 0,
			'resolved' => 0,
			'still'    => 0,
		);

		foreach ( $destination_keys as $destination_key ) {
			$old       = isset( $old_by_destination[ $destination_key ] ) ? $old_by_destination[ $destination_key ] : null;
			$new       = isset( $new_by_destination[ $destination_key ] ) ? $new_by_destination[ $destination_key ] : null;
			$old_issue = is_array( $old ) && 'ok' !== $old['result_code'];
			$new_issue = is_array( $new ) && 'ok' !== $new['result_code'];
			if ( ! $old_issue && ! $new_issue ) {
				continue;
			}

			$changed_fields = self::comparison_changed_fields( $old, $new );
			if ( ! $old_issue && $new_issue ) {
				$category  = 'new';
				$direction = 'new';
			} elseif ( $old_issue && ! $new_issue ) {
				$category  = 'resolved';
				$direction = 'resolved';
			} elseif ( ! empty( $changed_fields ) ) {
				$category  = 'changed';
				$direction = self::comparison_change_direction( $old, $new );
			} else {
				$category  = 'still';
				$direction = 'unchanged';
			}

			$summary[ $category ]++;
			$rows[] = array(
				'destination_url' => is_array( $new ) ? $new['destination_url'] : $old['destination_url'],
				'category'        => $category,
				'category_label'  => self::comparison_category_label( $category ),
				'direction'       => $direction,
				'direction_label' => self::comparison_direction_label( $direction ),
				'changed_fields'  => $changed_fields,
				'changed_labels'  => self::comparison_changed_field_labels( $changed_fields ),
				'old'             => $old,
				'new'             => $new,
			);
		}

		$category_order  = array( 'new' => 0, 'changed' => 1, 'still' => 2, 'resolved' => 3 );
		$direction_order = array( 'worsened' => 0, 'changed' => 1, 'improved' => 2, 'new' => 0, 'unchanged' => 0, 'resolved' => 0 );
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $category_order, $direction_order ): int {
				if ( $category_order[ $left['category'] ] !== $category_order[ $right['category'] ] ) {
					return $category_order[ $left['category'] ] <=> $category_order[ $right['category'] ];
				}
				if ( $direction_order[ $left['direction'] ] !== $direction_order[ $right['direction'] ] ) {
					return $direction_order[ $left['direction'] ] <=> $direction_order[ $right['direction'] ];
				}

				$left_evidence  = is_array( $left['new'] ) ? $left['new'] : $left['old'];
				$right_evidence = is_array( $right['new'] ) ? $right['new'] : $right['old'];
				if ( $left_evidence['result_rank'] !== $right_evidence['result_rank'] ) {
					return $right_evidence['result_rank'] <=> $left_evidence['result_rank'];
				}
				if ( $left_evidence['affected_source_count'] !== $right_evidence['affected_source_count'] ) {
					return $right_evidence['affected_source_count'] <=> $left_evidence['affected_source_count'];
				}

				return strcmp( $left['destination_url'], $right['destination_url'] );
			}
		);

		return array(
			'summary' => $summary,
			'rows'    => $rows,
		);
	}

	/**
	 * Aggregate every destination, including healthy evidence needed for regressions.
	 *
	 * @param array<int,array<string,mixed>> $results Occurrence rows.
	 * @return array<string,array<string,mixed>>
	 */
	private static function build_destination_comparison_evidence( array $results ): array {
		$groups = array();

		foreach ( $results as $row ) {
			$raw_destination = isset( $row['linked_url'] ) ? trim( (string) $row['linked_url'] ) : '';
			if ( '' === $raw_destination ) {
				continue;
			}
			$destination = self::normalize_destination_for_impact( $raw_destination );
			$group_key   = '' !== $destination ? 'url:' . $destination : 'raw:' . $raw_destination;
			if ( '' === $destination ) {
				$destination = $raw_destination;
			}

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'destination_url' => $destination,
					'http_statuses'   => array(),
					'redirect_count'  => 0,
					'final_urls'      => array(),
					'result_code'     => 'ok',
					'result_rank'     => self::result_code_rank( 'ok' ),
					'occurrence_count' => 0,
					'source_keys'     => array(),
				);
			}

			$groups[ $group_key ]['occurrence_count']++;
			$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] ) ? max( 0, (int) $row['redirect_count'] ) : 0;
			$groups[ $group_key ]['redirect_count'] = max( $groups[ $group_key ]['redirect_count'], $redirect_count );

			$status = isset( $row['http_status'] ) ? trim( (string) $row['http_status'] ) : '';
			if ( '' !== $status ) {
				$groups[ $group_key ]['http_statuses'][ $status ] = true;
			}

			$raw_final = isset( $row['final_url'] ) ? trim( (string) $row['final_url'] ) : '';
			if ( '' !== $raw_final ) {
				$final = self::normalize_destination_for_impact( $raw_final );
				$groups[ $group_key ]['final_urls'][ '' !== $final ? $final : $raw_final ] = true;
			}

			$source_id  = isset( $row['source_id'] ) ? max( 0, (int) $row['source_id'] ) : 0;
			$source_key = $source_id > 0 ? 'id:' . $source_id : self::normalize_destination_for_impact( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );
			if ( '' === $source_key ) {
				$source_key = 'source:' . ( isset( $row['source_title'] ) ? trim( (string) $row['source_title'] ) : '' );
			}
			$groups[ $group_key ]['source_keys'][ $source_key ] = true;

			$result_code = isset( $row['result_code'] ) && in_array( $row['result_code'], array( 'ok', 'warning', 'needs_review', 'blocked', 'error' ), true )
				? (string) $row['result_code']
				: self::result_code_from_label( isset( $row['result'] ) ? (string) $row['result'] : '' );
			$result_rank = self::result_code_rank( $result_code );
			if ( $result_rank > $groups[ $group_key ]['result_rank'] ) {
				$groups[ $group_key ]['result_code'] = $result_code;
				$groups[ $group_key ]['result_rank'] = $result_rank;
			}
		}

		$evidence = array();
		foreach ( $groups as $group_key => $group ) {
			$statuses  = array_keys( $group['http_statuses'] );
			$final_urls = array_keys( $group['final_urls'] );
			sort( $statuses, SORT_STRING );
			sort( $final_urls, SORT_STRING );

			$evidence[ $group_key ] = array(
				'destination_url'       => $group['destination_url'],
				'http_status_chain'     => implode( ' | ', $statuses ),
				'redirect_count'        => (int) $group['redirect_count'],
				'final_url'             => implode( ' | ', $final_urls ),
				'result_code'           => $group['result_code'],
				'result_rank'           => (int) $group['result_rank'],
				'result_severity'       => self::result_label_for_code( $group['result_code'] ),
				'occurrence_count'      => (int) $group['occurrence_count'],
				'affected_source_count' => count( $group['source_keys'] ),
			);
		}

		return $evidence;
	}

	/**
	 * Rank stable result codes from healthy to most severe.
	 */
	private static function result_code_rank( string $code ): int {
		$ranks = array(
			'ok'           => 1,
			'warning'      => 2,
			'needs_review' => 3,
			'blocked'      => 4,
			'error'        => 5,
		);

		return isset( $ranks[ $code ] ) ? $ranks[ $code ] : 3;
	}

	/**
	 * List required evidence fields that differ.
	 *
	 * @param array<string,mixed>|null $old Baseline evidence.
	 * @param array<string,mixed>|null $new Verification evidence.
	 * @return array<int,string>
	 */
	private static function comparison_changed_fields( ?array $old, ?array $new ): array {
		$fields = array( 'http_status_chain', 'redirect_count', 'final_url', 'result_code', 'occurrence_count', 'affected_source_count' );
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return $fields;
		}

		return array_values(
			array_filter(
				$fields,
				static function ( string $field ) use ( $old, $new ): bool {
					return $old[ $field ] !== $new[ $field ];
				}
			)
		);
	}

	/**
	 * Describe whether changed issue evidence worsened, improved, or changed laterally.
	 *
	 * @param array<string,mixed> $old Baseline evidence.
	 * @param array<string,mixed> $new Verification evidence.
	 */
	private static function comparison_change_direction( array $old, array $new ): string {
		if ( $new['result_rank'] > $old['result_rank'] ) {
			return 'worsened';
		}
		if ( $new['result_rank'] < $old['result_rank'] ) {
			return 'improved';
		}

		$worse  = $new['redirect_count'] > $old['redirect_count'] || $new['occurrence_count'] > $old['occurrence_count'] || $new['affected_source_count'] > $old['affected_source_count'];
		$better = $new['redirect_count'] < $old['redirect_count'] || $new['occurrence_count'] < $old['occurrence_count'] || $new['affected_source_count'] < $old['affected_source_count'];
		if ( $worse && ! $better ) {
			return 'worsened';
		}
		if ( $better && ! $worse ) {
			return 'improved';
		}

		return 'changed';
	}

	/**
	 * Translate a comparison category.
	 */
	private static function comparison_category_label( string $category ): string {
		switch ( $category ) {
			case 'new':
				return __( 'New issue', 'indexlane-redirect-internal-link-auditor' );
			case 'changed':
				return __( 'Changed issue', 'indexlane-redirect-internal-link-auditor' );
			case 'resolved':
				return __( 'Resolved', 'indexlane-redirect-internal-link-auditor' );
			default:
				return __( 'Still present', 'indexlane-redirect-internal-link-auditor' );
		}
	}

	/**
	 * Translate comparison direction.
	 */
	private static function comparison_direction_label( string $direction ): string {
		switch ( $direction ) {
			case 'new':
				return __( 'New issue', 'indexlane-redirect-internal-link-auditor' );
			case 'resolved':
				return __( 'Resolved', 'indexlane-redirect-internal-link-auditor' );
			case 'worsened':
				return __( 'Worsened', 'indexlane-redirect-internal-link-auditor' );
			case 'improved':
				return __( 'Improved but still present', 'indexlane-redirect-internal-link-auditor' );
			case 'changed':
				return __( 'Changed behavior', 'indexlane-redirect-internal-link-auditor' );
			default:
				return __( 'No change', 'indexlane-redirect-internal-link-auditor' );
		}
	}

	/**
	 * Translate changed evidence field names.
	 *
	 * @param array<int,string> $fields Field codes.
	 * @return array<int,string>
	 */
	private static function comparison_changed_field_labels( array $fields ): array {
		$labels = array(
			'http_status_chain'     => __( 'HTTP status chain', 'indexlane-redirect-internal-link-auditor' ),
			'redirect_count'        => __( 'redirect count', 'indexlane-redirect-internal-link-auditor' ),
			'final_url'             => __( 'final URL', 'indexlane-redirect-internal-link-auditor' ),
			'result_code'           => __( 'outcome', 'indexlane-redirect-internal-link-auditor' ),
			'occurrence_count'      => __( 'times linked', 'indexlane-redirect-internal-link-auditor' ),
			'affected_source_count' => __( 'content items affected', 'indexlane-redirect-internal-link-auditor' ),
		);

		$output = array();
		foreach ( $fields as $field ) {
			if ( isset( $labels[ $field ] ) ) {
				$output[] = $labels[ $field ];
			}
		}

		return $output;
	}

	/**
	 * Human-readable saved scope.
	 *
	 * @param array<string,mixed> $baseline Baseline.
	 */
	private static function baseline_scope_label( array $baseline ): string {
		$labels = array();
		foreach ( $baseline['settings']['post_types'] as $post_type ) {
			$object   = get_post_type_object( $post_type );
			$labels[] = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $post_type;
		}

		if ( 'all' === $baseline['settings']['content_scope'] ) {
			return sprintf(
				/* translators: %s: comma-separated content type labels */
				__( 'All published content: %s', 'indexlane-redirect-internal-link-auditor' ),
				implode( ', ', $labels )
			);
		}

		return sprintf(
			/* translators: 1: maximum content item count, 2: comma-separated content type labels */
			__( 'Newest %1$d content items: %2$s', 'indexlane-redirect-internal-link-auditor' ),
			(int) $baseline['settings']['max_posts'],
			implode( ', ', $labels )
		);
	}

	/**
	 * Format the saved scan time using this site's date and time settings.
	 *
	 * @param array<string,mixed> $baseline Baseline.
	 */
	private static function baseline_scan_date_label( array $baseline ): string {
		$scan_time = (string) $baseline['scan_utc'];
		$timestamp = strtotime( $scan_time );
		if ( false === $timestamp ) {
			return $scan_time;
		}

		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$time_format = (string) get_option( 'time_format', 'g:i a' );

		return wp_date( $date_format . ' ' . $time_format, $timestamp );
	}

	/**
	 * Count unique saved URLs that need attention.
	 *
	 * @param array<string,mixed> $baseline Baseline.
	 */
	private static function baseline_issue_url_count( array $baseline ): int {
		return self::issue_url_count( $baseline['results'] );
	}

	/**
	 * Count unique URLs with a non-OK result.
	 *
	 * @param array<int,array<string,mixed>> $results Scan result rows.
	 */
	private static function issue_url_count( array $results ): int {
		$destinations = self::build_destination_comparison_evidence( $results );
		$issues       = array_filter(
			$destinations,
			static function ( array $evidence ): bool {
				return 'ok' !== $evidence['result_code'];
			}
		);

		return count( $issues );
	}

	/**
	 * Read one explicit confirmation checkbox.
	 */
	private static function posted_confirmation( string $name ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The only caller verifies the request nonce first.
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) && '1' === (string) wp_unslash( $_POST[ $name ] );
	}

	/**
	 * Convert an internal baseline error to a fixed redirect notice code.
	 */
	private static function baseline_error_notice_code( WP_Error $error ): string {
		$code = $error->get_error_code();
		switch ( $code ) {
			case 'baseline_missing_file':
				return 'missing_file';
			case 'baseline_too_large':
				return 'file_too_large';
			case 'baseline_invalid_json':
				return 'invalid_json';
			case 'baseline_invalid_schema':
				return 'invalid_schema';
			case 'baseline_wrong_site':
				return 'wrong_site';
			default:
				return 'invalid_evidence';
		}
	}

	/**
	 * Redirect to the baseline panel after a synchronous management action.
	 */
	private static function redirect_after_baseline_action( string $notice_code ): void {
		$url = add_query_arg( array( 'baseline_notice' => sanitize_key( $notice_code ) ), self::admin_page_url() );
		wp_safe_redirect( $url . '#indexlane-rila-baseline' );
		exit;
	}

	/**
	 * Send one versioned baseline JSON document and terminate.
	 *
	 * @param array<string,mixed> $baseline Valid baseline.
	 */
	private static function send_baseline_json( array $baseline ): void {
		$json = wp_json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'WordPress could not create the saved-scan download.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		$scan_time = preg_replace( '/[^0-9]/', '', (string) $baseline['scan_utc'] );
		$scan_time = is_string( $scan_time ) && '' !== $scan_time ? $scan_time : gmdate( 'YmdHis' );
		header( 'Content-Disposition: attachment; filename=indexlane-redirect-internal-link-auditor-saved-scan-' . $scan_time . '.json' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated JSON download, not HTML.
		exit;
	}

	/**
	 * Send a verification comparison CSV and terminate.
	 *
	 * @param array<string,mixed> $comparison Comparison data.
	 */
	private static function send_comparison_csv( array $comparison ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=indexlane-redirect-internal-link-auditor-comparison-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		foreach ( self::build_comparison_csv_rows( $comparison ) as $csv_row ) {
			fputcsv( $output, $csv_row, ',', '"', '' );
		}

		exit;
	}

	/**
	 * Build safe comparison CSV rows.
	 *
	 * @param array<string,mixed> $comparison Comparison data.
	 * @return array<int,array<int,string>>
	 */
	private static function build_comparison_csv_rows( array $comparison ): array {
		$rows = array(
			array(
				self::csv_safe( __( 'Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Change', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Changed Fields', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan HTTP Status Chain', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan HTTP Status Chain', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan Redirect Count', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan Redirect Count', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan Final URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan Final URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan Times Linked', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan Times Linked', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Saved Scan Content Items Affected', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Latest Scan Content Items Affected', 'indexlane-redirect-internal-link-auditor' ) ),
			),
		);

		foreach ( $comparison['rows'] as $row ) {
			$rows[] = array(
				self::csv_safe( (string) $row['category_label'] ),
				self::csv_safe( (string) $row['direction_label'] ),
				self::csv_safe( (string) $row['destination_url'] ),
				self::csv_safe( implode( ' | ', $row['changed_labels'] ) ),
				self::comparison_csv_value( $row['old'], 'http_status_chain' ),
				self::comparison_csv_value( $row['new'], 'http_status_chain' ),
				self::comparison_csv_value( $row['old'], 'redirect_count' ),
				self::comparison_csv_value( $row['new'], 'redirect_count' ),
				self::comparison_csv_value( $row['old'], 'final_url' ),
				self::comparison_csv_value( $row['new'], 'final_url' ),
				self::comparison_csv_value( $row['old'], 'result_severity' ),
				self::comparison_csv_value( $row['new'], 'result_severity' ),
				self::comparison_csv_value( $row['old'], 'occurrence_count' ),
				self::comparison_csv_value( $row['new'], 'occurrence_count' ),
				self::comparison_csv_value( $row['old'], 'affected_source_count' ),
				self::comparison_csv_value( $row['new'], 'affected_source_count' ),
			);
		}

		return $rows;
	}

	/**
	 * Return one safe CSV value from optional comparison evidence.
	 *
	 * @param array<string,mixed>|null $evidence Evidence side.
	 * @param string                   $field    Evidence field.
	 */
	private static function comparison_csv_value( ?array $evidence, string $field ): string {
		$value = is_array( $evidence ) && array_key_exists( $field, $evidence ) ? (string) $evidence[ $field ] : '';
		return self::csv_safe( $value );
	}
}
