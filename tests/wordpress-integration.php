<?php
/**
 * WordPress-loaded integration coverage for resumable scans, reports, and baselines.
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

/**
 * Drain one finite built-in provider snapshot without starting HTTP work.
 *
 * @param string              $snapshot_method Snapshot callback method.
 * @param string              $next_method     Next-source callback method.
 * @param array<string,mixed> $settings        Scan settings.
 * @return array<int,array<string,mixed>>
 */
function indexlane_wp_provider_sources( string $snapshot_method, string $next_method, array $settings ): array {
	$snapshot = indexlane_wp_invoke( $snapshot_method, array( $settings ) );
	if ( is_wp_error( $snapshot ) ) {
		throw new RuntimeException( $snapshot->get_error_message() );
	}

	$sources = array();
	$cursor  = $snapshot['cursor'];
	$limit   = (int) $snapshot['total_items'] + 10;
	for ( $index = 0; $index < $limit; $index++ ) {
		$next   = indexlane_wp_invoke( $next_method, array( $cursor, $settings ) );
		$cursor = $next['cursor'];
		if ( is_array( $next['source'] ) ) {
			$sources[] = $next['source'];
		}
		if ( $next['done'] ) {
			break;
		}
	}

	return $sources;
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
		'Content' => 'Inhalt',
		'Paused'  => 'Pausiert',
		'URL'     => 'Adresse',
	);

	return isset( $translations[ $text ] ) ? $translations[ $text ] : $translation;
};
add_filter( 'gettext_indexlane-redirect-internal-link-auditor', $translation_filter, 10, 2 );
indexlane_wp_assert_same( 'Pausiert', indexlane_wp_invoke( 'scan_state_label', array( 'paused' ) ), 'Session-state copy must use the exact plugin text domain.' );
$translated_csv = indexlane_wp_invoke( 'build_csv_rows', array( array(), 'impact' ) );
indexlane_wp_assert_same( 'Adresse', $translated_csv[0][0], 'CSV headers must be translated through the exact plugin text domain.' );
$translated_coverage_csv = indexlane_wp_invoke( 'build_csv_rows', array( array(), 'coverage', array() ) );
indexlane_wp_assert_same( 'Inhalt', $translated_coverage_csv[0][0], 'Coverage CSV headers must use the exact plugin text domain.' );
$translated_comparison_csv = indexlane_wp_invoke(
	'build_comparison_csv_rows',
	array(
		array(
			'summary' => array(),
			'rows'    => array(),
		)
	)
);
indexlane_wp_assert_same( 'Adresse', $translated_comparison_csv[0][2], 'Comparison CSV headers must use the exact plugin text domain.' );
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
$shared_post_ids    = array();
$template_fixture_keys = array();
$classic_menu_id    = 0;
$widget_number      = 0;
$registered_sidebar = false;
$expected_shared_occurrences = 0;
$widget_block_before = get_option( 'widget_block', false );
$sidebars_before     = get_option( 'sidebars_widgets', false );
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
	delete_user_option( $administrator->ID, 'indexlane_rila_baseline', false );

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

	$available_sources = indexlane_wp_invoke( 'get_available_source_types' );
	indexlane_wp_assert_same(
		array(),
		array_values( array_diff( array( 'content', 'menu', 'navigation', 'pattern', 'template', 'template_part', 'widget' ), array_keys( $available_sources ) ) ),
		'Every planned WordPress-native source adapter must be registered.'
	);

	$adapter_settings = array(
		'source_types'     => array_keys( $available_sources ),
		'post_types'       => array( $post_type ),
		'old_domains'      => '',
		'old_domain_hosts' => array(),
		'content_scope'    => 'all',
		'max_posts'        => 100,
		'timeout'          => 2.0,
		'max_redirects'    => 5,
	);

	$classic_menu_id = wp_create_nav_menu( 'IndexLane integration ' . wp_generate_uuid4() );
	if ( is_wp_error( $classic_menu_id ) ) {
		throw new RuntimeException( $classic_menu_id->get_error_message() );
	}
	$classic_menu_id = (int) $classic_menu_id;
	$menu_item_id    = wp_update_nav_menu_item(
		$classic_menu_id,
		0,
		array(
			'menu-item-title'  => 'Shared menu target',
			'menu-item-url'    => $coverage_target_url,
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		)
	);
	if ( is_wp_error( $menu_item_id ) ) {
		throw new RuntimeException( $menu_item_id->get_error_message() );
	}
	$menu_sources = indexlane_wp_provider_sources( 'snapshot_classic_menu_provider', 'next_classic_menu_provider_source', $adapter_settings );
	$menu_by_key  = array_column( $menu_sources, null, 'key' );
	indexlane_wp_assert_same( true, isset( $menu_by_key[ 'menu:' . $classic_menu_id ] ), 'Classic menus must be exposed as exact shared sources.' );
	indexlane_wp_assert_same( 1, count( $menu_by_key[ 'menu:' . $classic_menu_id ]['links'] ), 'Classic menu sources must retain their exact stored link.' );
	indexlane_wp_assert_same( 'Shared menu target', $menu_by_key[ 'menu:' . $classic_menu_id ]['links'][0]['anchor'], 'Classic menu item labels must become exact anchor evidence.' );
	$expected_shared_occurrences++;

	if ( post_type_exists( 'wp_navigation' ) ) {
		$navigation_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'IndexLane integration navigation',
				'post_content' => wp_slash( '<!-- wp:navigation-link {"label":"Navigation target","url":"' . esc_url_raw( $coverage_target_url ) . '","kind":"custom"} /-->' ),
			),
			true
		);
		if ( is_wp_error( $navigation_id ) ) {
			throw new RuntimeException( $navigation_id->get_error_message() );
		}
		$shared_post_ids[] = (int) $navigation_id;
		$navigation_sources = indexlane_wp_provider_sources( 'snapshot_navigation_provider', 'next_navigation_provider_source', $adapter_settings );
		$navigation_by_key  = array_column( $navigation_sources, null, 'key' );
		$navigation_key     = 'navigation:' . (int) $navigation_id;
		indexlane_wp_assert_same( true, isset( $navigation_by_key[ $navigation_key ] ), 'Navigation entities must be exposed as exact shared sources.' );
		$normalized_navigation = indexlane_wp_invoke(
			'normalize_source_record',
			array( $navigation_by_key[ $navigation_key ], 'navigation', array( 'label' => 'Navigation', 'context' => 'shared' ) )
		);
		$navigation_links = indexlane_wp_invoke( 'extract_source_links', array( $normalized_navigation ) );
		indexlane_wp_assert_same( 1, count( $navigation_links ), 'Navigation entities must retain each exact stored link occurrence.' );
		indexlane_wp_assert_same( $coverage_target_url, $navigation_links[0]['href'], 'Self-closing Navigation links must be extracted from stored block attributes.' );
		$expected_shared_occurrences++;
	}

	if ( post_type_exists( 'wp_block' ) ) {
		$pattern_id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'IndexLane integration pattern',
				'post_content' => '<p><a href="' . esc_url( $coverage_target_url ) . '">Pattern target</a></p>',
			),
			true
		);
		$unsynced_pattern_id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'IndexLane unsynced integration pattern',
				'post_content' => '<p><a href="' . esc_url( $coverage_target_url ) . '">Unsynced target</a></p>',
				'meta_input'   => array( 'wp_pattern_sync_status' => 'unsynced' ),
			),
			true
		);
		if ( is_wp_error( $pattern_id ) || is_wp_error( $unsynced_pattern_id ) ) {
			throw new RuntimeException( 'WordPress could not create the synced-pattern fixtures.' );
		}
		$shared_post_ids[] = (int) $pattern_id;
		$shared_post_ids[] = (int) $unsynced_pattern_id;
		$pattern_sources   = indexlane_wp_provider_sources( 'snapshot_pattern_provider', 'next_pattern_provider_source', $adapter_settings );
		$pattern_by_key    = array_column( $pattern_sources, null, 'key' );
		indexlane_wp_assert_same( true, isset( $pattern_by_key[ 'pattern:' . (int) $pattern_id ] ), 'Synced patterns must be exposed as exact shared sources.' );
		indexlane_wp_assert_same( false, isset( $pattern_by_key[ 'pattern:' . (int) $unsynced_pattern_id ] ), 'Unsynced patterns must not be mislabeled as shared synced patterns.' );
		$expected_shared_occurrences++;
	}

	$template_fixture_slug = 'indexlane-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
	$template_fixtures     = array(
		array( 'post_type' => 'wp_template', 'provider_type' => 'template', 'title' => 'IndexLane integration template', 'slug' => $template_fixture_slug . '-template' ),
		array( 'post_type' => 'wp_template_part', 'provider_type' => 'template_part', 'title' => 'IndexLane integration footer', 'slug' => $template_fixture_slug . '-footer' ),
	);
	foreach ( $template_fixtures as $template_fixture ) {
		if ( ! post_type_exists( $template_fixture['post_type'] ) || ! taxonomy_exists( 'wp_theme' ) ) {
			continue;
		}
		$template_post_id = wp_insert_post(
			array(
				'post_type'    => $template_fixture['post_type'],
				'post_status'  => 'publish',
				'post_name'    => $template_fixture['slug'],
				'post_title'   => $template_fixture['title'],
				'post_content' => '<p><a href="' . esc_url( $coverage_target_url ) . '">Template target</a></p>',
			),
			true
		);
		if ( is_wp_error( $template_post_id ) ) {
			throw new RuntimeException( $template_post_id->get_error_message() );
		}
		$theme_terms = wp_set_object_terms( (int) $template_post_id, get_stylesheet(), 'wp_theme' );
		if ( is_wp_error( $theme_terms ) ) {
			throw new RuntimeException( $theme_terms->get_error_message() );
		}
		if ( 'wp_template_part' === $template_fixture['post_type'] && taxonomy_exists( 'wp_template_part_area' ) ) {
			$area_terms = wp_set_object_terms( (int) $template_post_id, 'footer', 'wp_template_part_area' );
			if ( is_wp_error( $area_terms ) ) {
				throw new RuntimeException( $area_terms->get_error_message() );
			}
		}
		$shared_post_ids[] = (int) $template_post_id;
		$template_fixture_keys[ $template_fixture['provider_type'] ] = $template_fixture['provider_type'] . ':' . get_stylesheet() . '//' . $template_fixture['slug'];
		$expected_shared_occurrences++;
	}

	register_sidebar(
		array(
			'id'   => 'indexlane-integration-area',
			'name' => 'IndexLane integration area',
		)
	);
	$registered_sidebar = true;
	$widget_instances = get_option( 'widget_block', array() );
	$widget_instances = is_array( $widget_instances ) ? $widget_instances : array();
	$numeric_widget_ids = array_filter( array_map( 'intval', array_keys( $widget_instances ) ) );
	$widget_number      = empty( $numeric_widget_ids ) ? 1 : max( $numeric_widget_ids ) + 1;
	$widget_instances[ $widget_number ] = array( 'content' => '<p><a href="' . esc_url( $coverage_target_url ) . '">Widget target</a></p>' );
	update_option( 'widget_block', $widget_instances );
	$sidebars = wp_get_sidebars_widgets();
	$sidebars['indexlane-integration-area'] = array( 'block-' . $widget_number );
	wp_set_sidebars_widgets( $sidebars );
	$widget_sources = indexlane_wp_provider_sources( 'snapshot_widget_provider', 'next_widget_provider_source', $adapter_settings );
	$widget_by_key  = array_column( $widget_sources, null, 'key' );
	indexlane_wp_assert_same( true, isset( $widget_by_key[ 'widget:block-' . $widget_number ] ), 'Assigned block widgets must be exposed as exact shared sources.' );
	indexlane_wp_assert_same( true, false !== strpos( $widget_by_key[ 'widget:block-' . $widget_number ]['title'], 'IndexLane integration area' ), 'Block widget identity must name the exact widget area where it is maintained.' );
	$expected_shared_occurrences++;

	$template_sources = indexlane_wp_provider_sources( 'snapshot_template_provider', 'next_template_provider_source', $adapter_settings );
	$template_by_key  = array_column( $template_sources, null, 'key' );
	if ( isset( $template_fixture_keys['template'] ) ) {
		indexlane_wp_assert_same( true, isset( $template_by_key[ $template_fixture_keys['template'] ] ), 'A stored block template must be exposed through its unified WordPress identity.' );
		$template_links = indexlane_wp_invoke( 'extract_links', array( $template_by_key[ $template_fixture_keys['template'] ]['content'] ) );
		indexlane_wp_assert_same( 1, count( $template_links ), 'A block-template fixture must retain its exact stored occurrence.' );
		indexlane_wp_assert_same( $coverage_target_url, $template_links[0]['href'], 'Block templates must expose links from their stored content without rendering.' );
	}
	foreach ( $template_sources as $template_source ) {
		indexlane_wp_assert_same( true, 0 === strpos( $template_source['key'], 'template:' ), 'Block template sources must retain their unified WordPress identity.' );
		indexlane_wp_assert_same( true, false !== strpos( $template_source['edit_url'], 'site-editor.php' ), 'Block template sources must link to their editor.' );
	}
	$template_part_sources = indexlane_wp_provider_sources( 'snapshot_template_part_provider', 'next_template_part_provider_source', $adapter_settings );
	$template_part_by_key  = array_column( $template_part_sources, null, 'key' );
	if ( isset( $template_fixture_keys['template_part'] ) ) {
		indexlane_wp_assert_same( true, isset( $template_part_by_key[ $template_fixture_keys['template_part'] ] ), 'A stored template part must be exposed through its unified WordPress identity.' );
		$template_part_links = indexlane_wp_invoke( 'extract_links', array( $template_part_by_key[ $template_fixture_keys['template_part'] ]['content'] ) );
		indexlane_wp_assert_same( 1, count( $template_part_links ), 'A template-part fixture must retain its exact stored occurrence.' );
		indexlane_wp_assert_same( $coverage_target_url, $template_part_links[0]['href'], 'Template parts must expose links from their stored content without rendering.' );
	}
	foreach ( $template_part_sources as $template_part_source ) {
		indexlane_wp_assert_same( true, 0 === strpos( $template_part_source['key'], 'template_part:' ), 'Template-part sources must retain their unified WordPress identity.' );
		indexlane_wp_assert_same( true, false !== strpos( $template_part_source['edit_url'], 'site-editor.php' ), 'Template-part sources must link to their editor.' );
	}

	$adapter_session = indexlane_wp_invoke( 'create_scan_session', array( $adapter_settings ) );
	if ( is_wp_error( $adapter_session ) ) {
		throw new RuntimeException( $adapter_session->get_error_message() );
	}
	for ( $adapter_batch = 0; $adapter_batch < 200 && 'complete' !== $adapter_session['status']; $adapter_batch++ ) {
		if ( 'limit_reached' === $adapter_session['status'] ) {
			$adapter_session['request_limit'] += 250;
			$adapter_session['request_allowance_extensions']++;
			$adapter_session['status'] = 'running';
		}
		$adapter_session = indexlane_wp_invoke( 'process_scan_batch', array( $adapter_session ) );
	}
	indexlane_wp_assert_same( 'complete', $adapter_session['status'], 'A scan selecting every built-in source adapter must complete through the normal engine.' );
	$adapter_result_types = array_values( array_unique( array_column( $adapter_session['results'], 'source_type_code' ) ) );
	sort( $adapter_result_types, SORT_STRING );
	$expected_adapter_types = array( 'content', 'menu', 'widget' );
	if ( isset( $navigation_key ) ) {
		$expected_adapter_types[] = 'navigation';
	}
	if ( isset( $pattern_id ) ) {
		$expected_adapter_types[] = 'pattern';
	}
	$expected_adapter_types = array_merge( $expected_adapter_types, array_keys( $template_fixture_keys ) );
	$expected_adapter_types = array_values( array_unique( $expected_adapter_types ) );
	sort( $expected_adapter_types, SORT_STRING );
	indexlane_wp_assert_same( array(), array_values( array_diff( $expected_adapter_types, $adapter_result_types ) ), 'Every fixture-backed built-in adapter must retain evidence through the shared scan engine.' );
	$adapter_coverage       = indexlane_wp_invoke( 'build_content_link_coverage', array( $adapter_session['content_items'], $adapter_session['results'] ) );
	$adapter_coverage_by_id = array_column( $adapter_coverage, null, 'target_id' );
	indexlane_wp_assert_same( 5, $adapter_coverage_by_id[ $coverage_target_id ]['contextual_incoming'], 'All-adapter coverage must retain contextual incoming occurrences.' );
	indexlane_wp_assert_same( $expected_shared_occurrences, $adapter_coverage_by_id[ $coverage_target_id ]['shared_incoming'], 'All-adapter coverage must retain each exact shared fixture occurrence.' );
	$http_calls = array();

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
	indexlane_wp_assert_same( array( 'content' ), $settings['source_types'], 'Pre-0.6 programmatic scan requests must retain their content-only scope.' );
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
	$session['request_allowance_extensions']++;
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
	indexlane_wp_assert_same( 7, $session['stats']['sources_processed'], 'Every selected stored source must be processed.' );
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

	// Restore the production request-allowance invariant after the deliberately
	// tiny allowance used above to exercise an interrupted redirect chain.
	$session['request_limit']                = 250;
	$session['request_allowance_extensions'] = 0;
	$baseline = indexlane_wp_invoke( 'build_baseline_from_session', array( $session ) );
	if ( is_wp_error( $baseline ) ) {
		throw new RuntimeException( $baseline->get_error_message() );
	}
	indexlane_wp_assert_same( 'indexlane-rila-baseline', $baseline['format'], 'A completed WordPress scan must produce portable baseline evidence.' );
	indexlane_wp_assert_same( 2, $baseline['schema_version'], 'The baseline must use the source-aware evidence schema.' );
	indexlane_wp_assert_same( '0.6.0', $baseline['plugin_version'], 'The saved scan must identify the plugin version that created it.' );
	indexlane_wp_assert_same( $home, $baseline['site_url'], 'The baseline must be bound to this exact WordPress site URL.' );
	indexlane_wp_assert_same( 7, $baseline['scope']['total_sources'], 'The baseline must preserve the complete selected source corpus.' );
	indexlane_wp_assert_same( 7, $baseline['scope']['content_items'], 'The baseline must preserve the selected content-target corpus.' );
	indexlane_wp_assert_same( true, $baseline['completion']['complete'], 'The baseline must explicitly record complete evidence.' );
	indexlane_wp_assert_same( true, indexlane_wp_invoke( 'save_baseline', array( $baseline ) ), 'WordPress must persist one opt-in baseline for the current administrator.' );
	indexlane_wp_assert_same( $baseline, indexlane_wp_invoke( 'get_saved_baseline' ), 'The saved baseline must round-trip through user-option validation.' );

	$baseline_json = wp_json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $baseline_json ) ) {
		throw new RuntimeException( 'WordPress could not encode baseline JSON.' );
	}
	indexlane_wp_assert_same( $baseline, indexlane_wp_invoke( 'parse_baseline_json', array( $baseline_json ) ), 'Exported JSON must pass strict import validation without changing evidence.' );
	$wrong_site             = $baseline;
	$wrong_site['site_url'] = 'https://different.example';
	$wrong_site_result      = indexlane_wp_invoke( 'validate_baseline', array( $wrong_site, true ) );
	indexlane_wp_assert_same( true, is_wp_error( $wrong_site_result ), 'A baseline owned by another site must be rejected.' );
	indexlane_wp_assert_same( 'baseline_wrong_site', $wrong_site_result->get_error_code(), 'Site rejection must use a stable validation code.' );

	$verification_settings = indexlane_wp_invoke( 'verification_settings_from_baseline', array( $baseline ) );
	if ( is_wp_error( $verification_settings ) ) {
		throw new RuntimeException( $verification_settings->get_error_message() );
	}
	indexlane_wp_assert_same( $baseline['settings']['post_types'], $verification_settings['post_types'], 'Verification must reproduce the saved public post types.' );
	indexlane_wp_assert_same( $baseline['settings']['source_types'], $verification_settings['source_types'], 'Verification must reproduce the saved source providers.' );
	indexlane_wp_assert_same( $baseline['settings']['content_scope'], $verification_settings['content_scope'], 'Verification must reproduce the saved content scope.' );
	$baseline_fingerprint = indexlane_wp_invoke( 'baseline_fingerprint', array( $baseline ) );
	$verification_session = indexlane_wp_invoke(
		'create_scan_session',
		array( $verification_settings, 'verification', $baseline['baseline_id'], $baseline_fingerprint )
	);
	indexlane_wp_assert_same( 'verification', $verification_session['scan_mode'], 'A verification session must be marked independently from a standard scan.' );
	indexlane_wp_assert_same( $baseline['baseline_id'], $verification_session['baseline_id'], 'A verification session must bind to the selected baseline identity.' );
	indexlane_wp_assert_same( 7, $verification_session['total_items'], 'Verification must snapshot the same all-content scope.' );

	$verification_batch_count = 0;
	while ( 'complete' !== $verification_session['status'] && $verification_batch_count < 30 ) {
		$verification_session = indexlane_wp_invoke( 'process_scan_batch', array( $verification_session ) );
		$verification_batch_count++;
	}
	indexlane_wp_assert_same( 'complete', $verification_session['status'], 'The exact-scope verification scan must complete.' );
	$comparison = indexlane_wp_invoke( 'get_session_comparison', array( $verification_session ) );
	if ( is_wp_error( $comparison ) ) {
		throw new RuntimeException( $comparison->get_error_message() );
	}
	indexlane_wp_assert_same(
		array(
			'new'      => 0,
			'changed'  => 0,
			'resolved' => 0,
			'still'    => 3,
		),
		$comparison['summary'],
		'Unchanged fixtures must classify every saved issue destination as still present.'
	);
	$comparison_csv_rows = indexlane_wp_invoke( 'build_comparison_csv_rows', array( $comparison ) );
	indexlane_wp_assert_same( 4, count( $comparison_csv_rows ), 'Comparison CSV must contain its header and all three issue destinations.' );

	$original_user_id = get_current_user_id();
	wp_set_current_user( 0 );
	indexlane_wp_assert_same( null, indexlane_wp_invoke( 'get_scan_session' ), 'Another user must not see the administrator session.' );
	indexlane_wp_assert_same( null, indexlane_wp_invoke( 'get_saved_baseline' ), 'Another user must not see the administrator baseline.' );
	wp_set_current_user( $original_user_id );
	indexlane_wp_assert_same( 7, count( indexlane_wp_invoke( 'get_scan_session' )['results'] ), 'The owning administrator must retain the completed evidence.' );
	indexlane_wp_assert_same( $baseline['baseline_id'], indexlane_wp_invoke( 'get_saved_baseline' )['baseline_id'], 'The owning administrator must retain the saved baseline.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );
	wp_set_current_user( $administrator->ID );
	indexlane_wp_invoke( 'delete_scan_session' );
	delete_user_option( $administrator->ID, 'indexlane_rila_baseline', false );
	foreach ( $fixture_ids as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
	foreach ( $shared_post_ids as $shared_post_id ) {
		wp_delete_post( $shared_post_id, true );
	}
	if ( $classic_menu_id > 0 ) {
		wp_delete_nav_menu( $classic_menu_id );
	}
	if ( false === $widget_block_before ) {
		delete_option( 'widget_block' );
	} else {
		update_option( 'widget_block', $widget_block_before );
	}
	if ( false === $sidebars_before ) {
		delete_option( 'sidebars_widgets' );
	} else {
		update_option( 'sidebars_widgets', $sidebars_before );
	}
	if ( $registered_sidebar ) {
		unregister_sidebar( 'indexlane-integration-area' );
	}
	unregister_post_type( $post_type );
}

fwrite( STDOUT, "WordPress integration tests passed.\n" );
