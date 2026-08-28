<?php
/**
 * Prepare genuine plugin states for release screenshots in isolated WordPress.
 *
 * Load this file through WordPress with INDEXLANE_SCREENSHOT_STATE set to one
 * of: empty, paused, complete.
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

if ( ! class_exists( 'IndexLane_Redirect_Internal_Link_Auditor' ) ) {
	throw new RuntimeException( 'The plugin class is not loaded.' );
}

$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator instanceof WP_User ) {
	throw new RuntimeException( 'The isolated screenshot fixture requires the admin user.' );
}
wp_set_current_user( $administrator->ID );

$state = (string) getenv( 'INDEXLANE_SCREENSHOT_STATE' );
if ( ! in_array( $state, array( 'empty', 'paused', 'complete' ), true ) ) {
	throw new RuntimeException( 'INDEXLANE_SCREENSHOT_STATE must be empty, paused, or complete.' );
}

indexlane_screenshot_invoke( 'delete_scan_session' );
if ( 'empty' === $state ) {
	fwrite( STDOUT, "Empty screenshot state prepared.\n" );
	return;
}

$settings = indexlane_screenshot_invoke(
	'get_request_settings',
	array(
		array(
			'post_types'    => array( 'indexlane_e2e' ),
			'content_scope' => 'all',
			'max_posts'     => 40,
			'old_domains'   => 'legacy.example',
			'timeout'       => 2,
			'max_redirects' => 5,
		)
	)
);
$session  = indexlane_screenshot_invoke( 'create_scan_session', array( $settings ) );

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
			"Paused screenshot state prepared: %d content items, %d requests.\n",
			(int) $session['stats']['content_items_processed'],
			(int) $session['stats']['http_requests']
		)
	);
	return;
}

for ( $batch = 0; $batch < 200 && 'complete' !== $session['status']; $batch++ ) {
	if ( 'limit_reached' === $session['status'] ) {
		$session['request_limit'] += 250;
		$session['status']         = 'running';
	}
	$session = indexlane_screenshot_invoke( 'process_scan_batch', array( $session ) );
}
if ( 'complete' !== $session['status'] ) {
	throw new RuntimeException( 'The screenshot scan did not finish within 200 batches.' );
}

indexlane_screenshot_invoke( 'save_scan_session', array( $session ) );
fwrite(
	STDOUT,
	sprintf(
		"Completed screenshot state prepared: %d content items, %d requests, %d issues.\n",
		(int) $session['stats']['content_items_processed'],
		(int) $session['stats']['http_requests'],
		(int) $session['stats']['actionable_issues']
	)
);
