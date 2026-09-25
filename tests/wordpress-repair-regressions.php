<?php
/** Real WordPress repair regressions; loaded by wordpress-integration.php. */

$repair_options = array();
foreach ( array( 'indexlane_rila_fix_journal', 'indexlane_rila_monitor', 'indexlane_rila_ignored_issues' ) as $option ) {
	$repair_options[ $option ] = get_option( $option, null );
	delete_option( $option );
}
$repair_post_id = 0;
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
} finally {
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
