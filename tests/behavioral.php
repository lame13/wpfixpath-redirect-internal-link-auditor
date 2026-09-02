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
			'result'          => $result,
			'result_code'     => $result_code,
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
		array( 'href' => '/stored-navigation', 'anchor' => 'Stored navigation' ),
		array( 'href' => '/parent', 'anchor' => 'Parent' ),
		array( 'href' => '/child', 'anchor' => 'Child' ),
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
indexlane_assert_same( "'\n+SUM(1,1)", $details_csv[1][10], 'CSV safety should block formulas after leading newlines.' );

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
indexlane_assert_same( 'Latest Scan Editable Sources Affected', $comparison_csv[0][15], 'Comparison CSV must expose latest-scan source impact explicitly.' );

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
	'schema_version'              => 4,
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
indexlane_assert_same( 2, $baseline['schema_version'], 'Baseline JSON must carry an explicit source-aware schema version.' );
indexlane_assert_same( '0.6.0', $baseline['plugin_version'], 'Saved-scan metadata must identify the plugin version.' );
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
	unset( $legacy_row['source_key'], $legacy_row['source_type_code'], $legacy_row['source_context'], $legacy_row['source_content_id'] );
}
unset( $legacy_row );
$upgraded_legacy = indexlane_invoke( 'validate_baseline', array( $legacy_baseline, true ) );
indexlane_assert_same( false, is_wp_error( $upgraded_legacy ), 'Strict 0.5 saved scans must remain importable as content-only evidence.' );
indexlane_assert_same( 2, $upgraded_legacy['schema_version'], 'A legacy saved scan must normalize to the source-aware schema.' );
indexlane_assert_same( array( 'content' ), $upgraded_legacy['settings']['source_types'], 'Legacy scans must retain their exact post-content-only scope.' );
indexlane_assert_same( 'contextual', $upgraded_legacy['results'][0]['source_context'], 'Legacy occurrences must normalize as contextual content sources.' );

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
	'schema_version'   => 4,
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

fwrite( STDOUT, "All behavioral tests passed.\n" );
