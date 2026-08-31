<?php
/**
 * Reports behavior for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Reports {
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
	 * Build one content-link coverage row for every item in the saved scan corpus.
	 *
	 * This is a local projection of the saved content snapshot and occurrence
	 * evidence. It never makes an HTTP request or queries a different corpus.
	 *
	 * @param array<int,array<string,mixed>> $content_items Saved scanned items.
	 * @param array<int,array<string,mixed>> $results       Saved occurrence rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_content_link_coverage( array $content_items, array $results ): array {
		$coverage  = array();
		$url_to_id = array();

		foreach ( $content_items as $item ) {
			$target_id = isset( $item['id'] ) ? max( 0, (int) $item['id'] ) : 0;
			if ( $target_id <= 0 || isset( $coverage[ $target_id ] ) ) {
				continue;
			}

			$title = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
			$url   = isset( $item['url'] ) ? trim( (string) $item['url'] ) : '';
			$coverage[ $target_id ] = array(
				'target_id'                      => $target_id,
				'target_title'                   => '' !== $title ? $title : __( '(no title)', 'indexlane-redirect-internal-link-auditor' ),
				'target_type'                    => isset( $item['type'] ) ? (string) $item['type'] : '',
				'target_url'                     => $url,
				'target_edit_url'                => isset( $item['edit_url'] ) ? (string) $item['edit_url'] : '',
				'incoming_occurrences'           => 0,
				'linking_source_count'           => 0,
				'outgoing_internal_occurrences'  => 0,
				'distinct_internal_destinations' => 0,
				'anchor_text_variants'           => array(),
				'self_link_count'                => 0,
				'direct_incoming'                => 0,
				'redirected_incoming'            => 0,
				'status_code'                    => 'none',
				'status'                         => '',
				'incoming_details'               => array(),
				'_linking_sources'                => array(),
				'_outgoing_destinations'          => array(),
				'_anchor_text_variants'           => array(),
			);

			$url_key = self::normalize_destination_for_impact( $url );
			if ( '' !== $url_key ) {
				$url_to_id[ $url_key ] = $target_id;
			}
		}

		foreach ( $results as $row ) {
			if ( ! self::coverage_result_is_same_site( $row ) ) {
				continue;
			}

			$source_id = self::coverage_source_id_for_result( $row, $coverage, $url_to_id );
			if ( $source_id > 0 && isset( $coverage[ $source_id ] ) ) {
				$coverage[ $source_id ]['outgoing_internal_occurrences']++;
				$destination_key = self::coverage_destination_key_for_result( $row );
				if ( '' !== $destination_key ) {
					$coverage[ $source_id ]['_outgoing_destinations'][ $destination_key ] = true;
				}
			}

			$target_id = self::coverage_target_id_for_result( $row, $coverage, $url_to_id );
			if ( $target_id <= 0 || ! isset( $coverage[ $target_id ] ) ) {
				continue;
			}

			$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] )
				? max( 0, (int) $row['redirect_count'] )
				: 0;
			$is_redirected  = $redirect_count > 0 || ( isset( $row['link_kind_code'] ) && 'redirected' === $row['link_kind_code'] );
			$source_key     = $source_id > 0
				? 'id:' . $source_id
				: self::normalize_destination_for_impact( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );
			if ( '' === $source_key ) {
				$source_key = 'source:' . ( isset( $row['source_title'] ) ? (string) $row['source_title'] : '' );
			}

			$anchor_text = isset( $row['anchor_text'] ) ? trim( (string) $row['anchor_text'] ) : '';
			if ( '' === $anchor_text ) {
				$anchor_text = __( '(no link text)', 'indexlane-redirect-internal-link-auditor' );
			}

			$coverage[ $target_id ]['incoming_occurrences']++;
			$coverage[ $target_id ]['_linking_sources'][ $source_key ] = true;
			$coverage[ $target_id ]['_anchor_text_variants'][ $anchor_text ] = true;
			if ( $is_redirected ) {
				$coverage[ $target_id ]['redirected_incoming']++;
			} else {
				$coverage[ $target_id ]['direct_incoming']++;
			}
			if ( $source_id > 0 && $source_id === $target_id ) {
				$coverage[ $target_id ]['self_link_count']++;
			}

			$coverage[ $target_id ]['incoming_details'][] = array(
				'source_id'       => $source_id,
				'source_title'    => isset( $row['source_title'] ) ? (string) $row['source_title'] : '',
				'source_type'     => isset( $row['source_type'] ) ? (string) $row['source_type'] : '',
				'source_url'      => isset( $row['source_url'] ) ? (string) $row['source_url'] : '',
				'source_edit_url' => isset( $row['source_edit_url'] ) ? (string) $row['source_edit_url'] : '',
				'anchor_text'     => $anchor_text,
				'link_kind_code'  => $is_redirected ? 'redirected' : 'direct',
				'linked_url'      => isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '',
				'http_status'     => isset( $row['http_status'] ) ? (string) $row['http_status'] : '',
				'redirect_count'  => $redirect_count,
				'final_url'       => isset( $row['final_url'] ) ? (string) $row['final_url'] : '',
				'is_self_link'    => $source_id > 0 && $source_id === $target_id,
			);
		}

		foreach ( $coverage as &$coverage_row ) {
			$coverage_row['linking_source_count']           = count( $coverage_row['_linking_sources'] );
			$coverage_row['distinct_internal_destinations'] = count( $coverage_row['_outgoing_destinations'] );
			$coverage_row['anchor_text_variants']           = array_keys( $coverage_row['_anchor_text_variants'] );
			natcasesort( $coverage_row['anchor_text_variants'] );
			$coverage_row['anchor_text_variants'] = array_values( $coverage_row['anchor_text_variants'] );

			if ( 0 === $coverage_row['linking_source_count'] ) {
				$coverage_row['status_code'] = 'none';
				$coverage_row['status']      = __( 'No incoming links detected in scanned content.', 'indexlane-redirect-internal-link-auditor' );
			} elseif ( 1 === $coverage_row['linking_source_count'] ) {
				$coverage_row['status_code'] = 'one';
				$coverage_row['status']      = __( 'One linking source', 'indexlane-redirect-internal-link-auditor' );
			} else {
				$coverage_row['status_code'] = 'multiple';
				$coverage_row['status']      = __( 'Multiple linking sources', 'indexlane-redirect-internal-link-auditor' );
			}

			usort(
				$coverage_row['incoming_details'],
				static function ( array $left, array $right ): int {
					$title_comparison = strnatcasecmp( $left['source_title'], $right['source_title'] );
					if ( 0 !== $title_comparison ) {
						return $title_comparison;
					}

					$anchor_comparison = strnatcasecmp( $left['anchor_text'], $right['anchor_text'] );
					if ( 0 !== $anchor_comparison ) {
						return $anchor_comparison;
					}

					return strcmp( $left['linked_url'], $right['linked_url'] );
				}
			);

			unset( $coverage_row['_linking_sources'], $coverage_row['_outgoing_destinations'], $coverage_row['_anchor_text_variants'] );
		}
		unset( $coverage_row );

		$coverage_rows = array_values( $coverage );
		usort(
			$coverage_rows,
			static function ( array $left, array $right ): int {
				if ( $left['linking_source_count'] !== $right['linking_source_count'] ) {
					return $left['linking_source_count'] <=> $right['linking_source_count'];
				}

				$title_comparison = strnatcasecmp( $left['target_title'], $right['target_title'] );
				if ( 0 !== $title_comparison ) {
					return $title_comparison;
				}

				return $left['target_id'] <=> $right['target_id'];
			}
		);

		return $coverage_rows;
	}

	/**
	 * Whether a saved occurrence started at a same-site destination.
	 *
	 * @param array<string,mixed> $row Occurrence row.
	 */
	private static function coverage_result_is_same_site( array $row ): bool {
		if ( array_key_exists( 'is_same_site', $row ) ) {
			return (bool) $row['is_same_site'];
		}

		return self::is_same_site_url( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' );
	}

	/**
	 * Resolve the scanned source item for a saved occurrence.
	 *
	 * @param array<string,mixed>                    $row       Occurrence row.
	 * @param array<int,array<string,mixed>>         $coverage  Coverage rows keyed by ID.
	 * @param array<string,int>                      $url_to_id Scanned permalink map.
	 */
	private static function coverage_source_id_for_result( array $row, array $coverage, array $url_to_id ): int {
		$source_id = isset( $row['source_id'] ) ? max( 0, (int) $row['source_id'] ) : 0;
		if ( $source_id > 0 && isset( $coverage[ $source_id ] ) ) {
			return $source_id;
		}

		$source_key = self::normalize_destination_for_impact( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );

		return '' !== $source_key && isset( $url_to_id[ $source_key ] ) ? (int) $url_to_id[ $source_key ] : 0;
	}

	/**
	 * Resolve the saved target, preferring a redirect's published final item.
	 *
	 * @param array<string,mixed>                    $row       Occurrence row.
	 * @param array<int,array<string,mixed>>         $coverage  Coverage rows keyed by ID.
	 * @param array<string,int>                      $url_to_id Scanned permalink map.
	 */
	private static function coverage_target_id_for_result( array $row, array $coverage, array $url_to_id ): int {
		$target_id = isset( $row['coverage_target_id'] ) ? max( 0, (int) $row['coverage_target_id'] ) : 0;
		if ( $target_id > 0 && isset( $coverage[ $target_id ] ) ) {
			return $target_id;
		}

		$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] )
			? max( 0, (int) $row['redirect_count'] )
			: 0;
		$is_redirected  = $redirect_count > 0 || ( isset( $row['link_kind_code'] ) && 'redirected' === $row['link_kind_code'] );
		$candidate_url  = $is_redirected
			? ( isset( $row['final_url'] ) ? (string) $row['final_url'] : '' )
			: ( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' );
		$candidate_key  = self::normalize_destination_for_impact( $candidate_url );

		return '' !== $candidate_key && isset( $url_to_id[ $candidate_key ] ) ? (int) $url_to_id[ $candidate_key ] : 0;
	}

	/**
	 * Build a stable distinct-destination key for one outgoing occurrence.
	 *
	 * @param array<string,mixed> $row Occurrence row.
	 */
	private static function coverage_destination_key_for_result( array $row ): string {
		$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] )
			? max( 0, (int) $row['redirect_count'] )
			: 0;
		$final_url      = isset( $row['final_url'] ) ? (string) $row['final_url'] : '';
		$destination    = $redirect_count > 0 && self::is_same_site_url( $final_url )
			? $final_url
			: ( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' );

		$normalized = self::normalize_destination_for_impact( $destination );

		return '' !== $normalized ? $normalized : trim( $destination );
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
	 * @param array<int,array<string,mixed>> $results       Result rows.
	 * @param string                         $report_type   Report type: details, impact, or coverage.
	 * @param array<int,array<string,mixed>> $content_items Saved scanned items.
	 */
	private static function send_csv( array $results, string $report_type, array $content_items = array() ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		$report_type  = in_array( $report_type, array( 'details', 'impact', 'coverage' ), true ) ? $report_type : 'details';
		$filename_type = 'coverage' === $report_type ? 'target-coverage' : $report_type;
		header( 'Content-Disposition: attachment; filename=indexlane-redirect-internal-link-auditor-' . $filename_type . '-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		foreach ( self::build_csv_rows( $results, $report_type, $content_items ) as $csv_row ) {
			fputcsv( $output, $csv_row, ',', '"', '' );
		}

		exit;
	}

	/**
	 * Build safe CSV rows, including the header, for a report type.
	 *
	 * @param array<int,array<string,mixed>> $results       Result rows.
	 * @param string                         $report_type   Report type: details, impact, or coverage.
	 * @param array<int,array<string,mixed>> $content_items Saved scanned items.
	 * @return array<int,array<int,string>>
	 */
	private static function build_csv_rows( array $results, string $report_type, array $content_items = array() ): array {
		if ( 'coverage' === $report_type ) {
			$rows = array(
				array(
					self::csv_safe( __( 'Content', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Content URL', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Times Linked', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Content Items Linking Here', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Links From This Content', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Unique URLs Linked', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Link Text', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Self-Links', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Direct Links Here', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Redirected Links Here', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
				),
			);

			foreach ( self::build_content_link_coverage( $content_items, $results ) as $row ) {
				$rows[] = array(
					self::csv_safe( (string) $row['target_title'] ),
					self::csv_safe( (string) $row['target_url'] ),
					self::csv_safe( (string) $row['incoming_occurrences'] ),
					self::csv_safe( (string) $row['linking_source_count'] ),
					self::csv_safe( (string) $row['outgoing_internal_occurrences'] ),
					self::csv_safe( (string) $row['distinct_internal_destinations'] ),
					self::csv_safe( implode( ' | ', $row['anchor_text_variants'] ) ),
					self::csv_safe( (string) $row['self_link_count'] ),
					self::csv_safe( (string) $row['direct_incoming'] ),
					self::csv_safe( (string) $row['redirected_incoming'] ),
					self::csv_safe( (string) $row['status'] ),
				);
			}

			return $rows;
		}

		if ( 'impact' === $report_type ) {
			$rows = array(
				array(
					self::csv_safe( __( 'URL', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Problem', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Times Linked', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Content Items Affected', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'HTTP Status', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Maximum Redirects', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Final URLs', 'indexlane-redirect-internal-link-auditor' ) ),
					self::csv_safe( __( 'Warnings', 'indexlane-redirect-internal-link-auditor' ) ),
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
				self::csv_safe( __( 'Content Item', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Content Type', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Content URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Linked URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'HTTP Status', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Redirects', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Final URL', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Warning', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Link Text', 'indexlane-redirect-internal-link-auditor' ) ),
				self::csv_safe( __( 'Outcome', 'indexlane-redirect-internal-link-auditor' ) ),
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
	 * Avoid spreadsheet formula execution on CSV open.
	 */
	private static function csv_safe( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		if ( '' !== $value && preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
