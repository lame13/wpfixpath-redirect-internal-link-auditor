<?php
/**
 * Stored WordPress source providers for the redirect and internal-link auditor.
 *
 * @package IndexLane_Redirect_Internal_Link_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait IndexLane_Redirect_Internal_Link_Auditor_Source_Providers {
	/**
	 * Get every public post type that can contain published content.
	 *
	 * @return array<string,string>
	 */
	private static function get_available_post_types(): array {
		$labels  = array();
		$objects = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $objects as $post_type => $post_type_object ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}

			$labels[ $post_type ] = isset( $post_type_object->labels->name )
				? (string) $post_type_object->labels->name
				: (string) $post_type;
		}

		natcasesort( $labels );

		return $labels;
	}

	/**
	 * Get the registered source-provider definitions.
	 *
	 * Provider snapshot callbacks receive sanitized scan settings and return an
	 * array with `total_items` and a serializable array `cursor`. Provider next
	 * callbacks receive the cursor and settings and return `source`, `cursor`,
	 * and `done`. A source is stored evidence, never rendered output.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_source_providers(): array {
		$providers = array(
			'content'       => array(
				'label'             => __( 'Content', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Posts, pages, and other content types selected below.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'contextual',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_content_provider' ),
				'next_callback'     => array( __CLASS__, 'next_content_provider_source' ),
			),
			'menu'          => array(
				'label'             => __( 'Classic menus', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in every classic WordPress menu.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_classic_menu_provider' ),
				'next_callback'     => array( __CLASS__, 'next_classic_menu_provider_source' ),
			),
			'navigation'    => array(
				'label'             => __( 'Navigation blocks', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in Navigation blocks from the Site Editor.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_navigation_provider' ),
				'next_callback'     => array( __CLASS__, 'next_navigation_provider_source' ),
			),
			'pattern'       => array(
				'label'             => __( 'Synced patterns', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in synced patterns, formerly called reusable blocks.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_pattern_provider' ),
				'next_callback'     => array( __CLASS__, 'next_pattern_provider_source' ),
			),
			'template'      => array(
				'label'             => __( 'Site Editor templates', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in full-page templates from the Site Editor.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_template_provider' ),
				'next_callback'     => array( __CLASS__, 'next_template_provider_source' ),
			),
			'template_part' => array(
				'label'             => __( 'Template parts', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in Site Editor headers, footers, and other template parts.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_template_part_provider' ),
				'next_callback'     => array( __CLASS__, 'next_template_part_provider_source' ),
			),
			'widget'        => array(
				'label'             => __( 'Widgets', 'indexlane-redirect-internal-link-auditor' ),
				'description'       => __( 'Links saved in block widgets that are currently in use.', 'indexlane-redirect-internal-link-auditor' ),
				'context'           => 'shared',
				'default'           => true,
				'snapshot_callback' => array( __CLASS__, 'snapshot_widget_provider' ),
				'next_callback'     => array( __CLASS__, 'next_widget_provider_source' ),
			),
		);

		/**
		 * Filters stored-source providers available to scans.
		 *
		 * Each provider is keyed by a stable lowercase ID and supplies `label`,
		 * `description`, `context` (`contextual` or `shared`), `default`,
		 * `snapshot_callback`, and `next_callback` values.
		 *
		 * @since 0.6.0
		 *
		 * @param array<string,array<string,mixed>> $providers Source providers.
		 */
		$providers = apply_filters( 'indexlane_rila_source_providers', $providers );

		return self::normalize_source_providers( is_array( $providers ) ? $providers : array() );
	}

	/**
	 * Keep only complete provider definitions with stable identifiers.
	 *
	 * @param array<mixed> $providers Filtered provider definitions.
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalize_source_providers( array $providers ): array {
		$normalized = array();

		foreach ( $providers as $provider_id => $provider ) {
			if (
				! is_string( $provider_id ) ||
				! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider_id ) ||
				! is_array( $provider ) ||
				! isset( $provider['label'], $provider['description'], $provider['context'], $provider['snapshot_callback'], $provider['next_callback'] ) ||
				! is_string( $provider['label'] ) ||
				'' === trim( $provider['label'] ) ||
				! is_string( $provider['description'] ) ||
				! in_array( $provider['context'], array( 'contextual', 'shared' ), true ) ||
				! is_callable( $provider['snapshot_callback'] ) ||
				! is_callable( $provider['next_callback'] )
			) {
				continue;
			}

			$normalized[ $provider_id ] = array(
				'label'             => trim( $provider['label'] ),
				'description'       => trim( $provider['description'] ),
				'context'           => (string) $provider['context'],
				'default'           => ! empty( $provider['default'] ),
				'snapshot_callback' => $provider['snapshot_callback'],
				'next_callback'     => $provider['next_callback'],
			);
		}

		return $normalized;
	}

	/**
	 * Get selectable source types and their operator-facing metadata.
	 *
	 * @return array<string,array{label:string,description:string,context:string,default:bool}>
	 */
	private static function get_available_source_types(): array {
		$types = array();
		foreach ( self::get_source_providers() as $provider_id => $provider ) {
			$types[ $provider_id ] = array(
				'label'       => (string) $provider['label'],
				'description' => (string) $provider['description'],
				'context'     => (string) $provider['context'],
				'default'     => (bool) $provider['default'],
			);
		}

		return $types;
	}

	/**
	 * Get source types selected for a new scan by default.
	 *
	 * @return array<int,string>
	 */
	private static function get_default_source_types(): array {
		$defaults = array();
		foreach ( self::get_available_source_types() as $provider_id => $provider ) {
			if ( $provider['default'] ) {
				$defaults[] = $provider_id;
			}
		}

		return $defaults;
	}

	/**
	 * Snapshot every selected provider without retaining source bodies.
	 *
	 * @param array<string,mixed> $settings Sanitized scan settings.
	 * @return array{states:array<int,array<string,mixed>>,total_items:int}|WP_Error
	 */
	private static function snapshot_selected_source_providers( array $settings ) {
		$providers = self::get_source_providers();
		$states    = array();
		$total     = 0;

		foreach ( $settings['source_types'] as $provider_id ) {
			if ( ! isset( $providers[ $provider_id ] ) ) {
				return new WP_Error( 'source_provider_unavailable', __( 'A selected link source is no longer available. Reload the page and choose the scan sources again.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$snapshot = call_user_func( $providers[ $provider_id ]['snapshot_callback'], $settings );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if (
				! is_array( $snapshot ) ||
				! isset( $snapshot['total_items'], $snapshot['cursor'] ) ||
				! is_int( $snapshot['total_items'] ) ||
				$snapshot['total_items'] < 0 ||
				! is_array( $snapshot['cursor'] )
			) {
				return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned an invalid scan snapshot.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$total += $snapshot['total_items'];
			if ( $total > self::MAX_SESSION_SOURCE_ITEMS ) {
				return new WP_Error(
					'source_limit_exceeded',
					sprintf(
						/* translators: %d: maximum number of stored sources in one scan */
						__( 'The selected scope contains more than %d stored sources. Narrow the content scope or selected link sources.', 'indexlane-redirect-internal-link-auditor' ),
						self::MAX_SESSION_SOURCE_ITEMS
					)
				);
			}

			$states[] = array(
				'id'              => $provider_id,
				'total_items'     => $snapshot['total_items'],
				'processed_items' => 0,
				'cursor'          => $snapshot['cursor'],
			);
		}

		return array(
			'states'      => $states,
			'total_items' => $total,
		);
	}

	/**
	 * Read the next stored source from the active provider cursor.
	 *
	 * @param array<string,mixed> $session Scan session.
	 * @return array{session:array<string,mixed>,source:array<string,mixed>|null}|WP_Error
	 */
	private static function get_next_scan_source( array $session ) {
		$providers = self::get_source_providers();
		$skips     = 0;

		while ( (int) $session['source_provider_index'] < count( $session['source_provider_states'] ) ) {
			$index = (int) $session['source_provider_index'];
			$state = $session['source_provider_states'][ $index ];
			$id    = isset( $state['id'] ) ? (string) $state['id'] : '';
			if ( ! isset( $providers[ $id ] ) ) {
				return new WP_Error( 'source_provider_unavailable', __( 'A link-source provider became unavailable while the scan was paused. Cancel this scan, restore the provider, or start a new scan.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			if ( (int) $state['processed_items'] >= (int) $state['total_items'] ) {
				$session['source_provider_index']++;
				continue;
			}

			$next = call_user_func( $providers[ $id ]['next_callback'], $state['cursor'], $session['settings'] );
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			if (
				! is_array( $next ) ||
				! array_key_exists( 'source', $next ) ||
				! isset( $next['cursor'], $next['done'] ) ||
				! is_array( $next['cursor'] ) ||
				! is_bool( $next['done'] ) ||
				( null !== $next['source'] && ! is_array( $next['source'] ) )
			) {
				return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned invalid scan progress.', 'indexlane-redirect-internal-link-auditor' ) );
			}

			$session['source_provider_states'][ $index ]['cursor'] = $next['cursor'];
			if ( null === $next['source'] ) {
				$remaining = max( 0, (int) $state['total_items'] - (int) $state['processed_items'] );
				if ( $next['done'] ) {
					$session['total_items'] -= $remaining;
					$session['source_provider_states'][ $index ]['total_items'] = (int) $state['processed_items'];
					$session['source_provider_index']++;
				} else {
					$session['total_items'] = max( 0, (int) $session['total_items'] - 1 );
					$session['source_provider_states'][ $index ]['total_items'] = max( (int) $state['processed_items'], (int) $state['total_items'] - 1 );
				}

				$skips++;
				if ( $skips > self::MAX_SESSION_SOURCE_ITEMS ) {
					return new WP_Error( 'source_provider_invalid', __( 'A link-source provider did not advance its scan cursor.', 'indexlane-redirect-internal-link-auditor' ) );
				}
				continue;
			}

			$source = self::normalize_source_record( $next['source'], $id, $providers[ $id ] );
			if ( is_wp_error( $source ) ) {
				return $source;
			}

			$session['source_provider_states'][ $index ]['processed_items']++;
			if ( $next['done'] || (int) $session['source_provider_states'][ $index ]['processed_items'] >= (int) $session['source_provider_states'][ $index ]['total_items'] ) {
				$session['source_provider_index']++;
			}

			return array(
				'session' => $session,
				'source'  => $source,
			);
		}

		return array(
			'session' => $session,
			'source'  => null,
		);
	}

	/**
	 * Normalize one provider source into the scan evidence contract.
	 *
	 * @param array<string,mixed> $source      Provider source.
	 * @param string              $provider_id Provider ID.
	 * @param array<string,mixed> $provider    Provider definition.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_source_record( array $source, string $provider_id, array $provider ) {
		$key      = isset( $source['key'] ) && is_string( $source['key'] ) ? trim( $source['key'] ) : '';
		$title    = isset( $source['title'] ) && is_string( $source['title'] ) ? trim( $source['title'] ) : '';
		$type     = isset( $source['type'] ) && is_string( $source['type'] ) ? trim( $source['type'] ) : '';
		$context  = isset( $source['context'] ) && is_string( $source['context'] ) ? $source['context'] : (string) $provider['context'];
		$url      = isset( $source['url'] ) && is_string( $source['url'] ) ? trim( $source['url'] ) : '';
		$edit_url = isset( $source['edit_url'] ) && is_string( $source['edit_url'] ) ? trim( $source['edit_url'] ) : '';
		$base_url = isset( $source['base_url'] ) && is_string( $source['base_url'] ) ? trim( $source['base_url'] ) : $url;

		if ( '' === $type ) {
			$type = (string) $provider['label'];
		}

		if (
			! preg_match( '/^[A-Za-z0-9][A-Za-z0-9:._\/-]{0,299}$/', $key ) ||
			strlen( $title ) > 1000 ||
			strlen( $type ) > 200 ||
			! in_array( $context, array( 'contextual', 'shared' ), true ) ||
			strlen( $url ) > 2048 ||
			strlen( $edit_url ) > 2048 ||
			strlen( $base_url ) > 2048 ||
			( '' !== $url && ! self::is_valid_http_url( $url ) ) ||
			( '' !== $edit_url && ! self::is_valid_http_url( $edit_url ) ) ||
			( '' !== $base_url && ! self::is_valid_http_url( $base_url ) )
		) {
			return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned invalid source identity data.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		if (
			( array_key_exists( 'content', $source ) && ! is_string( $source['content'] ) ) ||
			( array_key_exists( 'links', $source ) && ! is_array( $source['links'] ) ) ||
			( ! array_key_exists( 'content', $source ) && ! array_key_exists( 'links', $source ) ) ||
			( array_key_exists( 'content_item', $source ) && null !== $source['content_item'] && ! is_array( $source['content_item'] ) )
		) {
			return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned invalid stored source data.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$links = null;
		if ( array_key_exists( 'links', $source ) ) {
			$links = array();
			foreach ( $source['links'] as $link ) {
				if ( ! is_array( $link ) || ! isset( $link['href'], $link['anchor'] ) || ! is_string( $link['href'] ) || ! is_string( $link['anchor'] ) || strlen( $link['href'] ) > 2048 || strlen( $link['anchor'] ) > 2000 ) {
					return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned an invalid stored link occurrence.', 'indexlane-redirect-internal-link-auditor' ) );
				}
				$href = trim( $link['href'] );
				if ( self::should_ignore_href( $href ) ) {
					continue;
				}
				$links[] = array(
					'href'   => $href,
					'anchor' => self::normalize_anchor_text( $link['anchor'] ),
					'rel'    => isset( $link['rel'] ) && is_string( $link['rel'] ) ? self::normalize_link_rel( $link['rel'] ) : '',
				);
			}
		}

		$content_item = null;
		if ( isset( $source['content_item'] ) && is_array( $source['content_item'] ) ) {
			$item = $source['content_item'];
			if (
				! isset( $item['id'], $item['title'], $item['type'], $item['url'], $item['edit_url'] ) ||
				(int) $item['id'] <= 0 ||
				! is_string( $item['title'] ) ||
				strlen( $item['title'] ) > 1000 ||
				! is_string( $item['type'] ) ||
				strlen( $item['type'] ) > 200 ||
				! is_string( $item['url'] ) ||
				strlen( $item['url'] ) > 2048 ||
				! is_string( $item['edit_url'] ) ||
				strlen( $item['edit_url'] ) > 2048 ||
				( '' !== $item['url'] && ! self::is_valid_http_url( $item['url'] ) ) ||
				( '' !== $item['edit_url'] && ! self::is_valid_http_url( $item['edit_url'] ) )
			) {
				return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned invalid content target data.', 'indexlane-redirect-internal-link-auditor' ) );
			}
			$content_item = array(
				'id'       => (int) $item['id'],
				'title'    => (string) $item['title'],
				'type'     => (string) $item['type'],
				'url'      => (string) $item['url'],
				'edit_url' => (string) $item['edit_url'],
			);
		}
		$content_id = isset( $source['content_id'] ) ? max( 0, (int) $source['content_id'] ) : 0;
		if ( 'shared' === $context && $content_id > 0 ) {
			return new WP_Error( 'source_provider_invalid', __( 'A shared link source cannot identify itself as a contextual content item.', 'indexlane-redirect-internal-link-auditor' ) );
		}
		if ( is_array( $content_item ) ) {
			if ( 'contextual' !== $context || ( $content_id > 0 && $content_id !== (int) $content_item['id'] ) ) {
				return new WP_Error( 'source_provider_invalid', __( 'A link-source provider returned inconsistent content target data.', 'indexlane-redirect-internal-link-auditor' ) );
			}
			$content_id = (int) $content_item['id'];
		}

		return array(
			'key'          => $key,
			'id'           => isset( $source['id'] ) ? max( 0, (int) $source['id'] ) : 0,
			'content_id'   => $content_id,
			'title'        => '' !== $title ? $title : __( '(no title)', 'indexlane-redirect-internal-link-auditor' ),
			'type'         => $type,
			'type_code'    => $provider_id,
			'context'      => $context,
			'url'          => $url,
			'edit_url'     => $edit_url,
			'base_url'     => '' !== $base_url ? $base_url : home_url( '/' ),
			'content'      => isset( $source['content'] ) && is_string( $source['content'] ) ? $source['content'] : '',
			'links'        => $links,
			'content_item' => $content_item,
		);
	}

	/**
	 * Get links retained directly by a source or encoded in stored block markup.
	 *
	 * @param array<string,mixed> $source Normalized source.
	 * @return array<int,array{href:string,anchor:string,rel?:string}>
	 */
	private static function extract_source_links( array $source ): array {
		if ( is_array( $source['links'] ) ) {
			return $source['links'];
		}

		$content = (string) $source['content'];
		$links   = self::extract_links( $content );
		if ( '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return $links;
		}

		$attribute_links = self::extract_navigation_attribute_links( parse_blocks( $content ) );
		if ( empty( $attribute_links ) ) {
			return $links;
		}

		$stored_counts = array();
		foreach ( $links as $link ) {
			$key = $link['href'] . "\n" . $link['anchor'];
			$stored_counts[ $key ] = isset( $stored_counts[ $key ] ) ? $stored_counts[ $key ] + 1 : 1;
		}

		foreach ( $attribute_links as $link ) {
			$key = $link['href'] . "\n" . $link['anchor'];
			if ( isset( $stored_counts[ $key ] ) && $stored_counts[ $key ] > 0 ) {
				$stored_counts[ $key ]--;
				continue;
			}
			$links[] = $link;
		}

		return $links;
	}

	/**
	 * Extract URL attributes from stored self-closing link blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array{href:string,anchor:string,rel?:string}>
	 */
	private static function extract_navigation_attribute_links( array $blocks ): array {
		$links       = array();
		$link_blocks = array( 'core/navigation-link', 'core/navigation-submenu', 'core/social-link' );

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( in_array( $name, $link_blocks, true ) && isset( $attrs['url'] ) && is_string( $attrs['url'] ) ) {
				$href = trim( wp_specialchars_decode( $attrs['url'], ENT_QUOTES ) );
				if ( ! self::should_ignore_href( $href ) ) {
					$has_stored_anchor = false;
					$inner_html        = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
					foreach ( self::extract_links( $inner_html ) as $stored_link ) {
						if ( $href === trim( wp_specialchars_decode( $stored_link['href'], ENT_QUOTES ) ) ) {
							$has_stored_anchor = true;
							break;
						}
					}

					if ( ! $has_stored_anchor ) {
						$label   = isset( $attrs['label'] ) && is_string( $attrs['label'] ) ? $attrs['label'] : '';
						$links[] = array(
							'href'   => $href,
							'anchor' => self::normalize_anchor_text( $label ),
							'rel'    => isset( $attrs['rel'] ) && is_string( $attrs['rel'] ) ? self::normalize_link_rel( $attrs['rel'] ) : '',
						);
					}
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$links = array_merge( $links, self::extract_navigation_attribute_links( $block['innerBlocks'] ) );
			}
		}

		return $links;
	}

	/**
	 * Snapshot the selected published content corpus with a stable max-ID cursor.
	 *
	 * @param array<string,mixed> $settings Scan settings.
	 * @return array{total_items:int,cursor:array<string,int>}
	 */
	private static function snapshot_content_provider( array $settings ): array {
		$snapshot = self::get_content_snapshot( $settings['post_types'] );
		$total    = 'all' === $settings['content_scope']
			? $snapshot['total_items']
			: min( $snapshot['total_items'], (int) $settings['max_posts'] );

		return array(
			'total_items' => $total,
			'cursor'      => array(
				'before_id' => $snapshot['max_id'] + 1,
				'remaining' => $total,
			),
		);
	}

	/**
	 * Count the selected published corpus and record its highest post ID.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @return array{total_items:int,max_id:int}
	 */
	private static function get_content_snapshot( array $post_types ): array {
		if ( empty( $post_types ) ) {
			return array( 'total_items' => 0, 'max_id' => 0 );
		}

		$query = new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		return array(
			'total_items' => max( 0, (int) $query->found_posts ),
			'max_id'      => ! empty( $query->posts ) ? max( 0, (int) $query->posts[0] ) : 0,
		);
	}

	/**
	 * Read one content source below the persisted keyset cursor.
	 *
	 * @param array<string,mixed> $cursor   Provider cursor.
	 * @param array<string,mixed> $settings Scan settings.
	 * @return array<string,mixed>
	 */
	private static function next_content_provider_source( array $cursor, array $settings ): array {
		$remaining = isset( $cursor['remaining'] ) ? max( 0, (int) $cursor['remaining'] ) : 0;
		if ( 0 === $remaining ) {
			return array( 'source' => null, 'cursor' => $cursor, 'done' => true );
		}

		$posts = self::get_next_source_posts( $settings['post_types'], isset( $cursor['before_id'] ) ? (int) $cursor['before_id'] : 0, 1 );
		if ( empty( $posts ) ) {
			$cursor['remaining'] = 0;
			return array( 'source' => null, 'cursor' => $cursor, 'done' => true );
		}

		$post                = $posts[0];
		$cursor['before_id'] = (int) $post->ID;
		$cursor['remaining'] = $remaining - 1;

		return array(
			'source' => self::source_from_content_post( $post ),
			'cursor' => $cursor,
			'done'   => 0 === $cursor['remaining'],
		);
	}

	/**
	 * Fetch published posts below a keyset cursor.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $before_id  Exclusive upper ID bound.
	 * @param int               $limit      Maximum posts.
	 * @return array<int,WP_Post>
	 */
	private static function get_next_source_posts( array $post_types, int $before_id, int $limit ): array {
		add_filter( 'posts_where', array( __CLASS__, 'filter_scan_cursor_where' ), 10, 2 );
		$query = new WP_Query(
			array(
				'post_type'                      => $post_types,
				'post_status'                    => 'publish',
				'posts_per_page'                 => max( 1, $limit ),
				'orderby'                        => 'ID',
				'order'                          => 'DESC',
				'ignore_sticky_posts'            => true,
				'no_found_rows'                  => true,
				'indexlane_rila_before_post_id' => $before_id,
			)
		);
		remove_filter( 'posts_where', array( __CLASS__, 'filter_scan_cursor_where' ), 10 );

		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Apply the internal keyset cursor to scan-only WP_Query calls.
	 *
	 * @param string   $where SQL WHERE fragment.
	 * @param WP_Query $query Query object.
	 */
	public static function filter_scan_cursor_where( string $where, $query ): string {
		$before_id = absint( $query->get( 'indexlane_rila_before_post_id' ) );
		if ( $before_id <= 0 ) {
			return $where;
		}

		global $wpdb;
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before_id );
	}

	/**
	 * Build a contextual source from one published post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private static function source_from_content_post( $post ): array {
		$url      = get_permalink( $post );
		$edit_url = get_edit_post_link( $post->ID, '' );
		$title    = get_the_title( $post );

		return array(
			'key'          => 'content:' . (string) $post->post_type . ':' . (int) $post->ID,
			'id'           => (int) $post->ID,
			'content_id'   => (int) $post->ID,
			'title'        => is_string( $title ) ? $title : '',
			'type'         => self::get_post_type_label( (string) $post->post_type ),
			'context'      => 'contextual',
			'url'          => $url ? (string) $url : '',
			'edit_url'     => $edit_url ? (string) $edit_url : '',
			'base_url'     => $url ? (string) $url : home_url( '/' ),
			'content'      => (string) $post->post_content,
			'content_item' => array(
				'id'       => (int) $post->ID,
				'title'    => is_string( $title ) ? $title : '',
				'type'     => self::get_post_type_label( (string) $post->post_type ),
				'url'      => $url ? (string) $url : '',
				'edit_url' => $edit_url ? (string) $edit_url : '',
			),
		);
	}

	/**
	 * Snapshot classic menu term IDs.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function snapshot_classic_menu_provider( array $settings ) {
		unset( $settings );
		$menus = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus( array( 'hide_empty' => false ) ) : array();
		if ( is_wp_error( $menus ) ) {
			return new WP_Error( 'source_provider_failed', __( 'WordPress could not read the classic menus for this scan.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$ids = array();
		foreach ( is_array( $menus ) ? $menus : array() as $menu ) {
			if ( isset( $menu->term_id ) && (int) $menu->term_id > 0 ) {
				$ids[] = (int) $menu->term_id;
			}
		}
		sort( $ids, SORT_NUMERIC );

		return self::list_provider_snapshot( $ids );
	}

	/**
	 * Read one classic menu as a shared editable source.
	 */
	private static function next_classic_menu_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		$next = self::next_list_cursor_value( $cursor );
		if ( null === $next['value'] ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => true );
		}

		$menu = function_exists( 'wp_get_nav_menu_object' ) ? wp_get_nav_menu_object( (int) $next['value'] ) : false;
		if ( ! $menu || is_wp_error( $menu ) ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => $next['done'] );
		}

		$items = function_exists( 'wp_get_nav_menu_items' ) ? wp_get_nav_menu_items( $menu ) : array();
		$links = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$url = isset( $item->url ) ? trim( (string) $item->url ) : '';
			if ( self::should_ignore_href( $url ) ) {
				continue;
			}
			$links[] = array(
				'href'   => $url,
				'anchor' => isset( $item->title ) ? self::normalize_anchor_text( (string) $item->title ) : '',
				'rel'    => isset( $item->xfn ) && is_string( $item->xfn ) ? self::normalize_link_rel( (string) $item->xfn ) : '',
			);
		}

		$menu_id = (int) $menu->term_id;
		return array(
			'source' => array(
				'key'      => 'menu:' . $menu_id,
				'id'       => $menu_id,
				'title'    => isset( $menu->name ) ? (string) $menu->name : '',
				'type'     => __( 'Classic menu', 'indexlane-redirect-internal-link-auditor' ),
				'context'  => 'shared',
				'url'      => '',
				'edit_url' => add_query_arg( array( 'action' => 'edit', 'menu' => $menu_id ), admin_url( 'nav-menus.php' ) ),
				'base_url' => home_url( '/' ),
				'links'    => $links,
			),
			'cursor' => $next['cursor'],
			'done'   => $next['done'],
		);
	}

	/**
	 * Snapshot Navigation block entity IDs.
	 */
	private static function snapshot_navigation_provider( array $settings ): array {
		unset( $settings );
		return self::snapshot_post_entity_provider( 'wp_navigation', false );
	}

	/**
	 * Read one Navigation block entity.
	 */
	private static function next_navigation_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		return self::next_post_entity_provider_source( $cursor, 'wp_navigation', 'navigation', __( 'Navigation', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Snapshot synced-pattern post IDs.
	 */
	private static function snapshot_pattern_provider( array $settings ): array {
		unset( $settings );
		return self::snapshot_post_entity_provider( 'wp_block', true );
	}

	/**
	 * Read one synced pattern.
	 */
	private static function next_pattern_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		return self::next_post_entity_provider_source( $cursor, 'wp_block', 'pattern', __( 'Synced pattern', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Snapshot stored post entities used by shared block sources.
	 */
	private static function snapshot_post_entity_provider( string $post_type, bool $synced_only ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return self::list_provider_snapshot( array() );
		}

		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
			)
		);
		$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		if ( $synced_only ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( int $post_id ): bool {
						return 'unsynced' !== (string) get_post_meta( $post_id, 'wp_pattern_sync_status', true );
					}
				)
			);
		}

		return self::list_provider_snapshot( $ids );
	}

	/**
	 * Read one stored shared post entity.
	 */
	private static function next_post_entity_provider_source( array $cursor, string $post_type, string $type_code, string $type_label ): array {
		$next = self::next_list_cursor_value( $cursor );
		if ( null === $next['value'] ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => true );
		}

		$post = get_post( (int) $next['value'] );
		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => $next['done'] );
		}

		$edit_url = get_edit_post_link( $post->ID, '' );
		if ( ! $edit_url && 'wp_navigation' === $post_type ) {
			$edit_url = add_query_arg( array( 'p' => '/wp_navigation/' . (int) $post->ID, 'canvas' => 'edit' ), admin_url( 'site-editor.php' ) );
		} elseif ( ! $edit_url ) {
			$edit_url = add_query_arg( array( 'post' => (int) $post->ID, 'action' => 'edit' ), admin_url( 'post.php' ) );
		}

		return array(
			'source' => array(
				'key'      => $type_code . ':' . (int) $post->ID,
				'id'       => (int) $post->ID,
				'title'    => (string) get_the_title( $post ),
				'type'     => $type_label,
				'context'  => 'shared',
				'url'      => '',
				'edit_url' => (string) $edit_url,
				'base_url' => home_url( '/' ),
				'content'  => (string) $post->post_content,
			),
			'cursor' => $next['cursor'],
			'done'   => $next['done'],
		);
	}

	/**
	 * Snapshot block templates.
	 */
	private static function snapshot_template_provider( array $settings ) {
		unset( $settings );
		return self::snapshot_block_template_provider( 'wp_template' );
	}

	/**
	 * Read one block template.
	 */
	private static function next_template_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		return self::next_block_template_provider_source( $cursor, 'wp_template', 'template', __( 'Block template', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Snapshot block template parts.
	 */
	private static function snapshot_template_part_provider( array $settings ) {
		unset( $settings );
		return self::snapshot_block_template_provider( 'wp_template_part' );
	}

	/**
	 * Read one block template part.
	 */
	private static function next_template_part_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		return self::next_block_template_provider_source( $cursor, 'wp_template_part', 'template_part', __( 'Template part', 'indexlane-redirect-internal-link-auditor' ) );
	}

	/**
	 * Snapshot effective stored block-template IDs.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function snapshot_block_template_provider( string $template_type ) {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return self::list_provider_snapshot( array() );
		}

		$templates = get_block_templates( array(), $template_type );
		if ( ! is_array( $templates ) ) {
			return new WP_Error( 'source_provider_failed', __( 'WordPress could not read the block templates for this scan.', 'indexlane-redirect-internal-link-auditor' ) );
		}

		$ids = array();
		foreach ( $templates as $template ) {
			if ( isset( $template->id ) && is_string( $template->id ) && '' !== $template->id ) {
				$ids[] = $template->id;
			}
		}
		sort( $ids, SORT_STRING );

		return self::list_provider_snapshot( $ids );
	}

	/**
	 * Read one effective block template or part.
	 */
	private static function next_block_template_provider_source( array $cursor, string $template_type, string $type_code, string $type_label ): array {
		$next = self::next_list_cursor_value( $cursor );
		if ( null === $next['value'] ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => true );
		}

		$template_id = (string) $next['value'];
		$template    = function_exists( 'get_block_template' ) ? get_block_template( $template_id, $template_type ) : null;
		if ( ! $template || is_wp_error( $template ) ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => $next['done'] );
		}

		$title = isset( $template->title ) && is_string( $template->title ) ? $template->title : '';
		if ( '' === trim( $title ) && isset( $template->slug ) ) {
			$title = (string) $template->slug;
		}

		return array(
			'source' => array(
				'key'      => $type_code . ':' . $template_id,
				'id'       => 0,
				'title'    => $title,
				'type'     => $type_label,
				'context'  => 'shared',
				'url'      => '',
				'edit_url' => add_query_arg(
					array(
						'postType' => $template_type,
						'postId'   => $template_id,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				),
				'base_url' => home_url( '/' ),
				'content'  => isset( $template->content ) ? (string) $template->content : '',
			),
			'cursor' => $next['cursor'],
			'done'   => $next['done'],
		);
	}

	/**
	 * Snapshot assigned block widget IDs and their widget-area labels.
	 */
	private static function snapshot_widget_provider( array $settings ): array {
		unset( $settings );
		$instances = get_option( 'widget_block', array() );
		$sidebars  = function_exists( 'wp_get_sidebars_widgets' ) ? wp_get_sidebars_widgets() : array();
		if ( ! is_array( $instances ) || ! is_array( $sidebars ) ) {
			return self::list_provider_snapshot( array() );
		}

		global $wp_registered_sidebars;
		$entries = array();
		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || 'array_version' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}
			$area_label = isset( $wp_registered_sidebars[ $sidebar_id ]['name'] )
				? (string) $wp_registered_sidebars[ $sidebar_id ]['name']
				: (string) $sidebar_id;
			foreach ( $widget_ids as $widget_id ) {
				if ( ! is_string( $widget_id ) || ! preg_match( '/^block-([0-9]+)$/', $widget_id, $matches ) ) {
					continue;
				}
				$number = (int) $matches[1];
				if ( ! isset( $instances[ $number ]['content'] ) || ! is_string( $instances[ $number ]['content'] ) || '' === trim( $instances[ $number ]['content'] ) ) {
					continue;
				}
				if ( ! isset( $entries[ $number ] ) ) {
					$entries[ $number ] = array( 'number' => $number, 'areas' => array() );
				}
				$entries[ $number ]['areas'][ $area_label ] = true;
			}
		}

		ksort( $entries, SORT_NUMERIC );
		$values = array();
		foreach ( $entries as $entry ) {
			$areas = array_keys( $entry['areas'] );
			natcasesort( $areas );
			$values[] = array( 'number' => $entry['number'], 'areas' => array_values( $areas ) );
		}

		return self::list_provider_snapshot( $values );
	}

	/**
	 * Read one assigned block widget.
	 */
	private static function next_widget_provider_source( array $cursor, array $settings ): array {
		unset( $settings );
		$next = self::next_list_cursor_value( $cursor );
		if ( null === $next['value'] ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => true );
		}

		$entry     = is_array( $next['value'] ) ? $next['value'] : array();
		$number    = isset( $entry['number'] ) ? max( 0, (int) $entry['number'] ) : 0;
		$areas     = isset( $entry['areas'] ) && is_array( $entry['areas'] ) ? $entry['areas'] : array();
		$instances = get_option( 'widget_block', array() );
		if ( $number <= 0 || ! is_array( $instances ) || ! isset( $instances[ $number ]['content'] ) || ! is_string( $instances[ $number ]['content'] ) ) {
			return array( 'source' => null, 'cursor' => $next['cursor'], 'done' => $next['done'] );
		}

		$area_label = implode( ', ', array_map( 'strval', $areas ) );
		$title      = sprintf(
			/* translators: 1: widget-area name, 2: block widget number */
			__( '%1$s — block widget #%2$d', 'indexlane-redirect-internal-link-auditor' ),
			$area_label,
			$number
		);

		return array(
			'source' => array(
				'key'      => 'widget:block-' . $number,
				'id'       => $number,
				'title'    => $title,
				'type'     => __( 'Block widget', 'indexlane-redirect-internal-link-auditor' ),
				'context'  => 'shared',
				'url'      => '',
				'edit_url' => admin_url( 'widgets.php' ),
				'base_url' => home_url( '/' ),
				'content'  => (string) $instances[ $number ]['content'],
			),
			'cursor' => $next['cursor'],
			'done'   => $next['done'],
		);
	}

	/**
	 * Build a provider snapshot backed by a finite serializable value list.
	 *
	 * @param array<int,mixed> $values Values.
	 * @return array{total_items:int,cursor:array<string,mixed>}
	 */
	private static function list_provider_snapshot( array $values ): array {
		$values = array_values( $values );
		return array(
			'total_items' => count( $values ),
			'cursor'      => array(
				'values' => $values,
				'offset' => 0,
			),
		);
	}

	/**
	 * Advance one finite provider value list.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{value:mixed,cursor:array<string,mixed>,done:bool}
	 */
	private static function next_list_cursor_value( array $cursor ): array {
		$values = isset( $cursor['values'] ) && is_array( $cursor['values'] ) ? array_values( $cursor['values'] ) : array();
		$offset = isset( $cursor['offset'] ) ? max( 0, (int) $cursor['offset'] ) : 0;
		if ( ! array_key_exists( $offset, $values ) ) {
			return array( 'value' => null, 'cursor' => array( 'values' => $values, 'offset' => $offset ), 'done' => true );
		}

		$value  = $values[ $offset ];
		$offset++;

		return array(
			'value'  => $value,
			'cursor' => array( 'values' => $values, 'offset' => $offset ),
			'done'   => $offset >= count( $values ),
		);
	}

	/**
	 * Get a human post-type label for report rows.
	 */
	private static function get_post_type_label( string $post_type ): string {
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && isset( $post_type_object->labels->singular_name ) ) {
			return (string) $post_type_object->labels->singular_name;
		}

		return $post_type;
	}

	/**
	 * Resolve stable provider IDs to their current display labels.
	 *
	 * @param array<int,string> $source_types Provider IDs.
	 * @return array<int,string>
	 */
	private static function source_type_labels( array $source_types ): array {
		$available = self::get_available_source_types();
		$labels    = array();
		foreach ( $source_types as $source_type ) {
			$labels[] = isset( $available[ $source_type ]['label'] ) ? $available[ $source_type ]['label'] : $source_type;
		}

		return $labels;
	}

	/**
	 * Translate a stable source-context code.
	 */
	private static function source_context_label( string $context ): string {
		return 'shared' === $context
			? __( 'Site-wide', 'indexlane-redirect-internal-link-auditor' )
			: __( 'Individual content', 'indexlane-redirect-internal-link-auditor' );
	}
}
