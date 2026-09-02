<?php
/**
 * Scan behavior for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Scan {
	/**
	 * Default scan settings.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_settings(): array {
		return array(
			'source_types'     => self::get_default_source_types(),
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
		$settings          = self::default_settings();
		$available_types   = array_keys( self::get_available_post_types() );
		$available_sources = array_keys( self::get_available_source_types() );

		if ( isset( $post_data['source_types'] ) && is_array( $post_data['source_types'] ) ) {
			$source_types = array_filter( $post_data['source_types'], 'is_scalar' );
			$source_types = array_map( 'sanitize_key', $source_types );
			$source_types = array_values( array_unique( array_intersect( $source_types, $available_sources ) ) );
		} elseif ( isset( $post_data['source_types_present'] ) ) {
			$source_types = array();
		} else {
			// Preserve the pre-0.6 request contract for programmatic callers.
			$source_types = in_array( 'content', $available_sources, true ) ? array( 'content' ) : array();
		}
		$settings['source_types'] = $source_types;

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
	 * @param array<string,mixed> $settings             Sanitized settings.
	 * @param string              $scan_mode            Standard or verification.
	 * @param string              $baseline_id          Saved baseline ID for verification.
	 * @param string              $baseline_fingerprint Saved baseline fingerprint for verification.
	 * @return array<string,mixed>
	 */
	private static function create_scan_session( array $settings, string $scan_mode = 'standard', string $baseline_id = '', string $baseline_fingerprint = '' ) {
		$snapshot = self::snapshot_selected_source_providers( $settings );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$total = (int) $snapshot['total_items'];
		$now   = time();

		return array(
			'schema_version'     => self::SESSION_SCHEMA_VERSION,
			'id'                 => wp_generate_uuid4(),
			'status'             => 0 === $total ? 'complete' : 'running',
			'created_at'         => $now,
			'updated_at'         => $now,
			'expires_at'         => $now + self::SESSION_LIFETIME,
			'scan_mode'          => 'verification' === $scan_mode ? 'verification' : 'standard',
			'baseline_id'        => 'verification' === $scan_mode ? $baseline_id : '',
			'baseline_fingerprint' => 'verification' === $scan_mode ? $baseline_fingerprint : '',
			'settings'           => $settings,
			'total_items'        => $total,
			'source_provider_states' => $snapshot['states'],
			'source_provider_index' => 0,
			'sources_done'       => 0 === $total,
			'request_limit'      => self::INITIAL_REQUEST_ALLOWANCE,
			'request_allowance_extensions' => 0,
			'stats'              => self::empty_stats(),
			'content_items'      => array(),
			'content_item_ids'   => array(),
			'results'            => array(),
			'checked_urls'       => array(),
			'pending_checks'      => array(),
		);
	}

	/**
	 * Process a bounded amount of stored-source and HTTP work.
	 *
	 * @param array<string,mixed> $session Scan session.
	 * @return array<string,mixed>
	 */
	private static function process_scan_batch( array $session ): array {
		$batch_request_start = (int) $session['stats']['http_requests'];
		$batch_source_count  = 0;

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

			if ( ! empty( $session['sources_done'] ) ) {
				$session['status'] = 'complete';
				break;
			}

			if ( $batch_source_count >= self::MAX_SOURCE_ITEMS_PER_BATCH ) {
				break;
			}

			$next = self::get_next_scan_source( $session );
			if ( is_wp_error( $next ) ) {
				$session['status']          = 'failed';
				$session['failure_message'] = $next->get_error_message();
				break;
			}

			$session = $next['session'];
			if ( null === $next['source'] ) {
				$session['sources_done'] = true;
				$session['total_items']  = (int) $session['stats']['sources_processed'];
				continue;
			}

			$session = self::process_source_item( $session, $next['source'] );
			$batch_source_count++;

			if ( (int) $session['stats']['sources_processed'] >= (int) $session['total_items'] ) {
				$session['sources_done'] = true;
			}
		}

		if ( 'running' === $session['status'] && ! empty( $session['sources_done'] ) && empty( $session['pending_checks'] ) ) {
			$session['status'] = 'complete';
		}

		return $session;
	}

	/**
	 * Extract and queue every relevant occurrence from one stored source.
	 *
	 * @param array<string,mixed> $session Scan session.
	 * @param array<string,mixed> $source  Normalized stored source.
	 * @return array<string,mixed>
	 */
	private static function process_source_item( array $session, array $source ): array {
		$session['stats']['sources_processed']++;
		if ( is_array( $source['content_item'] ) ) {
			$content_id = (int) $source['content_item']['id'];
			if ( ! isset( $session['content_item_ids'][ $content_id ] ) ) {
				$session['stats']['content_items_processed']++;
				$session['content_items'][] = $source['content_item'];
				$session['content_item_ids'][ $content_id ] = true;
			}
		}

		$links = self::extract_source_links( $source );
		$session['stats']['links_extracted'] += count( $links );
		$occurrence_source = $source;
		unset( $occurrence_source['content'], $occurrence_source['links'], $occurrence_source['content_item'] );

		$current_host = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		foreach ( $links as $link ) {
			$prepared = self::prepare_link_occurrence( $link, $occurrence_source, $session['settings'], $current_host );
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
			'sources_processed'            => 0,
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
	 * Extract links from stored HTML or block content.
	 *
	 * @param string $content Stored source content.
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
		$linked_url = self::normalize_link_url( $link['href'], isset( $source['base_url'] ) ? (string) $source['base_url'] : (string) $source['url'] );

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
			$warnings[] = __( 'Old-site URL', 'indexlane-redirect-internal-link-auditor' );
		}
		if ( $is_staging ) {
			$warnings[] = __( 'Staging or development URL', 'indexlane-redirect-internal-link-auditor' );
		}

		$should_request = $is_current;

		if ( ! $should_request ) {
			$warnings[] = __( 'Not checked because this URL is on another site.', 'indexlane-redirect-internal-link-auditor' );

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
						/* translators: %d: initial outbound HTTP request limit */
						__( 'Status check incomplete because the %d-request limit was reached', 'indexlane-redirect-internal-link-auditor' ),
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

		$redirect_count_value = is_numeric( $redirect_count ) ? max( 0, (int) $redirect_count ) : 0;
		$is_same_site         = self::is_same_site_url( $linked_url );
		$direct_target_id      = $is_same_site ? self::published_content_id_for_url( $linked_url ) : 0;
		$final_target_id       = $redirect_count_value > 0 && self::is_same_site_url( $final_url )
			? self::published_content_id_for_url( $final_url )
			: 0;
		$coverage_target_id    = $redirect_count_value > 0 ? $final_target_id : $direct_target_id;

		return array(
			'source_id'          => isset( $source['id'] ) ? max( 0, (int) $source['id'] ) : 0,
			'source_key'         => isset( $source['key'] ) ? (string) $source['key'] : '',
			'source_title'       => $source['title'],
			'source_type'        => $source['type'],
			'source_type_code'   => isset( $source['type_code'] ) ? (string) $source['type_code'] : 'content',
			'source_context'     => isset( $source['context'] ) && 'shared' === $source['context'] ? 'shared' : 'contextual',
			'source_content_id'  => isset( $source['content_id'] ) ? max( 0, (int) $source['content_id'] ) : 0,
			'source_url'         => $source['url'],
			'source_edit_url'    => $source['edit_url'],
			'linked_url'         => $linked_url,
			'http_status'        => $http_status,
			'redirect_count'     => $redirect_count_value,
			'final_url'          => $final_url,
			'warning'            => $warning,
			'anchor_text'        => $link['anchor'],
			'result'             => $result,
			'result_code'        => $result_code,
			'is_same_site'       => $is_same_site,
			'direct_target_id'   => $direct_target_id,
			'final_target_id'    => $final_target_id,
			'coverage_target_id' => $coverage_target_id,
			'link_kind_code'     => $redirect_count_value > 0 ? 'redirected' : 'direct',
		);
	}

	/**
	 * Determine whether a URL belongs to the current site without requesting it.
	 */
	private static function is_same_site_url( string $url ): bool {
		$url_host     = self::normalize_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$current_host = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		return self::hosts_match( $url_host, $current_host );
	}

	/**
	 * Resolve a same-site URL to a currently published, viewable WordPress item.
	 *
	 * This uses local WordPress routing data and never performs an HTTP request.
	 */
	private static function published_content_id_for_url( string $url ): int {
		if ( ! self::is_same_site_url( $url ) || ! function_exists( 'url_to_postid' ) ) {
			return 0;
		}

		$post_id = (int) url_to_postid( $url );
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 0;
		}

		$post_type_object = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type_object ) {
			return 0;
		}

		$is_viewable = function_exists( 'is_post_type_viewable' )
			? is_post_type_viewable( $post_type_object )
			: ! empty( $post_type_object->publicly_queryable ) || ! empty( $post_type_object->public );

		return $is_viewable ? $post_id : 0;
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
			'scan_mode'                   => isset( $session['scan_mode'] ) && 'verification' === $session['scan_mode'] ? 'verification' : 'standard',
			'state_label'                 => self::scan_state_label( (string) $session['status'] ),
			'message'                     => 'failed' === $session['status'] && ! empty( $session['failure_message'] )
				? (string) $session['failure_message']
				: self::scan_state_message( (string) $session['status'] ),
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
				return __( 'Request limit reached', 'indexlane-redirect-internal-link-auditor' );
			case 'complete':
				return __( 'Complete', 'indexlane-redirect-internal-link-auditor' );
			case 'failed':
				return __( 'Scan stopped', 'indexlane-redirect-internal-link-auditor' );
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
				return __( 'Your progress is saved. Continue now or after reloading this page.', 'indexlane-redirect-internal-link-auditor' );
			case 'limit_reached':
				return __( 'Your progress is saved. Increase the request limit to continue.', 'indexlane-redirect-internal-link-auditor' );
			case 'complete':
				return __( 'All selected stored sources and same-site URLs have been checked. Downloads use these exact results.', 'indexlane-redirect-internal-link-auditor' );
			case 'failed':
				return __( 'The scan stopped before completion. Cancel it, resolve the link-source problem, and start again.', 'indexlane-redirect-internal-link-auditor' );
			default:
				return '';
		}
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
	 * Server request method wrapper for safer direct access.
	 */
	private static function server_request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	}
}
