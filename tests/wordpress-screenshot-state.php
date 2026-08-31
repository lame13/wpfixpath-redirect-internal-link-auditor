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
update_option( 'blogname', 'IndexLane 0.5.0 Test WordPress' );
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
			"Paused screenshot state prepared: %d content items, %d requests.\n",
			(int) $session['stats']['content_items_processed'],
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
		"Completed verification screenshot state prepared: %d content items, %d requests, %d issues.\n",
		(int) $session['stats']['content_items_processed'],
		(int) $session['stats']['http_requests'],
		(int) $session['stats']['actionable_issues']
	)
);
