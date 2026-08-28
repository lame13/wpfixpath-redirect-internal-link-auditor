<?php
/**
 * Seed deterministic browser/AJAX fixtures in an isolated WordPress install.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load this setup through WordPress.\n" );
	exit( 1 );
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
	throw new RuntimeException( 'An administrator account is required.' );
}

wp_set_password( 'password', $administrator->ID );
delete_transient( 'indexlane_rila_session_' . $administrator->ID );

$existing = get_posts(
	array(
		'post_type'      => 'indexlane_e2e',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

$home = untrailingslashit( home_url( '/' ) );
for ( $content_index = 1; $content_index <= 40; $content_index++ ) {
	$links = array();
	for ( $link_index = 1; $link_index <= 4; $link_index++ ) {
		$links[] = sprintf(
			'<a href="%1$s/e2e-ok-%2$d-%3$d">Healthy %2$d.%3$d</a>',
			esc_url( $home ),
			$content_index,
			$link_index
		);
	}
	$links[] = sprintf( '<a href="%1$s/e2e-broken-%2$d">Broken %2$d</a>', esc_url( $home ), $content_index );
	$links[] = sprintf( '<a href="%1$s/e2e-redirect-%2$d">Redirect %2$d</a>', esc_url( $home ), $content_index );
	$links[] = sprintf( '<a href="%s/e2e-common">Repeated destination</a>', esc_url( $home ) );

	if ( 1 === $content_index ) {
		$links[] = sprintf( '<a href="%s/e2e-external-redirect">External redirect target</a>', esc_url( $home ) );
		$links[] = '<a href="https://legacy.example/path">Legacy domain</a>';
		$links[] = '<a href="https://unrelated.invalid/path">Unrelated external</a>';
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'indexlane_e2e',
			'post_status'  => 'publish',
			'post_title'   => 'Help center article ' . $content_index,
			'post_content' => '<p>' . implode( ' &middot; ', $links ) . '</p>',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
}

fwrite( STDOUT, "WordPress AJAX fixtures created.\n" );
