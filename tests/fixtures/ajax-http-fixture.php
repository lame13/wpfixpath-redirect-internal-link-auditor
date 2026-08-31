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
	'admin_bar_menu',
	static function ( WP_Admin_Bar $admin_bar ): void {
		$admin_bar->remove_node( 'updates' );
		$admin_bar->remove_node( 'sqlite-db-integration' );
	},
	PHP_INT_MAX
);

add_action(
	'admin_head',
	static function (): void {
		$focus = isset( $_GET['indexlane_rila_e2e_focus'] )
			? sanitize_key( wp_unslash( $_GET['indexlane_rila_e2e_focus'] ) )
			: '';
		?>
		<style id="indexlane-rila-e2e-admin-cleanup">#adminmenu .menu-counter,#adminmenu .update-plugins{display:none}</style>
		<?php if ( 'comparison' === $focus ) : ?>
			<style id="indexlane-rila-e2e-comparison-focus">
				#indexlane-rila-baseline,
				#indexlane-rila-session,
				#indexlane-rila-results > h2:first-child,
				#indexlane-rila-results > p:first-of-type,
				#indexlane-rila-results > .indexlane-rila-export-actions,
				#indexlane-rila-results > .indexlane-rila-save-baseline,
				#indexlane-rila-results > .indexlane-rila-baseline-current,
				#indexlane-rila-comparison ~ * { display: none !important; }
			</style>
		<?php endif; ?>
		<?php
	}
);

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
		wp_set_auth_cookie( $administrator->ID, true, is_ssl() );

		$target     = isset( $_GET['indexlane_rila_e2e_target'] ) ? sanitize_key( wp_unslash( $_GET['indexlane_rila_e2e_target'] ) ) : '';
		$admin_page = admin_url( 'tools.php?page=indexlane-redirect-internal-link-auditor' );
		if ( 'comparison' === $target ) {
			$admin_page = add_query_arg( 'indexlane_rila_e2e_focus', 'comparison', $admin_page );
			$fragment   = '';
		} else {
			$fragment = in_array( $target, array( 'baseline', 'session', 'results' ), true ) ? '#indexlane-rila-' . $target : '';
		}
		wp_safe_redirect( $admin_page . $fragment );
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
		$resolved_post_id = (int) url_to_postid( $url );
		$resolved_post    = $resolved_post_id > 0 ? get_post( $resolved_post_id ) : null;
		$query_vars       = array();
		$query_string     = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query_string ) {
			parse_str( $query_string, $query_vars );
		}
		if ( ! $resolved_post instanceof WP_Post && isset( $query_vars['indexlane_e2e'] ) && is_scalar( $query_vars['indexlane_e2e'] ) ) {
			$resolved_post = get_page_by_path( sanitize_title( (string) $query_vars['indexlane_e2e'] ), OBJECT, 'indexlane_e2e' );
		}

		if ( $resolved_post instanceof WP_Post && 'indexlane_e2e' === $resolved_post->post_type && 'publish' === $resolved_post->post_status ) {
			$status = 200;
		} elseif ( '/e2e-common' === $path || preg_match( '#^/e2e-(ok|final)-[0-9]+(?:-[0-9]+)?$#', $path ) ) {
			$status = 200;
		} elseif ( preg_match( '#^/e2e-broken-[0-9]+$#', $path ) ) {
			$status = 404;
		} elseif ( preg_match( '#^/e2e-redirect-([0-9]+)$#', $path, $matches ) ) {
			$status   = 301;
			$location = home_url( '/e2e-final-' . $matches[1] );
		} elseif ( preg_match( '#^/e2e-content-redirect-[0-9]+-([0-9]+)$#', $path, $matches ) ) {
			$target_post = get_post( (int) $matches[1] );
			if ( $target_post instanceof WP_Post && 'indexlane_e2e' === $target_post->post_type && 'publish' === $target_post->post_status ) {
				$status   = 301;
				$location = (string) get_permalink( $target_post );
			} else {
				$status = 404;
			}
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
