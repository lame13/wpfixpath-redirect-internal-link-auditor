<?php
/**
 * Plugin Name: IndexLane auditor AJAX test fixture
 * Description: Registers an isolated test post type and preempts its HTTP evidence URLs.
 */

$indexlane_rila_e2e_enabled = defined( 'INDEXLANE_RILA_E2E' )
	? INDEXLANE_RILA_E2E
	: '1' === getenv( 'INDEXLANE_RILA_E2E' );
if ( ! $indexlane_rila_e2e_enabled ) {
	return;
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

add_action(
	'admin_init',
	static function (): void {
		remove_action( 'admin_notices', 'update_nag', 3 );
	}
);
add_filter( 'update_footer', '__return_empty_string', 99 );

add_action(
	'init',
	static function (): void {
		register_post_type(
			'indexlane_e2e',
			array(
				'label'        => 'Help center articles',
				'public'       => true,
				'show_ui'      => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
	}
);

add_action(
	'init',
	static function (): void {
		if ( ! isset( $_GET['indexlane_rila_e2e_login'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['indexlane_rila_e2e_login'] ) ) ) {
			return;
		}

		$administrator = get_user_by( 'login', 'admin' );
		if ( ! $administrator instanceof WP_User ) {
			wp_die( 'The isolated browser fixture requires an administrator.', 'E2E login unavailable', array( 'response' => 500 ) );
		}

		wp_set_current_user( $administrator->ID );
		wp_set_auth_cookie( $administrator->ID, false, is_ssl() );

		$target   = isset( $_GET['indexlane_rila_e2e_target'] ) ? sanitize_key( wp_unslash( $_GET['indexlane_rila_e2e_target'] ) ) : '';
		$fragment = in_array( $target, array( 'session', 'results' ), true ) ? '#indexlane-rila-' . $target : '';
		wp_safe_redirect( admin_url( 'tools.php?page=indexlane-redirect-internal-link-auditor' ) . $fragment );
		exit;
	},
	20
);

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$status   = 0;
		$location = '';

		if ( '/e2e-common' === $path || preg_match( '#^/e2e-(ok|final)-[0-9]+(?:-[0-9]+)?$#', $path ) ) {
			$status = 200;
		} elseif ( preg_match( '#^/e2e-broken-[0-9]+$#', $path ) ) {
			$status = 404;
		} elseif ( preg_match( '#^/e2e-redirect-([0-9]+)$#', $path, $matches ) ) {
			$status   = 301;
			$location = home_url( '/e2e-final-' . $matches[1] );
		} elseif ( '/e2e-external-redirect' === $path ) {
			$status   = 302;
			$location = 'https://external.invalid/landing';
		}

		if ( 0 === $status ) {
			return $preempt;
		}

		return array(
			'headers'  => '' === $location ? array() : array( 'location' => $location ),
			'body'     => '',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
