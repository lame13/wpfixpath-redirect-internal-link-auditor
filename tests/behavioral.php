<?php
/**
 * Focused behavioral tests for the single-file plugin.
 *
 * Run with: php tests/behavioral.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['wpfixpath_test_http_calls'] = array();
$GLOBALS['wpfixpath_test_responses']  = array();
$GLOBALS['wpfixpath_test_transients'] = array();
$GLOBALS['wpfixpath_test_user_id']    = 7;
$GLOBALS['wpfixpath_test_uuid_count'] = 0;
$GLOBALS['wpfixpath_test_translations'] = array();

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

function add_action(): void {}
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
	return isset( $GLOBALS['wpfixpath_test_translations'][ $text ] )
		? $GLOBALS['wpfixpath_test_translations'][ $text ]
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
	$GLOBALS['wpfixpath_test_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( ! array_key_exists( $url, $GLOBALS['wpfixpath_test_responses'] ) ) {
		return new WP_Error( 'Unexpected URL: ' . $url );
	}

	return $GLOBALS['wpfixpath_test_responses'][ $url ];
}
function wp_generate_uuid4(): string {
	$GLOBALS['wpfixpath_test_uuid_count']++;
	return sprintf( '12345678-1234-4abc-8def-%012d', $GLOBALS['wpfixpath_test_uuid_count'] );
}
function get_current_user_id(): int {
	return (int) $GLOBALS['wpfixpath_test_user_id'];
}
function set_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['wpfixpath_test_transients'][ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}
function get_transient( string $key ) {
	return isset( $GLOBALS['wpfixpath_test_transients'][ $key ] )
		? $GLOBALS['wpfixpath_test_transients'][ $key ]['value']
		: false;
}

require dirname( __DIR__ ) . '/wpfixpath-redirect-internal-link-auditor.php';

/**
 * @return mixed
 */
function wpfixpath_invoke( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'WPFixPath_Redirect_Internal_Link_Auditor', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

function wpfixpath_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite(
			STDERR,
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n"
		);
		exit( 1 );
	}
}

function wpfixpath_response( int $status, string $location = '' ): array {
	return array(
		'response' => array( 'code' => $status ),
		'headers'  => '' === $location ? array() : array( 'location' => $location ),
		'body'     => '',
	);
}

/**
 * Build one complete stored result row for report tests.
 *
 * @return array<string,mixed>
 */
function wpfixpath_result_row(
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

$without_slash = wpfixpath_invoke( 'normalize_url_for_compare', array( 'https://Example.test/foo#section' ) );
$with_slash    = wpfixpath_invoke( 'normalize_url_for_compare', array( 'https://example.test/foo/' ) );
wpfixpath_assert_same( 'https://example.test/foo', $without_slash, 'The cache key should normalize scheme and host case.' );
wpfixpath_assert_same( 'https://example.test/foo/', $with_slash, 'The cache key should preserve a trailing slash.' );
$resolved_with_query = wpfixpath_invoke( 'make_absolute_url', array( '/foo/?page=2', 'https://example.test/source/' ) );
wpfixpath_assert_same( 'https://example.test/foo/?page=2', $resolved_with_query, 'Relative redirect resolution should preserve a trailing slash before a query string.' );

$normalized_destination = wpfixpath_invoke( 'normalize_destination_for_impact', array( 'https://Example.test:443/foo#section' ) );
wpfixpath_assert_same( 'https://example.test/foo', $normalized_destination, 'Impact grouping should normalize scheme/host case, default ports, and fragments.' );
wpfixpath_assert_same(
	'https://example.test/foo/?page=2',
	wpfixpath_invoke( 'normalize_destination_for_impact', array( 'https://example.test/foo/?page=2#fragment' ) ),
	'Impact grouping should preserve trailing slashes and query strings.'
);

$impact_input = array(
	wpfixpath_result_row(
		'https://Example.test/source-a#top',
		'https://Example.test:443/broken#first',
		'404',
		0,
		'https://example.test/broken',
		'Broken link (404)',
		'Error'
	),
	wpfixpath_result_row(
		'https://example.test/source-a',
		'https://example.test/broken#second',
		'404',
		0,
		'https://example.test/broken',
		'Broken link (404)',
		'Error'
	),
	wpfixpath_result_row(
		'https://example.test/source-b',
		'https://example.test/broken',
		'301 -> 404',
		1,
		'https://example.test/gone',
		'Redirect (301); Broken link (404)',
		'Error'
	),
	wpfixpath_result_row(
		'https://example.test/source-c',
		'https://example.test/broken/',
		'410',
		0,
		'https://example.test/broken/',
		'Broken link (410)',
		'Error'
	),
	wpfixpath_result_row(
		'https://example.test/source-d',
		'https://example.test/server-error',
		'503',
		0,
		'https://example.test/server-error',
		'HTTP error (503)',
		'Error'
	),
	wpfixpath_result_row(
		'https://example.test/source-e',
		'https://example.test/no-location',
		'302',
		0,
		'https://example.test/no-location',
		'Redirect without final target (302)',
		'Needs review'
	),
	wpfixpath_result_row(
		'https://example.test/source-f',
		'https://example.test/alpha',
		'301 -> 200',
		1,
		'https://example.test/new-alpha',
		'Redirect (301)',
		'Warning'
	),
	wpfixpath_result_row(
		'https://example.test/source-g',
		'https://example.test/beta',
		'301 -> 200',
		1,
		'https://example.test/new-beta',
		'Redirect (301)',
		'Warning'
	),
	wpfixpath_result_row(
		'https://example.test/source-h',
		'https://example.test/query?id=1',
		'302 -> 200',
		1,
		'https://example.test/new-query?id=1',
		'Redirect (302)',
		'Warning'
	),
	wpfixpath_result_row(
		'https://example.test/source-i',
		'https://example.test/query?id=2',
		'302 -> 200',
		1,
		'https://example.test/new-query?id=2',
		'Redirect (302)',
		'Warning'
	),
	wpfixpath_result_row(
		'https://example.test/source-z',
		'https://example.test/healthy',
		'200',
		0,
		'https://example.test/healthy',
		'None',
		'OK'
	),
);

$http_calls_before_impact = count( $GLOBALS['wpfixpath_test_http_calls'] );
$impact_rows              = wpfixpath_invoke( 'build_destination_impact', array( $impact_input ) );
wpfixpath_assert_same( $http_calls_before_impact, count( $GLOBALS['wpfixpath_test_http_calls'] ), 'Impact aggregation must not make HTTP requests.' );
wpfixpath_assert_same( 8, count( $impact_rows ), 'Only broken/error and redirected destinations should appear in the impact view.' );
wpfixpath_assert_same( 'https://example.test/broken', $impact_rows[0]['destination_url'], 'The highest-impact destination should sort first.' );
wpfixpath_assert_same( 3, $impact_rows[0]['occurrences'], 'Repeated links should count as separate occurrences.' );
wpfixpath_assert_same( 2, $impact_rows[0]['affected_sources'], 'Repeated links in one source should count as one affected content item.' );
wpfixpath_assert_same( 'Broken/error after redirect', $impact_rows[0]['impact'], 'A broken target with redirect evidence should expose both conditions.' );
wpfixpath_assert_same( '301 -> 404 | 404', $impact_rows[0]['http_status_evidence'], 'Status variants should be deduplicated and sorted deterministically.' );
wpfixpath_assert_same( 1, $impact_rows[0]['max_redirect_count'], 'The aggregate should retain the maximum observed redirect count.' );
wpfixpath_assert_same(
	'https://example.test/broken | https://example.test/gone',
	$impact_rows[0]['effective_final_url'],
	'All distinct effective final URLs should be preserved as deterministic evidence.'
);

$impact_destinations = array_column( $impact_rows, 'destination_url' );
wpfixpath_assert_same(
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
wpfixpath_assert_same( 'Redirect', $impact_rows[3]['impact'], 'A terminal redirect without a Location header must still appear as redirect impact.' );

$GLOBALS['wpfixpath_test_translations'] = array(
	'Error'        => 'Fehler',
	'Broken/error' => 'Defekt/Fehler',
);
$localized_impact = wpfixpath_invoke(
	'build_destination_impact',
	array(
		array(
			wpfixpath_result_row(
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
wpfixpath_assert_same( 1, count( $localized_impact ), 'Localized Error results should remain actionable without English warning matching.' );
wpfixpath_assert_same( 'not a valid absolute URL', $localized_impact[0]['destination_url'], 'Invalid linked URLs should remain visible under a raw fallback group key.' );
wpfixpath_assert_same( 'Defekt/Fehler', $localized_impact[0]['impact'], 'Impact labels should use the active translation.' );
wpfixpath_assert_same( 'Fehler', $localized_impact[0]['result'], 'Localized result labels should retain their severity.' );
$GLOBALS['wpfixpath_test_translations'] = array();

$details_csv = wpfixpath_invoke(
	'build_csv_rows',
	array(
		array(
			wpfixpath_result_row(
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
wpfixpath_assert_same( 'Source Post/Page', $details_csv[0][0], 'Detailed CSV should retain its existing first column.' );
wpfixpath_assert_same( '\' =HYPERLINK("https://attacker.test")', $details_csv[1][0], 'CSV safety should block formulas after leading spaces.' );
wpfixpath_assert_same( "'\n+SUM(1,1)", $details_csv[1][8], 'CSV safety should block formulas after leading newlines.' );

$impact_csv_input = array(
	wpfixpath_result_row(
		'https://example.test/source',
		'https://example.test/broken',
		'404',
		0,
		'https://example.test/broken',
		'=IMPORTXML("https://attacker.test") Broken link (404)',
		'Error'
	),
);
$impact_csv = wpfixpath_invoke( 'build_csv_rows', array( $impact_csv_input, 'impact' ) );
wpfixpath_assert_same( 'Destination', $impact_csv[0][0], 'Impact CSV should have a destination-centric header.' );
wpfixpath_assert_same( '\'=IMPORTXML("https://attacker.test") Broken link (404)', $impact_csv[1][8], 'Impact evidence should receive the same CSV formula protection.' );
wpfixpath_assert_same( "'\t@SUM(1,1)", wpfixpath_invoke( 'csv_safe', array( "\t@SUM(1,1)" ) ), 'CSV safety should block formulas after a leading tab.' );
wpfixpath_assert_same( "'-2+3", wpfixpath_invoke( 'csv_safe', array( '-2+3' ) ), 'CSV safety should block minus-prefixed formulas.' );
wpfixpath_assert_same( ' ordinary text', wpfixpath_invoke( 'csv_safe', array( ' ordinary text' ) ), 'CSV safety should not alter non-formula text.' );

$GLOBALS['wpfixpath_test_http_calls'] = array();
$GLOBALS['wpfixpath_test_responses']  = array(
	'https://example.test/foo'  => wpfixpath_response( 301, '/foo/' ),
	'https://example.test/foo/' => wpfixpath_response( 200 ),
);
$request_count = 0;
$check_args    = array( 'https://example.test/foo', 2.0, 5, &$request_count );
$check         = wpfixpath_invoke( 'check_url', $check_args );
wpfixpath_assert_same( array( '301', '200' ), $check['statuses'], 'GET evidence should include the redirect and distinct trailing-slash target.' );
wpfixpath_assert_same( false, $check['redirect_loop'], 'A normal /foo to /foo/ redirect must not be treated as a loop.' );
wpfixpath_assert_same( 'https://example.test/foo/', $check['final_url'], 'The trailing-slash destination should be fetched.' );
wpfixpath_assert_same( 2, $request_count, 'Every redirect hop should consume exactly one request-budget unit.' );
wpfixpath_assert_same( 2, count( $GLOBALS['wpfixpath_test_http_calls'] ), 'Each budget unit should map to one HTTP API call.' );
wpfixpath_assert_same( 0, $GLOBALS['wpfixpath_test_http_calls'][0]['args']['redirection'], 'WordPress must not follow redirects outside the audited redirect loop.' );
wpfixpath_assert_same( true, $GLOBALS['wpfixpath_test_http_calls'][0]['args']['reject_unsafe_urls'], 'Requests must use unsafe-URL rejection.' );
wpfixpath_assert_same( 4096, $GLOBALS['wpfixpath_test_http_calls'][0]['args']['limit_response_size'], 'GET response bodies must be bounded.' );

$GLOBALS['wpfixpath_test_http_calls'] = array();
$GLOBALS['wpfixpath_test_responses']  = array(
	'https://example.test/start' => wpfixpath_response( 302, '/finish' ),
	'https://example.test/finish' => wpfixpath_response( 200 ),
);
$request_count = 249;
$budget_args   = array( 'https://example.test/start', 2.0, 5, &$request_count );
$budget_check  = wpfixpath_invoke( 'check_url', $budget_args );
wpfixpath_assert_same( true, $budget_check['budget_exhausted'], 'A redirect chain should stop when the actual request budget is exhausted.' );
wpfixpath_assert_same( 250, $request_count, 'The actual request count must never exceed the hard limit.' );
wpfixpath_assert_same( 1, count( $GLOBALS['wpfixpath_test_http_calls'] ), 'No HTTP call may occur after request 250.' );

$results = array( array( 'linked_url' => 'https://example.test/foo/' ) );
$token   = wpfixpath_invoke( 'store_export_results', array( $results ) );
$loaded  = wpfixpath_invoke( 'get_export_results', array( $token ) );
wpfixpath_assert_same( $results, $loaded, 'CSV export should load the exact saved result set.' );
$saved_transient = reset( $GLOBALS['wpfixpath_test_transients'] );
wpfixpath_assert_same( 3600, $saved_transient['expiration'], 'Saved export results should expire after one hour.' );

$GLOBALS['wpfixpath_test_user_id'] = 8;
$other_user_results = wpfixpath_invoke( 'get_export_results', array( $token ) );
wpfixpath_assert_same( null, $other_user_results, 'Saved export results must be scoped to the administrator who ran the scan.' );

fwrite( STDOUT, "All behavioral tests passed.\n" );
