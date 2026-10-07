<?php
/** Regression cases loaded by behavioral.php. */

$from = 'https://example.test/old/';
$to = 'https://example.test/new/';
$untouched = array(
	'<script>const link = \'<a href="/old/">text</a>\'; const data = {"url":"/old/"};</script>',
	'<!-- <a href="/old/">comment</a> -->',
	'<textarea><a href="/old/">text</a></textarea>',
	'<a data-href="/old/" href="/keep/">text</a>',
	'<a title=\'href="/old/"\' href="/keep/">text</a>',
	'<p>{"url":"/old/"}</p>',
	'<!-- wp:custom/block {"url":"/old/"} /-->',
	'<!-- wp:navigation-link {"url":"/keep/","metadata":{"url":"/old/"}} /-->',
);
foreach ( $untouched as $content ) {
	$result = indexlane_invoke( 'replace_stored_link_url', array( $content, $from, $to, 'https://example.test/' ) );
	indexlane_assert_same( $content, $result['content'], 'Repair must leave scripts, comments, data attributes, text, and unrelated block settings untouched.' );
	indexlane_assert_same( 0, $result['replacements'], 'Untouched content must not count as a repair.' );
}
$result = indexlane_invoke( 'replace_stored_link_url', array( '<a href=/old/>Link</a>', $from, $to, 'https://example.test/' ) );
indexlane_assert_same( '<a href="/new/">Link</a>', $result['content'], 'Unquoted href values must be repairable.' );
$block = '<!-- wp:navigation-link {"url":"/old/","metadata":{"url":"/old/"}} /-->';
$result = indexlane_invoke( 'replace_stored_link_url', array( $block, $from, $to . '?a=1&b=2', 'https://example.test/' ) );
indexlane_assert_same( '<!-- wp:navigation-link {"url":"/new/?a=1\\u0026b=2","metadata":{"url":"/old/"}} /-->', $result['content'], 'Only the top-level block link URL may change, using block-safe JSON escaping.' );
$result = indexlane_invoke( 'replace_stored_link_url', array( $block, $from, $to . '?x=-->', 'https://example.test/' ) );
indexlane_assert_same( 1, substr_count( $result['content'], '-->' ), 'Replacement URLs cannot terminate a block comment.' );

$GLOBALS['indexlane_test_posts'][911] = (object) array( 'ID' => 911, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<a href="/old/">A</a><a href="/old/">B</a><p>C:\\notes\\file</p>' );
$row = array_merge( $broken_row, array( 'source_key' => 'content:page:911', 'source_id' => 911, 'source_content_id' => 911, 'source_type_code' => 'content', 'linked_url' => $from ) );
$scan = array( 'status' => 'complete', 'results' => array( $row, $row ) );
$plan = indexlane_invoke( 'build_fix_plan', array( $scan, $from, $to ) );
indexlane_assert_same( 2, $plan['occurrences'], 'Repeated scan rows must not multiply the two actual stored replacements.' );
indexlane_assert_same( 1, $plan['sources'], 'Repeated source rows must produce one write.' );
$confirmation = indexlane_invoke( 'fix_confirmation_action', array( $plan ) );
$changed_plan = $plan;
$changed_plan['items'][0]['before'] .= 'manual edit';
indexlane_assert_same( false, $confirmation === indexlane_invoke( 'fix_confirmation_action', array( $changed_plan ) ), 'A changed source must invalidate the preview confirmation.' );
$changed_plan = $plan;
$changed_plan['to_url'] = 'https://example.test/different/';
indexlane_assert_same( false, $confirmation === indexlane_invoke( 'fix_confirmation_action', array( $changed_plan ) ), 'A changed replacement must invalidate the preview confirmation.' );
ob_start();
indexlane_invoke( 'render_fix_preview', array( $plan ) );
$preview_html = ob_get_clean();
indexlane_assert_same( true, false !== strpos( $preview_html, 'name="fix_confirmation"' ), 'The apply form must carry an exact-plan confirmation.' );
indexlane_assert_same( true, false !== strpos( $preview_html, esc_html( $plan['items'][0]['after'] ) ), 'The full replacement content must be available in the preview.' );
$GLOBALS['indexlane_test_fail_options']['indexlane_rila_fix_journal'] = true;
$failed = indexlane_invoke( 'apply_fix_plan', array( $plan ) );
indexlane_assert_same( 'fix_journal_failed', $failed->get_error_code(), 'A failed undo-journal write must stop the repair.' );
indexlane_assert_same( $plan['items'][0]['before'], $GLOBALS['indexlane_test_posts'][911]->post_content, 'Content must remain unchanged when undo cannot be recorded.' );
unset( $GLOBALS['indexlane_test_fail_options']['indexlane_rila_fix_journal'] );
$applied = indexlane_invoke( 'apply_fix_plan', array( $plan ) );
indexlane_assert_same( $plan['items'][0]['after'], $GLOBALS['indexlane_test_posts'][911]->post_content, 'Applying through the WordPress API must preserve literal backslashes.' );
indexlane_invoke( 'undo_fix_batch', array( $applied['batch_id'] ) );
indexlane_assert_same( $plan['items'][0]['before'], $GLOBALS['indexlane_test_posts'][911]->post_content, 'Undo must also preserve literal backslashes.' );
$GLOBALS['indexlane_test_options']['indexlane_rila_fix_journal_lock'] = time();
$busy = indexlane_invoke( 'apply_fix_plan', array( $plan ) );
indexlane_assert_same( 'fix_busy', $busy->get_error_code(), 'A concurrent repair must not replace another repair journal.' );
unset( $GLOBALS['indexlane_test_options']['indexlane_rila_fix_journal_lock'] );

$unsupported = $scan;
$unsupported['results'][0]['source_type_code'] = 'custom_provider';
$unsupported['results'] = array( $unsupported['results'][0] );
$result = indexlane_invoke( 'build_fix_plan', array( $unsupported, $from, $to ) );
indexlane_assert_same( 'fix_nothing_editable', $result->get_error_code(), 'Custom provider IDs must not be interpreted as WordPress post IDs.' );
$GLOBALS['indexlane_test_post_meta'][102]['_menu_item_url'] = 'https://example.test/retired-contact/';
$result = indexlane_invoke( 'build_fix_plan', array( array( 'results' => array( $object_menu_row ) ), 'https://example.test/retired-contact/', $to ) );
indexlane_assert_same( 'fix_nothing_editable', $result->get_error_code(), 'Stale URL metadata must not make object-backed menu items editable.' );

// The encoded journal is larger than raw strings containing JSON escapes.
$GLOBALS['indexlane_test_posts'][911]->post_content = str_repeat( '\\', 2100000 ) . '<a href="/old/">A</a>';
$result = indexlane_invoke( 'build_fix_plan', array( $scan, $from, $to ) );
indexlane_assert_same( 'fix_too_large', $result->get_error_code(), 'The repair budget must include JSON escaping and both stored values.' );
$GLOBALS['indexlane_test_posts'][911]->post_content = $plan['items'][0]['before'];

$GLOBALS['indexlane_test_posts'][912] = clone $GLOBALS['indexlane_test_posts'][911];
$GLOBALS['indexlane_test_posts'][912]->ID = 912;
$row2 = array_merge( $row, array( 'source_key' => 'content:page:912', 'source_id' => 912, 'source_content_id' => 912 ) );
$two_plan = indexlane_invoke( 'build_fix_plan', array( array( 'results' => array( $row, $row2 ) ), $from, $to ) );
$two_applied = indexlane_invoke( 'apply_fix_plan', array( $two_plan ) );
$GLOBALS['indexlane_test_posts'][912]->post_content = 'Manual edit';
$partial = indexlane_invoke( 'undo_fix_batch', array( $two_applied['batch_id'] ) );
indexlane_assert_same( 1, $partial['restored'], 'Undo should restore unchanged sources and skip later edits.' );
$GLOBALS['indexlane_test_posts'][912]->post_content = $two_plan['items'][1]['after'];
$retried = indexlane_invoke( 'undo_fix_batch', array( $two_applied['batch_id'] ) );
indexlane_assert_same( 1, $retried['restored'], 'Partial undo must remain retryable without restoring a source twice.' );

$GLOBALS['indexlane_test_options']['indexlane_rila_ignored_issues'] = array( 'items' => array() );
$config = indexlane_invoke( 'get_monitor_config' );
$config['issues'] = array( indexlane_invoke( 'issue_key_for_row', array( $broken_row ) ) );
$config['has_baseline'] = true;
$sent_before = count( $GLOBALS['indexlane_test_mail'] );
indexlane_invoke( 'evaluate_monitor_results', array( $config, array( 'status' => 'limit_reached', 'results' => array(), 'stats' => array( 'sources_processed' => 1 ) ) ) );
$partial_config = indexlane_invoke( 'get_monitor_config' );
indexlane_assert_same( 'partial', $partial_config['last_run']['status'], 'Request-limited monitoring must stay visibly partial.' );
indexlane_assert_same( $config['issues'], $partial_config['issues'], 'A partial scan must not replace the previous complete comparison set.' );
indexlane_assert_same( 0, $partial_config['last_run']['resolved'], 'Unseen URLs in a partial scan cannot be called resolved.' );
indexlane_assert_same( $sent_before, count( $GLOBALS['indexlane_test_mail'] ), 'A partial scan must not email false resolutions.' );
indexlane_assert_same( 'recommended', indexlane_invoke( 'site_health_monitor_test' )['status'], 'Site Health cannot label a partial monitor result good.' );

$old_row = array_merge( $broken_row, array( 'linked_url' => 'https://old.example/retired/', 'result_code' => 'needs_review', 'result' => 'Needs review', 'http_status' => '', 'redirect_count' => 0 ) );
$blocked_row = array_merge( $broken_row, array( 'linked_url' => 'https://example.test/blocked/', 'result_code' => 'blocked', 'result' => 'Blocked', 'http_status' => '403', 'redirect_count' => 0 ) );
$rows = indexlane_invoke( 'cli_issue_rows', array( array( 'results' => array( $old_row, $blocked_row ) ), 10 ) );
indexlane_assert_same( 2, count( $rows ), 'CLI reports must include migration-only and blocked findings rather than claiming no problems.' );

$fragment_row = indexlane_invoke( 'build_result_row', array(
	array( 'title' => 'Source', 'type' => 'Page', 'url' => 'https://example.test/page/', 'edit_url' => '' ),
	array( 'href' => 'https://old.example/old/#details', 'anchor' => 'Details' ),
	'https://old.example/old/', '', 0, '', 'Old-site URL', 'Needs review', 'needs_review',
) );
indexlane_assert_same( 'https://old.example/old/#details', $fragment_row['linked_url'], 'Migration findings must preserve fragments so they can be repaired exactly.' );
$fragment_redirect = array_merge( $redirect_row, array( 'linked_url' => 'https://example.test/old-service/#details' ) );
indexlane_assert_same( 'https://example.test/services/#details', indexlane_invoke( 'suggested_replacement_for_row', array( $fragment_redirect ) ), 'A redirect suggestion must not silently discard the linked section.' );

// 1.1.0: one reviewed batch plan that repairs every suggested URL.
$post_backup = $_POST;
$_POST       = array();
indexlane_assert_same( null, indexlane_invoke( 'requested_fix_selection' ), 'A first review without a selection field must mean every suggestion.' );
$_POST = array( 'include_present' => '1' );
indexlane_assert_same( array(), indexlane_invoke( 'requested_fix_selection' ), 'Clearing every suggestion must post an explicit empty selection.' );
$_POST = array(
	'include_present' => '1',
	'include'         => array( 'https://example.test/old-a/', 'https://example.test/old-a/', 'not a url' ),
);
indexlane_assert_same( array( 'https://example.test/old-a/' ), indexlane_invoke( 'requested_fix_selection' ), 'A review selection must keep unique, valid stored URLs.' );
$_POST = $post_backup;

$GLOBALS['indexlane_test_options']['indexlane_rila_fix_journal'] = array( 'batches' => array() );
$GLOBALS['indexlane_test_posts'][921] = (object) array(
	'ID'           => 921,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_content' => '<a href="/old-a/">A</a><a href="/old-b/">B</a>',
);
$GLOBALS['indexlane_test_posts'][922] = (object) array(
	'ID'           => 922,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_content' => '<p><a href="/old-a/">A</a></p>',
);

$batch_row_a = array(
	'source_id'        => 921,
	'source_key'       => 'content:page:921',
	'source_content_id' => 921,
	'source_title'     => 'Page A',
	'source_type'      => 'Page',
	'source_type_code' => 'content',
	'source_context'   => 'contextual',
	'source_url'       => 'https://example.test/page-a/',
	'source_edit_url'  => '',
	'linked_url'       => 'https://example.test/old-a/',
	'http_status'      => '301 -> 200',
	'redirect_count'   => 1,
	'final_url'        => 'https://example.test/new-a/',
	'result'           => 'Warning',
	'result_code'      => 'warning',
	'intent_code'      => '',
);
$batch_row_b = array_merge(
	$batch_row_a,
	array(
		'linked_url' => 'https://example.test/old-b/',
		'final_url'  => 'https://example.test/new-b/',
	)
);
$batch_row_a2 = array_merge( $batch_row_a, array( 'source_id' => 922, 'source_key' => 'content:page:922', 'source_content_id' => 922, 'source_title' => 'Page B' ) );

$batch_scan    = array( 'status' => 'complete', 'results' => array( $batch_row_a, $batch_row_b, $batch_row_a2 ) );
$batch_all     = indexlane_invoke( 'build_fix_all_plan', array( $batch_scan ) );
indexlane_assert_same( false, is_wp_error( $batch_all ), 'Every suggested URL must produce one batch plan.' );
indexlane_assert_same( 2, count( $batch_all['pairs'] ), 'The batch must list one pair per suggested URL.' );
indexlane_assert_same( 2, $batch_all['sources'], 'A source with two suggested URLs must be folded into one write.' );
indexlane_assert_same( 3, $batch_all['occurrences'], 'The batch must count every exact replacement once.' );
indexlane_assert_same(
	'<a href="/new-a/">A</a><a href="/new-b/">B</a>',
	$batch_all['items'][0]['after'],
	'A source must apply every suggested replacement in one merged value.'
);

ob_start();
indexlane_invoke( 'render_fix_all_preview', array( $batch_all ) );
$batch_preview_html = (string) ob_get_clean();
indexlane_assert_same( 2, substr_count( $batch_preview_html, 'name="include[]"' ), 'The batch preview must offer one include checkbox per suggested URL.' );
indexlane_assert_same( true, false !== strpos( $batch_preview_html, 'value="fix_apply_all"' ), 'The batch preview must apply through the batch action.' );
indexlane_assert_same( true, false !== strpos( $batch_preview_html, 'name="fix_confirmation"' ), 'The batch preview must carry an exact-plan confirmation.' );

$batch_confirmation = indexlane_invoke( 'fix_confirmation_action', array( $batch_all ) );
$batch_changed      = $batch_all;
$batch_changed['items'][0]['before'] .= 'manual edit';
indexlane_assert_same( false, $batch_confirmation === indexlane_invoke( 'fix_confirmation_action', array( $batch_changed ) ), 'A source changed after the batch preview must invalidate its confirmation.' );
$batch_changed = $batch_all;
$batch_changed['pairs'][0]['to_url'] = 'https://example.test/other/';
indexlane_assert_same( false, $batch_confirmation === indexlane_invoke( 'fix_confirmation_action', array( $batch_changed ) ), 'A changed suggestion must invalidate the batch confirmation.' );

$batch_subset = indexlane_invoke( 'build_fix_all_plan', array( $batch_scan, array( 'https://example.test/old-b/' ) ) );
indexlane_assert_same( 1, count( $batch_subset['pairs'] ), 'Selecting one suggested URL must build a single-URL batch.' );
indexlane_assert_same( 1, $batch_subset['occurrences'], 'A single-URL batch must count only the selected suggestion.' );

$empty_selection = indexlane_invoke( 'build_fix_all_plan', array( $batch_scan, array() ) );
indexlane_assert_same( 'fix_all_none_selected', $empty_selection->get_error_code(), 'Clearing every suggestion must fail with a clear message.' );

$batch_applied = indexlane_invoke( 'apply_fix_all_plan', array( $batch_all ) );
indexlane_assert_same( false, is_wp_error( $batch_applied ), 'A confirmed batch must apply in one pass.' );
indexlane_assert_same( 2, $batch_applied['sources'], 'The batch must report the number of changed sources.' );
indexlane_assert_same( 3, $batch_applied['occurrences'], 'The batch must report every exact replacement.' );
indexlane_assert_same(
	'<a href="/new-a/">A</a><a href="/new-b/">B</a>',
	$GLOBALS['indexlane_test_posts'][921]->post_content,
	'Applying the batch must update a source with multiple suggestions.'
);
indexlane_assert_same( '<p><a href="/new-a/">A</a></p>', $GLOBALS['indexlane_test_posts'][922]->post_content, 'Applying the batch must update every affected source.' );

$batch_journal = indexlane_invoke( 'get_fix_journal' );
indexlane_assert_same( 2, count( $batch_journal['batches'][0]['pairs'] ), 'The undo history must retain every repaired URL in one batch.' );
indexlane_assert_same( 'suggested', $batch_journal['batches'][0]['kind'], 'A suggested-fix batch must be recorded as one undoable entry.' );

$cli_batch_repairs = indexlane_invoke( 'cli_repairs' );
indexlane_assert_same( true, false !== strpos( $cli_batch_repairs[0]['from'], 'suggested fix' ), 'The command-line repair list must label a suggested-fix batch.' );

$batch_undo = indexlane_invoke( 'undo_fix_batch', array( $batch_applied['batch_id'] ) );
indexlane_assert_same( 2, $batch_undo['restored'], 'One undo must restore every source in the batch.' );
indexlane_assert_same( '<a href="/old-a/">A</a><a href="/old-b/">B</a>', $GLOBALS['indexlane_test_posts'][921]->post_content, 'Undo must restore a source with multiple suggestions.' );
indexlane_assert_same( '<p><a href="/old-a/">A</a></p>', $GLOBALS['indexlane_test_posts'][922]->post_content, 'Undo must restore every source in the batch.' );

// A grouped repair must handle raw menu URLs as well as HTML sources.
$GLOBALS['indexlane_test_menu_items'][93] = array( (object) array( 'ID' => 923, 'type' => 'custom', 'title' => 'Old A' ) );
$GLOBALS['indexlane_test_post_meta'][923]['_menu_item_url'] = '/old-a/';
$batch_menu_row = array_merge( $batch_row_a, array( 'source_id' => 93, 'source_key' => 'menu:93', 'source_content_id' => 0, 'source_type_code' => 'menu' ) );
$menu_batch = indexlane_invoke( 'build_fix_all_plan', array( array( 'results' => array( $batch_menu_row ) ) ) );
indexlane_assert_same( false, is_wp_error( $menu_batch ), 'A menu-only suggestion must produce an editable batch.' );
indexlane_assert_same( '/new-a/', $menu_batch['items'][0]['after'], 'Batch menu repair must preserve site-relative storage.' );
$menu_applied = indexlane_invoke( 'apply_fix_all_plan', array( $menu_batch ) );
indexlane_assert_same( '/new-a/', get_post_meta( 923, '_menu_item_url', true ), 'A batch must apply custom menu URL changes.' );
indexlane_invoke( 'undo_fix_batch', array( $menu_applied['batch_id'] ) );
indexlane_assert_same( '/old-a/', get_post_meta( 923, '_menu_item_url', true ), 'Batch undo must restore custom menu URLs.' );

// Each pair is matched against the original value, including block attributes.
$cascade_before = '<a href="/old-a/">A</a><a href="/old-b/">B</a><!-- wp:navigation-link {"url":"/old-a/"} /-->';
$GLOBALS['indexlane_test_posts'][921]->post_content = $cascade_before;
$cascade_a = array_merge( $batch_row_a, array( 'final_url' => $batch_row_b['linked_url'] ) );
$cascade_scan = array( 'results' => array( $cascade_a, $batch_row_b ) );
$cascade = indexlane_invoke( 'build_fix_all_plan', array( $cascade_scan ) );
indexlane_assert_same( '<a href="/old-b/">A</a><a href="/new-b/">B</a><!-- wp:navigation-link {"url":"/old-b/"} /-->', $cascade['items'][0]['after'], 'A later suggestion must never rewrite an earlier replacement.' );
indexlane_assert_same( 3, $cascade['occurrences'], 'Each original stored attribute must count only once in a batch.' );
$swap_scan = array( 'results' => array( $cascade_a, array_merge( $batch_row_b, array( 'final_url' => $batch_row_a['linked_url'] ) ) ) );
$swap = indexlane_invoke( 'build_fix_all_plan', array( $swap_scan ) );
indexlane_assert_same( '<a href="/old-b/">A</a><a href="/old-a/">B</a><!-- wp:navigation-link {"url":"/old-b/"} /-->', $swap['items'][0]['after'], 'Suggestions that swap two URLs must preserve both reviewed replacements.' );
$GLOBALS['indexlane_test_posts'][921]->post_content = '<a href="/old-a/">A</a><a href="/old-b/">B</a>';

// Skipped suggestions are absent from the posted checkboxes, but do not change the writes.
$uneditable_row = array_merge( $batch_row_a, array( 'linked_url' => 'https://example.test/uneditable/', 'source_type_code' => 'custom_provider' ) );
$mixed_scan = array( 'results' => array( $batch_row_a, $uneditable_row ) );
$mixed_preview = indexlane_invoke( 'build_fix_all_plan', array( $mixed_scan ) );
$mixed_apply = indexlane_invoke( 'build_fix_all_plan', array( $mixed_scan, array_column( $mixed_preview['pairs'], 'from_url' ) ) );
indexlane_assert_same( 1, count( $mixed_preview['skipped'] ), 'The review must still explain uneditable suggestions.' );
indexlane_assert_same( indexlane_invoke( 'fix_confirmation_action', array( $mixed_preview ) ), indexlane_invoke( 'fix_confirmation_action', array( $mixed_apply ) ), 'Skipped suggestions must not invalidate confirmation of identical reviewed writes.' );

unset( $GLOBALS['indexlane_test_posts'][921], $GLOBALS['indexlane_test_posts'][922], $GLOBALS['indexlane_test_menu_items'][93], $GLOBALS['indexlane_test_post_meta'][923] );
