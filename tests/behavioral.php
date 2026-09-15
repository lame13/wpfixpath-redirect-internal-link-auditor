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
$GLOBALS['indexlane_test_user_options'] = array();
$GLOBALS['indexlane_test_user_id']    = 7;
$GLOBALS['indexlane_test_uuid_count'] = 0;
$GLOBALS['indexlane_test_translations'] = array();
$GLOBALS['indexlane_test_actions']      = array();
$GLOBALS['indexlane_test_filters']      = array();
$GLOBALS['indexlane_test_admin_pages']  = array();
$GLOBALS['indexlane_test_styles']       = array();
$GLOBALS['indexlane_test_scripts']      = array();
$GLOBALS['indexlane_test_localizations'] = array();
$GLOBALS['indexlane_test_plugin_url_files'] = array();

class WP_Error {
	/** @var string */
	private $code;

	/** @var string */
	private $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = '' === $message ? 'error' : $code;
		$this->message = '' === $message ? $code : $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function add_action( string $hook_name, $callback ): void {
	$GLOBALS['indexlane_test_actions'][ $hook_name ][] = $callback;
}
function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['indexlane_test_filters'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
	return true;
}
function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
	if ( empty( $GLOBALS['indexlane_test_filters'][ $hook_name ][ $priority ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['indexlane_test_filters'][ $hook_name ][ $priority ] as $index => $registered ) {
		if ( $registered['callback'] === $callback ) {
			unset( $GLOBALS['indexlane_test_filters'][ $hook_name ][ $priority ][ $index ] );
			return true;
		}
	}
	return false;
}
function apply_filters( string $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['indexlane_test_filters'][ $hook_name ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['indexlane_test_filters'][ $hook_name ], SORT_NUMERIC );
	foreach ( $GLOBALS['indexlane_test_filters'][ $hook_name ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$arguments = array_slice( array_merge( array( $value ), $args ), 0, $registered['accepted_args'] );
			$value     = call_user_func_array( $registered['callback'], $arguments );
		}
	}
	return $value;
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
	$GLOBALS['indexlane_test_plugin_url_files'][] = $plugin_file;
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
function wp_enqueue_script( string $handle, string $src, array $dependencies = array(), string $version = '', bool $in_footer = false ): void {
	$GLOBALS['indexlane_test_scripts'][] = array(
		'handle'       => $handle,
		'src'          => $src,
		'dependencies' => $dependencies,
		'version'      => $version,
		'in_footer'    => $in_footer,
	);
}
function wp_localize_script( string $handle, string $object_name, array $data ): bool {
	$GLOBALS['indexlane_test_localizations'][] = array(
		'handle'      => $handle,
		'object_name' => $object_name,
		'data'        => $data,
	);
	return true;
}
function wp_create_nonce( string $action ): string {
	return 'test-nonce-for-' . $action;
}
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function plugin_basename( string $file ): string {
	return basename( $file );
}
function home_url(): string {
	return 'https://example.test/';
}
function get_post_types( array $args = array(), string $output = 'names' ): array {
	$objects = array();
	foreach ( array( 'post' => 'Posts', 'page' => 'Pages', 'indexlane_fixture' => 'Audit fixtures' ) as $name => $label ) {
		$object                        = new stdClass();
		$object->labels                = new stdClass();
		$object->labels->name          = $label;
		$object->labels->singular_name = rtrim( $label, 's' );
		$objects[ $name ]              = $object;
	}

	return 'objects' === $output ? $objects : array_keys( $objects );
}
function get_post_type_object( string $post_type ) {
	$objects = get_post_types( array( 'public' => true ), 'objects' );
	return isset( $objects[ $post_type ] ) ? $objects[ $post_type ] : null;
}
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
}
function sanitize_textarea_field( string $value ): string {
	return trim( str_replace( "\0", '', $value ) );
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( str_replace( "\0", '', $value ) ) );
}
function wp_specialchars_decode( string $value, int $flags = ENT_QUOTES ): string {
	return html_entity_decode( $value, $flags | ENT_HTML5, 'UTF-8' );
}
function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}
function absint( $value ): int {
	return abs( (int) $value );
}
function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( string $text ): string {
	return isset( $GLOBALS['indexlane_test_translations'][ $text ] )
		? $GLOBALS['indexlane_test_translations'][ $text ]
		: $text;
}
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	unset( $domain );
	return __( 1 === $number ? $single : $plural );
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ): string {
	return (string) $url;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( __( $text ) );
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( __( $text ) );
}
function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo esc_attr( __( $text ) );
}
function wp_remote_retrieve_response_code( $response ): int {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_header( $response, string $name ) {
	return isset( $response['headers'][ strtolower( $name ) ] ) ? $response['headers'][ strtolower( $name ) ] : '';
}
function wp_remote_retrieve_body( $response ): string {
	return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
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
function get_user_option( string $option, int $user_id = 0 ) {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	return isset( $GLOBALS['indexlane_test_user_options'][ $user_id ][ $option ] )
		? $GLOBALS['indexlane_test_user_options'][ $user_id ][ $option ]
		: false;
}
function update_user_option( int $user_id, string $option, $value, bool $global = false ) {
	$GLOBALS['indexlane_test_user_options'][ $user_id ][ $option ] = $value;
	return true;
}
function delete_user_option( int $user_id, string $option, bool $global = false ): bool {
	if ( ! isset( $GLOBALS['indexlane_test_user_options'][ $user_id ][ $option ] ) ) {
		return false;
	}
	unset( $GLOBALS['indexlane_test_user_options'][ $user_id ][ $option ] );
	return true;
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
function delete_transient( string $key ): bool {
	if ( ! isset( $GLOBALS['indexlane_test_transients'][ $key ] ) ) {
		return false;
	}
	unset( $GLOBALS['indexlane_test_transients'][ $key ] );
	return true;
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

$repository_root  = dirname( __DIR__ );
$plugin_source    = file_get_contents( $repository_root . '/indexlane-redirect-internal-link-auditor.php' );
$readme_source    = file_get_contents( $repository_root . '/readme.txt' );
$changelog_source = file_get_contents( $repository_root . '/CHANGELOG.md' );
if ( false === $plugin_source || false === $readme_source || false === $changelog_source ) {
	fwrite( STDERR, "Release metadata files could not be read.\n" );
	exit( 1 );
}
if ( ! preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*([^\s]+)[ \t]*$/m', $plugin_source, $plugin_version_match ) ) {
	fwrite( STDERR, "The plugin header version could not be read.\n" );
	exit( 1 );
}
if ( ! preg_match( '/^Stable tag:[ \t]*([^\s]+)[ \t]*$/m', $readme_source, $stable_tag_match ) ) {
	fwrite( STDERR, "The WordPress.org stable tag could not be read.\n" );
	exit( 1 );
}
if ( ! preg_match( '/^##[ \t]+([0-9]+\.[0-9]+\.[0-9]+)[ \t]+-/m', $changelog_source, $changelog_version_match ) ) {
	fwrite( STDERR, "The latest root changelog version could not be read.\n" );
	exit( 1 );
}
if ( ! preg_match( '/^=[ \t]+([0-9]+\.[0-9]+\.[0-9]+)[ \t]+=$/m', $readme_source, $readme_changelog_version_match ) ) {
	fwrite( STDERR, "The latest WordPress.org changelog version could not be read.\n" );
	exit( 1 );
}

$release_version   = (string) $plugin_version_match[1];
$plugin_reflection = new ReflectionClass( 'IndexLane_Redirect_Internal_Link_Auditor' );
indexlane_assert_same( $release_version, $plugin_reflection->getConstant( 'VERSION' ), 'The internal plugin version must match the plugin header.' );
indexlane_assert_same( $release_version, (string) $stable_tag_match[1], 'The WordPress.org stable tag must match the plugin header.' );
indexlane_assert_same( $release_version, (string) $changelog_version_match[1], 'The latest root changelog entry must match the plugin header.' );
indexlane_assert_same( $release_version, (string) $readme_changelog_version_match[1], 'The latest WordPress.org changelog entry must match the plugin header.' );

function indexlane_response( int $status, string $location = '', string $body = '', array $headers = array() ): array {
	$response_headers = array();
	foreach ( $headers as $name => $value ) {
		$response_headers[ strtolower( (string) $name ) ] = $value;
	}
	if ( '' !== $location ) {
		$response_headers['location'] = $location;
	}

	return array(
		'response' => array( 'code' => $status ),
		'headers'  => $response_headers,
		'body'     => $body,
	);
}

/**
 * Build an HTML response for destination-intent coverage.
 *
 * @param string               $body    Response body.
 * @param array<string,string> $headers Extra response headers.
 */
function indexlane_html_response( string $body, array $headers = array() ): array {
	$headers = array_merge( array( 'content-type' => 'text/html; charset=UTF-8' ), $headers );

	return indexlane_response( 200, '', $body, $headers );
}

indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_menu'] ), 'The Tools page must be registered on admin_menu.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_enqueue_scripts'] ), 'Admin styles must use admin_enqueue_scripts.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['admin_init'] ), 'CSV exports must remain registered on admin_init.' );
$admin_init_methods = array_map(
	static function ( array $callback ): string {
		return isset( $callback[1] ) ? (string) $callback[1] : '';
	},
	$GLOBALS['indexlane_test_actions']['admin_init']
);
indexlane_assert_same( true, in_array( 'maybe_handle_baseline_action', $admin_init_methods, true ), 'Baseline management must use a capability- and nonce-protected admin request.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['wp_ajax_indexlane_rila_start_scan'] ), 'Starting a scan must use authenticated WordPress AJAX.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['wp_ajax_indexlane_rila_run_batch'] ), 'Scan batches must use authenticated WordPress AJAX.' );
indexlane_assert_same( true, isset( $GLOBALS['indexlane_test_actions']['wp_ajax_indexlane_rila_control_scan'] ), 'Scan controls must use authenticated WordPress AJAX.' );

indexlane_invoke( 'register_admin_page' );
indexlane_assert_same(
	'indexlane-redirect-internal-link-auditor',
	$GLOBALS['indexlane_test_admin_pages'][0]['menu_slug'],
	'The Tools page must retain the submitted plugin slug.'
);

indexlane_invoke( 'enqueue_admin_assets', array( 'dashboard' ) );
indexlane_assert_same( array(), $GLOBALS['indexlane_test_styles'], 'The plugin stylesheet must not load on unrelated admin pages.' );
indexlane_assert_same( array(), $GLOBALS['indexlane_test_scripts'], 'The plugin browser controller must not load on unrelated admin pages.' );

indexlane_invoke( 'enqueue_admin_assets', array( 'tools_page_indexlane-redirect-internal-link-auditor' ) );
indexlane_assert_same(
	array(
		array(
			'handle'       => 'indexlane-rila-admin',
			'src'          => 'https://example.test/wp-content/plugins/indexlane-redirect-internal-link-auditor/assets/admin.css',
			'dependencies' => array(),
			'version'      => $release_version,
		),
	),
	$GLOBALS['indexlane_test_styles'],
	'The plugin stylesheet must load with the release version only on its Tools page.'
);
indexlane_assert_same(
	array(
		array(
			'handle'       => 'indexlane-rila-admin',
			'src'          => 'https://example.test/wp-content/plugins/indexlane-redirect-internal-link-auditor/assets/admin.js',
			'dependencies' => array(),
			'version'      => $release_version,
			'in_footer'    => true,
		),
	),
	$GLOBALS['indexlane_test_scripts'],
	'The authenticated batch controller must load with the release version only on its Tools page.'
);
$expected_plugin_file = dirname( __DIR__ ) . '/indexlane-redirect-internal-link-auditor.php';
indexlane_assert_same(
	array( $expected_plugin_file, $expected_plugin_file ),
	$GLOBALS['indexlane_test_plugin_url_files'],
	'Admin assets must resolve relative to the root plugin bootstrap after the class is split into include files.'
);
indexlane_assert_same( 'IndexLaneRila', $GLOBALS['indexlane_test_localizations'][0]['object_name'], 'The browser controller must receive its nonce and persisted session summary.' );

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
	string $anchor_text = 'Link',
	array $extra = array()
): array {
	$result_codes = array(
		'OK'           => 'ok',
		'Warning'      => 'warning',
		'Needs review' => 'needs_review',
		'Blocked'      => 'blocked',
		'Error'        => 'error',
	);
	$result_code  = isset( $result_codes[ $result ] ) ? $result_codes[ $result ] : 'needs_review';

	$row = array_merge(
		array(
			'source_id'       => 0,
			'source_key'      => 'content:test:' . substr( hash( 'sha256', strtolower( (string) preg_replace( '/#.*$/', '', $source_url ) ) ), 0, 16 ),
			'source_title'    => $source_title,
			'source_type'     => 'Page',
			'source_type_code' => 'content',
			'source_context'  => 'contextual',
			'source_content_id' => 0,
			'source_url'      => $source_url,
			'source_edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit',
			'linked_url'      => $linked_url,
			'http_status'     => $http_status,
			'redirect_count'  => $redirect_count,
			'final_url'       => $final_url,
			'warning'         => $warning,
			'anchor_text'     => $anchor_text,
			'link_rel'        => '',
			'result'          => $result,
			'result_code'     => $result_code,
			'intent_code'     => '',
			'intent_severity' => '',
			'intent_detail'   => '',
			'is_same_site'    => true,
			'direct_target_id' => 0,
			'final_target_id'  => 0,
			'coverage_target_id' => 0,
			'link_kind_code'   => $redirect_count > 0 ? 'redirected' : 'direct',
		),
		$extra
	);
	if ( ! array_key_exists( 'source_content_id', $extra ) ) {
		$row['source_content_id'] = (int) $row['source_id'];
	}

	return $row;
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

$attribute_links = indexlane_invoke(
	'extract_navigation_attribute_links',
	array(
		array(
			array(
				'blockName'   => 'core/navigation-link',
				'attrs'       => array( 'url' => '/stored-navigation', 'label' => 'Stored navigation' ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/navigation-submenu',
				'attrs'       => array( 'url' => '/parent', 'label' => 'Parent' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/navigation-link',
						'attrs'       => array( 'url' => '/child', 'label' => 'Child' ),
						'innerBlocks' => array(),
					),
				),
			),
			array(
				'blockName'   => 'core/social-link',
				'attrs'       => array( 'url' => '/serialized-social' ),
				'innerHTML'   => '<li><a href="/serialized-social"><span>Social profile</span></a></li>',
				'innerBlocks' => array(),
			),
		)
	)
);
indexlane_assert_same(
	array(
		array( 'href' => '/stored-navigation', 'anchor' => 'Stored navigation', 'rel' => '' ),
		array( 'href' => '/parent', 'anchor' => 'Parent', 'rel' => '' ),
		array( 'href' => '/child', 'anchor' => 'Child', 'rel' => '' ),
	),
	$attribute_links,
	'Self-closing Navigation blocks must retain stored URLs without duplicating links already present in serialized markup.'
);

$invalid_shared_content = indexlane_invoke(
	'normalize_source_record',
	array(
		array(
			'key'        => 'fixture:shared-content',
			'id'         => 9,
			'content_id' => 9,
			'title'      => 'Invalid shared content',
			'type'       => 'Fixture surface',
			'context'    => 'shared',
			'base_url'   => 'https://example.test/',
			'links'      => array(),
		),
		'fixture',
		array( 'label' => 'Fixture surface', 'context' => 'shared' ),
	)
);
indexlane_assert_same( true, is_wp_error( $invalid_shared_content ), 'Shared providers must not masquerade as contextual content targets.' );

$invalid_content_target = indexlane_invoke(
	'normalize_source_record',
	array(
		array(
			'key'        => 'fixture:invalid-target',
			'id'         => 10,
			'content_id' => 10,
			'title'      => 'Invalid target',
			'type'       => 'Fixture content',
			'context'    => 'contextual',
			'base_url'   => 'https://example.test/',
			'links'      => array(),
			'content_item' => array(
				'id'       => 10,
				'title'    => 'Invalid target',
				'type'     => 'Fixture content',
				'url'      => 'javascript:alert(1)',
				'edit_url' => 'https://example.test/wp-admin/post.php?post=10&action=edit',
			),
		),
		'fixture',
		array( 'label' => 'Fixture content', 'context' => 'contextual' ),
	)
);
indexlane_assert_same( true, is_wp_error( $invalid_content_target ), 'Provider coverage targets must use bounded HTTP URLs.' );

$pending_source_session = array(
	'settings'      => array( 'old_domain_hosts' => array() ),
	'stats'         => indexlane_invoke( 'empty_stats' ),
	'content_items' => array(),
	'content_item_ids' => array(),
	'results'       => array(),
	'checked_urls'  => array(),
	'pending_checks' => array(),
);
$pending_source_session = indexlane_invoke(
	'process_source_item',
	array(
		$pending_source_session,
		array(
			'key'          => 'fixture:large-stored-source',
			'id'           => 11,
			'content_id'   => 0,
			'title'        => 'Large stored source',
			'type'         => 'Fixture surface',
			'type_code'    => 'fixture',
			'context'      => 'shared',
			'url'          => '',
			'edit_url'     => 'https://example.test/wp-admin/fixture.php?id=11',
			'base_url'     => 'https://example.test/',
			'content'      => str_repeat( 'stored source body', 1000 ),
			'links'        => array( array( 'href' => '/pending-target', 'anchor' => 'Pending target' ) ),
			'content_item' => null,
		),
	)
);
$pending_source_check      = reset( $pending_source_session['pending_checks'] );
$pending_source_occurrence = $pending_source_check['occurrences'][0]['source'];
indexlane_assert_same( false, array_key_exists( 'content', $pending_source_occurrence ), 'Pending HTTP work must not duplicate a stored source body in the scan transient.' );
indexlane_assert_same( false, array_key_exists( 'links', $pending_source_occurrence ), 'Pending HTTP work must not duplicate an extracted link list in the scan transient.' );
indexlane_assert_same( false, array_key_exists( 'content_item', $pending_source_occurrence ), 'Pending HTTP work must retain only source identity evidence.' );

$fixture_provider_filter = static function ( array $providers ): array {
	$providers['fixture_shared'] = array(
		'label'       => 'Fixture shared sources',
		'description' => 'Behavioral provider fixture.',
		'context'     => 'shared',
		'default'     => false,
		'snapshot_callback' => static function ( array $settings ): array {
			unset( $settings );
			return array( 'total_items' => 2, 'cursor' => array( 'offset' => 0 ) );
		},
		'next_callback' => static function ( array $cursor, array $settings ): array {
			unset( $settings );
			$offset           = isset( $cursor['offset'] ) ? (int) $cursor['offset'] : 0;
			$cursor['offset'] = $offset + 1;
			return array(
				'source' => array(
					'key'      => 'fixture:' . ( $offset + 1 ),
					'id'       => $offset + 1,
					'title'    => 'Shared fixture ' . ( $offset + 1 ),
					'type'     => 'Fixture surface',
					'context'  => 'shared',
					'url'      => '',
					'edit_url' => 'https://example.test/wp-admin/fixture.php?id=' . ( $offset + 1 ),
					'base_url' => 'https://example.test/',
					'links'    => array( array( 'href' => '/provider-target', 'anchor' => 'Provider target' ) ),
				),
				'cursor' => $cursor,
				'done'   => $cursor['offset'] >= 2,
			);
		},
	);

	return $providers;
};
add_filter( 'indexlane_rila_source_providers', $fixture_provider_filter );
$provider_settings = indexlane_invoke(
	'get_request_settings',
	array(
		array(
			'source_types_present' => '1',
			'source_types'         => array( 'fixture_shared' ),
			'content_scope'        => 'all',
			'timeout'              => 2,
			'max_redirects'        => 5,
		)
	)
);
indexlane_assert_same( array( 'fixture_shared' ), $provider_settings['source_types'], 'The public provider filter must make an added source selectable.' );
$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array( 'https://example.test/provider-target' => indexlane_response( 200 ) );
$provider_session = indexlane_invoke( 'create_scan_session', array( $provider_settings ) );
indexlane_assert_same( false, is_wp_error( $provider_session ), 'A valid filtered provider must create a resumable scan session.' );
$provider_session = indexlane_invoke( 'process_scan_batch', array( $provider_session ) );
indexlane_assert_same( 'complete', $provider_session['status'], 'A filtered provider must complete through the normal bounded scan engine.' );
indexlane_assert_same( 2, $provider_session['stats']['sources_processed'], 'Provider progress must count stored sources independently from content items.' );
indexlane_assert_same( 0, $provider_session['stats']['content_items_processed'], 'Shared provider sources must not become content coverage targets.' );
indexlane_assert_same( 1, $provider_session['stats']['http_requests'], 'Request deduplication must span every source from a filtered provider.' );
indexlane_assert_same( 2, count( $provider_session['results'] ), 'Each provider occurrence must retain exact evidence.' );
indexlane_assert_same( 'fixture:1', $provider_session['results'][0]['source_key'], 'Provider evidence must retain its exact stable source identity.' );
indexlane_assert_same( 'shared', $provider_session['results'][0]['source_context'], 'Provider evidence must retain contextual-versus-shared scope.' );
$provider_baseline = indexlane_invoke( 'build_baseline_from_session', array( $provider_session ) );
indexlane_assert_same( false, is_wp_error( $provider_baseline ), 'A shared-only provider scan must remain portable saved evidence.' );
indexlane_assert_same( 2, $provider_baseline['scope']['total_sources'], 'Saved evidence must retain shared sources independently from content targets.' );
indexlane_assert_same( 0, $provider_baseline['scope']['content_items'], 'A shared-only saved scan must not invent content coverage targets.' );
$orphaned_provider_session = indexlane_invoke( 'create_scan_session', array( $provider_settings ) );
remove_filter( 'indexlane_rila_source_providers', $fixture_provider_filter );
$orphaned_provider_session = indexlane_invoke( 'process_scan_batch', array( $orphaned_provider_session ) );
indexlane_assert_same( 'failed', $orphaned_provider_session['status'], 'A paused scan must stop safely when its selected provider disappears.' );
indexlane_assert_same( 0, $orphaned_provider_session['stats']['sources_processed'], 'A missing provider must not create partial source evidence.' );
$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array();

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
indexlane_assert_same( 2, $impact_rows[0]['affected_sources'], 'Repeated links in one source should count as one affected editable source.' );
indexlane_assert_same( 2, count( $impact_rows[0]['affected_source_details'] ), 'Problem URLs must retain the exact editable sources behind the aggregate.' );
$impact_source_occurrences = array_column( $impact_rows[0]['affected_source_details'], 'occurrences' );
sort( $impact_source_occurrences, SORT_NUMERIC );
indexlane_assert_same( array( 1, 2 ), $impact_source_occurrences, 'Per-source impact evidence must retain repeated occurrences in one source.' );
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
indexlane_assert_same( 'Source', $details_csv[0][0], 'Detailed CSV should begin with the editable source.' );
indexlane_assert_same( '\' =HYPERLINK("https://attacker.test")', $details_csv[1][0], 'CSV safety should block formulas after leading spaces.' );
indexlane_assert_same( "'\n+SUM(1,1)", $details_csv[1][12], 'CSV safety should block formulas after leading newlines.' );

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
indexlane_assert_same( 'URL', $impact_csv[0][0], 'Problem-URL CSV should begin with the URL.' );
indexlane_assert_same( '\'=IMPORTXML("https://attacker.test") Broken link (404)', $impact_csv[1][10], 'Impact evidence should receive the same CSV formula protection.' );
indexlane_assert_same( "'\t@SUM(1,1)", indexlane_invoke( 'csv_safe', array( "\t@SUM(1,1)" ) ), 'CSV safety should block formulas after a leading tab.' );
indexlane_assert_same( "'-2+3", indexlane_invoke( 'csv_safe', array( '-2+3' ) ), 'CSV safety should block minus-prefixed formulas.' );
indexlane_assert_same( ' ordinary text', indexlane_invoke( 'csv_safe', array( ' ordinary text' ) ), 'CSV safety should not alter non-formula text.' );

$coverage_items = array(
	array(
		'id'       => 1,
		'title'    => 'Alpha target',
		'type'     => 'Page',
		'url'      => 'https://example.test/alpha',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit',
	),
	array(
		'id'       => 2,
		'title'    => 'Source one',
		'type'     => 'Page',
		'url'      => 'https://example.test/source-one',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=2&action=edit',
	),
	array(
		'id'       => 3,
		'title'    => 'Source two',
		'type'     => 'Page',
		'url'      => 'https://example.test/source-two',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=3&action=edit',
	),
	array(
		'id'       => 4,
		'title'    => 'Lonely target',
		'type'     => 'Page',
		'url'      => 'https://example.test/lonely',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=4&action=edit',
	),
	array(
		'id'       => 5,
		'title'    => 'Self target',
		'type'     => 'Page',
		'url'      => 'https://example.test/self',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=5&action=edit',
	),
);

$coverage_results = array(
	indexlane_result_row(
		'https://example.test/source-one',
		'https://example.test/alpha',
		'200',
		0,
		'https://example.test/alpha',
		'None',
		'OK',
		'Source one',
		'Alpha guide',
		array( 'source_id' => 2, 'is_same_site' => true, 'coverage_target_id' => 1, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'https://example.test/source-one',
		'https://example.test/alpha',
		'200',
		0,
		'https://example.test/alpha',
		'None',
		'OK',
		'Source one',
		'Read alpha',
		array( 'source_id' => 2, 'is_same_site' => true, 'coverage_target_id' => 1, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'https://example.test/source-two',
		'https://example.test/old-alpha',
		'301 -> 200',
		1,
		'https://example.test/alpha',
		'Redirect (301)',
		'Warning',
		'Source two',
		'Legacy alpha',
		array( 'source_id' => 3, 'is_same_site' => true, 'coverage_target_id' => 1, 'link_kind_code' => 'redirected' )
	),
	indexlane_result_row(
		'https://example.test/source-one',
		'https://example.test/source-two',
		'200',
		0,
		'https://example.test/source-two',
		'None',
		'OK',
		'Source one',
		'Other source',
		array( 'source_id' => 2, 'is_same_site' => true, 'coverage_target_id' => 3, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'https://example.test/self',
		'https://example.test/self',
		'200',
		0,
		'https://example.test/self',
		'None',
		'OK',
		'Self target',
		'On this page',
		array( 'source_id' => 5, 'is_same_site' => true, 'coverage_target_id' => 5, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'https://example.test/source-two',
		'https://example.test/broken-destination',
		'404',
		0,
		'https://example.test/broken-destination',
		'Broken link (404)',
		'Error',
		'Source two',
		'Broken destination',
		array( 'source_id' => 3, 'is_same_site' => true, 'coverage_target_id' => 0, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'https://example.test/source-two',
		'https://legacy.example/path',
		'',
		0,
		'',
		'Old domain',
		'Needs review',
		'Source two',
		'Legacy site',
		array( 'source_id' => 3, 'is_same_site' => false, 'coverage_target_id' => 0, 'link_kind_code' => 'direct' )
	),
	indexlane_result_row(
		'',
		'https://example.test/alpha',
		'200',
		0,
		'https://example.test/alpha',
		'None',
		'OK',
		'Footer menu',
		'Menu alpha',
		array(
			'source_id'         => 7,
			'source_key'        => 'menu:7',
			'source_type'       => 'Classic menu',
			'source_type_code'  => 'menu',
			'source_context'    => 'shared',
			'source_content_id' => 0,
			'is_same_site'      => true,
			'coverage_target_id' => 1,
			'link_kind_code'    => 'direct',
		)
	),
);

$http_calls_before_coverage = count( $GLOBALS['indexlane_test_http_calls'] );
$coverage_rows              = indexlane_invoke( 'build_content_link_coverage', array( $coverage_items, $coverage_results ) );
indexlane_assert_same( $http_calls_before_coverage, count( $GLOBALS['indexlane_test_http_calls'] ), 'Coverage aggregation must not make HTTP requests.' );
indexlane_assert_same( 5, count( $coverage_rows ), 'Coverage must contain one row for every item in the saved scanned corpus.' );
$coverage_by_id = array_column( $coverage_rows, null, 'target_id' );
indexlane_assert_same( 4, $coverage_by_id[1]['incoming_occurrences'], 'Every contextual, shared, direct, and redirected occurrence should count toward its final target.' );
indexlane_assert_same( 3, $coverage_by_id[1]['linking_source_count'], 'Repeated links from one source should count as one editable source.' );
indexlane_assert_same( 3, $coverage_by_id[1]['contextual_incoming'], 'Contextual incoming occurrences must be counted separately.' );
indexlane_assert_same( 1, $coverage_by_id[1]['shared_incoming'], 'Navigation and shared incoming occurrences must be counted separately.' );
indexlane_assert_same( 2, $coverage_by_id[1]['contextual_source_count'], 'Distinct contextual sources must remain exact.' );
indexlane_assert_same( 1, $coverage_by_id[1]['shared_source_count'], 'Distinct shared sources must remain exact.' );
indexlane_assert_same( array( 'Alpha guide', 'Legacy alpha', 'Menu alpha', 'Read alpha' ), $coverage_by_id[1]['anchor_text_variants'], 'Target rows should retain every distinct anchor-text variant deterministically.' );
indexlane_assert_same( 3, $coverage_by_id[1]['direct_incoming'], 'Direct incoming links should remain distinguishable.' );
indexlane_assert_same( 1, $coverage_by_id[1]['redirected_incoming'], 'A redirect to a published target should count toward the final item.' );
indexlane_assert_same( 'shared', $coverage_by_id[1]['incoming_details'][0]['source_context'], 'Target detail evidence should identify a shared source.' );
indexlane_assert_same( 'redirected', $coverage_by_id[1]['incoming_details'][3]['link_kind_code'], 'Target detail evidence should retain the redirect classification.' );
indexlane_assert_same( 'https://example.test/old-alpha', $coverage_by_id[1]['incoming_details'][3]['linked_url'], 'Target detail evidence should retain the originally linked redirect URL.' );
indexlane_assert_same( 'https://example.test/alpha', $coverage_by_id[1]['incoming_details'][3]['final_url'], 'Target detail evidence should retain the published final URL.' );
indexlane_assert_same( 3, $coverage_by_id[2]['outgoing_internal_occurrences'], 'Outgoing coverage should count every same-site occurrence from the source.' );
indexlane_assert_same( 2, $coverage_by_id[2]['distinct_internal_destinations'], 'Repeated outgoing links to one destination should be deduplicated.' );
indexlane_assert_same( 2, $coverage_by_id[3]['outgoing_internal_occurrences'], 'Outgoing coverage should include published and unresolved same-site destinations.' );
indexlane_assert_same( 1, $coverage_by_id[5]['self_link_count'], 'Self-links should be counted explicitly.' );
indexlane_assert_same( 'No incoming links detected in selected sources.', $coverage_by_id[4]['status'], 'Zero-source content must use conservative selected-source wording.' );
indexlane_assert_same( 'One linking source', $coverage_by_id[3]['status'], 'One-source content should have the planned status.' );
indexlane_assert_same( 'Multiple linking sources', $coverage_by_id[1]['status'], 'Multi-source content should have the planned status.' );

$coverage_csv = indexlane_invoke( 'build_csv_rows', array( $coverage_results, 'coverage', $coverage_items ) );
indexlane_assert_same( 6, count( $coverage_csv ), 'The target-coverage CSV should contain every scanned item plus its header.' );
indexlane_assert_same( 'Content', $coverage_csv[0][0], 'The dedicated coverage CSV should begin with the content item.' );
$alpha_csv_rows = array_values(
	array_filter(
		$coverage_csv,
		static function ( array $row ): bool {
			return isset( $row[0] ) && 'Alpha target' === $row[0];
		}
	)
);
indexlane_assert_same( 1, count( $alpha_csv_rows ), 'The coverage CSV should contain exactly one Alpha target row.' );
indexlane_assert_same(
	array( '4', '3', '1', '3', '0', '0', 'Alpha guide | Legacy alpha | Menu alpha | Read alpha', '0', '3', '1', 'Multiple linking sources' ),
	array_slice( $alpha_csv_rows[0], 2, 11 ),
	'The coverage CSV should export exact contextual, shared, source, outgoing, anchor, self-link, and direct/redirect metrics.'
);

$comparison_old = array(
	indexlane_result_row( 'https://example.test/source-a', 'https://example.test/regression', '200', 0, 'https://example.test/regression', 'None', 'OK' ),
	indexlane_result_row( 'https://example.test/source-b', 'https://example.test/resolved', '404', 0, 'https://example.test/resolved', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-c', 'https://example.test/changed', '301 -> 200', 1, 'https://example.test/old-final', 'Redirect (301)', 'Warning' ),
	indexlane_result_row( 'https://example.test/source-d', 'https://example.test/many', '404', 0, 'https://example.test/many', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-e', 'https://example.test/many', '404', 0, 'https://example.test/many', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-f', 'https://example.test/many', '404', 0, 'https://example.test/many', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-g', 'https://example.test/still', '404', 0, 'https://example.test/still', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-h', 'https://example.test/cleanup', '301 -> 200', 1, 'https://example.test/clean', 'Redirect (301)', 'Warning' ),
);
$comparison_new = array(
	indexlane_result_row( 'https://example.test/source-a', 'https://example.test/regression', '404', 0, 'https://example.test/regression', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-b', 'https://example.test/resolved', '200', 0, 'https://example.test/resolved', 'None', 'OK' ),
	indexlane_result_row( 'https://example.test/source-c', 'https://example.test/changed', '301 -> 200', 1, 'https://example.test/different-final', 'Redirect (301)', 'Warning' ),
	indexlane_result_row( 'https://example.test/source-d', 'https://example.test/many', '404', 0, 'https://example.test/many', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-g', 'https://example.test/still', '404', 0, 'https://example.test/still', 'Broken link (404)', 'Error' ),
	indexlane_result_row( 'https://example.test/source-h', 'https://example.test/cleanup', '200', 0, 'https://example.test/cleanup', 'None', 'OK' ),
);

$http_calls_before_comparison = count( $GLOBALS['indexlane_test_http_calls'] );
$comparison = indexlane_invoke( 'build_scan_comparison', array( $comparison_old, $comparison_new ) );
indexlane_assert_same( $http_calls_before_comparison, count( $GLOBALS['indexlane_test_http_calls'] ), 'Baseline comparison must derive only from saved evidence.' );
indexlane_assert_same(
	array( 'new' => 1, 'changed' => 2, 'resolved' => 2, 'still' => 1 ),
	$comparison['summary'],
	'Comparison summaries must use the four planned remediation categories.'
);
$comparison_by_url = array_column( $comparison['rows'], null, 'destination_url' );
indexlane_assert_same( 'new', $comparison_by_url['https://example.test/regression']['category'], 'A healthy destination becoming 404 must be a new issue.' );
indexlane_assert_same( '200', $comparison_by_url['https://example.test/regression']['old']['http_status_chain'], 'New issues must retain the healthy baseline status chain.' );
indexlane_assert_same( '404', $comparison_by_url['https://example.test/regression']['new']['http_status_chain'], 'New issues must retain the regressed verification status chain.' );
indexlane_assert_same( 'resolved', $comparison_by_url['https://example.test/resolved']['category'], 'A 404 becoming 200 must be resolved.' );
indexlane_assert_same( 'resolved', $comparison_by_url['https://example.test/cleanup']['category'], 'A redirected link cleaned to a direct 200 must be resolved.' );
indexlane_assert_same( 'changed', $comparison_by_url['https://example.test/changed']['category'], 'A redirect changing final URL must retain changed behavior.' );
indexlane_assert_same( true, in_array( 'final_url', $comparison_by_url['https://example.test/changed']['changed_fields'], true ), 'Changed final URLs must be named as changed evidence.' );
indexlane_assert_same( 'improved', $comparison_by_url['https://example.test/many']['direction'], 'A broken destination dropping from three sources to one must be improved but still present.' );
indexlane_assert_same( 3, $comparison_by_url['https://example.test/many']['old']['affected_source_count'], 'Baseline affected-source counts must be exact.' );
indexlane_assert_same( 1, $comparison_by_url['https://example.test/many']['new']['affected_source_count'], 'Verification affected-source counts must be exact.' );
indexlane_assert_same( 'still', $comparison_by_url['https://example.test/still']['category'], 'Unchanged issue evidence must remain still present.' );

$comparison_csv = indexlane_invoke( 'build_comparison_csv_rows', array( $comparison ) );
indexlane_assert_same( 7, count( $comparison_csv ), 'Comparison CSV must contain every compared issue destination plus its header.' );
indexlane_assert_same( 'Outcome', $comparison_csv[0][0], 'Comparison CSV must begin with its outcome.' );
indexlane_assert_same( 'Saved Scan HTTP Status Chain', $comparison_csv[0][4], 'Comparison CSV must expose saved-scan status results explicitly.' );
indexlane_assert_same( 'Latest Scan Editable Sources Affected', $comparison_csv[0][19], 'Comparison CSV must expose latest-scan source impact explicitly.' );

$baseline_content_items = array(
	array(
		'id'       => 10,
		'title'    => 'Baseline source one',
		'type'     => 'Page',
		'url'      => 'https://example.test/source-a',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=10&action=edit',
	),
	array(
		'id'       => 11,
		'title'    => 'Baseline source two',
		'type'     => 'Page',
		'url'      => 'https://example.test/source-b',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=11&action=edit',
	),
);
$baseline_stats = array(
	'sources_processed'            => 2,
	'content_items_processed'    => 2,
	'links_extracted'             => count( $comparison_old ),
	'links_audited'               => count( $comparison_old ),
	'skipped_external'            => 0,
	'unique_destinations_checked' => 6,
	'http_requests'               => 6,
	'actionable_issues'           => 7,
);
$baseline_session = array(
	'schema_version'              => 5,
	'id'                          => '12345678-1234-4abc-8def-000000000050',
	'status'                      => 'complete',
	'created_at'                  => time() - 60,
	'updated_at'                  => time(),
	'expires_at'                  => time() + 86400,
	'scan_mode'                   => 'standard',
	'baseline_id'                 => '',
	'baseline_fingerprint'        => '',
	'settings'                    => array(
		'source_types'     => array( 'content' ),
		'post_types'       => array( 'post', 'page' ),
		'old_domains'      => 'legacy.example',
		'old_domain_hosts' => array( 'legacy.example' ),
		'content_scope'    => 'all',
		'max_posts'        => 100,
		'timeout'          => 5.0,
		'max_redirects'    => 5,
	),
	'total_items'                 => 2,
	'source_provider_states'      => array(),
	'source_provider_index'       => 0,
	'sources_done'                => true,
	'request_limit'               => 250,
	'request_allowance_extensions' => 0,
	'stats'                       => $baseline_stats,
	'content_items'               => $baseline_content_items,
	'content_item_ids'            => array( 10 => true, 11 => true ),
	'results'                     => $comparison_old,
	'checked_urls'                => array(),
	'pending_checks'              => array(),
);

$baseline = indexlane_invoke( 'build_baseline_from_session', array( $baseline_session ) );
indexlane_assert_same( false, is_wp_error( $baseline ), 'A complete consistent scan must produce portable baseline evidence.' );
indexlane_assert_same( 'indexlane-rila-baseline', $baseline['format'], 'Baseline JSON must identify its document format.' );
indexlane_assert_same( 3, $baseline['schema_version'], 'Baseline JSON must carry an explicit destination-intent schema version.' );
indexlane_assert_same( '0.7.0', $baseline['plugin_version'], 'Saved-scan metadata must identify the plugin version.' );
indexlane_assert_same( 'https://example.test', $baseline['site_url'], 'Baseline site ownership must use a normalized exact home URL.' );
indexlane_assert_same( true, $baseline['completion']['complete'], 'Only complete evidence may be saved as a baseline.' );
indexlane_assert_same( 0, $baseline['completion']['request_allowance_extensions'], 'Baseline metadata must preserve the request-limit extension state.' );

$baseline_json   = wp_json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$parsed_baseline = indexlane_invoke( 'parse_baseline_json', array( $baseline_json ) );
indexlane_assert_same( $baseline, $parsed_baseline, 'Exported baseline JSON must round-trip through strict import validation.' );

$legacy_baseline                   = $baseline;
$legacy_baseline['schema_version'] = 1;
unset( $legacy_baseline['settings']['source_types'] );
$legacy_baseline['scope'] = array(
	'post_types'    => $baseline['scope']['post_types'],
	'content_scope' => $baseline['scope']['content_scope'],
	'content_limit' => $baseline['scope']['content_limit'],
	'total_items'   => $baseline['scope']['content_items'],
);
$legacy_baseline['completion']['content_done'] = $legacy_baseline['completion']['sources_done'];
unset( $legacy_baseline['completion']['sources_done'], $legacy_baseline['stats']['sources_processed'] );
foreach ( $legacy_baseline['results'] as &$legacy_row ) {
	unset( $legacy_row['source_key'], $legacy_row['source_type_code'], $legacy_row['source_context'], $legacy_row['source_content_id'], $legacy_row['link_rel'], $legacy_row['intent_code'], $legacy_row['intent_severity'], $legacy_row['intent_detail'] );
}
unset( $legacy_row );
$upgraded_legacy = indexlane_invoke( 'validate_baseline', array( $legacy_baseline, true ) );
indexlane_assert_same( false, is_wp_error( $upgraded_legacy ), 'Strict 0.5 saved scans must remain importable as content-only evidence.' );
indexlane_assert_same( 3, $upgraded_legacy['schema_version'], 'A legacy saved scan must normalize to the current schema.' );
indexlane_assert_same( array( 'content' ), $upgraded_legacy['settings']['source_types'], 'Legacy scans must retain their exact post-content-only scope.' );
indexlane_assert_same( 'contextual', $upgraded_legacy['results'][0]['source_context'], 'Legacy occurrences must normalize as contextual content sources.' );
indexlane_assert_same( '', $upgraded_legacy['results'][0]['intent_code'], 'Legacy occurrences must carry no invented destination-intent finding.' );

$source_aware_baseline                   = $baseline;
$source_aware_baseline['schema_version'] = 2;
foreach ( $source_aware_baseline['results'] as &$source_aware_row ) {
	unset( $source_aware_row['link_rel'], $source_aware_row['intent_code'], $source_aware_row['intent_severity'], $source_aware_row['intent_detail'] );
}
unset( $source_aware_row );
$upgraded_source_aware = indexlane_invoke( 'validate_baseline', array( $source_aware_baseline, true ) );
indexlane_assert_same( false, is_wp_error( $upgraded_source_aware ), 'Strict 0.6 saved scans must remain importable as transport-only evidence.' );
indexlane_assert_same( 3, $upgraded_source_aware['schema_version'], 'A source-aware saved scan must normalize to the destination-intent schema.' );
indexlane_assert_same( '', $upgraded_source_aware['results'][0]['intent_detail'], 'An upgraded 0.6 saved scan must record no destination-intent finding.' );

$wrong_site             = $baseline;
$wrong_site['site_url'] = 'https://other.example';
$wrong_site_result      = indexlane_invoke( 'validate_baseline', array( $wrong_site, true ) );
indexlane_assert_same( true, is_wp_error( $wrong_site_result ), 'A baseline from another site must be rejected.' );
indexlane_assert_same( 'baseline_wrong_site', $wrong_site_result->get_error_code(), 'Site mismatch must have a specific validation code.' );

$unknown_field               = $baseline;
$unknown_field['unexpected'] = true;
$unknown_field_result        = indexlane_invoke( 'validate_baseline', array( $unknown_field, true ) );
indexlane_assert_same( 'baseline_invalid_schema', $unknown_field_result->get_error_code(), 'Strict schema validation must reject unknown top-level fields.' );

$inconsistent_stats                                  = $baseline;
$inconsistent_stats['stats']['actionable_issues']    = 6;
$inconsistent_stats_result = indexlane_invoke( 'validate_baseline', array( $inconsistent_stats, true ) );
indexlane_assert_same( 'baseline_invalid_evidence', $inconsistent_stats_result->get_error_code(), 'Import must reject counters that disagree with occurrence evidence.' );

indexlane_assert_same( true, indexlane_invoke( 'save_baseline', array( $baseline ) ), 'One validated baseline must persist for the current administrator.' );
indexlane_assert_same( $baseline, indexlane_invoke( 'get_saved_baseline' ), 'The owning administrator must load the exact canonical baseline.' );
$GLOBALS['indexlane_test_user_id'] = 8;
indexlane_assert_same( null, indexlane_invoke( 'get_saved_baseline' ), 'Another administrator must not see the saved baseline.' );
$GLOBALS['indexlane_test_user_id'] = 7;

$verification_settings = indexlane_invoke( 'verification_settings_from_baseline', array( $baseline ) );
indexlane_assert_same( false, is_wp_error( $verification_settings ), 'A verification scan must reproduce currently available baseline scope.' );
indexlane_assert_same( $baseline['settings']['source_types'], $verification_settings['source_types'], 'Verification must retain the exact saved source providers.' );
indexlane_assert_same( $baseline['settings']['post_types'], $verification_settings['post_types'], 'Verification must retain the exact saved post types.' );
indexlane_assert_same( $baseline['settings']['content_scope'], $verification_settings['content_scope'], 'Verification must retain the exact saved content scope.' );

$verification_session                         = $baseline_session;
$verification_session['id']                   = '12345678-1234-4abc-8def-000000000051';
$verification_session['scan_mode']            = 'verification';
$verification_session['baseline_id']          = $baseline['baseline_id'];
$verification_session['baseline_fingerprint'] = indexlane_invoke( 'baseline_fingerprint', array( $baseline ) );
$verification_session['results']              = $comparison_new;
$verification_session['stats']['links_extracted']  = count( $comparison_new );
$verification_session['stats']['links_audited']    = count( $comparison_new );
$verification_session['stats']['actionable_issues'] = 4;
$stored_comparison = indexlane_invoke( 'get_session_comparison', array( $verification_session ) );
indexlane_assert_same( $comparison['summary'], $stored_comparison['summary'], 'Completed verification must compare against the exact saved baseline fingerprint.' );
$verification_session['baseline_fingerprint'] = str_repeat( '0', 64 );
$stale_comparison = indexlane_invoke( 'get_session_comparison', array( $verification_session ) );
indexlane_assert_same( true, is_wp_error( $stale_comparison ), 'A changed baseline must invalidate a stale verification comparison.' );

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
indexlane_assert_same(
	262144,
	$GLOBALS['indexlane_test_http_calls'][0]['args']['limit_response_size'],
	'GET response bodies must be bounded but large enough to inspect page intent.'
);

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

$GLOBALS['indexlane_test_http_calls'] = array();
$GLOBALS['indexlane_test_responses']  = array();
$pending_checks = array();
$occurrence     = array(
	'source'     => array(
		'id'       => 1,
		'key'      => 'content:page:1',
		'content_id' => 1,
		'title'    => 'Batch source',
		'type'     => 'Page',
		'type_code' => 'content',
		'context'  => 'contextual',
		'url'      => 'https://example.test/source',
		'base_url' => 'https://example.test/source',
		'edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit',
	),
	'link'       => array( 'href' => '/target', 'anchor' => 'Target' ),
	'linked_url' => 'https://example.test/target',
	'warnings'   => array(),
	'is_old'     => false,
	'is_staging' => false,
	'fragment'   => '',
);

for ( $i = 1; $i <= 6; $i++ ) {
	$url = 'https://example.test/batch-' . $i;
	$GLOBALS['indexlane_test_responses'][ $url ] = indexlane_response( 200 );
	$pending_checks[ 'batch-' . $i ] = array(
		'url'         => $url,
		'occurrences' => 1 === $i ? array( $occurrence, $occurrence ) : array( $occurrence ),
		'check_state' => indexlane_invoke( 'initial_check_state', array( $url ) ),
	);
}

$batch_session = array(
	'schema_version'   => 5,
	'id'               => '12345678-1234-4abc-8def-000000000010',
	'scan_mode'        => 'standard',
	'baseline_id'      => '',
	'baseline_fingerprint' => '',
	'status'           => 'running',
	'created_at'       => time(),
	'updated_at'       => time(),
	'expires_at'       => time() + 86400,
	'settings'         => array( 'timeout' => 2.0, 'max_redirects' => 5 ),
	'total_items'      => 1,
	'sources_done'     => true,
	'request_limit'    => 250,
	'request_allowance_extensions' => 0,
	'stats'            => indexlane_invoke( 'empty_stats' ),
	'content_items'    => array(),
	'results'          => array(),
	'checked_urls'     => array(),
	'pending_checks'   => $pending_checks,
);
$batch_session['stats']['sources_processed']       = 1;
$batch_session['stats']['content_items_processed'] = 1;
$first_batch = indexlane_invoke( 'process_scan_batch', array( $batch_session ) );
indexlane_assert_same( 5, $first_batch['stats']['http_requests'], 'One AJAX batch must make no more than five outbound requests.' );
indexlane_assert_same( 5, $first_batch['stats']['unique_destinations_checked'], 'The batch must count completed unique destinations, not occurrences.' );
indexlane_assert_same( 6, count( $first_batch['results'] ), 'Duplicate occurrences must share one request while retaining exact occurrence evidence.' );
indexlane_assert_same( 'running', $first_batch['status'], 'A bounded batch must remain resumable while queued work remains.' );
$second_batch = indexlane_invoke( 'process_scan_batch', array( $first_batch ) );
indexlane_assert_same( 6, $second_batch['stats']['http_requests'], 'A later batch must continue from the saved request count.' );
indexlane_assert_same( 6, $second_batch['stats']['unique_destinations_checked'], 'Request deduplication must span the entire session.' );
indexlane_assert_same( 7, count( $second_batch['results'] ), 'Every accumulated occurrence must remain in completed evidence.' );
indexlane_assert_same( 'complete', $second_batch['status'], 'The session completes only after content and queued destinations are finished.' );

$limit_session                         = $batch_session;
$limit_session['stats']['http_requests'] = 250;
$limit_session['request_limit']          = 250;
$GLOBALS['indexlane_test_http_calls']    = array();
$limit_session = indexlane_invoke( 'process_scan_batch', array( $limit_session ) );
indexlane_assert_same( 'limit_reached', $limit_session['status'], 'A session must pause at its explicit total request allowance.' );
indexlane_assert_same( 0, count( $GLOBALS['indexlane_test_http_calls'] ), 'No request may be made beyond the current session allowance.' );
indexlane_assert_same( 0, count( $limit_session['results'] ), 'Allowance exhaustion must not create incomplete evidence rows.' );

$saved_session = $second_batch;
indexlane_assert_same( true, indexlane_invoke( 'save_scan_session', array( $saved_session ) ), 'Session progress must be stored in the current user transient.' );
$loaded_session = indexlane_invoke( 'get_scan_session' );
indexlane_assert_same( $saved_session['results'], $loaded_session['results'], 'CSV export must read the exact accumulated session evidence.' );
$saved_transient = reset( $GLOBALS['indexlane_test_transients'] );
indexlane_assert_same( 86400, $saved_transient['expiration'], 'Abandoned and completed sessions should expire automatically after 24 hours.' );

$GLOBALS['indexlane_test_user_id'] = 8;
$other_user_session = indexlane_invoke( 'get_scan_session' );
indexlane_assert_same( null, $other_user_session, 'Saved scan sessions must be scoped to the administrator who started them.' );

$GLOBALS['indexlane_test_user_id'] = 7;
$session_key = 'indexlane_rila_session_7';
$GLOBALS['indexlane_test_transients'][ $session_key ]['value']['expires_at'] = time() - 1;
$expired_session = indexlane_invoke( 'get_scan_session' );
indexlane_assert_same( null, $expired_session, 'Explicitly expired abandoned sessions must be rejected.' );
indexlane_assert_same( false, isset( $GLOBALS['indexlane_test_transients'][ $session_key ] ), 'Expired abandoned sessions must be cleaned up on access.' );

$GLOBALS['indexlane_test_http_calls'] = array();

$intent_source = array(
	'id'         => 3,
	'key'        => 'content:page:3',
	'content_id' => 3,
	'title'      => 'Service page',
	'type'       => 'Page',
	'type_code'  => 'content',
	'context'    => 'contextual',
	'url'        => 'https://example.test/service',
	'base_url'   => 'https://example.test/service',
	'edit_url'   => 'https://example.test/wp-admin/post.php?post=3&action=edit',
);

/**
 * Build one prepared occurrence for destination-intent coverage.
 *
 * @param array<string,mixed> $source     Normalized stored source.
 * @param string              $linked_url Linked URL.
 * @param string              $fragment   Fragment from the stored link.
 * @param string              $rel        Stored rel attribute.
 */
function indexlane_intent_occurrence( array $source, string $linked_url, string $fragment = '', string $rel = '' ): array {
	return array(
		'source'     => $source,
		'link'       => array( 'href' => $linked_url, 'anchor' => 'Service', 'rel' => $rel ),
		'linked_url' => $linked_url,
		'warnings'   => array(),
		'is_old'     => false,
		'is_staging' => false,
		'fragment'   => $fragment,
	);
}

/**
 * Wrap inspected intent evidence in the completed-check contract.
 *
 * @param string              $url    Final URL.
 * @param array<string,mixed> $intent Inspected intent evidence.
 * @param int                 $status Final HTTP status.
 */
function indexlane_intent_check( string $url, array $intent, int $status = 200 ): array {
	return array(
		'ok'                     => true,
		'error'                  => '',
		'statuses'               => array( (string) $status ),
		'redirect_count'         => 0,
		'redirect_codes'         => array(),
		'final_status'           => $status,
		'final_url'              => $url,
		'redirect_limit_reached' => false,
		'redirect_loop'          => false,
		'redirect_left_site'     => false,
		'intent'                 => $intent,
		'budget_exhausted'       => false,
	);
}

indexlane_assert_same( 'enterprise', indexlane_invoke( 'link_fragment_from_href', array( '/pricing/#enterprise' ) ), 'A stored link fragment must be retained for intent evidence.' );
indexlane_assert_same( '', indexlane_invoke( 'link_fragment_from_href', array( '/pricing/' ) ), 'A link without a fragment must carry no fragment evidence.' );
indexlane_assert_same( 'nofollow noopener', indexlane_invoke( 'normalize_link_rel', array( 'NoFollow, noopener nofollow' ) ), 'Stored rel evidence must be normalized into unique lowercase tokens.' );

$GLOBALS['indexlane_test_responses'] = array(
	'https://example.test/ok-page' => indexlane_html_response(
		'<html><head><link rel="canonical" href="https://example.test/ok-page"><meta name="robots" content="index, follow"></head><body><h2 id="details">Details</h2><a name="legacy">Legacy anchor</a></body></html>'
	),
);
$clean_request_count = 0;
$clean_check         = indexlane_invoke( 'check_url', array( 'https://example.test/ok-page', 2.0, 5, &$clean_request_count ) );
indexlane_assert_same( true, $clean_check['intent']['checked'], 'A 2xx same-site response must be inspected for destination intent.' );
indexlane_assert_same( 'html', $clean_check['intent']['content_kind'], 'An HTML response must be classified as a page.' );
indexlane_assert_same( 'same', $clean_check['intent']['canonical_state'], 'A canonical that matches the fetched URL must be recorded as matching.' );
indexlane_assert_same( false, $clean_check['intent']['header_noindex'], 'A response without a robots header must not claim noindex.' );
indexlane_assert_same( array( 'details', 'legacy' ), $clean_check['intent']['fragments'], 'An inspected page must retain its fragment targets.' );
indexlane_assert_same( true, $clean_check['intent']['fragments_complete'], 'A fully inspected page must report complete fragment evidence.' );
indexlane_assert_same( 1, $clean_request_count, 'Destination-intent inspection must not add an outbound request.' );

$clean_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/ok-page', 'details' ), $clean_check ) );
indexlane_assert_same( '', $clean_row['intent_code'], 'A page with matching intent must not create an intent finding.' );
indexlane_assert_same( 'ok', $clean_row['result_code'], 'A matching canonical and existing fragment must remain OK.' );

// Regression coverage for exact HTML evidence and conservative fragment checks.
$literal_html = '<!-- <meta name="robots" content="noindex"><div id="comment"> -->'
	. '<script>const example = \'<link rel="canonical" href="/wrong"><div id="script">\';</script>'
	. '<textarea><meta name="robots" content="none"></textarea>'
	. '<div title=\'id="attribute" <meta name="robots" content="none">\'>id="text"</div>'
	. '<h2 title="1 > 0" id="123">Numbers</h2><a data-name="fake" name="Legacy">Anchor</a>';
$literal_evidence = indexlane_invoke( 'inspect_response_intent', array( indexlane_html_response( $literal_html ), 'https://example.test/ok-page' ) );
indexlane_assert_same( false, $literal_evidence['meta_noindex'], 'Comments, scripts, textareas, and quoted markup must not become robots evidence.' );
indexlane_assert_same( '', $literal_evidence['canonical_state'], 'A canonical example in a script must not be treated as an actual canonical.' );
indexlane_assert_same( array( '123', 'Legacy' ), $literal_evidence['fragments'], 'Only real fragment attributes must be retained, including numeric IDs.' );
indexlane_assert_same( '', indexlane_invoke( 'fragment_intent_finding', array( '123', $literal_evidence ) )['code'], 'Numeric fragment IDs must match.' );
indexlane_assert_same( 'fragment_missing', indexlane_invoke( 'fragment_intent_finding', array( 'legacy', $literal_evidence ) )['code'], 'Fragment names are case-sensitive.' );
indexlane_assert_same( '', indexlane_invoke( 'fragment_intent_finding', array( 'TOP', $literal_evidence ) )['code'], 'The browser top-of-page fragment does not require an ID.' );
indexlane_assert_same( 'fragment_inconclusive', indexlane_invoke( 'fragment_intent_finding', array( ':~:text=Numbers', $literal_evidence ) )['code'], 'Text fragments must not be reported as missing element IDs.' );
$many_targets = '<h2 id="first">First</h2>';
for ( $target_number = 0; $target_number < 100; $target_number++ ) {
	$many_targets .= '<p id="item-' . $target_number . '">Item</p>';
}
$many_evidence = indexlane_invoke( 'inspect_response_intent', array( indexlane_html_response( $many_targets ), 'https://example.test/ok-page' ) );
indexlane_assert_same( '', indexlane_invoke( 'fragment_intent_finding', array( 'first', $many_evidence ) )['code'], 'Known targets must still match when the target inventory is capped.' );
indexlane_assert_same( 'fragment_inconclusive', indexlane_invoke( 'fragment_intent_finding', array( 'item-99', $many_evidence ) )['code'], 'A target outside the inventory cap must be inconclusive.' );
$long_target = str_repeat( 'a', 200 ) . 'b';
$long_evidence = indexlane_invoke( 'inspect_response_intent', array( indexlane_html_response( '<p id="' . $long_target . '">Long</p>' ), 'https://example.test/ok-page' ) );
indexlane_assert_same( 'fragment_inconclusive', indexlane_invoke( 'fragment_intent_finding', array( str_repeat( 'a', 200 ), $long_evidence ) )['code'], 'Truncating a target must never invent a matching fragment.' );
indexlane_assert_same( 'html', indexlane_invoke( 'response_content_kind', array( 'Text/HTML; charset=UTF-8' ) ), 'Media types are case-insensitive.' );
indexlane_assert_same( true, indexlane_invoke( 'robots_text_has_directive', array( 'googlebot:noindex', 'noindex' ) ), 'Agent-scoped robots headers must accept a directive without whitespace after the colon.' );
indexlane_assert_same( 'differs', indexlane_invoke( 'canonical_state_for', array( '/ok-page/', 'https://example.test/ok-page' ) ), 'Canonical paths must retain meaningful trailing-slash differences.' );
$base_evidence = indexlane_invoke( 'parse_document_evidence', array( '<base href="/docs/"><link rel="canonical" href="guide"><meta http-equiv="refresh" content="0; url=next">', 'https://example.test/guide' ) );
indexlane_assert_same( 'https://example.test/docs/guide', $base_evidence['canonical'], 'Relative canonicals must respect the document base URL.' );
indexlane_assert_same( 'https://example.test/docs/next', $base_evidence['meta_refresh'], 'Relative refresh targets must respect the document base URL.' );
indexlane_assert_same( 'invalid', indexlane_invoke( 'parse_document_evidence', array( '<link rel="canonical" href="">', 'https://example.test/guide' ) )['canonical_state'], 'An empty canonical must be reported as unreadable.' );
$range_evidence = indexlane_invoke( 'inspect_response_intent', array( indexlane_response( 206, '', '<p>Partial</p>', array( 'content-type' => 'text/html' ) ), 'https://example.test/guide' ) );
indexlane_assert_same( 'fragment_inconclusive', indexlane_invoke( 'fragment_intent_finding', array( 'later', $range_evidence ) )['code'], 'A partial HTTP response must not prove a fragment is missing.' );
$unicode_detail = indexlane_invoke( 'truncate_text', array( str_repeat( 'ก', 1000 ), 1000 ) );
indexlane_assert_same( true, strlen( $unicode_detail ) <= 1000 && 1 === preg_match( '//u', $unicode_detail ), 'Text limits must bound bytes and preserve UTF-8.' );
$navigation_rel = indexlane_invoke( 'extract_navigation_attribute_links', array( array( array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'url' => '/guide', 'label' => 'Guide', 'rel' => 'nofollow' ) ) ) ) );
indexlane_assert_same( 'nofollow', $navigation_rel[0]['rel'], 'Self-closing Navigation blocks must retain their stored relationship.' );
$fragment_occurrence = indexlane_invoke( 'prepare_link_occurrence', array( array( 'href' => 'https://example.test/ok-page#details', 'anchor' => 'Details' ), $intent_source, $baseline_session['settings'], 'example.test' ) );
$fragment_row = indexlane_invoke( 'build_checked_result_row', array( $fragment_occurrence['occurrence'], $clean_check ) );
indexlane_assert_same( 'https://example.test/ok-page#details', $fragment_row['linked_url'], 'Occurrence evidence must preserve the original clickable fragment.' );
$redirect_fragment_check = $clean_check;
$redirect_fragment_check['redirect_fragment'] = 'details';
indexlane_assert_same( '', indexlane_invoke( 'occurrence_intent_summary', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/ok-page', 'missing' ), $redirect_fragment_check ) )['code'], 'A fragment explicitly set by a redirect replaces the original fragment.' );
$redirect_fragment_check['redirect_fragment'] = '';
indexlane_assert_same( '', indexlane_invoke( 'occurrence_intent_summary', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/ok-page', 'missing' ), $redirect_fragment_check ) )['code'], 'An explicit empty redirect fragment must clear the original fragment.' );

$nofollow_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/ok-page', 'details', 'nofollow' ), $clean_check ) );
indexlane_assert_same( 'internal_nofollow', $nofollow_row['intent_code'], 'An internal nofollow link must be reported as informational evidence.' );
indexlane_assert_same( 'info', $nofollow_row['intent_severity'], 'Internal nofollow must never be escalated to an error.' );
indexlane_assert_same( 'nofollow', $nofollow_row['link_rel'], 'Stored rel evidence must be kept on the occurrence row.' );
indexlane_assert_same( 'ok', $nofollow_row['result_code'], 'An informational finding must not create an actionable issue.' );

$GLOBALS['indexlane_test_responses'] = array(
	'https://example.test/old-service' => indexlane_html_response(
		'<html><head><link rel="canonical" href="https://example.test/new-service"><meta name="robots" content="noindex"></head><body><p id="intro">Moved</p></body></html>',
		array( 'x-robots-tag' => 'noindex, nofollow' )
	),
);
$moved_request_count = 0;
$moved_check         = indexlane_invoke( 'check_url', array( 'https://example.test/old-service', 2.0, 5, &$moved_request_count ) );
indexlane_assert_same( true, $moved_check['intent']['header_noindex'], 'An X-Robots-Tag noindex directive must be recorded.' );
indexlane_assert_same( true, $moved_check['intent']['meta_noindex'], 'A robots meta noindex directive must be recorded.' );
indexlane_assert_same( 'differs', $moved_check['intent']['canonical_state'], 'A canonical pointing at another page must be recorded as different.' );

$moved_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/old-service' ), $moved_check ) );
indexlane_assert_same( 'canonical_differs', $moved_row['intent_code'], 'The most actionable intent finding must become the primary intent code.' );
indexlane_assert_same( 'needs_review', $moved_row['intent_severity'], 'A different canonical must need review.' );
indexlane_assert_same( 'needs_review', $moved_row['result_code'], 'A 200 response with a different canonical must not stay OK.' );
indexlane_assert_same( true, false !== strpos( $moved_row['intent_detail'], 'Canonical points to a different URL' ), 'The primary intent finding must remain in the row detail text.' );
indexlane_assert_same( true, false !== strpos( $moved_row['intent_detail'], 'noindex' ), 'Supporting intent findings must remain in the row detail text.' );

$offsite_canonical_check = indexlane_invoke(
	'inspect_response_intent',
	array(
		indexlane_html_response( '<html><head><link rel="canonical" href="https://elsewhere.example/service"></head><body></body></html>' ),
		'https://example.test/service',
	)
);
indexlane_assert_same( 'offsite', $offsite_canonical_check['canonical_state'], 'A canonical on another site must be recorded separately.' );

$partial_check = indexlane_invoke(
	'inspect_response_intent',
	array(
		indexlane_html_response( '<html><head></head><body>' . str_repeat( 'a', 262144 ) . '</body></html>' ),
		'https://example.test/long-page',
	)
);
indexlane_assert_same( true, $partial_check['body_truncated'], 'A response that reaches the body limit must be reported as partial.' );
indexlane_assert_same( false, $partial_check['fragments_complete'], 'A partial response must not claim complete fragment evidence.' );

$partial_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/long-page', 'intro' ), indexlane_intent_check( 'https://example.test/long-page', $partial_check ) ) );
indexlane_assert_same( 'fragment_inconclusive', $partial_row['intent_code'], 'An unverified fragment must be reported as inconclusive, not missing.' );
indexlane_assert_same( 'info', $partial_row['intent_severity'], 'An inconclusive fragment must stay informational.' );
indexlane_assert_same( 'ok', $partial_row['result_code'], 'An inconclusive fragment must not create an actionable issue.' );

$GLOBALS['indexlane_test_responses'] = array(
	'https://example.test/pricing' => indexlane_html_response(
		'<html><head><meta http-equiv="refresh" content="0; url=/pricing-new/"></head><body><h2 id="starter">Starter</h2></body></html>'
	),
);
$refresh_request_count = 0;
$refresh_check         = indexlane_invoke( 'check_url', array( 'https://example.test/pricing', 2.0, 5, &$refresh_request_count ) );
indexlane_assert_same( 'https://example.test/pricing-new/', $refresh_check['intent']['meta_refresh'], 'A meta refresh target must be resolved against the fetched URL.' );

$refresh_row    = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/pricing' ), $refresh_check ) );
$missing_row    = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/pricing', 'enterprise' ), $refresh_check ) );
$present_row    = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/pricing', 'starter' ), $refresh_check ) );
$clean_missing_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/ok-page', 'enterprise' ), $clean_check ) );
indexlane_assert_same( 'meta_refresh', $refresh_row['intent_code'], 'A meta refresh must be reported as a page-intent warning.' );
indexlane_assert_same( 'warning', $refresh_row['result_code'], 'A meta refresh on an otherwise healthy page must be a warning.' );
indexlane_assert_same( 'meta_refresh', $missing_row['intent_code'], 'Page-level findings must take precedence over link-level findings of equal severity.' );
indexlane_assert_same( true, false !== strpos( $missing_row['intent_detail'], 'does not contain the fragment' ), 'Supporting fragment evidence must remain in the row detail text.' );
indexlane_assert_same( 'meta_refresh', $present_row['intent_code'], 'A fragment that exists must add no finding of its own.' );
indexlane_assert_same( 'warning', $present_row['result_code'], 'The page-level warning must still apply to every occurrence.' );
indexlane_assert_same( 'fragment_missing', $clean_missing_row['intent_code'], 'A fragment that is absent from the page must be reported.' );
indexlane_assert_same( 'warning', $clean_missing_row['result_code'], 'A missing fragment on an otherwise healthy page must be a warning.' );

$pdf_check = indexlane_invoke(
	'inspect_response_intent',
	array(
		indexlane_response( 200, '', '%PDF-1.4', array( 'content-type' => 'application/pdf' ) ),
		'https://example.test/brochure.pdf',
	)
);
indexlane_assert_same( 'pdf', $pdf_check['content_kind'], 'A PDF destination must be classified as a file response.' );
$pdf_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/brochure.pdf' ), indexlane_intent_check( 'https://example.test/brochure.pdf', $pdf_check ) ) );
indexlane_assert_same( 'file_response', $pdf_row['intent_code'], 'A PDF destination must be reported as informational evidence.' );
indexlane_assert_same( 'ok', $pdf_row['result_code'], 'A PDF destination must not be reported as a broken page.' );

$not_found_check = indexlane_invoke(
	'inspect_response_intent',
	array( indexlane_html_response( '<html><head><meta name="robots" content="noindex"></head></html>' ), 'https://example.test/gone' )
);
$not_found_row = indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/gone' ), indexlane_intent_check( 'https://example.test/gone', $not_found_check, 404 ) ) );
indexlane_assert_same( 'error', $not_found_row['result_code'], 'A broken transport result must never be softened by intent evidence.' );

indexlane_assert_same( 'error', indexlane_invoke( 'merge_intent_result_code', array( 'error', 'needs_review' ) ), 'Intent must not soften a broken transport result.' );
indexlane_assert_same( 'blocked', indexlane_invoke( 'merge_intent_result_code', array( 'blocked', 'needs_review' ) ), 'Intent must not soften a blocked transport result.' );
indexlane_assert_same( 'warning', indexlane_invoke( 'merge_intent_result_code', array( 'warning', 'needs_review' ) ), 'A redirected destination must keep its transport warning.' );
indexlane_assert_same( 'needs_review', indexlane_invoke( 'merge_intent_result_code', array( 'ok', 'needs_review' ) ), 'Page-level intent must escalate a healthy response.' );
indexlane_assert_same( 'warning', indexlane_invoke( 'merge_intent_result_code', array( 'ok', 'warning' ) ), 'A page warning must escalate a healthy response.' );
indexlane_assert_same( 'warning', indexlane_invoke( 'merge_intent_result_code', array( 'warning', 'info' ) ), 'Informational intent must not change a transport warning.' );
indexlane_assert_same( 'ok', indexlane_invoke( 'merge_intent_result_code', array( 'ok', 'info' ) ), 'Informational intent alone must stay OK.' );

$http_calls_before_intent_rows = count( $GLOBALS['indexlane_test_http_calls'] );
indexlane_invoke( 'build_checked_result_row', array( indexlane_intent_occurrence( $intent_source, 'https://example.test/old-service', 'intro' ), $moved_check ) );
indexlane_assert_same( $http_calls_before_intent_rows, count( $GLOBALS['indexlane_test_http_calls'] ), 'Occurrence-level intent evidence must reuse the stored destination check.' );

$intent_impact = indexlane_invoke( 'build_destination_impact', array( array( $moved_row ) ) );
indexlane_assert_same( 1, count( $intent_impact ), 'A destination that returns 200 but needs review must be reported as a problem URL.' );
indexlane_assert_same( 'Responds, but needs review', $intent_impact[0]['impact'], 'An intent-only destination must be labelled separately from broken and redirected URLs.' );
indexlane_assert_same( 'canonical_differs', $intent_impact[0]['intent_code_evidence'], 'Grouped problem URLs must retain the primary intent code of every occurrence.' );
indexlane_assert_same( true, false !== strpos( $intent_impact[0]['intent_evidence'], 'noindex' ), 'Grouped problem URLs must retain the readable intent evidence.' );
indexlane_assert_same( 'Needs review', $intent_impact[0]['result'], 'Grouped problem URLs must retain the merged outcome label.' );

$warning_only_impact = indexlane_invoke( 'build_destination_impact', array( array( $refresh_row ) ) );
indexlane_assert_same( 'Responds with a warning', $warning_only_impact[0]['impact'], 'A meta-refresh destination must be labelled as a warning.' );

$intent_impact_csv = indexlane_invoke( 'build_csv_rows', array( array( $moved_row ), 'impact' ) );
indexlane_assert_same( 'Intent', $intent_impact_csv[0][11], 'Problem-URL CSV must expose the stable intent codes.' );
indexlane_assert_same( 'canonical_differs', $intent_impact_csv[1][11], 'Problem-URL CSV must export the exact primary intent code.' );
indexlane_assert_same( true, false !== strpos( (string) $intent_impact_csv[1][12], 'Canonical points to a different URL' ), 'Problem-URL CSV must export readable intent details.' );

$intent_details_csv = indexlane_invoke( 'build_csv_rows', array( array( $moved_row ), 'details' ) );
indexlane_assert_same( 'Intent', $intent_details_csv[0][10], 'Link-details CSV must expose the stable intent code.' );
indexlane_assert_same( 'canonical_differs', $intent_details_csv[1][10], 'Link-details CSV must export the primary intent code.' );
indexlane_assert_same( 'Outcome', $intent_details_csv[0][13], 'Link-details CSV must retain its outcome column after the intent columns.' );

$intent_baseline_session                            = $baseline_session;
$intent_baseline_session['id']                      = '12345678-1234-4abc-8def-000000000060';
$intent_baseline_session['results']                 = array( $moved_row );
$intent_baseline_session['stats']['links_extracted'] = 1;
$intent_baseline_session['stats']['links_audited']   = 1;
$intent_baseline_session['stats']['unique_destinations_checked'] = 1;
$intent_baseline_session['stats']['http_requests']   = 1;
$intent_baseline_session['stats']['actionable_issues'] = 1;

$intent_baseline = indexlane_invoke( 'build_baseline_from_session', array( $intent_baseline_session ) );
indexlane_assert_same( false, is_wp_error( $intent_baseline ), 'A scan with an OK-but-wrong destination must be saveable for comparison.' );
indexlane_assert_same( 'canonical_differs', $intent_baseline['results'][0]['intent_code'], 'Saved scans must retain the exact intent code.' );
indexlane_assert_same(
	$intent_baseline,
	indexlane_invoke( 'parse_baseline_json', array( wp_json_encode( $intent_baseline, JSON_UNESCAPED_SLASHES ) ) ),
	'Destination-intent evidence must round-trip through strict import validation.'
);

$unsupported_intent                          = $intent_baseline;
$unsupported_intent['results'][0]['intent_code'] = 'invented_intent';
$unsupported_intent_result                   = indexlane_invoke( 'validate_baseline', array( $unsupported_intent, true ) );
indexlane_assert_same( true, is_wp_error( $unsupported_intent_result ), 'An unsupported intent code must fail strict import validation.' );

$severity_without_code                       = $intent_baseline;
$severity_without_code['results'][0]['intent_code'] = '';
$severity_without_code_result                = indexlane_invoke( 'validate_baseline', array( $severity_without_code, true ) );
indexlane_assert_same( true, is_wp_error( $severity_without_code_result ), 'An intent severity without an intent code must fail strict import validation.' );

$wrong_intent_severity = $intent_baseline;
$wrong_intent_severity['results'][0]['intent_severity'] = 'info';
indexlane_assert_same( true, is_wp_error( indexlane_invoke( 'validate_baseline', array( $wrong_intent_severity, true ) ) ), 'Known intent codes with contradictory severities must fail import.' );
$long_rel_baseline = $intent_baseline;
$long_rel_baseline['results'][0]['link_rel'] = implode( ' ', array_map( static function ( $number ) { return str_repeat( 'a', 31 ) . $number; }, range( 0, 7 ) ) );
indexlane_assert_same( false, is_wp_error( indexlane_invoke( 'validate_baseline', array( $long_rel_baseline, true ) ) ), 'All bounded rel lists produced by the scanner must be importable.' );

$intent_verification_row = indexlane_result_row(
	'https://example.test/service',
	'https://example.test/old-service',
	'200',
	0,
	'https://example.test/old-service',
	'None',
	'Needs review',
	'Service page',
	'Service',
	array(
		'intent_code'     => 'noindex',
		'intent_severity' => 'needs_review',
		'intent_detail'   => 'The page contains a noindex robots meta tag.',
	)
);
$intent_comparison = indexlane_invoke( 'build_scan_comparison', array( $intent_baseline['results'], array( $intent_verification_row ) ) );
indexlane_assert_same( 'changed', $intent_comparison['rows'][0]['category'], 'A destination whose intent changed must be reported as changed.' );
indexlane_assert_same( true, in_array( 'intent_code', $intent_comparison['rows'][0]['changed_fields'], true ), 'Changed intent must be named as changed evidence.' );
indexlane_assert_same( 'changed', $intent_comparison['rows'][0]['direction'], 'A lateral intent change must report changed behavior.' );

$intent_verification_session                              = $intent_baseline_session;
$intent_verification_session['id']                        = '12345678-1234-4abc-8def-000000000061';
$intent_verification_session['scan_mode']                 = 'verification';
$intent_verification_session['baseline_id']               = $intent_baseline['baseline_id'];
$intent_verification_session['baseline_fingerprint']      = indexlane_invoke( 'baseline_fingerprint', array( $intent_baseline ) );
$intent_verification_session['results']                   = array( $intent_verification_row );
$stored_intent_comparison = indexlane_invoke( 'save_baseline', array( $intent_baseline ) );
indexlane_assert_same( true, $stored_intent_comparison, 'A scan with intent evidence must persist as the saved comparison.' );
$comparison_with_intent = indexlane_invoke( 'get_session_comparison', array( $intent_verification_session ) );
indexlane_assert_same( 'changed', $comparison_with_intent['rows'][0]['category'], 'A fix check must compare stored intent evidence exactly.' );
indexlane_assert_same( 'Saved Scan Intent', indexlane_invoke( 'build_comparison_csv_rows', array( $comparison_with_intent ) )[0][10], 'Comparison CSV must expose the saved-scan intent code.' );
indexlane_assert_same( 'Latest Scan Intent', indexlane_invoke( 'build_comparison_csv_rows', array( $comparison_with_intent ) )[0][11], 'Comparison CSV must expose the latest-scan intent code.' );

ob_start();
indexlane_invoke( 'render_destination_impact', array( $intent_impact ) );
$intent_impact_html = (string) ob_get_clean();
indexlane_assert_same( true, false !== strpos( $intent_impact_html, 'Responds, but needs review' ), 'The problem-URL table must label an OK-but-wrong destination.' );
indexlane_assert_same( true, false !== strpos( $intent_impact_html, 'Page intent' ), 'The problem-URL details must name the page-intent evidence.' );
indexlane_assert_same( true, false !== strpos( $intent_impact_html, 'Canonical points to a different URL' ), 'The problem-URL details must show the stored intent evidence.' );

ob_start();
indexlane_invoke( 'render_occurrence_details', array( array( $moved_row ) ) );
$intent_details_html = (string) ob_get_clean();
indexlane_assert_same( 11, substr_count( $intent_details_html, '<th>' ), 'The link-details table must expose one header per evidence column.' );
indexlane_assert_same( 11, substr_count( $intent_details_html, '<td>' ), 'Every link-details column must keep a matching cell.' );
indexlane_assert_same( true, false !== strpos( $intent_details_html, 'Canonical points to a different URL' ), 'The link-details table must show page intent for each occurrence.' );

ob_start();
indexlane_invoke(
	'render_occurrence_details',
	array(
		array(
			indexlane_result_row(
				'https://example.test/source-a',
				'https://example.test/healthy',
				'200',
				0,
				'https://example.test/healthy',
				'None',
				'OK'
			),
		),
	)
);
$healthy_details_html = (string) ob_get_clean();
indexlane_assert_same(
	2,
	preg_match_all( '#<td>\s*None\s*</td>#', $healthy_details_html ),
	'An occurrence without intent evidence must render an explicit empty value beside its empty warning.'
);

ob_start();
indexlane_invoke( 'render_comparison', array( $comparison_with_intent ) );
$comparison_html = (string) ob_get_clean();
indexlane_assert_same( true, false !== strpos( $comparison_html, 'Page intent' ), 'The comparison details must name the page-intent evidence.' );
indexlane_assert_same( true, false !== strpos( $comparison_html, 'noindex robots meta tag' ), 'The comparison details must show the latest intent evidence.' );
indexlane_assert_same( true, false !== strpos( $comparison_html, 'page intent' ), 'A changed intent must be named among the changed fields.' );

fwrite( STDOUT, "All behavioral tests passed.\n" );
