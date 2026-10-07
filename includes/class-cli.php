<?php
/**
 * WP-CLI commands for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'IndexLane_RILA_CLI' ) ) {
	/**
	 * Verify and repair internal links from the command line.
	 */
	class IndexLane_RILA_CLI {
		/**
		 * Report a command failure.
		 *
		 * @param WP_Error $error Error object.
		 */
		private function fail( WP_Error $error ): void {
			WP_CLI::error( $error->get_error_message() );
		}

		/**
		 * Scan stored WordPress sources and report the URLs needing attention.
		 *
		 * ## OPTIONS
		 *
		 * [--source-types=<types>]
		 * : Comma-separated source providers. Defaults to every available provider.
		 *
		 * [--post-types=<types>]
		 * : Comma-separated public post types. Defaults to every public post type.
		 *
		 * [--content-scope=<scope>]
		 * : `all` or `limit`. Defaults to `all`.
		 *
		 * [--max-posts=<number>]
		 * : Number of newest content items when the scope is `limit`.
		 *
		 * [--old-domains=<domains>]
		 * : Comma-separated previous site domains to flag without contacting them.
		 *
		 * [--timeout=<seconds>]
		 * : Timeout for each HTTP request. Defaults to 5 seconds.
		 *
		 * [--max-redirects=<number>]
		 * : Maximum redirect hops. Defaults to 5.
		 *
		 * [--request-limit=<number>]
		 * : Maximum outbound requests. Defaults to 2000.
		 *
		 * [--limit=<number>]
		 * : Maximum problem URLs to list. Defaults to 200.
		 *
		 * [--format=<format>]
		 * : `table`, `json`, or `csv`. Defaults to `table`.
		 *
		 * ## EXAMPLES
		 *
		 *     wp indexlane scan --content-scope=all --format=table
		 *     wp indexlane scan --source-types=content,menu --format=json
		 *
		 * @param array<int,string>   $args       Positional arguments.
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		public function scan( $args, $assoc_args ): void {
			unset( $args );

			$result = IndexLane_Redirect_Internal_Link_Auditor::cli_scan( $assoc_args );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result );
			}

			$format = $this->format( $assoc_args );
			if ( 'table' === $format ) {
				WP_CLI::log(
					sprintf(
						/* translators: 1: number of stored sources, 2: number of unique URLs checked, 3: number of outbound requests */
						__( 'Checked %1$d stored sources and %2$d links using %3$d requests.', 'indexlane-redirect-internal-link-auditor' ),
						(int) $result['sources'],
						(int) $result['links'],
						(int) $result['requests']
					)
				);

				WP_CLI::log(
					sprintf(
						/* translators: 1: number of URLs needing attention, 2: number of acknowledged URLs */
						__( '%1$d URLs need attention. %2$d URLs are acknowledged.', 'indexlane-redirect-internal-link-auditor' ),
						(int) $result['issues']['active'],
						(int) $result['issues']['acknowledged']
					)
				);
			}

			if ( 'partial' === (string) $result['status'] ) {
				WP_CLI::warning( __( 'The scan stopped before it finished. These results cover only the sources checked so far.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			if ( 'table' === $format && empty( $result['problem_urls'] ) ) {
				WP_CLI::success( __( 'No problem URLs were found in the sources checked so far.', 'indexlane-redirect-internal-link-auditor' ) );
				return;
			}

			WP_CLI\Utils\format_items( $this->format( $assoc_args ), $result['problem_urls'], array( 'url', 'outcome', 'acknowledged', 'occurrences', 'sources', 'http', 'final_url', 'page_intent' ) );
		}

		/**
		 * Replace one stored URL with another in the selected WordPress sources.
		 *
		 * ## OPTIONS
		 *
		 * --from=<url>
		 * : The exact stored link URL to replace.
		 *
		 * --to=<url>
		 * : The replacement URL.
		 *
		 * [--dry-run]
		 * : Preview the exact changes without writing anything.
		 *
		 * [--yes]
		 * : Apply without an interactive confirmation.
		 *
		 * [--source-types=<types>]
		 * : Comma-separated source providers. Defaults to every available provider.
		 *
		 * [--post-types=<types>]
		 * : Comma-separated public post types. Defaults to every public post type.
		 *
		 * [--content-scope=<scope>]
		 * : `all` or `limit`. Defaults to `all`.
		 *
		 * [--max-posts=<number>]
		 * : Number of newest content items when the scope is `limit`.
		 *
		 * [--old-domains=<domains>]
		 * : Comma-separated previous site domains to flag without contacting them.
		 *
		 * [--timeout=<seconds>]
		 * : Timeout for each HTTP request. Defaults to 5 seconds.
		 *
		 * [--max-redirects=<number>]
		 * : Maximum redirect hops. Defaults to 5.
		 *
		 * [--request-limit=<number>]
		 * : Maximum outbound requests. Defaults to 2000.
		 *
		 * ## EXAMPLES
		 *
		 *     wp indexlane fix --from=https://example.com/old/ --to=https://example.com/new/ --dry-run
		 *     wp indexlane fix --from=https://example.com/old/ --to=https://example.com/new/ --yes
		 *
		 * @param array<int,string>   $args       Positional arguments.
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		public function fix( $args, $assoc_args ): void {
			unset( $args );

			$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
			$to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';
			if ( '' === $from || '' === $to ) {
				WP_CLI::error( __( 'Provide both --from and --to.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$dry_run = isset( $assoc_args['dry-run'] );
			if ( ! $dry_run ) {
				WP_CLI::confirm( __( 'Replace this link in the current site content?', 'indexlane-redirect-internal-link-auditor' ), $assoc_args );
			}

			$result = IndexLane_Redirect_Internal_Link_Auditor::cli_fix( $from, $to, $dry_run, $assoc_args );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result );
			}

			WP_CLI\Utils\format_items( 'table', $result['items'], array( 'source', 'surface', 'storage', 'occurrences' ) );

			if ( ! empty( $result['skipped'] ) ) {
				WP_CLI::log( __( 'Sources left untouched:', 'indexlane-redirect-internal-link-auditor' ) );
				foreach ( $result['skipped'] as $skipped ) {
					WP_CLI::log( '  - ' . (string) $skipped['source_title'] . ': ' . (string) $skipped['reason'] );
				}
			}

			if ( $dry_run ) {
				WP_CLI::success(
					sprintf(
						/* translators: 1: number of link occurrences, 2: number of stored sources */
						__( 'Preview only: %1$d link occurrences in %2$d sources would change.', 'indexlane-redirect-internal-link-auditor' ),
						(int) $result['occurrences'],
						(int) $result['sources']
					)
				);
				return;
			}

			WP_CLI::success(
				sprintf(
					/* translators: 1: number of link occurrences, 2: number of stored sources, 3: repair batch ID */
					__( 'Replaced %1$d link occurrences in %2$d sources. Undo with: wp indexlane undo --batch=%3$s', 'indexlane-redirect-internal-link-auditor' ),
					(int) $result['occurrences'],
					(int) $result['sources'],
					(string) $result['batch_id']
				)
			);
		}

		/**
		 * Replace every stored link that has a suggested replacement.
		 *
		 * Each suggested URL was derived from the redirect target or the
		 * published content it resolves to. The batch is written as one
		 * change that can be undone with a single command.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Preview the exact changes without writing anything.
		 *
		 * [--yes]
		 * : Apply without an interactive confirmation.
		 *
		 * [--from=<urls>]
		 * : Comma-separated stored URLs to limit the batch to. Defaults to every suggestion.
		 *
		 * [--source-types=<types>]
		 * : Comma-separated source providers. Defaults to every available provider.
		 *
		 * [--post-types=<types>]
		 * : Comma-separated public post types. Defaults to every public post type.
		 *
		 * [--content-scope=<scope>]
		 * : `all` or `limit`. Defaults to `all`.
		 *
		 * [--max-posts=<number>]
		 * : Number of newest content items when the scope is `limit`.
		 *
		 * [--old-domains=<domains>]
		 * : Comma-separated previous site domains to flag without contacting them.
		 *
		 * [--timeout=<seconds>]
		 * : Timeout for each HTTP request. Defaults to 5 seconds.
		 *
		 * [--max-redirects=<number>]
		 * : Maximum redirect hops. Defaults to 5.
		 *
		 * [--request-limit=<number>]
		 * : Maximum outbound requests. Defaults to 2000.
		 *
		 * [--format=<format>]
		 * : `table`, `json`, or `csv`. Defaults to `table`.
		 *
		 * ## EXAMPLES
		 *
		 *     wp indexlane fix-suggested --dry-run
		 *     wp indexlane fix-suggested --yes
		 *
		 * @param array<int,string>   $args       Positional arguments.
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		public function fix_suggested( $args, $assoc_args ): void {
			unset( $args );

			$dry_run = isset( $assoc_args['dry-run'] );
			if ( ! $dry_run ) {
				WP_CLI::confirm( __( 'Replace every suggested link in the current site content?', 'indexlane-redirect-internal-link-auditor' ), $assoc_args );
			}

			$include = null;
			if ( isset( $assoc_args['from'] ) ) {
				$include = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['from'] ) ) ) );
			}

			$result = IndexLane_Redirect_Internal_Link_Auditor::cli_fix_suggested( $dry_run, $assoc_args, $include );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result );
			}

			$rows = array();
			foreach ( $result['pairs'] as $pair ) {
				$rows[] = array(
					'from' => (string) $pair['from_url'],
					'to'   => (string) $pair['to_url'],
				);
			}

			if ( 'table' === $this->format( $assoc_args ) && empty( $rows ) ) {
				WP_CLI::warning( __( 'No suggested replacements were found.', 'indexlane-redirect-internal-link-auditor' ) );
				return;
			}

			WP_CLI\Utils\format_items( $this->format( $assoc_args ), $rows, array( 'from', 'to' ) );

			if ( ! empty( $result['skipped'] ) ) {
				WP_CLI::log( __( 'Suggestions left untouched:', 'indexlane-redirect-internal-link-auditor' ) );
				foreach ( $result['skipped'] as $skipped ) {
					$label = isset( $skipped['source_title'] ) && '' !== (string) $skipped['source_title'] ? (string) $skipped['source_title'] : (string) ( $skipped['from_url'] ?? '' );
					WP_CLI::log( '  - ' . $label . ': ' . (string) $skipped['reason'] );
				}
			}

			if ( $dry_run ) {
				WP_CLI::success(
					sprintf(
						/* translators: 1: number of link occurrences, 2: number of stored sources, 3: number of stored URLs */
						__( 'Preview only: %1$d link occurrences in %2$d sources across %3$d URLs would change.', 'indexlane-redirect-internal-link-auditor' ),
						(int) $result['occurrences'],
						(int) $result['sources'],
						(int) $result['urls']
					)
				);
				return;
			}

			WP_CLI::success(
				sprintf(
					/* translators: 1: number of link occurrences, 2: number of stored sources, 3: number of stored URLs, 4: repair batch ID */
					__( 'Replaced %1$d link occurrences in %2$d sources across %3$d URLs. Undo with: wp indexlane undo --batch=%4$s', 'indexlane-redirect-internal-link-auditor' ),
					(int) $result['occurrences'],
					(int) $result['sources'],
					(int) $result['urls'],
					(string) $result['batch_id']
				)
			);
		}

		/**
		 * Undo a recorded link repair.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<id>]
		 * : Repair batch ID. Defaults to the most recent applied repair.
		 *
		 * [--yes]
		 * : Undo without an interactive confirmation.
		 *
		 * ## EXAMPLES
		 *
		 *     wp indexlane undo --yes
		 *     wp indexlane undo --batch=0f5f6d1e-1c51-4f75-9e0d-2b0f0f2f9a11 --yes
		 *
		 * @param array<int,string>   $args       Positional arguments.
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		public function undo( $args, $assoc_args ): void {
			unset( $args );

			WP_CLI::confirm( __( 'Restore the previous stored links for this repair?', 'indexlane-redirect-internal-link-auditor' ), $assoc_args );

			$batch  = isset( $assoc_args['batch'] ) ? (string) $assoc_args['batch'] : '';
			$result = IndexLane_Redirect_Internal_Link_Auditor::cli_undo( $batch );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result );
			}

			WP_CLI::success(
				sprintf(
					/* translators: %d: number of restored stored sources */
					_n( 'Restored %d source.', 'Restored %d sources.', (int) $result['restored'], 'indexlane-redirect-internal-link-auditor' ),
					(int) $result['restored']
				)
			);

			foreach ( (array) $result['skipped'] as $skipped ) {
				WP_CLI::warning( (string) $skipped['source_title'] . ': ' . (string) $skipped['reason'] );
			}
		}

		/**
		 * List the recorded link repairs and their undo status.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : `table`, `json`, or `csv`. Defaults to `table`.
		 *
		 * ## EXAMPLES
		 *
		 *     wp indexlane repairs
		 *
		 * @param array<int,string>   $args       Positional arguments.
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		public function repairs( $args, $assoc_args ): void {
			unset( $args );

			$rows = IndexLane_Redirect_Internal_Link_Auditor::cli_repairs();
			if ( 'table' === $this->format( $assoc_args ) && empty( $rows ) ) {
				WP_CLI::success( __( 'No link repairs have been applied from this plugin.', 'indexlane-redirect-internal-link-auditor' ) );
				return;
			}

			WP_CLI\Utils\format_items( $this->format( $assoc_args ), $rows, array( 'batch_id', 'created', 'from', 'to', 'sources', 'status' ) );
		}

		/**
		 * Resolve the requested output format.
		 *
		 * @param array<string,mixed> $assoc_args Associative arguments.
		 */
		private function format( array $assoc_args ): string {
			$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

			return in_array( $format, array( 'table', 'json', 'csv' ), true ) ? $format : 'table';
		}
	}
}
