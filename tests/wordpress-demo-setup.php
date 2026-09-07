<?php
/**
 * Seed the small scan/fix/recheck example used by the public walkthrough.
 *
 * Run with wp eval-file in a disposable local WordPress instance. Enable
 * INDEXLANE_RILA_DEMO and install tests/fixtures/demo-site.php as an mu-plugin
 * first, activate a classic theme with menus (such as Twenty Twenty-One), and
 * enable post-name permalinks. Use the actual browser controls to scan, save,
 * edit, and recheck.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'INDEXLANE_RILA_DEMO' ) || ! INDEXLANE_RILA_DEMO || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'The demo requires WP-CLI and an explicitly enabled disposable local WordPress site.' );
}

$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator instanceof WP_User ) {
	throw new RuntimeException( 'The demo requires the isolated admin account.' );
}
wp_set_current_user( $administrator->ID );

$previous = get_option( 'indexlane_rila_demo_records', array() );
foreach ( isset( $previous['pages'] ) ? $previous['pages'] : array() as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
if ( ! empty( $previous['menu'] ) ) {
	wp_delete_nav_menu( (int) $previous['menu'] );
}

delete_transient( 'indexlane_rila_session_' . $administrator->ID );
delete_user_option( $administrator->ID, 'indexlane_rila_baseline', false );
update_option( 'blogname', 'IndexLane Demo Site' );
update_option( 'blogdescription', 'Fictional content for the IndexLane walkthrough.' );

$page_content = array(
	'about'    => '<p>Find out about <a href="' . esc_url( home_url( '/old-services/' ) ) . '">our services</a> or <a href="' . esc_url( home_url( '/contact/' ) ) . '">contact us</a>.</p>',
	'services' => '<p>Learn more <a href="' . esc_url( home_url( '/about/' ) ) . '">about us</a> and <a href="' . esc_url( home_url( '/contact/' ) ) . '">get in touch</a>.</p>',
	'contact'  => '<p>Ask about <a href="' . esc_url( home_url( '/services/' ) ) . '">our services</a>.</p>',
);
$pages = array();
foreach ( $page_content as $slug => $content ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => ucfirst( $slug ),
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$pages[ $slug ] = (int) $post_id;
}

$menu_id = wp_create_nav_menu( 'Footer links' );
if ( is_wp_error( $menu_id ) ) {
	throw new RuntimeException( $menu_id->get_error_message() );
}
$menu_items = array();
foreach ( array( 'Contact' => '/retired-contact/', 'Services' => '/services/' ) as $title => $path ) {
	$item_id = wp_update_nav_menu_item(
		(int) $menu_id,
		0,
		array(
			'menu-item-title'  => $title,
			'menu-item-url'    => home_url( $path ),
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		)
	);
	if ( is_wp_error( $item_id ) ) {
		throw new RuntimeException( $item_id->get_error_message() );
	}
	$menu_items[ $title ] = (int) $item_id;
}
update_option( 'indexlane_rila_demo_records', array( 'pages' => $pages, 'menu' => (int) $menu_id, 'menu_items' => $menu_items ) );
flush_rewrite_rules( false );

fwrite( STDOUT, "Demo prepared: three published pages and the Footer links menu. Use the browser to scan and edit the Contact menu item.\n" );
