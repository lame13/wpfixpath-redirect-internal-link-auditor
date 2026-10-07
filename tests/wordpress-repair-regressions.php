<?php
/** Real WordPress repair regressions; loaded by wordpress-integration.php. */

$repair_options = array();
foreach ( array( 'indexlane_rila_fix_journal', 'indexlane_rila_monitor', 'indexlane_rila_ignored_issues' ) as $option ) {
	$repair_options[ $option ] = get_option( $option, null );
	delete_option( $option );
}
$repair_post_id = 0;
$repair_menu_id = 0;
$repair_before = '<!-- wp:navigation-link {"label":"A \\"quote\\"","url":"/repair-old/"} /--><p>C:\\notes\\file</p><a href="/repair-old/">Old</a><script>const data = {"url":"/repair-old/"};</script>';
try {
	$repair_post_id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Repair regression', 'post_content' => $repair_before ) ), true );
	if ( is_wp_error( $repair_post_id ) ) {
		throw new RuntimeException( $repair_post_id->get_error_message() );
	}
	$repair_before = get_post( $repair_post_id )->post_content;
	$repair_row = array(
		'source_key' => 'content:page:' . $repair_post_id,
		'source_type_code' => 'content', 'source_type' => 'Page',
		'source_title' => 'Repair regression', 'source_id' => $repair_post_id,
		'source_content_id' => $repair_post_id, 'source_edit_url' => '',
		'linked_url' => home_url( '/repair-old/' ), 'result_code' => 'error',
	);
	$repair_plan = indexlane_wp_invoke( 'build_fix_plan', array( array( 'results' => array( $repair_row, $repair_row ) ), home_url( '/repair-old/' ), home_url( '/repair-new/?a=1&b=2' ) ) );
	if ( is_wp_error( $repair_plan ) ) {
		throw new RuntimeException( $repair_plan->get_error_message() );
	}
	indexlane_wp_assert_same( 2, $repair_plan['occurrences'], 'Real post repair must count each changed stored attribute only once.' );
	$repair_nonce = wp_create_nonce( indexlane_wp_invoke( 'fix_confirmation_action', array( $repair_plan ) ) );
	$repair_changed_plan = $repair_plan;
	$repair_changed_plan['items'][0]['before'] .= 'Another edit';
	indexlane_wp_assert_same( false, wp_verify_nonce( $repair_nonce, indexlane_wp_invoke( 'fix_confirmation_action', array( $repair_changed_plan ) ) ), 'A real WordPress nonce must reject a changed preview.' );
	$repair_result = indexlane_wp_invoke( 'apply_fix_plan', array( $repair_plan ) );
	if ( is_wp_error( $repair_result ) ) {
		throw new RuntimeException( $repair_result->get_error_message() );
	}
	indexlane_wp_assert_same( $repair_plan['items'][0]['after'], get_post( $repair_post_id )->post_content, 'WordPress must save the exact previewed bytes, including block JSON and backslashes.' );
	indexlane_wp_assert_same( true, false !== strpos( get_post( $repair_post_id )->post_content, '<script>const data = {"url":"/repair-old/"};</script>' ), 'Real repair must preserve unrelated script data.' );
	$repaired_blocks = parse_blocks( get_post( $repair_post_id )->post_content );
	indexlane_wp_assert_same( '/repair-new/?a=1&b=2', $repaired_blocks[0]['attrs']['url'], 'Repaired block JSON must remain parseable by WordPress.' );
	$repair_undo = indexlane_wp_invoke( 'undo_fix_batch', array( $repair_result['batch_id'] ) );
	indexlane_wp_assert_same( 1, $repair_undo['restored'], 'Real WordPress undo must restore the source.' );
	indexlane_wp_assert_same( $repair_before, get_post( $repair_post_id )->post_content, 'Real WordPress undo must restore the exact original bytes.' );

	$repair_fail_journal = static function ( $new_value, $old_value ) { return $old_value; };
	add_filter( 'pre_update_option_indexlane_rila_fix_journal', $repair_fail_journal, 10, 2 );
	try {
		$repair_failed = indexlane_wp_invoke( 'apply_fix_plan', array( $repair_plan ) );
		indexlane_wp_assert_same( 'fix_journal_failed', $repair_failed->get_error_code(), 'An unsuccessful journal write must stop real WordPress content updates.' );
		indexlane_wp_assert_same( $repair_before, get_post( $repair_post_id )->post_content, 'Journal failure must leave the original source untouched.' );
	} finally {
		remove_filter( 'pre_update_option_indexlane_rila_fix_journal', $repair_fail_journal, 10 );
	}

	// Exercise grouped menu and post repairs with the real storage APIs and nonce.
	$repair_menu_id = wp_create_nav_menu( 'Grouped repair regression' );
	$repair_menu_item_id = wp_update_nav_menu_item( $repair_menu_id, 0, array(
		'menu-item-title' => 'Old link', 'menu-item-type' => 'custom',
		'menu-item-url' => home_url( '/repair-old/' ), 'menu-item-status' => 'publish',
	) );
	$batch_before = '<a href="/repair-old/">Old</a><a href="/repair-next/">Next</a><!-- wp:navigation-link {"url":"/repair-old/"} /-->';
	wp_update_post( wp_slash( array( 'ID' => $repair_post_id, 'post_content' => $batch_before ) ) );
	$batch_row = array_merge( $repair_row, array( 'result_code' => 'warning', 'http_status' => '301 -> 200', 'redirect_count' => 1, 'final_url' => home_url( '/repair-next/' ) ) );
	$batch_rows = array(
		$batch_row,
		array_merge( $batch_row, array( 'linked_url' => home_url( '/repair-next/' ), 'final_url' => home_url( '/repair-final/' ) ) ),
		array_merge( $batch_row, array( 'source_type_code' => 'menu', 'source_key' => 'menu:' . $repair_menu_id, 'source_id' => $repair_menu_id, 'source_content_id' => 0 ) ),
		array_merge( $batch_row, array( 'source_type_code' => 'custom_provider', 'linked_url' => home_url( '/uneditable/' ) ) ),
	);
	$batch_scan = array( 'results' => $batch_rows );
	$batch_preview = indexlane_wp_invoke( 'build_fix_all_plan', array( $batch_scan ) );
	indexlane_wp_assert_same( false, is_wp_error( $batch_preview ), 'Real sources must produce a batch plan.' );
	indexlane_wp_assert_same( 2, $batch_preview['sources'], 'A grouped repair must include both the post and custom menu item.' );
	indexlane_wp_assert_same( 4, $batch_preview['occurrences'], 'Overlapping suggestions must count each original attribute once.' );
	$batch_nonce = wp_create_nonce( indexlane_wp_invoke( 'fix_confirmation_action', array( $batch_preview ) ) );
	$batch_plan = indexlane_wp_invoke( 'build_fix_all_plan', array( $batch_scan, array_column( $batch_preview['pairs'], 'from_url' ) ) );
	indexlane_wp_assert_same( true, false !== wp_verify_nonce( $batch_nonce, indexlane_wp_invoke( 'fix_confirmation_action', array( $batch_plan ) ) ), 'Uneditable suggestions must not block real batch confirmation.' );
	$batch_result = indexlane_wp_invoke( 'apply_fix_all_plan', array( $batch_plan ) );
	indexlane_wp_assert_same( false, is_wp_error( $batch_result ), 'Real grouped repairs must apply successfully.' );
	indexlane_wp_assert_same( '<a href="/repair-next/">Old</a><a href="/repair-final/">Next</a><!-- wp:navigation-link {"url":"/repair-next/"} /-->', get_post( $repair_post_id )->post_content, 'WordPress must store each original link replacement without cascading.' );
	indexlane_wp_assert_same( home_url( '/repair-next/' ), get_post_meta( $repair_menu_item_id, '_menu_item_url', true ), 'Grouped repair must update custom menu metadata.' );
	$batch_undo = indexlane_wp_invoke( 'undo_fix_batch', array( $batch_result['batch_id'] ) );
	indexlane_wp_assert_same( 2, $batch_undo['restored'], 'One undo must restore the post and menu item.' );
	indexlane_wp_assert_same( $batch_before, get_post( $repair_post_id )->post_content, 'Grouped undo must restore the complete original post.' );
	indexlane_wp_assert_same( home_url( '/repair-old/' ), get_post_meta( $repair_menu_item_id, '_menu_item_url', true ), 'Grouped undo must restore the original menu URL.' );
} finally {
	if ( is_int( $repair_menu_id ) && $repair_menu_id > 0 ) {
		wp_delete_nav_menu( $repair_menu_id );
	}
	if ( is_int( $repair_post_id ) && $repair_post_id > 0 ) {
		wp_delete_post( $repair_post_id, true );
	}
	foreach ( $repair_options as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
}
