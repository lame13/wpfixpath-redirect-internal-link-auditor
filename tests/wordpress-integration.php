<?php
/**
 * WordPress-loaded integration coverage for resumable scans and 0.4 reports.
 *
 * Run after WordPress is installed and the plugin is active:
 * wp eval-file wp-content/plugins/indexlane-redirect-internal-link-auditor/tests/wordpress-integration.php
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load this test through WordPress.\n" );
	exit( 1 );
}

/**
 * Invoke a private plugin method for integration coverage.
 *
 * @param string             $method    Method name.
 * @param array<int,mixed>   $arguments Arguments.
 * @return mixed
 */
function indexlane_wp_invoke( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'IndexLane_Redirect_Internal_Link_Auditor', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}

	return $reflection->invokeArgs( null, $arguments );
}

/**
 * Fail with a useful integration-test message.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion message.
 */
function indexlane_wp_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

if ( ! class_exists( 'IndexLane_Redirect_Internal_Link_Auditor' ) ) {
	throw new RuntimeException( 'The plugin class is not loaded.' );
}

$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator instanceof WP_User ) {
	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	$administrator = isset( $administrators[0] ) ? $administrators[0] : null;
}
if ( ! $administrator instanceof WP_User ) {
	throw new RuntimeException( 'An administrator account is required for the integration test.' );
}
wp_set_current_user( $administrator->ID );

$translation_filter = static function ( string $translation, string $text ): string {
	$translations = array(
		'Destination' => 'Ziel',
		'Paused'      => 'Pausiert',
		'Target Title' => 'Zieltitel',
	);

	return isset( $translations[ $text ] ) ? $translations[ $text ] : $translation;
};
add_filter( 'gettext_indexlane-redirect-internal-link-auditor', $translation_filter, 10, 2 );
indexlane_wp_assert_same( 'Pausiert', indexlane_wp_invoke( 'scan_state_label', array( 'paused' ) ), 'Session-state copy must use the exact plugin text domain.' );
$translated_csv = indexlane_wp_invoke( 'build_csv_rows', array( array(), 'impact' ) );
indexlane_wp_assert_same( 'Ziel', $translated_csv[0][0], 'CSV headers must be translated through the exact plugin text domain.' );
$translated_coverage_csv = indexlane_wp_invoke( 'build_csv_rows', array( array(), 'coverage', array() ) );
indexlane_wp_assert_same( 'Zieltitel', $translated_coverage_csv[0][0], 'Coverage CSV headers must use the exact plugin text domain.' );
remove_filter( 'gettext_indexlane-redirect-internal-link-auditor', $translation_filter, 10 );

$post_type = 'indexlane_fixture';
register_post_type(
	$post_type,
	array(
		'label'        => 'Audit fixtures',
		'public'       => true,
		'show_ui'      => true,
		'supports'     => array( 'title', 'editor' ),
	)
);

$fixture_ids = array();
$http_calls  = array();
$home        = untrailingslashit( home_url( '/' ) );
$coverage_target_url = '';
$contents    = array(
	'<a href="' . esc_url( $home . '/fixture-redirect' ) . '">Redirect again</a>',
	'<a href="' . esc_url( $home . '/fixture-ok' ) . '">Healthy</a>',
	'<a href="https://unrelated.invalid/example">Unrelated external</a>',
	'<a href="https://old.example/path">Old domain</a>',
	'<a href="' . esc_url( $home . '/fixture-broken' ) . '">Broken</a>',
	'<a href="' . esc_url( $home . '/fixture-ok' ) . '">Healthy one</a> <a href="' . esc_url( $home . '/fixture-ok' ) . '">Healthy two</a>',
	'<a href="' . esc_url( $home . '/fixture-redirect' ) . '">Redirect first</a>',
);

$http_filter = static function ( $preempt, array $args, string $url ) use ( &$http_calls, $home, &$coverage_target_url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$known_paths = array( '/fixture-ok', '/fixture-redirect', '/fixture-final', '/fixture-broken' );
	$is_coverage_target = '' !== $coverage_target_url && untrailingslashit( $url ) === untrailingslashit( $coverage_target_url );
	if ( ! $is_coverage_target && ! in_array( $path, $known_paths, true ) ) {
		return $preempt;
	}

	$http_calls[] = $url;
	$status       = 200;
	$headers      = array();
	if ( '/fixture-redirect' === $path ) {
		$status              = 301;
		$headers['location'] = '' !== $coverage_target_url ? $coverage_target_url : $home . '/fixture-final';
	} elseif ( '/fixture-broken' === $path ) {
		$status = 404;
	}

	return array(
		'headers'  => $headers,
		'body'     => '',
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

try {
	foreach ( $contents as $index => $content ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => 'Audit fixture ' . ( $index + 1 ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}
		$fixture_ids[] = (int) $post_id;
	}

	$coverage_target_id  = $fixture_ids[1];
	$coverage_target_url = (string) get_permalink( $coverage_target_id );
	if ( '' === $coverage_target_url ) {
		throw new RuntimeException( 'The coverage target requires a published permalink.' );
	}
	foreach ( $fixture_ids as $fixture_id ) {
		$fixture_post = get_post( $fixture_id );
		if ( ! $fixture_post instanceof WP_Post ) {
			throw new RuntimeException( 'A coverage fixture post could not be loaded.' );
		}

		$updated_content = str_replace( $home . '/fixture-ok', $coverage_target_url, (string) $fixture_post->post_content );
		$updated_id      = wp_update_post(
			array(
				'ID'           => $fixture_id,
				'post_content' => $updated_content,
			),
			true
		);
		if ( is_wp_error( $updated_id ) ) {
			throw new RuntimeException( $updated_id->get_error_message() );
		}
	}

	$settings = indexlane_wp_invoke(
		'get_request_settings',
		array(
			array(
				'post_types'    => array( $post_type ),
				'content_scope' => 'all',
				'max_posts'     => 1,
				'old_domains'   => 'old.example',
				'timeout'       => 2,
				'max_redirects' => 5,
			)
		)
	);
	indexlane_wp_assert_same( array( $post_type ), $settings['post_types'], 'Every registered public post type must be selectable.' );
	indexlane_wp_assert_same( 'all', $settings['content_scope'], 'The all-published-content scope must survive request validation.' );

	$session = indexlane_wp_invoke( 'create_scan_session', array( $settings ) );
	indexlane_wp_assert_same( 7, $session['total_items'], 'The all-content snapshot must include the complete selected corpus.' );
	$session['request_limit'] = 1;
	$session = indexlane_wp_invoke( 'process_scan_batch', array( $session ) );
	indexlane_wp_assert_same( 'limit_reached', $session['status'], 'The scan must stop at its explicit total request allowance.' );
	indexlane_wp_assert_same( 1, $session['stats']['http_requests'], 'The first redirect hop must consume the one-request allowance.' );
	indexlane_wp_assert_same( 0, count( $session['results'] ), 'A redirect interrupted by the allowance must not produce incomplete evidence.' );
	indexlane_wp_assert_same( array( $home . '/fixture-redirect' ), $http_calls, 'The redirect target must remain pending instead of restarting or being fetched early.' );

	indexlane_wp_assert_same( true, indexlane_wp_invoke( 'save_scan_session', array( $session ) ), 'WordPress must persist the session between requests.' );
	$session = indexlane_wp_invoke( 'get_scan_session' );
	indexlane_wp_assert_same( 'limit_reached', $session['status'], 'A request-limited session must resume after a page/request boundary.' );
	$session['request_limit'] += 250;
	$session['status']         = 'running';

	$batch_count   = 0;
	$pause_checked = false;
	while ( 'complete' !== $session['status'] && $batch_count < 30 ) {
		$requests_before = (int) $session['stats']['http_requests'];
		$session = indexlane_wp_invoke( 'process_scan_batch', array( $session ) );
		$request_delta = (int) $session['stats']['http_requests'] - $requests_before;
		if ( $request_delta > 5 ) {
			throw new RuntimeException( 'One WordPress scan batch exceeded the five-request hard limit.' );
		}

		if ( ! $pause_checked && 'running' === $session['status'] ) {
			$session['status'] = 'paused';
			$paused_stats      = $session['stats'];
			$session           = indexlane_wp_invoke( 'process_scan_batch', array( $session ) );
			indexlane_wp_assert_same( $paused_stats, $session['stats'], 'Paused sessions must not process content or HTTP work.' );
			indexlane_wp_invoke( 'save_scan_session', array( $session ) );
			$session = indexlane_wp_invoke( 'get_scan_session' );
			indexlane_wp_assert_same( 'paused', $session['status'], 'A paused session must remain paused after reload.' );
			$session['status'] = 'running';
			$pause_checked     = true;
		}

		indexlane_wp_invoke( 'save_scan_session', array( $session ) );
		$session = indexlane_wp_invoke( 'get_scan_session' );
		$batch_count++;
	}

	indexlane_wp_assert_same( 'complete', $session['status'], 'The resumed WordPress session must complete.' );
	indexlane_wp_assert_same( 7, $session['stats']['content_items_processed'], 'Every selected content item must be processed.' );
	indexlane_wp_assert_same( 8, $session['stats']['links_extracted'], 'Every link occurrence must be extracted.' );
	indexlane_wp_assert_same( 7, $session['stats']['links_audited'], 'Only relevant same-site, old-domain, or staging occurrences are audited.' );
	indexlane_wp_assert_same( 1, $session['stats']['skipped_external'], 'Unrelated external links must remain outside the audit.' );
	indexlane_wp_assert_same( 3, $session['stats']['unique_destinations_checked'], 'Unique destination progress must use the full-session request cache.' );
	indexlane_wp_assert_same( 4, $session['stats']['http_requests'], 'Redirect hops count while repeated destinations remain deduplicated.' );
	indexlane_wp_assert_same( 4, $session['stats']['actionable_issues'], 'Actionable progress must count exact non-OK occurrences.' );
	indexlane_wp_assert_same( 7, count( $session['results'] ), 'Completed evidence must retain every audited occurrence.' );
	indexlane_wp_assert_same( 7, count( $session['content_items'] ), 'The completed session must retain one metadata row per scanned content item.' );
	indexlane_wp_assert_same( 0, count( array_keys( $http_calls, $home . '/fixture-ok', true ) ), 'The placeholder URL must be replaced by the published coverage target before scanning.' );
	indexlane_wp_assert_same( 1, count( array_keys( $http_calls, $home . '/fixture-redirect', true ) ), 'A repeated redirecting destination must be requested once for the whole session.' );
	indexlane_wp_assert_same( 2, count( array_keys( $http_calls, $coverage_target_url, true ) ), 'The published target is checked once directly and once as the redirect chain final hop.' );

	$http_calls_before_coverage = count( $http_calls );
	$coverage_rows = indexlane_wp_invoke( 'build_content_link_coverage', array( $session['content_items'], $session['results'] ) );
	indexlane_wp_assert_same( $http_calls_before_coverage, count( $http_calls ), 'Building coverage from the completed session must not make more HTTP requests.' );
	indexlane_wp_assert_same( 7, count( $coverage_rows ), 'Coverage must contain one row per scanned published item.' );
	$coverage_by_id = array_column( $coverage_rows, null, 'target_id' );
	indexlane_wp_assert_same( 5, $coverage_by_id[ $coverage_target_id ]['incoming_occurrences'], 'Direct and redirected occurrences must resolve to the published WordPress target.' );
	indexlane_wp_assert_same( 4, $coverage_by_id[ $coverage_target_id ]['linking_source_count'], 'Distinct linking sources must deduplicate repeated links in one item.' );
	indexlane_wp_assert_same( 3, $coverage_by_id[ $coverage_target_id ]['direct_incoming'], 'Direct incoming target evidence must be counted separately.' );
	indexlane_wp_assert_same( 2, $coverage_by_id[ $coverage_target_id ]['redirected_incoming'], 'Redirect aliases must count toward their final published target.' );
	indexlane_wp_assert_same( 1, $coverage_by_id[ $coverage_target_id ]['self_link_count'], 'A target linking to itself must retain explicit self-link evidence.' );
	indexlane_wp_assert_same( 'Multiple linking sources', $coverage_by_id[ $coverage_target_id ]['status'], 'The coverage status must describe multiple scanned linking sources.' );

	$detail_rows = indexlane_wp_invoke( 'build_csv_rows', array( $session['results'], 'details' ) );
	$impact_rows = indexlane_wp_invoke( 'build_csv_rows', array( $session['results'], 'impact' ) );
	$coverage_csv_rows = indexlane_wp_invoke( 'build_csv_rows', array( $session['results'], 'coverage', $session['content_items'] ) );
	indexlane_wp_assert_same( 8, count( $detail_rows ), 'Detailed CSV must contain the exact seven evidence rows plus its header.' );
	indexlane_wp_assert_same( 3, count( $impact_rows ), 'Impact CSV must contain the broken and redirect destinations plus its header.' );
	indexlane_wp_assert_same( 8, count( $coverage_csv_rows ), 'Coverage CSV must contain every scanned content item plus its header.' );

	$original_user_id = get_current_user_id();
	wp_set_current_user( 0 );
	indexlane_wp_assert_same( null, indexlane_wp_invoke( 'get_scan_session' ), 'Another user must not see the administrator session.' );
	wp_set_current_user( $original_user_id );
	indexlane_wp_assert_same( 7, count( indexlane_wp_invoke( 'get_scan_session' )['results'] ), 'The owning administrator must retain the completed evidence.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );
	wp_set_current_user( $administrator->ID );
	indexlane_wp_invoke( 'delete_scan_session' );
	foreach ( $fixture_ids as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	unregister_post_type( $post_type );
}

fwrite( STDOUT, "WordPress integration tests passed.\n" );
