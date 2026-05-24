<?php
/**
 * Plugin Name: WPFixPath Redirect & Internal Link Auditor
 * Plugin URI: https://indexlane.dev/plugins/redirect-internal-link-auditor/
 * Description: A small free WordPress plugin for finding broken, redirected, or suspicious internal links inside post, page, and product content.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpfixpath-redirect-internal-link-auditor
 * Update URI: https://indexlane.dev/plugins/redirect-internal-link-auditor/
 *
 * @package WPFixPath_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPFixPath_Redirect_Internal_Link_Auditor' ) ) {
	/**
	 * Admin-only internal link and redirect diagnostic helper.
	 */
	final class WPFixPath_Redirect_Internal_Link_Auditor {
		private const VERSION      = '0.1.1';
		private const SLUG         = 'wpfixpath-redirect-internal-link-auditor';
		private const CAPABILITY   = 'manage_options';
		private const NONCE_ACTION = 'wpfixpath_rila_run_scan';
		private const NONCE_NAME   = 'wpfixpath_rila_nonce';
		private const MAX_LINK_REQUESTS = 250;

		/**
		 * Boot the plugin.
		 */
		public static function init(): void {
			add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
		}

		/**
		 * Load translations when available.
		 */
		public static function load_textdomain(): void {
			load_plugin_textdomain(
				'wpfixpath-redirect-internal-link-auditor',
				false,
				dirname( plugin_basename( __FILE__ ) ) . '/languages'
			);
		}

		/**
		 * Register Tools admin page.
		 */
		public static function register_admin_page(): void {
			add_management_page(
				__( 'Redirect & Internal Link Auditor', 'wpfixpath-redirect-internal-link-auditor' ),
				__( 'Redirect & Internal Link Auditor', 'wpfixpath-redirect-internal-link-auditor' ),
				self::CAPABILITY,
				self::SLUG,
				array( __CLASS__, 'render_admin_page' )
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

			$action = isset( $_POST['wpfixpath_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['wpfixpath_rila_action'] ) ) : '';
			if ( 'export' !== $action ) {
				return;
			}

			check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

			$settings = self::get_request_settings();
			$scan     = self::run_scan( $settings );

			self::send_csv( $scan['results'] );
		}

		/**
		 * Render the admin UI.
		 */
		public static function render_admin_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wpfixpath-redirect-internal-link-auditor' ) );
			}

			$settings = self::default_settings();
			$scan     = null;

			if ( 'POST' === self::server_request_method() ) {
				$action = isset( $_POST['wpfixpath_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['wpfixpath_rila_action'] ) ) : '';
				if ( 'run' === $action ) {
					check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
					$settings = self::get_request_settings();
					$scan     = self::run_scan( $settings );
				}
			}

			?>
			<div class="wrap wpfixpath-rila-wrap">
				<h1><?php esc_html_e( 'Redirect & Internal Link Auditor', 'wpfixpath-redirect-internal-link-auditor' ); ?></h1>

				<p>
					<?php esc_html_e( 'Find internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains.', 'wpfixpath-redirect-internal-link-auditor' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="wpfixpath-rila-form">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Content types', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<td>
									<?php foreach ( self::get_available_post_types() as $post_type => $label ) : ?>
										<label class="wpfixpath-rila-checkbox">
											<input
												type="checkbox"
												name="post_types[]"
												value="<?php echo esc_attr( $post_type ); ?>"
												<?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>
											/>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description">
										<?php esc_html_e( 'Published posts, pages, and products are supported when the post type exists on this site.', 'wpfixpath-redirect-internal-link-auditor' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wpfixpath-rila-old-domains"><?php esc_html_e( 'Old domains', 'wpfixpath-redirect-internal-link-auditor' ); ?></label>
								</th>
								<td>
									<textarea
										id="wpfixpath-rila-old-domains"
										name="old_domains"
										rows="4"
										class="large-text code"
										placeholder="old-example.com&#10;staging.example.com"
									><?php echo esc_textarea( $settings['old_domains'] ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'Optional. Add one old or migration domain per line. Matching links are flagged even when status checks are limited to the current site.', 'wpfixpath-redirect-internal-link-auditor' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Scan limits', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<td>
									<label for="wpfixpath-rila-max-posts">
										<?php esc_html_e( 'Maximum content items', 'wpfixpath-redirect-internal-link-auditor' ); ?>
										<input
											id="wpfixpath-rila-max-posts"
											type="number"
											name="max_posts"
											min="1"
											max="100"
											value="<?php echo esc_attr( (string) $settings['max_posts'] ); ?>"
										/>
									</label>
									<label for="wpfixpath-rila-timeout" class="wpfixpath-rila-inline-field">
										<?php esc_html_e( 'Request timeout', 'wpfixpath-redirect-internal-link-auditor' ); ?>
										<input
											id="wpfixpath-rila-timeout"
											type="number"
											name="timeout"
											min="1"
											max="15"
											step="0.5"
											value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
										/>
									</label>
									<label for="wpfixpath-rila-max-redirects" class="wpfixpath-rila-inline-field">
										<?php esc_html_e( 'Maximum redirects', 'wpfixpath-redirect-internal-link-auditor' ); ?>
										<input
											id="wpfixpath-rila-max-redirects"
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
								<th scope="row"><?php esc_html_e( 'Status checks', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<td>
									<p><?php esc_html_e( 'Same-site link targets are checked. Old, staging, or development-domain links are flagged but not fetched in v0.1.', 'wpfixpath-redirect-internal-link-auditor' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" name="wpfixpath_rila_action" value="run" class="button button-primary">
							<?php esc_html_e( 'Run checks', 'wpfixpath-redirect-internal-link-auditor' ); ?>
						</button>
						<button type="submit" name="wpfixpath_rila_action" value="export" class="button">
							<?php esc_html_e( 'Export CSV', 'wpfixpath-redirect-internal-link-auditor' ); ?>
						</button>
					</p>
				</form>

				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Scope: scans selected WordPress content and checks same-site link targets. Old or staging-domain links are flagged for review.', 'wpfixpath-redirect-internal-link-auditor' ); ?>
					</p>
				</div>

				<?php
				if ( is_array( $scan ) ) {
					self::render_results( $scan, $settings );
				}
				?>
			</div>
			<style>
				.wpfixpath-rila-wrap .wpfixpath-rila-checkbox {
					display: inline-block;
					margin-right: 18px;
				}

				.wpfixpath-rila-wrap .wpfixpath-rila-inline-field {
					display: inline-block;
					margin-left: 18px;
				}

				.wpfixpath-rila-wrap input[type="number"] {
					margin-left: 6px;
					width: 86px;
				}

				.wpfixpath-rila-results {
					margin-top: 24px;
				}

				.wpfixpath-rila-results table {
					table-layout: fixed;
				}

				.wpfixpath-rila-results td,
				.wpfixpath-rila-results th {
					vertical-align: top;
					word-break: break-word;
				}
			</style>
			<?php
		}

		/**
		 * Render scan results.
		 *
		 * @param array<string,mixed> $scan     Scan data.
		 * @param array<string,mixed> $settings Sanitized settings.
		 */
		private static function render_results( array $scan, array $settings ): void {
			$stats   = $scan['stats'];
			$results = $scan['results'];
			?>
			<div class="wpfixpath-rila-results">
				<h2><?php esc_html_e( 'Results', 'wpfixpath-redirect-internal-link-auditor' ); ?></h2>

				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: source count, 2: link count, 3: audited count, 4: skipped count */
							__( 'Scanned %1$d content items, found %2$d links, audited %3$d relevant links, and skipped %4$d unrelated external links.', 'wpfixpath-redirect-internal-link-auditor' ),
							(int) $stats['sources_scanned'],
							(int) $stats['links_found'],
							(int) $stats['links_audited'],
							(int) $stats['skipped_external']
						)
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>">
					<?php
					wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
					self::render_hidden_settings_fields( $settings );
					?>
					<p>
						<button type="submit" name="wpfixpath_rila_action" value="export" class="button">
							<?php esc_html_e( 'Export CSV', 'wpfixpath-redirect-internal-link-auditor' ); ?>
						</button>
					</p>
				</form>

				<?php if ( empty( $results ) ) : ?>
					<p><?php esc_html_e( 'No internal, old-domain, or staging/development-domain content links were found in the scanned content.', 'wpfixpath-redirect-internal-link-auditor' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source Post/Page', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Source Type', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Source URL', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Linked URL', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'HTTP Status', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Redirect Count', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Final URL', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Warning', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Anchor Text', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
								<th><?php esc_html_e( 'Result', 'wpfixpath-redirect-internal-link-auditor' ); ?></th>
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
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render hidden fields for the export form.
		 *
		 * @param array<string,mixed> $settings Sanitized settings.
		 */
		private static function render_hidden_settings_fields( array $settings ): void {
			foreach ( $settings['post_types'] as $post_type ) {
				printf(
					'<input type="hidden" name="post_types[]" value="%s" />' . "\n",
					esc_attr( $post_type )
				);
			}

			printf( '<input type="hidden" name="old_domains" value="%s" />' . "\n", esc_attr( $settings['old_domains'] ) );
			printf( '<input type="hidden" name="max_posts" value="%s" />' . "\n", esc_attr( (string) $settings['max_posts'] ) );
			printf( '<input type="hidden" name="timeout" value="%s" />' . "\n", esc_attr( (string) $settings['timeout'] ) );
			printf( '<input type="hidden" name="max_redirects" value="%s" />' . "\n", esc_attr( (string) $settings['max_redirects'] ) );

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
		 * Get available supported content types.
		 *
		 * @return array<string,string>
		 */
		private static function get_available_post_types(): array {
			$labels = array();

			foreach ( array( 'post', 'page', 'product' ) as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					continue;
				}

				$post_type_object = get_post_type_object( $post_type );
				$labels[ $post_type ] = $post_type_object && isset( $post_type_object->labels->singular_name )
					? $post_type_object->labels->singular_name
					: $post_type;
			}

			return $labels;
		}

		/**
		 * Default scan settings.
		 *
		 * @return array<string,mixed>
		 */
		private static function default_settings(): array {
			return array(
				'post_types'            => array_keys( self::get_available_post_types() ),
				'old_domains'           => '',
				'old_domain_hosts'      => array(),
				'max_posts'             => 50,
				'timeout'               => 5.0,
				'max_redirects'         => 5,
			);
		}

		/**
		 * Sanitize scan settings from POST.
		 *
		 * @return array<string,mixed>
		 */
		private static function get_request_settings(): array {
			$settings        = self::default_settings();
			$post_data       = wp_unslash( $_POST );
			$available_types = array_keys( self::get_available_post_types() );

			$post_types = isset( $post_data['post_types'] ) && is_array( $post_data['post_types'] ) ? $post_data['post_types'] : array();
			$post_types = array_filter( $post_types, 'is_scalar' );
			$post_types = array_map( 'sanitize_key', $post_types );
			$post_types = array_values( array_intersect( $post_types, $available_types ) );

			if ( ! empty( $post_types ) ) {
				$settings['post_types'] = $post_types;
			}

			if ( isset( $post_data['old_domains'] ) && is_scalar( $post_data['old_domains'] ) ) {
				$settings['old_domains'] = sanitize_textarea_field( (string) $post_data['old_domains'] );
			}

			if ( isset( $post_data['max_posts'] ) && is_scalar( $post_data['max_posts'] ) ) {
				$settings['max_posts'] = min( 100, max( 1, absint( $post_data['max_posts'] ) ) );
			}

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
		 * Run the content link audit.
		 *
		 * @param array<string,mixed> $settings Sanitized settings.
		 * @return array<string,mixed>
		 */
		private static function run_scan( array $settings ): array {
			$results = array();
			$stats   = array(
				'sources_scanned'  => 0,
				'links_found'      => 0,
				'links_audited'    => 0,
				'skipped_external' => 0,
			);

			if ( empty( $settings['post_types'] ) ) {
				return array(
					'results' => $results,
					'stats'   => $stats,
				);
			}

			$current_host  = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$checked_urls  = array();
			$request_count = 0;

			$query = new WP_Query(
				array(
					'post_type'           => $settings['post_types'],
					'post_status'         => 'publish',
					'posts_per_page'      => (int) $settings['max_posts'],
					'orderby'             => 'ID',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$stats['sources_scanned']++;

				$source_url = get_permalink( $post );
				if ( ! $source_url ) {
					continue;
				}

				$source = array(
					'id'       => (int) $post->ID,
					'title'    => get_the_title( $post ),
					'type'     => self::get_post_type_label( $post->post_type ),
					'url'      => $source_url,
					'edit_url' => get_edit_post_link( $post->ID, '' ),
				);

				$links                 = self::extract_links( (string) $post->post_content );
				$stats['links_found'] += count( $links );

				foreach ( $links as $link ) {
					$row = self::audit_link( $link, $source, $settings, $current_host, $checked_urls, $request_count );

					if ( 'skip' === $row ) {
						$stats['skipped_external']++;
						continue;
					}

					$stats['links_audited']++;
					$results[] = $row;
				}
			}

			wp_reset_postdata();

			return array(
				'results' => $results,
				'stats'   => $stats,
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
		 * Audit a single extracted link.
		 *
		 * @param array{href:string,anchor:string} $link         Extracted link.
		 * @param array<string,mixed>              $source       Source post data.
		 * @param array<string,mixed>              $settings     Sanitized settings.
		 * @param string                           $current_host Normalized current site host.
		 * @param array<string,array<string,mixed>> $checked_urls Per-run URL request cache.
		 * @param int                              $request_count Per-run HTTP request count.
		 * @return array<string,mixed>|string
		 */
		private static function audit_link( array $link, array $source, array $settings, string $current_host, array &$checked_urls, int &$request_count ) {
			$linked_url = self::normalize_link_url( $link['href'], (string) $source['url'] );

			if ( '' === $linked_url ) {
				return self::build_result_row(
					$source,
					$link,
					$link['href'],
					'',
					'',
					'',
					'Invalid URL',
					'Error'
				);
			}

			$linked_host = self::normalize_host( (string) wp_parse_url( $linked_url, PHP_URL_HOST ) );
			$is_current  = self::hosts_match( $linked_host, $current_host );
			$is_old      = in_array( $linked_host, $settings['old_domain_hosts'], true );
			$is_staging  = ! $is_current && self::is_staging_or_dev_host( $linked_host );

			if ( ! $is_current && ! $is_old && ! $is_staging ) {
				return 'skip';
			}

			$warnings = array();
			if ( $is_old ) {
				$warnings[] = __( 'Old domain', 'wpfixpath-redirect-internal-link-auditor' );
			}
			if ( $is_staging ) {
				$warnings[] = __( 'Staging/dev domain', 'wpfixpath-redirect-internal-link-auditor' );
			}

			$should_request = $is_current;

			if ( ! $should_request ) {
				$warnings[] = __( 'Status check skipped by same-site scope', 'wpfixpath-redirect-internal-link-auditor' );

				return self::build_result_row(
					$source,
					$link,
					$linked_url,
					'',
					'',
					'',
					implode( '; ', $warnings ),
					'Needs review'
				);
			}

			if ( ! self::is_valid_http_url( $linked_url ) ) {
				$warnings[] = __( 'Invalid HTTP URL', 'wpfixpath-redirect-internal-link-auditor' );

				return self::build_result_row(
					$source,
					$link,
					$linked_url,
					'',
					'',
					'',
					implode( '; ', $warnings ),
					'Error'
				);
			}

			$cache_key = self::normalize_url_for_compare( $linked_url );

			if ( isset( $checked_urls[ $cache_key ] ) ) {
				$check = $checked_urls[ $cache_key ];
			} elseif ( $request_count >= self::MAX_LINK_REQUESTS ) {
				$warnings[] = sprintf(
					/* translators: %d: maximum number of same-site HTTP requests per run */
					__( 'Status check skipped after the %d-request cap was reached', 'wpfixpath-redirect-internal-link-auditor' ),
					self::MAX_LINK_REQUESTS
				);

				return self::build_result_row(
					$source,
					$link,
					$linked_url,
					'',
					'',
					'',
					self::format_warning_text( $warnings ),
					'Needs review'
				);
			} else {
				$request_count++;
				$check = self::check_url( $linked_url, (float) $settings['timeout'], (int) $settings['max_redirects'] );
				$checked_urls[ $cache_key ] = $check;
			}

			if ( ! $check['ok'] ) {
				if ( ! empty( $check['error'] ) ) {
					$warnings[] = $check['error'];
				}

				return self::build_result_row(
					$source,
					$link,
					$linked_url,
					implode( ' -> ', $check['statuses'] ),
					$check['redirect_count'],
					$check['final_url'],
					self::format_warning_text( $warnings ),
					'Error'
				);
			}

			$redirect_codes = $check['redirect_codes'];
			if ( count( $redirect_codes ) > 1 ) {
				$warnings[] = __( 'Redirect chain', 'wpfixpath-redirect-internal-link-auditor' );
			} elseif ( 1 === count( $redirect_codes ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP redirect status code */
					__( 'Redirect (%d)', 'wpfixpath-redirect-internal-link-auditor' ),
					(int) $redirect_codes[0]
				);
			}

			if ( ! empty( $check['redirect_limit_reached'] ) ) {
				$warnings[] = __( 'Redirect limit reached', 'wpfixpath-redirect-internal-link-auditor' );
			}

			if ( ! empty( $check['redirect_loop'] ) ) {
				$warnings[] = __( 'Redirect loop', 'wpfixpath-redirect-internal-link-auditor' );
			}

			if ( ! empty( $check['redirect_left_site'] ) ) {
				$warnings[] = __( 'Redirect leaves site; external target was not fetched.', 'wpfixpath-redirect-internal-link-auditor' );
			}

			$final_status = (int) $check['final_status'];
			if ( $final_status <= 0 ) {
				$warnings[] = __( 'No HTTP status returned', 'wpfixpath-redirect-internal-link-auditor' );
			} elseif ( in_array( $final_status, array( 404, 410 ), true ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Broken link (%d)', 'wpfixpath-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( in_array( $final_status, array( 401, 403, 429 ), true ) ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Blocked or rate limited (%d)', 'wpfixpath-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( $final_status >= 400 ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP error (%d)', 'wpfixpath-redirect-internal-link-auditor' ),
					$final_status
				);
			} elseif ( $final_status >= 300 && $final_status < 400 ) {
				$warnings[] = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Redirect without final target (%d)', 'wpfixpath-redirect-internal-link-auditor' ),
					$final_status
				);
			}

			$result = self::result_label_for_check( $warnings, $final_status, (int) $check['redirect_count'], $is_old, $is_staging );

			return self::build_result_row(
				$source,
				$link,
				$linked_url,
				implode( ' -> ', $check['statuses'] ),
				$check['redirect_count'],
				$check['final_url'],
				self::format_warning_text( $warnings ),
				$result
			);
		}

		/**
		 * Check a URL and follow redirects manually.
		 *
		 * @param string $url           URL to check.
		 * @param float  $timeout       Request timeout.
		 * @param int    $max_redirects Max redirects.
		 * @return array<string,mixed>
		 */
		private static function check_url( string $url, float $timeout, int $max_redirects ): array {
			$current_url            = $url;
			$statuses               = array();
			$redirect_codes         = array();
			$visited                = array();
			$redirect_count         = 0;
			$redirect_limit_reached = false;
			$redirect_loop          = false;
			$redirect_left_site     = false;
			$current_host           = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

			while ( true ) {
				if ( isset( $visited[ $current_url ] ) ) {
					$redirect_loop = true;
					break;
				}

				$visited[ $current_url ] = true;

				$response = self::request_url_without_redirects( $current_url, $timeout );

				if ( is_wp_error( $response ) ) {
					return array(
						'ok'                     => false,
						'error'                  => $response->get_error_message(),
						'statuses'               => $statuses,
						'redirect_count'         => $redirect_count,
						'redirect_codes'         => $redirect_codes,
						'final_status'           => 0,
						'final_url'              => $current_url,
						'redirect_limit_reached' => $redirect_limit_reached,
						'redirect_loop'          => $redirect_loop,
						'redirect_left_site'     => $redirect_left_site,
					);
				}

				$status     = (int) wp_remote_retrieve_response_code( $response );
				$statuses[] = (string) $status;

				if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
					return array(
						'ok'                     => true,
						'error'                  => '',
						'statuses'               => $statuses,
						'redirect_count'         => $redirect_count,
						'redirect_codes'         => $redirect_codes,
						'final_status'           => $status,
						'final_url'              => $current_url,
						'redirect_limit_reached' => $redirect_limit_reached,
						'redirect_loop'          => $redirect_loop,
						'redirect_left_site'     => $redirect_left_site,
					);
				}

				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( is_array( $location ) ) {
					$location = reset( $location );
				}
				$location = is_string( $location ) ? trim( $location ) : '';

				if ( '' === $location ) {
					return array(
						'ok'                     => true,
						'error'                  => '',
						'statuses'               => $statuses,
						'redirect_count'         => $redirect_count,
						'redirect_codes'         => $redirect_codes,
						'final_status'           => $status,
						'final_url'              => $current_url,
						'redirect_limit_reached' => $redirect_limit_reached,
						'redirect_loop'          => $redirect_loop,
						'redirect_left_site'     => $redirect_left_site,
					);
				}

				$redirect_count++;
				$redirect_codes[] = $status;

				if ( $redirect_count > $max_redirects ) {
					$redirect_limit_reached = true;
					break;
				}

				$next_url = self::make_absolute_url( $location, $current_url );

				if ( ! self::is_valid_http_url( $next_url ) ) {
					return array(
						'ok'                     => false,
						'error'                  => __( 'Invalid redirect target', 'wpfixpath-redirect-internal-link-auditor' ),
						'statuses'               => $statuses,
						'redirect_count'         => $redirect_count,
						'redirect_codes'         => $redirect_codes,
						'final_status'           => $status,
						'final_url'              => $next_url,
						'redirect_limit_reached' => $redirect_limit_reached,
						'redirect_loop'          => $redirect_loop,
						'redirect_left_site'     => $redirect_left_site,
					);
				}

				if ( ! self::hosts_match( self::normalize_host( (string) wp_parse_url( $next_url, PHP_URL_HOST ) ), $current_host ) ) {
					$redirect_left_site = true;

					return array(
						'ok'                     => true,
						'error'                  => '',
						'statuses'               => $statuses,
						'redirect_count'         => $redirect_count,
						'redirect_codes'         => $redirect_codes,
						'final_status'           => $status,
						'final_url'              => $next_url,
						'redirect_limit_reached' => false,
						'redirect_loop'          => false,
						'redirect_left_site'     => true,
					);
				}

				$current_url = $next_url;
			}

			return array(
				'ok'                     => true,
				'error'                  => '',
				'statuses'               => $statuses,
				'redirect_count'         => $redirect_count,
				'redirect_codes'         => $redirect_codes,
				'final_status'           => count( $statuses ) ? (int) end( $statuses ) : 0,
				'final_url'              => $current_url,
				'redirect_limit_reached' => $redirect_limit_reached,
				'redirect_loop'          => $redirect_loop,
				'redirect_left_site'     => $redirect_left_site,
			);
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
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'WPFixPath Redirect & Internal Link Auditor/' . self::VERSION . '; ' . home_url( '/' ),
			);

			$response = wp_remote_head( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $status, array( 0, 403, 405, 501 ), true ) ) {
				return $response;
			}

			$args['method']              = 'GET';
			$args['limit_response_size'] = 4096;

			return wp_remote_request( $url, $args );
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
		 * @return array<string,mixed>
		 */
		private static function build_result_row( array $source, array $link, string $linked_url, string $http_status, $redirect_count, string $final_url, string $warning, string $result ): array {
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
			);
		}

		/**
		 * Get a conservative result label.
		 *
		 * @param array<int,string> $warnings       Warning texts.
		 * @param int               $final_status   Final HTTP status.
		 * @param int               $redirect_count Redirect count.
		 * @param bool              $is_old         Whether link uses old domain.
		 * @param bool              $is_staging     Whether link uses staging/dev host.
		 */
		private static function result_label_for_check( array $warnings, int $final_status, int $redirect_count, bool $is_old, bool $is_staging ): string {
			if ( $final_status <= 0 ) {
				return 'Needs review';
			}

			if ( in_array( $final_status, array( 401, 403, 429 ), true ) ) {
				return 'Blocked';
			}

			if ( in_array( $final_status, array( 404, 410 ), true ) || $final_status >= 500 ) {
				return 'Error';
			}

			if ( $final_status >= 400 ) {
				return 'Needs review';
			}

			if ( $redirect_count > 0 ) {
				return 'Warning';
			}

			if ( $is_old || $is_staging || ! empty( $warnings ) ) {
				return 'Needs review';
			}

			return 'OK';
		}

		/**
		 * Format warning text.
		 *
		 * @param array<int,string> $warnings Warning texts.
		 */
		private static function format_warning_text( array $warnings ): string {
			$warnings = array_filter( array_map( 'trim', $warnings ) );

			return empty( $warnings ) ? __( 'None', 'wpfixpath-redirect-internal-link-auditor' ) : implode( '; ', array_unique( $warnings ) );
		}

		/**
		 * Send CSV response and terminate.
		 *
		 * @param array<int,array<string,mixed>> $results Result rows.
		 */
		private static function send_csv( array $results ): void {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=wpfixpath-redirect-internal-link-auditor-' . gmdate( 'Y-m-d-His' ) . '.csv' );

			$output = fopen( 'php://output', 'w' );
			if ( false === $output ) {
				exit;
			}

			fputcsv(
				$output,
				array(
					'Source Post/Page',
					'Source Type',
					'Source URL',
					'Linked URL',
					'HTTP Status',
					'Redirect Count',
					'Final URL',
					'Warning',
					'Anchor Text',
					'Result',
				)
			);

			foreach ( $results as $row ) {
				fputcsv(
					$output,
					array(
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
					)
				);
			}

			fclose( $output );
			exit;
		}

		/**
		 * Avoid spreadsheet formula execution on CSV open.
		 */
		private static function csv_safe( string $value ): string {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

			if ( '' !== $value && preg_match( '/^[=+\-@\t]/', $value ) ) {
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
		 * Normalize a URL for per-run request cache keys.
		 */
		private static function normalize_url_for_compare( string $url ): string {
			$url   = preg_replace( '/#.*/', '', $url );
			$url   = is_string( $url ) ? $url : '';
			$parts = wp_parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return strtolower( untrailingslashit( $url ) );
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			$host   = self::normalize_host( (string) $parts['host'] );
			$port   = empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'];
			$path   = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '/';
			$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

			if ( '' === $path ) {
				$path = '/';
			}

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

			return '/' . implode( '/', $output ) . $query;
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
				return __( '(empty anchor)', 'wpfixpath-redirect-internal-link-auditor' );
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

	WPFixPath_Redirect_Internal_Link_Auditor::init();
}
