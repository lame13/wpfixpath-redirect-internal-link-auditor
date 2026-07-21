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
	return $text;
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

$without_slash = wpfixpath_invoke( 'normalize_url_for_compare', array( 'https://Example.test/foo#section' ) );
$with_slash    = wpfixpath_invoke( 'normalize_url_for_compare', array( 'https://example.test/foo/' ) );
wpfixpath_assert_same( 'https://example.test/foo', $without_slash, 'The cache key should normalize scheme and host case.' );
wpfixpath_assert_same( 'https://example.test/foo/', $with_slash, 'The cache key should preserve a trailing slash.' );
$resolved_with_query = wpfixpath_invoke( 'make_absolute_url', array( '/foo/?page=2', 'https://example.test/source/' ) );
wpfixpath_assert_same( 'https://example.test/foo/?page=2', $resolved_with_query, 'Relative redirect resolution should preserve a trailing slash before a query string.' );

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
