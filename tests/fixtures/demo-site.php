<?php
/**
 * Real HTTP routes for the isolated screenshot demo. Never package this file.
 *
 * Install as an mu-plugin only in a disposable local WordPress instance with
 * INDEXLANE_RILA_DEMO explicitly enabled.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'INDEXLANE_RILA_DEMO' ) || ! INDEXLANE_RILA_DEMO || 'local' !== wp_get_environment_type() ) {
	return;
}

add_action(
	'template_redirect',
	static function (): void {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( '/old-services/' === $request_path ) {
			wp_safe_redirect( home_url( '/services/' ), 301, 'IndexLane demo fixture' );
			exit;
		}

		if ( '/retired-contact/' === $request_path ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			status_header( 404 );
		}
	},
	1
);
