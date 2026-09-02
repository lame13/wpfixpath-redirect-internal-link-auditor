<?php
/**
 * Prepare genuine plugin states for release screenshots in isolated WordPress.
 *
 * Load this file through WordPress with INDEXLANE_SCREENSHOT_STATE set to one
 * of: empty, paused, verification. The legacy complete name remains an alias
 * for verification so older local screenshot commands continue to work.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load this helper through WordPress.\n" );
	exit( 1 );
}

/**
 * Invoke a private plugin method for screenshot preparation.
 *
 * @param string           $method    Method name.
 * @param array<int,mixed> $arguments Arguments.
 * @return mixed
 */
function indexlane_screenshot_invoke( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'IndexLane_Redirect_Internal_Link_Auditor', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}

	return $reflection->invokeArgs( null, $arguments );
}

/**
 * Finish a screenshot scan, explicitly extending its bounded allowance when
 * the deterministic fixture corpus requires more than the initial allowance.
 *
 * @param array<string,mixed> $session Scan session.
 * @return array<string,mixed>
 */
function indexlane_screenshot_complete_session( array $session ): array {
	for ( $batch = 0; $batch < 200 && 'complete' !== $session['status']; $batch++ ) {
		if ( 'limit_reached' === $session['status'] ) {
			$session['request_limit'] += 250;
			$session['request_allowance_extensions'] = isset( $session['request_allowance_extensions'] )
				? (int) $session['request_allowance_extensions'] + 1
				: 1;
			$session['status'] = 'running';
		}
		$session = indexlane_screenshot_invoke( 'process_scan_batch', array( $session ) );
	}

	if ( 'complete' !== $session['status'] ) {
		throw new RuntimeException( 'The screenshot scan did not finish within 200 batches.' );
	}

	return $session;
}

/**
 * Remove deterministic shared-source fixtures from an earlier screenshot state.
 */
function indexlane_screenshot_reset_shared_sources(): void {
	foreach ( wp_get_nav_menus( array( 'hide_empty' => false ) ) as $menu ) {
		if ( $menu instanceof WP_Term && 'IndexLane screenshot menu' === $menu->name ) {
			wp_delete_nav_menu( $menu->term_id );
		}
	}

	$post_fixtures = array(
		'wp_navigation'    => 'indexlane-screenshot-navigation',
		'wp_block'         => 'indexlane-screenshot-pattern',
		'wp_template'      => 'indexlane-screenshot-template',
		'wp_template_part' => 'indexlane-screenshot-footer',
	);
	foreach ( $post_fixtures as $post_type => $slug ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	$widget_number = 606;
	$widgets       = get_option( 'widget_block', array() );
	$widgets       = is_array( $widgets ) ? $widgets : array();
	unset( $widgets[ $widget_number ] );
	update_option( 'widget_block', $widgets );

	$sidebars = wp_get_sidebars_widgets();
	foreach ( $sidebars as $sidebar_id => $widget_ids ) {
		if ( ! is_array( $widget_ids ) ) {
			continue;
		}
		$sidebars[ $sidebar_id ] = array_values( array_diff( $widget_ids, array( 'block-' . $widget_number ) ) );
	}
	wp_set_sidebars_widgets( $sidebars );
}

/**
 * Create one genuine, editable fixture for every shared WordPress source.
 */
function indexlane_screenshot_prepare_shared_sources(): void {
	$targets = get_posts(
		array(
			'post_type'      => 'indexlane_e2e',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		)
	);
	$target = isset( $targets[0] ) ? $targets[0] : null;
	if ( ! $target instanceof WP_Post ) {
		throw new RuntimeException( 'Create the browser fixtures before preparing screenshot states.' );
	}

	$target_url = (string) get_permalink( $target );
	$broken_url = home_url( '/e2e-broken-1' );
	if ( '' === $target_url ) {
		throw new RuntimeException( 'The screenshot coverage target requires a permalink.' );
	}

	$menu_id = wp_create_nav_menu( 'IndexLane screenshot menu' );
	if ( is_wp_error( $menu_id ) ) {
		throw new RuntimeException( $menu_id->get_error_message() );
	}
	$menu_item = wp_update_nav_menu_item(
		(int) $menu_id,
		0,
		array(
			'menu-item-title'  => 'Help center',
			'menu-item-url'    => $target_url,
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		)
	);
	if ( is_wp_error( $menu_item ) ) {
		throw new RuntimeException( $menu_item->get_error_message() );
	}

	if ( post_type_exists( 'wp_navigation' ) ) {
		$navigation_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_name'    => 'indexlane-screenshot-navigation',
				'post_title'   => 'Main navigation',
				'post_content' => wp_slash( '<!-- wp:navigation-link {"label":"Help center","url":"' . esc_url_raw( $target_url ) . '","kind":"custom"} /-->' ),
			),
			true
		);
		if ( is_wp_error( $navigation_id ) ) {
			throw new RuntimeException( $navigation_id->get_error_message() );
		}
	}

	if ( post_type_exists( 'wp_block' ) ) {
		$pattern_id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_name'    => 'indexlane-screenshot-pattern',
				'post_title'   => 'Support callout',
				'post_content' => '<p><a href="' . esc_url( $target_url ) . '">Browse help articles</a></p>',
			),
			true
		);
		if ( is_wp_error( $pattern_id ) ) {
			throw new RuntimeException( $pattern_id->get_error_message() );
		}
	}

	$template_fixtures = array(
		array(
			'post_type' => 'wp_template',
			'slug'      => 'indexlane-screenshot-template',
			'title'     => 'Help center article template',
			'content'   => '<p><a href="' . esc_url( $target_url ) . '">Help center home</a></p>',
		),
		array(
			'post_type' => 'wp_template_part',
			'slug'      => 'indexlane-screenshot-footer',
			'title'     => 'Support footer',
			'content'   => '<p><a href="' . esc_url( $target_url ) . '">Support home</a> · <a href="' . esc_url( $broken_url ) . '">Contact support</a></p>',
		),
	);
	foreach ( $template_fixtures as $fixture ) {
		if ( ! post_type_exists( $fixture['post_type'] ) || ! taxonomy_exists( 'wp_theme' ) ) {
			continue;
		}
		$template_id = wp_insert_post(
			array(
				'post_type'    => $fixture['post_type'],
				'post_status'  => 'publish',
				'post_name'    => $fixture['slug'],
				'post_title'   => $fixture['title'],
				'post_content' => $fixture['content'],
			),
			true
		);
		if ( is_wp_error( $template_id ) ) {
			throw new RuntimeException( $template_id->get_error_message() );
		}
		$theme_terms = wp_set_object_terms( (int) $template_id, get_stylesheet(), 'wp_theme' );
		if ( is_wp_error( $theme_terms ) ) {
			throw new RuntimeException( $theme_terms->get_error_message() );
		}
		if ( 'wp_template_part' === $fixture['post_type'] && taxonomy_exists( 'wp_template_part_area' ) ) {
			$area_terms = wp_set_object_terms( (int) $template_id, 'footer', 'wp_template_part_area' );
			if ( is_wp_error( $area_terms ) ) {
				throw new RuntimeException( $area_terms->get_error_message() );
			}
		}
	}

	register_sidebar(
		array(
			'id'   => 'indexlane-screenshot-area',
			'name' => 'Help center sidebar',
		)
	);
	$widget_number = 606;
	$widgets       = get_option( 'widget_block', array() );
	$widgets       = is_array( $widgets ) ? $widgets : array();
	$widgets[ $widget_number ] = array( 'content' => '<p><a href="' . esc_url( $target_url ) . '">Popular help articles</a></p>' );
	update_option( 'widget_block', $widgets );
	$sidebars = wp_get_sidebars_widgets();
	$sidebars['indexlane-screenshot-area'] = array( 'block-' . $widget_number );
	wp_set_sidebars_widgets( $sidebars );
}

if ( ! class_exists( 'IndexLane_Redirect_Internal_Link_Auditor' ) ) {
	throw new RuntimeException( 'The plugin class is not loaded.' );
}

$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator instanceof WP_User ) {
	throw new RuntimeException( 'The isolated screenshot fixture requires the admin user.' );
}
wp_set_current_user( $administrator->ID );

$state = (string) getenv( 'INDEXLANE_SCREENSHOT_STATE' );
if ( ! in_array( $state, array( 'empty', 'paused', 'verification', 'complete' ), true ) ) {
	throw new RuntimeException( 'INDEXLANE_SCREENSHOT_STATE must be empty, paused, or verification.' );
}
$state = 'complete' === $state ? 'verification' : $state;

indexlane_screenshot_invoke( 'delete_scan_session' );
delete_user_option( $administrator->ID, 'indexlane_rila_baseline', false );
update_option( 'blogname', 'IndexLane 0.6.0 Test WordPress' );
indexlane_screenshot_reset_shared_sources();
if ( 'empty' === $state ) {
	fwrite( STDOUT, "Empty screenshot state prepared.\n" );
	return;
}

indexlane_screenshot_prepare_shared_sources();

$settings = indexlane_screenshot_invoke(
	'get_request_settings',
	array(
		array(
			'source_types_present' => '1',
			'source_types'         => array( 'content', 'menu', 'navigation', 'pattern', 'template', 'template_part', 'widget' ),
			'post_types'           => array( 'indexlane_e2e' ),
			'content_scope'        => 'all',
			'max_posts'            => 40,
			'old_domains'          => 'legacy.example',
			'timeout'              => 2,
			'max_redirects'        => 5,
		)
	)
);
$baseline_session = indexlane_screenshot_complete_session(
	indexlane_screenshot_invoke( 'create_scan_session', array( $settings ) )
);
$baseline = indexlane_screenshot_invoke( 'build_baseline_from_session', array( $baseline_session ) );
if ( is_wp_error( $baseline ) ) {
	throw new RuntimeException( $baseline->get_error_message() );
}
if ( ! indexlane_screenshot_invoke( 'save_baseline', array( $baseline ) ) ) {
	throw new RuntimeException( 'The screenshot baseline could not be saved.' );
}

$verification_settings = indexlane_screenshot_invoke( 'verification_settings_from_baseline', array( $baseline ) );
if ( is_wp_error( $verification_settings ) ) {
	throw new RuntimeException( $verification_settings->get_error_message() );
}
$session = indexlane_screenshot_invoke(
	'create_scan_session',
	array(
		$verification_settings,
		'verification',
		(string) $baseline['baseline_id'],
		indexlane_screenshot_invoke( 'baseline_fingerprint', array( $baseline ) ),
	)
);

if ( 'paused' === $state ) {
	for ( $batch = 0; $batch < 8 && 'running' === $session['status']; $batch++ ) {
		$session = indexlane_screenshot_invoke( 'process_scan_batch', array( $session ) );
	}
	if ( 'running' !== $session['status'] ) {
		throw new RuntimeException( 'The screenshot session stopped before the planned pause.' );
	}
	$session['status'] = 'paused';
	indexlane_screenshot_invoke( 'save_scan_session', array( $session ) );
	fwrite(
		STDOUT,
		sprintf(
			"Paused screenshot state prepared: %d sources, %d requests.\n",
			(int) $session['stats']['sources_processed'],
			(int) $session['stats']['http_requests']
		)
	);
	return;
}

$session = indexlane_screenshot_complete_session( $session );
indexlane_screenshot_invoke( 'save_scan_session', array( $session ) );
fwrite(
	STDOUT,
	sprintf(
		"Completed verification screenshot state prepared: %d sources, %d requests, %d issues.\n",
		(int) $session['stats']['sources_processed'],
		(int) $session['stats']['http_requests'],
		(int) $session['stats']['actionable_issues']
	)
);
