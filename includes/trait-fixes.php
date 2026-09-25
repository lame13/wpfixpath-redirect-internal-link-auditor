<?php
/**
 * Reversible in-place link repair for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Fixes {
	/**
	 * Repair plan awaiting confirmation during this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $pending_fix_plan = null;

	/**
	 * Build the admin-page URL that opens the repair panel for one URL.
	 *
	 * @param string $destination Stored URL.
	 */
	private static function fix_panel_url( string $destination ): string {
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'fix_url' => $destination,
			),
			admin_url( 'tools.php' )
		) . '#indexlane-rila-fix';
	}

	/**
	 * Result codes that may be repaired in place.
	 *
	 * @return array<int,string>
	 */
	private static function repairable_result_codes(): array {
		return array( 'error', 'blocked', 'warning', 'needs_review' );
	}

	/**
	 * Stable result code for one stored row.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function row_result_code( array $row ): string {
		if ( isset( $row['result_code'] ) && is_string( $row['result_code'] ) && '' !== $row['result_code'] ) {
			return (string) $row['result_code'];
		}

		return self::result_code_from_label( isset( $row['result'] ) ? (string) $row['result'] : '' );
	}

	/**
	 * Fragment-aware identity for one linked URL.
	 *
	 * @param string $url Linked URL.
	 */
	private static function fix_destination_key( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$fragment = self::link_fragment_from_href( $url );

		return self::normalize_url_for_compare( $url ) . '#' . $fragment;
	}

	/**
	 * Build the repairable destinations found by a completed scan.
	 *
	 * Candidate rows are grouped by the exact linked URL, including its
	 * fragment, because a link to "#pricing" must not be rewritten when the
	 * stored URL never contained that fragment.
	 *
	 * @param array<int,array<string,mixed>> $results Completed-scan rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_fix_candidates( array $results ): array {
		$candidates = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$linked_url = isset( $row['linked_url'] ) ? trim( (string) $row['linked_url'] ) : '';
			if ( '' === $linked_url || ! self::is_valid_http_url( $linked_url ) ) {
				continue;
			}

			$result_code = self::row_result_code( $row );
			if ( ! in_array( $result_code, self::repairable_result_codes(), true ) ) {
				continue;
			}

			$key = self::fix_destination_key( $linked_url );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = array(
					'from_url'         => $linked_url,
					'destination_url'  => self::normalize_url_for_compare( $linked_url ),
					'occurrences'      => 0,
					'result_code'      => $result_code,
					'result_rank'      => self::result_impact_rank( isset( $row['result'] ) ? (string) $row['result'] : '' ),
					'result'           => isset( $row['result'] ) ? (string) $row['result'] : '',
					'source_keys'      => array(),
					'sources'          => array(),
					'suggestion'       => '',
					'suggestion_label' => '',
				);
			}

			$candidates[ $key ]['occurrences']++;
			$candidates[ $key ]['source_keys'][ isset( $row['source_key'] ) ? (string) $row['source_key'] : '' ] = true;

			$source_key = isset( $row['source_key'] ) ? (string) $row['source_key'] : '';
			if ( '' !== $source_key && ! isset( $candidates[ $key ]['sources'][ $source_key ] ) ) {
				$candidates[ $key ]['sources'][ $source_key ] = array(
					'key'      => $source_key,
					'title'    => isset( $row['source_title'] ) ? (string) $row['source_title'] : '',
					'type'     => isset( $row['source_type'] ) ? (string) $row['source_type'] : '',
					'context'  => isset( $row['source_context'] ) && 'shared' === $row['source_context'] ? 'shared' : 'contextual',
					'edit_url' => isset( $row['source_edit_url'] ) ? (string) $row['source_edit_url'] : '',
				);
			}

			$suggestion = self::suggested_replacement_for_row( $row );
			if ( '' !== $suggestion && '' === $candidates[ $key ]['suggestion'] ) {
				$candidates[ $key ]['suggestion']       = $suggestion;
				$candidates[ $key ]['suggestion_label'] = self::replacement_suggestion_label( $row );
			}
		}

		foreach ( $candidates as $key => $candidate ) {
			$source_keys                            = array_filter( array_keys( (array) $candidate['source_keys'] ), 'strlen' );
			$candidates[ $key ]['affected_sources'] = count( $source_keys );
			$candidates[ $key ]['sources']          = array_values( (array) $candidate['sources'] );
			unset( $candidates[ $key ]['source_keys'] );
		}

		$sorted = array_values( $candidates );
		usort(
			$sorted,
			static function ( array $left, array $right ): int {
				if ( $left['result_rank'] !== $right['result_rank'] ) {
					return $right['result_rank'] <=> $left['result_rank'];
				}
				if ( $left['affected_sources'] !== $right['affected_sources'] ) {
					return $right['affected_sources'] <=> $left['affected_sources'];
				}
				if ( $left['occurrences'] !== $right['occurrences'] ) {
					return $right['occurrences'] <=> $left['occurrences'];
				}

				return strcmp( $left['from_url'], $right['from_url'] );
			}
		);

		return $sorted;
	}

	/**
	 * Propose a replacement URL from the evidence already collected.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function suggested_replacement_for_row( array $row ): string {
		$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] ) ? (int) $row['redirect_count'] : 0;
		$final_url      = isset( $row['final_url'] ) ? trim( (string) $row['final_url'] ) : '';

		if ( $redirect_count > 0 && '' !== $final_url && self::is_same_site_url( $final_url ) ) {
			$final_status = self::final_status_from_evidence( isset( $row['http_status'] ) ? (string) $row['http_status'] : '' );
			if ( $final_status >= 200 && $final_status < 300 && self::fix_destination_key( $final_url ) !== self::fix_destination_key( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' ) ) {
				$fragment = self::link_fragment_from_href( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' );
				return false === strpos( $final_url, '#' ) && '' !== $fragment ? $final_url . '#' . $fragment : $final_url;
			}
		}

		foreach ( array( 'final_target_id', 'direct_target_id' ) as $field ) {
			$target_id = isset( $row[ $field ] ) ? (int) $row[ $field ] : 0;
			if ( $target_id > 0 && function_exists( 'get_permalink' ) ) {
				$permalink = get_permalink( $target_id );
				if ( is_string( $permalink ) && '' !== $permalink && false === strpos( $permalink, '?' ) ) {
					return $permalink;
				}
			}
		}

		return '';
	}

	/**
	 * Human label describing where a suggested replacement came from.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function replacement_suggestion_label( array $row ): string {
		$redirect_count = isset( $row['redirect_count'] ) && is_numeric( $row['redirect_count'] ) ? (int) $row['redirect_count'] : 0;
		if ( $redirect_count > 0 && '' !== trim( isset( $row['final_url'] ) ? (string) $row['final_url'] : '' ) ) {
			return __( 'Suggested from the final URL this link redirects to.', 'indexlane-redirect-internal-link-auditor' );
		}

		return __( 'Suggested from the published content this link resolves to.', 'indexlane-redirect-internal-link-auditor' );
	}

	/**
	 * Build an exact, unapplied repair plan for one linked URL.
	 *
	 * @param array<string,mixed> $scan     Completed scan session.
	 * @param string              $from_url Stored URL to replace.
	 * @param string              $to_url   Replacement URL.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build_fix_plan( array $scan, string $from_url, string $to_url ) {
		$from_url = trim( $from_url );
		$to_url   = trim( $to_url );

		if ( '' === $from_url || '' === $to_url ) {
			return new WP_Error( 'fix_missing_url', __( 'Enter both the link to replace and the replacement URL.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( ! self::is_valid_http_url( $from_url ) ) {
			return new WP_Error( 'fix_invalid_from', __( 'The link to replace must be a complete http or https URL.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( ! self::is_valid_http_url( $to_url ) ) {
			return new WP_Error( 'fix_invalid_to', __( 'The replacement must be a complete http or https URL.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( self::fix_destination_key( $from_url ) === self::fix_destination_key( $to_url ) ) {
			return new WP_Error( 'fix_same_url', __( 'The replacement URL is the same as the link being replaced.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$target_key = self::fix_destination_key( $from_url );
		$results    = isset( $scan['results'] ) && is_array( $scan['results'] ) ? $scan['results'] : array();
		$rows       = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( self::fix_destination_key( isset( $row['linked_url'] ) ? (string) $row['linked_url'] : '' ) !== $target_key ) {
				continue;
			}
			if ( ! in_array( self::row_result_code( $row ), self::repairable_result_codes(), true ) ) {
				continue;
			}
			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'fix_no_matches', __( 'This URL is no longer part of the current scan results. Run the scan again before fixing it.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$items     = array();
		$seen      = array();
		$skipped   = array();
		$occurrences = 0;

		foreach ( $rows as $row ) {
			$candidates = self::resolve_fix_source_items( $row, $from_url, $to_url );
			foreach ( $candidates as $item ) {
				if ( '' !== $item['skip_reason'] ) {
					$skipped[ $item['storage'] . ':' . $item['target_id'] . ':' . $item['startup'] ] = array(
						'source_title' => $item['source_title'],
						'source_type'  => $item['source_type'],
						'edit_url'     => $item['edit_url'],
						'reason'       => $item['skip_reason'],
					);
					continue;
				}

				$dedupe = $item['storage'] . ':' . $item['target_id'] . ':' . (string) $item['target_sub'];
				if ( isset( $seen[ $dedupe ] ) ) {
					continue;
				}

				$seen[ $dedupe ] = count( $items );
				$items[]         = $item;
				$occurrences   += (int) $item['occurrences'];
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'fix_nothing_editable', __( 'No selected source can be edited for this URL. The listed sources are maintained outside this plugin; use their editing links instead.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		if ( count( $items ) > self::FIX_MAX_ITEMS_PER_BATCH ) {
			return new WP_Error( 'fix_too_many_items', __( 'This change would edit too many sources at once. Narrow the scan, or repair this URL in smaller groups.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$change_count = 0;
		$journal_bytes = 0;
		foreach ( $items as $index => $item ) {
			$items[ $index ]['before'] = $item['before'];
			$items[ $index ]['after']  = $item['after'];
			$change_count             += (int) $item['occurrences'];
			$encoded                  = wp_json_encode( $item );
			$journal_bytes            += is_string( $encoded ) ? strlen( $encoded ) : self::FIX_JOURNAL_MAX_BYTES;
		}

		if ( $journal_bytes + strlen( $from_url ) + strlen( $to_url ) + 1024 > self::FIX_JOURNAL_MAX_BYTES ) {
			return new WP_Error( 'fix_too_large', __( 'This repair is too large to record a reversible change. Fix fewer sources at once, or use the source editing links.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		return array(
			'from_url'      => $from_url,
			'to_url'        => $to_url,
			'items'         => $items,
			'skipped'       => array_values( $skipped ),
			'sources'       => count( $items ),
			'occurrences'   => $occurrences,
			'changes'       => $change_count,
			'journal_bytes' => $journal_bytes,
		);
	}

	/**
	 * Resolve every editable storage location behind one scan row.
	 *
	 * @param array<string,mixed> $row      Completed-scan row.
	 * @param string              $from_url Stored URL to replace.
	 * @param string              $to_url   Replacement URL.
	 * @return array<int,array<string,mixed>>
	 */
	private static function resolve_fix_source_items( array $row, string $from_url, string $to_url ): array {
		$type_code     = isset( $row['source_type_code'] ) ? (string) $row['source_type_code'] : 'content';
		$source_key    = isset( $row['source_key'] ) ? (string) $row['source_key'] : '';
		$source_id     = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
		$content_id    = isset( $row['source_content_id'] ) ? (int) $row['source_content_id'] : 0;
		$source_title  = isset( $row['source_title'] ) ? (string) $row['source_title'] : '';
		$source_type   = isset( $row['source_type'] ) ? (string) $row['source_type'] : '';
		$edit_url      = isset( $row['source_edit_url'] ) ? (string) $row['source_edit_url'] : '';

		$base = array(
			'source_key'    => $source_key,
			'source_title'  => $source_title,
			'source_type'   => $source_type,
			'edit_url'      => $edit_url,
			'storage'       => '',
			'target_id'     => 0,
			'target_sub'    => '',
			'before'        => '',
			'after'         => '',
			'occurrences'   => 1,
			'startup'       => '',
			'skip_reason'   => '',
			'base_url'      => home_url( '/' ),
		);

		if ( ! in_array( $type_code, array( 'content', 'menu', 'widget', 'navigation', 'pattern', 'template', 'template_part' ), true ) ) {
			$base['skip_reason'] = __( 'This source uses storage that cannot be edited by the plugin.', 'indexlane-redirect-internal-link-auditor' );
			return array( $base );
		}

		if ( 'menu' === $type_code ) {
			return self::resolve_menu_fix_items( $base, $source_id, $from_url, $to_url );
		}

		if ( 'widget' === $type_code ) {
			$number    = $source_id;
			$instances = get_option( 'widget_block', array() );
			if ( $number <= 0 || ! is_array( $instances ) || ! isset( $instances[ $number ]['content'] ) || ! is_string( $instances[ $number ]['content'] ) ) {
				$base['skip_reason'] = __( 'This block widget is no longer stored on the site.', 'indexlane-redirect-internal-link-auditor' );
				$base['storage']     = 'widget_block';
				$base['target_id']   = $number;
				return array( $base );
			}

			$replaced = self::replace_stored_link_url( (string) $instances[ $number ]['content'], $from_url, $to_url, home_url( '/' ) );
			if ( $replaced['replacements'] < 1 ) {
				return array();
			}

			$base['storage']      = 'widget_block';
			$base['target_id']    = $number;
			$base['target_sub']   = 'block-' . $number;
			$base['before']       = (string) $instances[ $number ]['content'];
			$base['after']        = $replaced['content'];
			$base['occurrences']  = $replaced['replacements'];
			$base['startup']      = 'widget';
			return array( $base );
		}

		$post_id = 0;
		if ( in_array( $type_code, array( 'navigation', 'pattern' ), true ) ) {
			$post_id = $source_id;
		} elseif ( 'template' === $type_code || 'template_part' === $type_code ) {
			$post_id = self::resolve_template_storage_post_id( $source_key, $type_code, $base );
			if ( 0 === $post_id ) {
				$base['storage'] = 'post_content';
				return array( $base );
			}
		} else {
			$post_id = $content_id > 0 ? $content_id : $source_id;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$base['storage']     = 'post_content';
			$base['target_id']   = $post_id;
			$base['skip_reason'] = __( 'This stored source no longer exists on the site.', 'indexlane-redirect-internal-link-auditor' );
			return array( $base );
		}

		$permalink = 'content' === $type_code && function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
		$base_url  = is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' );
		$replaced  = self::replace_stored_link_url( (string) $post->post_content, $from_url, $to_url, $base_url );
		if ( $replaced['replacements'] < 1 ) {
			return array();
		}

		$base['storage']     = 'post_content';
		$base['target_id']   = $post_id;
		$base['before']      = (string) $post->post_content;
		$base['after']       = $replaced['content'];
		$base['occurrences'] = $replaced['replacements'];
		$base['base_url']    = $base_url;
		$base['startup']     = 'post';

		return array( $base );
	}

	/**
	 * Resolve the stored post behind a block template source.
	 *
	 * Theme-file templates have no database post and cannot be rewritten.
	 *
	 * @param string              $source_key Source key.
	 * @param string              $type_code  Provider ID.
	 * @param array<string,mixed> $base       Base item, updated with a skip reason.
	 */
	private static function resolve_template_storage_post_id( string $source_key, string $type_code, array &$base ): int {
		$template_id = $source_key;
		$separator   = strpos( $source_key, ':' );
		if ( false !== $separator ) {
			$template_id = substr( $source_key, $separator + 1 );
		}

		$post_type = 'template_part' === $type_code ? 'wp_template_part' : 'wp_template';
		$template  = function_exists( 'get_block_template' ) ? get_block_template( $template_id, $post_type ) : null;
		if ( ! $template || is_wp_error( $template ) ) {
			$base['skip_reason'] = __( 'This template can no longer be read.', 'indexlane-redirect-internal-link-auditor' );
			return 0;
		}

		$wp_id = isset( $template->wp_id ) ? (int) $template->wp_id : 0;
		if ( $wp_id <= 0 ) {
			$base['skip_reason'] = __( 'This template comes from the active theme files, so it must be edited in the theme instead.', 'indexlane-redirect-internal-link-auditor' );
			return 0;
		}

		return $wp_id;
	}

	/**
	 * Resolve classic menu items that store one URL.
	 *
	 * @param array<string,mixed> $base      Base item.
	 * @param int                 $menu_id   Menu term ID.
	 * @param string              $from_url  Stored URL to replace.
	 * @param string              $to_url    Replacement URL.
	 * @return array<int,array<string,mixed>>
	 */
	private static function resolve_menu_fix_items( array $base, int $menu_id, string $from_url, string $to_url ): array {
		if ( $menu_id <= 0 || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$menu_items = wp_get_nav_menu_items( $menu_id );
		if ( ! is_array( $menu_items ) ) {
			return array();
		}

		$items        = array();
		$base_title   = (string) $base['source_title'];

		foreach ( $menu_items as $menu_item ) {
			if ( ! isset( $menu_item->ID ) ) {
				continue;
			}

			$stored  = get_post_meta( (int) $menu_item->ID, '_menu_item_url', true );
			$stored  = is_string( $stored ) ? $stored : '';
			$item_id = (int) $menu_item->ID;

			if ( '' === $stored || ! isset( $menu_item->type ) || 'custom' !== $menu_item->type ) {
				$display_url = isset( $menu_item->url ) ? trim( (string) $menu_item->url ) : '';
				if ( '' !== $display_url && self::stored_url_matches( $display_url, $from_url, home_url( '/' ) ) ) {
					$base['storage']     = 'menu_item_url';
					$base['target_id']   = $item_id;
					$base['target_sub']  = (string) $item_id;
					$base['skip_reason'] = __( 'This menu item links to a WordPress item, so its URL follows that item. Change the linked item in the menu editor instead.', 'indexlane-redirect-internal-link-auditor' );
					$items[]             = $base;
				}
				continue;
			}
			$base['skip_reason'] = '';

			if ( ! self::stored_url_matches( $stored, $from_url, home_url( '/' ) ) ) {
				continue;
			}

			$base['storage']                = 'menu_item_url';
			$base['target_id']              = $item_id;
			$base['target_sub']             = (string) $item_id;
			$base['base_url']               = home_url( '/' );
			$base['occurrences']            = 1;
			$base['before']                 = $stored;
			$base['after']                  = self::stored_url_replacement_value( $stored, $to_url, home_url( '/' ) );
			$base['startup']                = 'menu';
			$base['source_title']           = isset( $menu_item->title ) && '' !== trim( (string) $menu_item->title )
				? $base_title . ' → ' . trim( (string) $menu_item->title )
				: $base_title;
			$items[]                        = $base;
		}

		return $items;
	}

	/**
	 * Replace one exact link URL inside stored source content.
	 *
	 * Only complete `href`/`src` attribute values and block `url` attributes
	 * are considered. Text that merely contains the URL is left untouched.
	 *
	 * @param string $content  Stored source content.
	 * @param string $from_url Linked URL to replace.
	 * @param string $to_url   Replacement URL.
	 * @param string $base_url Base URL used to resolve relative stored values.
	 * @return array{content:string,replacements:int}
	 */
	private static function replace_stored_link_url( string $content, string $from_url, string $to_url, string $base_url ): array {
		if ( '' === $content || '' === $from_url || '' === $to_url ) {
			return array(
				'content'      => $content,
				'replacements' => 0,
			);
		}

		$replacements = 0;
		// Consume comments and raw-text elements before considering real tags.
		$pattern = '~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|title|template|xmp|iframe|noembed|noframes|plaintext)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>[\s\S]*?(?:</\1\s*>|$)|<([a-z][a-z0-9:-]*)(?=[\s/>])(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~i';
		$replaced = preg_replace_callback(
			$pattern,
			static function ( array $token ) use ( $from_url, $to_url, $base_url, &$replacements ): string {
				$markup = $token[0];
				if ( 0 === strpos( $markup, '<!--' ) ) {
					return self::replace_block_link_url( $markup, $from_url, $to_url, $base_url, $replacements );
				}
				if ( empty( $token[2] ) || ! in_array( strtolower( $token[2] ), array( 'a', 'area', 'img', 'source', 'audio', 'video', 'track', 'embed', 'input' ), true ) ) {
					return $markup;
				}

				// Consume every attribute, including quoted non-link values, so a
				// data-href or an href-looking string in a title cannot be changed.
				$seen = array();
				$attribute = '~(\s+)([^\s/=>]+)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>`=]+))~';
				$result = preg_replace_callback(
					$attribute,
					static function ( array $match ) use ( $token, $from_url, $to_url, $base_url, &$replacements, &$seen ): string {
						$name = strtolower( $match[2] );
						$wanted = in_array( strtolower( $token[2] ), array( 'a', 'area' ), true ) ? 'href' : 'src';
						if ( $name !== $wanted || isset( $seen[ $name ] ) ) {
							return $match[0];
						}
						$seen[ $name ] = true;
						$prefix = $match[1] . $match[2] . $match[3];
						$raw = substr( $match[0], strlen( $prefix ) );
						$quote = '"' === $raw[0] || "'" === $raw[0] ? $raw[0] : '';
						$value = '' === $quote ? $raw : substr( $raw, 1, -1 );
						$stored = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						if ( ! self::stored_url_matches( $stored, $from_url, $base_url ) ) {
							return $match[0];
						}
						$replacement = self::encode_url_for_attribute( self::stored_url_replacement_value( $stored, $to_url, $base_url ) );
						$quote = '' === $quote ? '"' : $quote;
						$replacements++;
						return $prefix . $quote . $replacement . $quote;
					},
					$markup
				);
				return is_string( $result ) ? $result : $markup;
			},
			$content
		);

		return array(
			'content'      => is_string( $replaced ) ? $replaced : $content,
			'replacements' => is_string( $replaced ) ? $replacements : 0,
		);
	}

	/**
	 * Replace only the top-level URL of a supported block comment.
	 *
	 * @param string $comment      Stored comment.
	 * @param string $from_url     URL to replace.
	 * @param string $to_url       Replacement URL.
	 * @param string $base_url     Source base URL.
	 * @param int    $replacements Number of changed attributes.
	 */
	private static function replace_block_link_url( string $comment, string $from_url, string $to_url, string $base_url, int &$replacements ): string {
		if ( ! preg_match( '~^(<!--\s+wp:(?:core/)?(?:navigation-link|navigation-submenu|social-link)\s+)(\{.*\})(\s*/?-->)$~s', $comment, $block ) ) {
			return $comment;
		}
		$attributes = json_decode( $block[2], true );
		if ( ! is_array( $attributes ) || ! isset( $attributes['url'] ) || ! is_string( $attributes['url'] ) || ! self::stored_url_matches( $attributes['url'], $from_url, $base_url ) ) {
			return $comment;
		}

		preg_match_all( '~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]~s', $block[2], $tokens, PREG_OFFSET_CAPTURE );
		$depth = 0;
		$edits = array();
		foreach ( $tokens[0] as $token ) {
			if ( '{' === $token[0] || '[' === $token[0] ) {
				$depth++;
			} elseif ( '}' === $token[0] || ']' === $token[0] ) {
				$depth--;
			} elseif ( 1 === $depth && 'url' === json_decode( $token[0] ) ) {
				$offset = $token[1] + strlen( $token[0] );
				if ( preg_match( '~\G\s*:\s*("(?:[^"\\\\]|\\\\.)*")~s', $block[2], $value, PREG_OFFSET_CAPTURE, $offset ) ) {
					$edits[] = $value[1];
				}
			}
		}
		// Duplicate URL keys are ambiguous; leave the block to its editor.
		if ( 1 !== count( $edits ) ) {
			return $comment;
		}
		$value = self::stored_url_replacement_value( $attributes['url'], $to_url, $base_url );
		$json = substr_replace( $block[2], '"' . self::encode_url_for_json( $value ) . '"', $edits[0][1], strlen( $edits[0][0] ) );
		$replacements++;
		return $block[1] . $json . $block[3];
	}

	/**
	 * Whether one stored attribute value is the exact link being replaced.
	 *
	 * The stored fragment must equal the linked fragment, so `/pricing/` is
	 * never rewritten because of a separate `/pricing/#enterprise` link.
	 *
	 * @param string $stored_value Stored attribute value.
	 * @param string $from_url     Linked URL.
	 * @param string $base_url     Base URL.
	 */
	private static function stored_url_matches( string $stored_value, string $from_url, string $base_url ): bool {
		$stored_value = trim( $stored_value );
		if ( '' === $stored_value ) {
			return false;
		}

		if ( self::link_fragment_from_href( $stored_value ) !== self::link_fragment_from_href( $from_url ) ) {
			return false;
		}

		$stored_absolute = self::normalize_link_url( $stored_value, $base_url );
		if ( '' === $stored_absolute ) {
			return false;
		}

		return self::normalize_url_for_compare( $stored_absolute ) === self::normalize_url_for_compare( $from_url );
	}

	/**
	 * Build the stored replacement value, keeping site-relative links relative.
	 *
	 * @param string $stored_value Original stored value.
	 * @param string $to_url       Replacement URL.
	 * @param string $base_url     Base URL.
	 */
	private static function stored_url_replacement_value( string $stored_value, string $to_url, string $base_url ): string {
		$stored_value = trim( $stored_value );
		$to_url       = trim( $to_url );

		if ( ! self::stored_value_is_site_relative( $stored_value ) || ! self::is_same_site_url( $to_url ) ) {
			return $to_url;
		}

		$parts = wp_parse_url( $to_url );
		if ( ! is_array( $parts ) ) {
			return $to_url;
		}

		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		$frag  = isset( $parts['fragment'] ) ? '#' . (string) $parts['fragment'] : '';

		return $path . $query . $frag;
	}

	/**
	 * Whether a stored attribute value carries no scheme.
	 *
	 * @param string $stored_value Stored attribute value.
	 */
	private static function stored_value_is_site_relative( string $stored_value ): bool {
		$stored_value = trim( $stored_value );

		if ( '' === $stored_value || 0 === strpos( $stored_value, '//' ) ) {
			return false;
		}

		return ! (bool) preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $stored_value );
	}

	/**
	 * Escape a URL for storage inside an HTML attribute.
	 *
	 * @param string $url Replacement URL.
	 */
	private static function encode_url_for_attribute( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape a URL for storage inside a block attribute JSON string.
	 *
	 * @param string $url Replacement URL.
	 */
	private static function encode_url_for_json( string $url ): string {
		$encoded = wp_json_encode( $url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) < 2 ) {
			return $url;
		}

		return str_replace( array( '--', '<', '>', '&' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026' ), substr( $encoded, 1, -1 ) );
	}

	/**
	 * Keep simultaneous plugin repairs from replacing each other's journal.
	 *
	 * @param callable $operation Repair or undo operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function with_fix_lock( callable $operation ) {
		$lock = self::FIX_JOURNAL_OPTION . '_lock';
		$existing = get_option( $lock );
		if ( is_numeric( $existing ) && (int) $existing < time() - HOUR_IN_SECONDS ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'fix_busy', __( 'Another repair or undo is running. Try again when it finishes.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		try {
			return $operation();
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Apply one confirmed repair plan and record a reversible journal entry.
	 *
	 * @param array<string,mixed> $plan Repair plan.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function apply_fix_plan( array $plan ) {
		return self::with_fix_lock( static function () use ( $plan ) {
			return self::apply_fix_plan_locked( $plan );
		} );
	}

	/** Execute this operation while holding the repair journal lock. */
	private static function apply_fix_plan_locked( array $plan ) {
		$items   = isset( $plan['items'] ) && is_array( $plan['items'] ) ? $plan['items'] : array();
		$applied = array();
		$skipped = array();
		$ready   = array();

		foreach ( $items as $item ) {
			$current = self::read_fix_storage_value( $item );
			if ( is_wp_error( $current ) || $current !== (string) $item['before'] ) {
				$skipped[] = array(
					'source_title' => (string) $item['source_title'],
					'reason' => __( 'This source changed after the preview, so it was left untouched.', 'indexlane-redirect-internal-link-auditor' ),
				);
				continue;
			}
			$ready[] = $item;
		}
		if ( empty( $ready ) ) {
			return new WP_Error( 'fix_not_applied', __( 'No stored source could be updated. Nothing was changed.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$batch_id = wp_generate_uuid4();
		$batch = array(
			'batch_id'   => $batch_id,
			'created_at' => time(),
			'user_id'    => get_current_user_id(),
			'from_url'   => (string) $plan['from_url'],
			'to_url'     => (string) $plan['to_url'],
			'status'     => 'applied',
			'items'      => $ready,
		);
		// Save undo evidence before the first content write. A failed journal
		// write must never leave a successful but unrecorded repair behind.
		$journal = self::get_fix_journal();
		array_unshift( $journal['batches'], $batch );
		if ( ! self::save_fix_journal( $journal ) ) {
			return new WP_Error( 'fix_journal_failed', __( 'The undo history could not be saved. Nothing was changed.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		foreach ( $ready as $item ) {
			// Recheck immediately before the write in case an earlier save hook
			// changed another source in this batch.
			if ( self::read_fix_storage_value( $item ) !== (string) $item['before'] ) {
				$skipped[] = array( 'source_title' => $item['source_title'], 'reason' => __( 'This source changed after the preview, so it was left untouched.', 'indexlane-redirect-internal-link-auditor' ) );
				continue;
			}
			$written = self::write_fix_storage_value( $item, (string) $item['after'] );
			if ( is_wp_error( $written ) ) {
				$skipped[] = array( 'source_title' => $item['source_title'], 'reason' => $written->get_error_message() );
				// Save filters may alter content despite reporting a successful
				// WordPress write. Retain the actual value so undo still works.
				$actual = self::read_fix_storage_value( $item );
				if ( is_wp_error( $actual ) || $actual === $item['before'] ) {
					continue;
				}
				$item['after'] = $actual;
				$item['occurrences'] = 0;
			}
			$item['revision_id'] = is_array( $written ) && isset( $written['revision_id'] ) ? (int) $written['revision_id'] : 0;
			$applied[] = $item;
		}

		$journal['batches'][0]['items'] = $applied;
		if ( empty( $applied ) ) {
			array_shift( $journal['batches'] );
		}
		self::save_fix_journal( $journal );
		if ( empty( $applied ) ) {
			return new WP_Error( 'fix_not_applied', __( 'No stored source could be updated. Nothing was changed.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$occurrences = 0;
		foreach ( $applied as $item ) {
			$occurrences += isset( $item['occurrences'] ) ? (int) $item['occurrences'] : 1;
		}

		return array(
			'batch_id'    => $batch_id,
			'sources'     => count( $applied ),
			'occurrences' => $occurrences,
			'skipped'     => $skipped,
		);
	}

	/**
	 * Restore every stored value recorded by one repair batch.
	 *
	 * An item is restored only when its stored value still matches what the
	 * repair wrote, so later manual edits are never silently overwritten.
	 *
	 * @param string $batch_id Journal batch ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function undo_fix_batch( string $batch_id ) {
		return self::with_fix_lock( static function () use ( $batch_id ) {
			return self::undo_fix_batch_locked( $batch_id );
		} );
	}

	/** Execute this operation while holding the repair journal lock. */
	private static function undo_fix_batch_locked( string $batch_id ) {
		$journal = self::get_fix_journal();
		$index   = -1;

		foreach ( $journal['batches'] as $position => $batch ) {
			if ( isset( $batch['batch_id'] ) && hash_equals( (string) $batch['batch_id'], $batch_id ) ) {
				$index = (int) $position;
				break;
			}
		}

		if ( $index < 0 ) {
			return new WP_Error( 'fix_batch_missing', __( 'That repair is no longer in the undo history.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$batch = $journal['batches'][ $index ];
		if ( isset( $batch['status'] ) && 'undone' === $batch['status'] ) {
			return new WP_Error( 'fix_batch_undone', __( 'That repair was already undone.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$restored = 0;
		$skipped  = array();

		foreach ( (array) $batch['items'] as $position => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['storage'], $item['target_id'], $item['before'], $item['after'] ) ) {
				continue;
			}

			if ( ! empty( $item['undone'] ) ) {
				continue;
			}
			$current = self::read_fix_storage_value( $item );
			if ( is_wp_error( $current ) || $current !== (string) $item['after'] ) {
				$skipped[] = array(
					'source_title' => (string) $item['source_title'],
					'reason'       => __( 'This source was edited after the repair, so it was left as it is now.', 'indexlane-redirect-internal-link-auditor' ),
				);
				continue;
			}

			$written = self::write_fix_storage_value( $item, (string) $item['before'] );
			if ( is_wp_error( $written ) ) {
				$skipped[] = array(
					'source_title' => (string) $item['source_title'],
					'reason'       => $written->get_error_message(),
				);
				continue;
			}

			$restored++;
			$journal['batches'][ $index ]['items'][ $position ]['undone'] = true;
			$journal['batches'][ $index ]['items'][ $position ]['revision_id'] = isset( $written['revision_id'] ) ? (int) $written['revision_id'] : 0;
		}

		if ( 0 === $restored ) {
			return new WP_Error( 'fix_undo_nothing', __( 'Nothing could be restored because the affected sources changed after the repair.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$journal['batches'][ $index ]['status']    = empty( $skipped ) ? 'undone' : 'partial';
		$journal['batches'][ $index ]['undone_at'] = time();
		self::save_fix_journal( $journal );

		return array(
			'batch_id' => $batch_id,
			'restored' => $restored,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Read the current stored value behind one repair item.
	 *
	 * @param array<string,mixed> $item Repair item.
	 * @return string|WP_Error
	 */
	private static function read_fix_storage_value( array $item ) {
		$target_id = isset( $item['target_id'] ) ? (int) $item['target_id'] : 0;
		$storage   = isset( $item['storage'] ) ? (string) $item['storage'] : '';

		switch ( $storage ) {
			case 'post_content':
				$post = $target_id > 0 ? get_post( $target_id ) : null;
				if ( ! $post ) {
					return new WP_Error( 'fix_source_missing', __( 'This stored source no longer exists.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				return (string) $post->post_content;

			case 'menu_item_url':
				$stored = get_post_meta( $target_id, '_menu_item_url', true );

				return is_string( $stored ) ? $stored : '';

			case 'widget_block':
				$instances = get_option( 'widget_block', array() );
				if ( ! is_array( $instances ) || ! isset( $instances[ $target_id ]['content'] ) || ! is_string( $instances[ $target_id ]['content'] ) ) {
					return new WP_Error( 'fix_source_missing', __( 'This block widget no longer exists.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				return (string) $instances[ $target_id ]['content'];
		}

		return new WP_Error( 'fix_unsupported_storage', __( 'This storage type cannot be edited by the plugin.', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Write one stored value and report the resulting revision.
	 *
	 * @param array<string,mixed> $item  Repair item.
	 * @param string              $value New stored value.
	 * @return array{revision_id:int}|WP_Error
	 */
	private static function write_fix_storage_value( array $item, string $value ) {
		$target_id = isset( $item['target_id'] ) ? (int) $item['target_id'] : 0;
		$storage   = isset( $item['storage'] ) ? (string) $item['storage'] : '';

		switch ( $storage ) {
			case 'post_content':
				if ( $target_id <= 0 ) {
					return new WP_Error( 'fix_source_missing', __( 'This stored source no longer exists.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				$restore = self::remove_content_save_filters();
				try {
					$result = wp_update_post(
						array(
							'ID'           => $target_id,
							'post_content' => wp_slash( $value ),
						),
						true
					);
				} finally {
					self::restore_content_save_filters( $restore );
				}

				if ( is_wp_error( $result ) ) {
					return new WP_Error( 'fix_write_failed', __( 'WordPress could not save this source.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				$verified = get_post( $target_id );
				if ( ! $verified || (string) $verified->post_content !== $value ) {
					return new WP_Error( 'fix_write_verify_failed', __( 'WordPress altered the saved content. Review this source before continuing; its stored values are kept in the undo history.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				return array( 'revision_id' => self::latest_revision_id( $target_id ) );

			case 'menu_item_url':
				if ( $target_id <= 0 || ! update_post_meta( $target_id, '_menu_item_url', wp_slash( $value ) ) ) {
					return new WP_Error( 'fix_write_failed', __( 'WordPress could not save this menu item.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				return array( 'revision_id' => 0 );

			case 'widget_block':
				$instances = get_option( 'widget_block', array() );
				if ( ! is_array( $instances ) || ! isset( $instances[ $target_id ]['content'] ) ) {
					return new WP_Error( 'fix_source_missing', __( 'This block widget no longer exists.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				$instances[ $target_id ]['content'] = $value;
				if ( ! update_option( 'widget_block', $instances ) ) {
					return new WP_Error( 'fix_write_failed', __( 'WordPress could not save this block widget.', 'indexlane-redirect-internal-link-auditor' ) );
				}

				return array( 'revision_id' => 0 );
		}

		return new WP_Error( 'fix_unsupported_storage', __( 'This storage type cannot be edited by the plugin.', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Remove content filters that could rewrite the repaired markup.
	 *
	 * The replacement is an exact, previously confirmed URL change, so
	 * sanitizing filters are suspended for the single plugin-owned write and
	 * restored immediately afterwards.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function remove_content_save_filters(): array {
		$hooks    = array( 'content_save_pre', 'content_filtered_save_pre' );
		$restored = array();

		foreach ( $hooks as $hook ) {
			foreach ( array( 'wp_filter_post_kses', 'wp_filter_kses' ) as $callback ) {
				if ( remove_filter( $hook, $callback ) ) {
					$restored[] = array( $hook, $callback );
				}
			}
		}

		return $restored;
	}

	/**
	 * Restore the content filters removed for one plugin-owned write.
	 *
	 * @param array<int,array{0:string,1:string}> $filters Removed filters.
	 */
	private static function restore_content_save_filters( array $filters ): void {
		foreach ( $filters as $filter ) {
			add_filter( $filter[0], $filter[1] );
		}
	}

	/**
	 * Latest revision ID created for one post, when revisions are enabled.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function latest_revision_id( int $post_id ): int {
		if ( $post_id <= 0 || ! function_exists( 'wp_get_post_revisions' ) ) {
			return 0;
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		if ( ! is_array( $revisions ) || empty( $revisions ) ) {
			return 0;
		}

		$revision = reset( $revisions );

		return $revision && isset( $revision->ID ) ? (int) $revision->ID : 0;
	}

	/**
	 * Read the bounded repair journal.
	 *
	 * @return array{batches:array<int,array<string,mixed>>}
	 */
	private static function get_fix_journal(): array {
		$journal = get_option( self::FIX_JOURNAL_OPTION, array() );
		if ( ! is_array( $journal ) || ! isset( $journal['batches'] ) || ! is_array( $journal['batches'] ) ) {
			return array( 'batches' => array() );
		}

		$batches = array();
		foreach ( $journal['batches'] as $batch ) {
			if ( is_array( $batch ) && isset( $batch['batch_id'], $batch['items'] ) && is_string( $batch['batch_id'] ) && is_array( $batch['items'] ) ) {
				$batches[] = $batch;
			}
		}

		return array( 'batches' => $batches );
	}

	/**
	 * Persist the repair journal, keeping the newest bounded entries.
	 *
	 * @param array<string,mixed> $journal Journal data.
	 */
	private static function save_fix_journal( array $journal ): bool {
		$batches = isset( $journal['batches'] ) && is_array( $journal['batches'] ) ? array_values( $journal['batches'] ) : array();

		// Entries are prepended when applied; keep that order when timestamps
		// share a second, including on PHP versions with unstable sorting.

		$kept  = array();
		$bytes = strlen( '{"batches":[]}' );

		foreach ( $batches as $batch ) {
			if ( count( $kept ) >= self::FIX_JOURNAL_MAX_BATCHES ) {
				break;
			}

			$encoded = wp_json_encode( $batch );
			$size    = is_string( $encoded ) ? strlen( $encoded ) + 1 : self::FIX_JOURNAL_MAX_BYTES + 1;
			if ( ( $bytes + $size ) > self::FIX_JOURNAL_MAX_BYTES ) {
				if ( empty( $kept ) ) {
					return false;
				}
				break;
			}

			$kept[] = $batch;
			$bytes += $size;
		}

		return update_option( self::FIX_JOURNAL_OPTION, array( 'batches' => $kept ), false );
	}

	/**
	 * Handle preview, apply, and undo requests before page output.
	 */
	public static function maybe_handle_fix_action(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || 'POST' !== self::server_request_method() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}

		$action = isset( $_POST['indexlane_rila_action'] ) ? sanitize_key( wp_unslash( $_POST['indexlane_rila_action'] ) ) : '';
		if ( ! in_array( $action, array( 'fix_preview', 'fix_apply', 'fix_undo' ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( 'fix_undo' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is verified immediately above.
			$batch_id = isset( $_POST['batch_id'] ) && is_scalar( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['batch_id'] ) ) : '';
			$result   = self::undo_fix_batch( $batch_id );

			if ( is_wp_error( $result ) ) {
				self::redirect_after_fix_action( 'undo_failed', $result->get_error_message() );
			}

			/* translators: %d: number of stored sources restored */
			$detail = sprintf( _n( '%d source restored.', '%d sources restored.', (int) $result['restored'], 'indexlane-redirect-internal-link-auditor' ), (int) $result['restored'] );
			self::redirect_after_fix_action( 'undone', $detail );
		}

		$session = self::get_scan_session();
		if ( ! is_array( $session ) || 'complete' !== $session['status'] ) {
			self::redirect_after_fix_action( 'missing_scan', '' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The action nonce is verified before these URLs are read.
		$from_url = isset( $_POST['from_url'] ) && is_scalar( $_POST['from_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['from_url'] ) ) ) : '';
		$to_url   = isset( $_POST['to_url'] ) && is_scalar( $_POST['to_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['to_url'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$plan = self::build_fix_plan( $session, $from_url, $to_url );
		if ( is_wp_error( $plan ) ) {
			self::redirect_after_fix_action( 'plan_failed', $plan->get_error_message() );
		}

		if ( 'fix_preview' === $action ) {
			self::$pending_fix_plan = $plan;
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified against this exact plan below.
		$confirmation = isset( $_POST['fix_confirmation'] ) && is_string( $_POST['fix_confirmation'] ) ? wp_unslash( $_POST['fix_confirmation'] ) : '';
		if ( ! wp_verify_nonce( $confirmation, self::fix_confirmation_action( $plan ) ) ) {
			self::redirect_after_fix_action( 'plan_failed', __( 'The source or replacement changed since the preview. Preview the changes again before applying them.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$result = self::apply_fix_plan( $plan );
		if ( is_wp_error( $result ) ) {
			self::redirect_after_fix_action( 'apply_failed', $result->get_error_message() );
		}

		$detail = sprintf(
			/* translators: 1: number of link occurrences replaced, 2: number of stored sources changed */
			_n( '%1$d link replacement in %2$d source.', '%1$d link replacements in %2$d sources.', (int) $result['occurrences'], 'indexlane-redirect-internal-link-auditor' ),
			(int) $result['occurrences'],
			(int) $result['sources']
		);

		if ( ! empty( $result['skipped'] ) ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: number of stored sources left untouched */
				_n( '%d source was left untouched.', '%d sources were left untouched.', count( $result['skipped'] ), 'indexlane-redirect-internal-link-auditor' ),
				count( $result['skipped'] )
			);
		}

		self::redirect_after_fix_action( 'applied', $detail );
	}

	/**
	 * Bind confirmation to the complete preview, not just the two URLs.
	 *
	 * @param array<string,mixed> $plan Previewed plan.
	 */
	private static function fix_confirmation_action( array $plan ): string {
		return 'indexlane_rila_fix_' . hash( 'sha256', (string) wp_json_encode( $plan ) );
	}

	/**
	 * Redirect after a repair action so a reload cannot repeat it.
	 *
	 * @param string $code   Notice code.
	 * @param string $detail Extra detail text.
	 */
	private static function redirect_after_fix_action( string $code, string $detail ): void {
		$args = array(
			'page'        => self::SLUG,
			'fix_notice'  => $code,
		);

		if ( '' !== $detail ) {
			$args['fix_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Return the localized message for one repair notice code.
	 *
	 * @param string $code   Notice code.
	 * @param string $detail Extra detail text.
	 * @return array{type:string,message:string}|null
	 */
	private static function fix_notice_message( string $code, string $detail ): ?array {
		$message = '';
		$type    = 'error';

		switch ( $code ) {
			case 'preview':
				return null;
			case 'applied':
				$type    = 'success';
				$message = __( 'The repair finished. Review the summary below and check the links again.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'undone':
				$type    = 'success';
				$message = __( 'The repair was undone and the previous stored links were restored.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'plan_failed':
			case 'apply_failed':
			case 'undo_failed':
				$message = __( 'The repair could not be completed.', 'indexlane-redirect-internal-link-auditor' );
				break;
			case 'missing_scan':
				$message = __( 'These scan results are unavailable or have expired. Run the scan again before fixing links.', 'indexlane-redirect-internal-link-auditor' );
				break;
			default:
				return null;
		}

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Render the repair notice for the current request.
	 */
	private static function render_fix_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This displays a fixed message after a nonce-protected redirect.
		$code = isset( $_GET['fix_notice'] ) ? sanitize_key( wp_unslash( $_GET['fix_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This displays a fixed message after a nonce-protected redirect.
		$detail  = isset( $_GET['fix_detail'] ) && is_scalar( $_GET['fix_detail'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['fix_detail'] ) ) : '';
		$notice  = self::fix_notice_message( $code, $detail );

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Render the fix-in-place panel for a completed scan.
	 *
	 * @param array<string,mixed> $scan Completed scan session.
	 */
	private static function render_fix_panel( array $scan ): void {
		$results    = isset( $scan['results'] ) && is_array( $scan['results'] ) ? $scan['results'] : array();
		$all        = self::build_fix_candidates( $results );
		$candidates = array_slice( $all, 0, self::FIX_MAX_CANDIDATES_IN_PANEL );
		$truncated  = count( $all ) - count( $candidates );
		$journal    = self::get_fix_journal();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prefills one field from an already-rendered link on this page.
		$preselected = isset( $_GET['fix_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_GET['fix_url'] ) ) ) : '';

		?>
		<section id="indexlane-rila-fix" class="indexlane-rila-fix" aria-labelledby="indexlane-rila-fix-heading">
			<h2 id="indexlane-rila-fix-heading"><?php esc_html_e( 'Fix links', 'indexlane-redirect-internal-link-auditor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Replace one stored URL with another directly in the selected WordPress sources. Nothing is written until you review the exact changes and confirm them, and every repair can be undone.', 'indexlane-redirect-internal-link-auditor' ); ?>
			</p>

			<?php if ( is_array( self::$pending_fix_plan ) ) : ?>
				<?php self::render_fix_preview( self::$pending_fix_plan ); ?>
			<?php endif; ?>

			<?php if ( empty( $candidates ) ) : ?>
				<p><?php esc_html_e( 'No repairable URL was found in these results.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
			<?php else : ?>
				<?php if ( $truncated > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of repairable URLs listed, 2: number of additional repairable URLs */
								_n( 'Showing the %1$d most actionable URL. %2$d more is not listed here; narrow the scan or use the WP-CLI fix command.', 'Showing the %1$d most actionable URLs. %2$d more are not listed here; narrow the scan or use the WP-CLI fix command.', (int) count( $candidates ), 'indexlane-redirect-internal-link-auditor' ),
								(int) count( $candidates ),
								(int) $truncated
							)
						);
						?>
					</p>
				<?php endif; ?>
				<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Fix links', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
				<table class="widefat striped indexlane-rila-fix-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Stored URL', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Outcome', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Where it appears', 'indexlane-redirect-internal-link-auditor' ); ?></th>
							<th><?php esc_html_e( 'Replace with', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $candidates as $candidate ) : ?>
							<tr>
								<td class="indexlane-rila-fix-url">
									<?php if ( '' !== esc_url( $candidate['from_url'] ) ) : ?>
										<a href="<?php echo esc_url( $candidate['from_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $candidate['from_url'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $candidate['from_url'] ); ?>
									<?php endif; ?>
								</td>
								<td><strong><?php echo esc_html( $candidate['result'] ); ?></strong></td>
								<td>
									<?php
									/* translators: %d: number of editable sources containing a URL */
									echo esc_html( sprintf( _n( '%d source', '%d sources', (int) $candidate['affected_sources'], 'indexlane-redirect-internal-link-auditor' ), (int) $candidate['affected_sources'] ) );
									echo ' ';
									/* translators: %d: number of times a URL is linked */
									echo esc_html( sprintf( _n( '(%d link)', '(%d links)', (int) $candidate['occurrences'], 'indexlane-redirect-internal-link-auditor' ), (int) $candidate['occurrences'] ) );
									?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-fix-form">
										<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
										<input type="hidden" name="from_url" value="<?php echo esc_attr( $candidate['from_url'] ); ?>" />
										<label class="screen-reader-text" for="<?php echo esc_attr( 'indexlane-rila-to-' . md5( (string) $candidate['from_url'] ) ); ?>">
											<?php esc_html_e( 'Replacement URL', 'indexlane-redirect-internal-link-auditor' ); ?>
										</label>
										<input
											id="<?php echo esc_attr( 'indexlane-rila-to-' . md5( (string) $candidate['from_url'] ) ); ?>"
											type="url"
											name="to_url"
											class="regular-text code"
											required
											value="<?php echo esc_attr( '' !== $candidate['suggestion'] ? $candidate['suggestion'] : $preselected ); ?>"
											placeholder="https://example.com/new-url/"
										/>
										<button type="submit" name="indexlane_rila_action" value="fix_preview" class="button">
											<?php esc_html_e( 'Preview changes', 'indexlane-redirect-internal-link-auditor' ); ?>
										</button>
										<?php if ( '' !== $candidate['suggestion'] ) : ?>
											<span class="indexlane-rila-cell-note"><?php echo esc_html( $candidate['suggestion_label'] ); ?></span>
										<?php endif; ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<?php self::render_fix_journal( $journal ); ?>
		</section>
		<?php
	}

	/**
	 * Render the exact, unapplied changes in one repair plan.
	 *
	 * @param array<string,mixed> $plan Repair plan.
	 */
	private static function render_fix_preview( array $plan ): void {
		$items   = isset( $plan['items'] ) && is_array( $plan['items'] ) ? $plan['items'] : array();
		$skipped = isset( $plan['skipped'] ) && is_array( $plan['skipped'] ) ? $plan['skipped'] : array();
		?>
		<div class="indexlane-rila-fix-preview">
			<h3><?php esc_html_e( 'Review the exact changes', 'indexlane-redirect-internal-link-auditor' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of link occurrences replaced, 2: number of stored sources changed */
						_n( '%1$d link occurrence in %2$d source will change. Nothing has been written yet.', '%1$d link occurrences in %2$d sources will change. Nothing has been written yet.', (int) $plan['occurrences'], 'indexlane-redirect-internal-link-auditor' ),
						(int) $plan['occurrences'],
						(int) $plan['sources']
					)
				);
				?>
			</p>
			<dl class="indexlane-rila-fix-preview-summary">
				<div><dt><?php esc_html_e( 'Replace', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><code><?php echo esc_html( (string) $plan['from_url'] ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'With', 'indexlane-redirect-internal-link-auditor' ); ?></dt><dd><code><?php echo esc_html( (string) $plan['to_url'] ); ?></code></dd></div>
			</dl>

			<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Review the exact changes', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
			<table class="widefat striped indexlane-rila-fix-preview-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Surface', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Stored value', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'New value', 'indexlane-redirect-internal-link-auditor' ); ?></th>
						<th><?php esc_html_e( 'Links changed', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td>
								<?php if ( '' !== (string) $item['edit_url'] ) : ?>
									<a href="<?php echo esc_url( (string) $item['edit_url'] ); ?>"><?php echo esc_html( (string) $item['source_title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( (string) $item['source_title'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $item['source_type'] ); ?></td>
							<td><details><summary><?php esc_html_e( 'View full stored value', 'indexlane-redirect-internal-link-auditor' ); ?></summary><code><?php echo esc_html( (string) $item['before'] ); ?></code></details></td>
							<td><details><summary><?php esc_html_e( 'View full stored value', 'indexlane-redirect-internal-link-auditor' ); ?></summary><code><?php echo esc_html( (string) $item['after'] ); ?></code></details></td>
							<td><?php echo esc_html( (string) (int) $item['occurrences'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<?php if ( ! empty( $skipped ) ) : ?>
				<h4><?php esc_html_e( 'Sources that cannot be changed here', 'indexlane-redirect-internal-link-auditor' ); ?></h4>
				<ul class="indexlane-rila-fix-skipped">
					<?php foreach ( $skipped as $item ) : ?>
						<li>
							<?php if ( '' !== (string) $item['edit_url'] ) : ?>
								<a href="<?php echo esc_url( (string) $item['edit_url'] ); ?>"><?php echo esc_html( (string) $item['source_title'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( (string) $item['source_title'] ); ?>
							<?php endif; ?>
							<span><?php echo esc_html( (string) $item['reason'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-fix-apply">
				<?php wp_nonce_field( self::fix_confirmation_action( $plan ), 'fix_confirmation' ); ?>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="from_url" value="<?php echo esc_attr( (string) $plan['from_url'] ); ?>" />
				<input type="hidden" name="to_url" value="<?php echo esc_attr( (string) $plan['to_url'] ); ?>" />
				<button type="submit" name="indexlane_rila_action" value="fix_apply" class="button button-primary" data-indexlane-rila-confirm="<?php esc_attr_e( 'Apply these exact link changes now?', 'indexlane-redirect-internal-link-auditor' ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of stored sources that will change */
							_n( 'Apply change in %d source', 'Apply changes in %d sources', (int) $plan['sources'], 'indexlane-redirect-internal-link-auditor' ),
							(int) $plan['sources']
						)
					);
					?>
				</button>
				<a class="button" href="<?php echo esc_url( self::admin_page_url() ); ?>#indexlane-rila-fix"><?php esc_html_e( 'Cancel', 'indexlane-redirect-internal-link-auditor' ); ?></a>
				<span class="description"><?php esc_html_e( 'Expand the stored values to review every change before applying the repair.', 'indexlane-redirect-internal-link-auditor' ); ?></span>
			</form>
		</div>
		<?php
	}

	/**
	 * Build a short, honest preview snippet around the changed URL.
	 *
	 * @param string $value  Stored or new value.
	 * @param string $needle URL expected inside the value.
	 */
	private static function fix_preview_value( string $value, string $needle ): string {
		$needle = trim( $needle );
		$value  = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$position = '' !== $needle ? strpos( $value, $needle ) : false;
		if ( false === $position ) {
			return self::truncate_text( $value, 120 );
		}

		$start = max( 0, $position - 40 );
		$slice = substr( $value, $start, strlen( $needle ) + 80 );

		return ( $start > 0 ? '…' : '' ) . self::truncate_text( $slice, 140 );
	}

	/**
	 * Render the reversible repair history.
	 *
	 * @param array<string,mixed> $journal Repair journal.
	 */
	private static function render_fix_journal( array $journal ): void {
		$batches = isset( $journal['batches'] ) && is_array( $journal['batches'] ) ? $journal['batches'] : array();
		if ( empty( $batches ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Recent repairs', 'indexlane-redirect-internal-link-auditor' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Each repair keeps the previous stored values so it can be undone. A repair is skipped on undo when its sources were edited again afterwards.', 'indexlane-redirect-internal-link-auditor' ); ?></p>
		<div class="indexlane-rila-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Recent repairs', 'indexlane-redirect-internal-link-auditor' ); ?>" tabindex="0">
		<table class="widefat striped indexlane-rila-fix-journal">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Change', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Sources', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Status', 'indexlane-redirect-internal-link-auditor' ); ?></th>
					<th><?php esc_html_e( 'Action', 'indexlane-redirect-internal-link-auditor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $batches as $batch ) : ?>
					<?php
					$created = isset( $batch['created_at'] ) ? (int) $batch['created_at'] : 0;
					$when    = $created > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) : '';
					$status  = isset( $batch['status'] ) && 'undone' === $batch['status'] ? __( 'Undone', 'indexlane-redirect-internal-link-auditor' ) : ( isset( $batch['status'] ) && 'partial' === $batch['status'] ? __( 'Partly undone', 'indexlane-redirect-internal-link-auditor' ) : __( 'Applied', 'indexlane-redirect-internal-link-auditor' ) );
					?>
					<tr>
						<td><?php echo esc_html( $when ); ?></td>
						<td>
							<code><?php echo esc_html( (string) $batch['from_url'] ); ?></code>
							<br />
							<code><?php echo esc_html( (string) $batch['to_url'] ); ?></code>
						</td>
						<td><?php echo esc_html( (string) count( (array) $batch['items'] ) ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
						<td>
							<?php if ( 'undone' !== (string) $batch['status'] ) : ?>
								<form method="post" action="<?php echo esc_url( self::admin_page_url() ); ?>" class="indexlane-rila-fix-undo">
									<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
									<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['batch_id'] ); ?>" />
									<button type="submit" name="indexlane_rila_action" value="fix_undo" class="button" data-indexlane-rila-confirm="<?php esc_attr_e( 'Restore the previous stored links for this repair?', 'indexlane-redirect-internal-link-auditor' ); ?>">
										<?php esc_html_e( 'Undo', 'indexlane-redirect-internal-link-auditor' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
