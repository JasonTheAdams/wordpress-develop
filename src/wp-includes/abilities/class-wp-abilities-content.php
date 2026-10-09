<?php
/**
 * Abilities API: WP_Abilities_Content class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 7.2.0
 */

declare( strict_types = 1 );

/**
 * Core class used to register content-related abilities.
 *
 * Registers two read-only abilities for posts of post types exposed to abilities via
 * `show_in_abilities`:
 *
 *   - `core/content-get` gets one readable post through a post reference: by ID, by post type
 *     and slug, by post type and path, or by URL.
 *   - `core/content-query` lists readable posts of one post type, narrowed by the filters
 *     under `where`, sorted by `order`, and paged by page number under `page`.
 *
 * Both take a `select` object that chooses the returned fields. Text fields take a `format`
 * (plain text, rendered HTML, or the stored source), and relations (`author` and `parent`)
 * take a list of their own fields. The stored source is only returned to users who can
 * edit the post.
 *
 * The input shapes nest only where a group has its own rules, its own alternatives, or
 * arguments that belong to one field; single values stay flat. Each input is a plain
 * object at the top level, with any `oneOf` inside one of its properties, since some
 * tool-calling clients reject a `oneOf` at the top level of a tool's input schema.
 *
 * Unlike the other core abilities, which are self-contained closures registered directly
 * in wp_register_core_abilities(), the content abilities live in a dedicated class because
 * their callbacks and schemas share helpers: the permission and execute callbacks resolve
 * and authorize the requested posts through the same code, and both abilities share the
 * field selection, the post output schema, and the field projection. Future write-oriented
 * content abilities can reuse them as well.
 *
 * Only register() is public. The ability callbacks are closures that call private
 * methods, so callers go through the Abilities API, such as
 * `wp_get_ability( 'core/content-get' )->execute()`, which validates the input and checks
 * permissions before running them.
 *
 * @since 7.2.0
 *
 * @access private
 */
final class WP_Abilities_Content {

	/**
	 * The ability category used for content abilities.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of posts returned per page by `core/content-query`.
	 *
	 * @since 7.2.0
	 * @var int
	 */
	private const DEFAULT_PAGE_SIZE = 10;

	/**
	 * Maximum number of posts returned per page by `core/content-query`.
	 *
	 * @since 7.2.0
	 * @var int
	 */
	private const MAX_PAGE_SIZE = 100;

	/**
	 * The text format that returns a field's stored value, which requires edit access.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private const SOURCE_FORMAT = 'source';

	/**
	 * Selectable fields that hold a single value.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	private const VALUE_FIELDS = array( 'type', 'status', 'slug', 'url', 'published_at', 'modified_at', 'password_protected' );

	/**
	 * Selectable text fields, in output order.
	 *
	 * Each names the post type feature it needs, the post column that holds its stored
	 * source, the formats it accepts, and the format returned when none is chosen.
	 *
	 * @since 7.2.0
	 * @var array<string, array{supports: string, column: string, formats: list<string>, default: string}>
	 */
	private const TEXT_FIELDS = array(
		'title'   => array(
			'supports' => 'title',
			'column'   => 'post_title',
			'formats'  => array( 'text', 'html', 'source' ),
			'default'  => 'text',
		),
		'excerpt' => array(
			'supports' => 'excerpt',
			'column'   => 'post_excerpt',
			'formats'  => array( 'text', 'html', 'source' ),
			'default'  => 'text',
		),
		'content' => array(
			'supports' => 'editor',
			'column'   => 'post_content',
			'formats'  => array( 'html', 'text', 'source' ),
			'default'  => 'html',
		),
	);

	/**
	 * Selectable relations and their fields, in output order.
	 *
	 * A relation's `id` is always returned.
	 *
	 * @since 7.2.0
	 * @var array<string, list<string>>
	 */
	private const RELATION_FIELDS = array(
		'author' => array( 'id', 'slug', 'name', 'url' ),
		'parent' => array( 'id', 'slug', 'title', 'url' ),
	);

	/**
	 * The normalized selection used when the caller does not select fields.
	 *
	 * @since 7.2.0
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFAULT_SELECT = array(
		'type'         => array(),
		'status'       => array(),
		'slug'         => array(),
		'url'          => array(),
		'title'        => array( 'format' => 'text' ),
		'published_at' => array(),
	);

	/**
	 * Sort keys accepted by `order.by`, mapped to WP_Query `orderby` values.
	 *
	 * @since 7.2.0
	 * @var array<string, string>
	 */
	private const ORDER_BY = array(
		'published'  => 'date',
		'modified'   => 'modified',
		'title'      => 'title',
		'menu_order' => 'menu_order',
	);

	/**
	 * Globals that rendering a post changes: the global post and the globals that
	 * setup_postdata() populates.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	private const LOOP_GLOBALS = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );

	/**
	 * Registers all content abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook. Post types must be registered with
	 * `show_in_abilities` before the abilities are registered so they are included in
	 * the input schemas.
	 *
	 * @since 7.2.0
	 */
	public function register(): void {
		$post_types = array_values( get_post_types( array( 'show_in_abilities' => true ) ) );
		if ( empty( $post_types ) ) {
			return;
		}

		$this->register_content_get( $post_types );
		$this->register_content_query( $post_types );

		/*
		 * Future write-oriented content abilities can be registered here, reusing the
		 * shared helpers below (resolve_post_reference(), format_post(), get_select_input_schema()).
		 */
	}

	/**
	 * Registers the read-only `core/content-get` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 */
	private function register_content_get( array $post_types ): void {
		wp_register_ability(
			'core/content-get',
			array(
				'label'               => __( 'Get Content' ),
				'description'         => __( 'Gets one readable post, page, or other content item of a post type exposed to abilities. Reference it by ID, by post type and slug, by post type and path, or by URL. Use `select` to choose the returned fields and the format of text fields. Requires an authenticated user.' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_get_input_schema( $post_types ),
				'output_schema'       => $this->get_post_output_schema(),
				'execute_callback'    => function ( $input = array() ) {
					return $this->execute_content_get( $input );
				},
				'permission_callback' => function ( $input = array() ): bool {
					return $this->check_content_get_permission( $input );
				},
				'meta'                => $this->get_ability_meta(),
			)
		);
	}

	/**
	 * Registers the read-only `core/content-query` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 */
	private function register_content_query( array $post_types ): void {
		/*
		 * Internal statuses (e.g. `inherit`) are excluded, so post types that rely on
		 * them (attachments) are only reachable through `core/content-get`. Revisit if such
		 * a post type is ever exposed via `show_in_abilities`.
		 */
		$statuses = array_values( get_post_stati( array( 'internal' => false ) ) );

		wp_register_ability(
			'core/content-query',
			array(
				'label'               => __( 'Query Content' ),
				'description'         => __( 'Lists readable posts, pages, or other content items of one post type exposed to abilities. Narrow the results with `where` (status, author, parent, or IDs), sort them with `order`, and page through them with `page`. Use `select` to choose the returned fields and the format of text fields. Requires an authenticated user. Filters are exact-match only; the ability does not perform full-text search.' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_query_input_schema( $post_types, $statuses ),
				'output_schema'       => $this->get_content_query_output_schema(),
				'execute_callback'    => function ( $input = array() ) {
					return $this->execute_content_query( $input );
				},
				'permission_callback' => function ( $input = array() ): bool {
					return $this->check_content_query_permission( $input );
				},
				'meta'                => $this->get_ability_meta(),
			)
		);
	}

	/**
	 * Returns the meta shared by the read-only content abilities.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The ability meta.
	 */
	private function get_ability_meta(): array {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'public'      => true,
		);
	}

	/**
	 * Permission callback for the `core/content-get` ability.
	 *
	 * This gate is the authoritative permission decision: it resolves the referenced post
	 * and denies missing, mismatched, or unreadable posts before execution. Requests that
	 * select the stored source of a text field require edit access to the post.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	private function check_content_get_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post = $this->resolve_post_reference( $input['post'] ?? null );
		if ( ! $post ) {
			return false;
		}

		if ( $this->requires_edit( $this->normalize_select( $input ) ) ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		return $this->check_read_permission( $post );
	}

	/**
	 * Permission callback for the `core/content-query` ability.
	 *
	 * The query is only gated coarsely here (collection status capabilities);
	 * {@see self::execute_content_query()} enforces row-level read/edit permissions, since
	 * individual rows are unknown until the query runs. Requests that select the stored
	 * source of a text field require edit access to the post type.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	private function check_content_query_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$where            = $this->get_object_value( $input['where'] ?? null ) ?? array();
		$post_type_object = $this->get_queried_post_type( $where );
		if ( ! $post_type_object ) {
			return false;
		}

		if ( $this->requires_edit( $this->normalize_select( $input ) ) ) {
			return current_user_can( $post_type_object->cap->edit_posts );
		}

		return $this->can_query_statuses( $this->normalize_statuses( $where ), $post_type_object );
	}

	/**
	 * Parses a raw input value into an integer of at least a minimum, or null when invalid.
	 *
	 * Accepts the whole numbers that the integer schema accepts: native integers, floats such
	 * as the 2.0 that JSON encoders produce, and integer strings such as "2", "2.0", or "+2".
	 * Only the REST run controller converts input to the schema types, so other callers, such
	 * as the MCP adapter or a direct WP_Ability::execute() call, pass them on unconverted.
	 * Anything else, such as a fraction, is rejected rather than coerced: a malformed ID would
	 * otherwise resolve to another post, and a malformed `parent` filter would be read as 0,
	 * which asks for top-level posts.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $value The raw input value.
	 * @param int   $min   The smallest acceptable value.
	 * @return int|null The parsed integer, or null when the value is not an integer >= $min.
	 */
	private function parse_filter_int( $value, int $min ): ?int {
		if ( is_int( $value ) ) {
			return $value >= $min ? $value : null;
		}

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$int = (int) $value;

			return $int >= $min ? $int : null;
		}

		if ( ! rest_is_integer( $value ) ) {
			return null;
		}

		/*
		 * Other whole numbers are read as floats, which hold every integer only up to 2 ** 53.
		 * Past that, the value may not be the one that was sent, and casting a float beyond the
		 * integer range wraps it to an arbitrary integer, such as the ID of another post.
		 */
		$number = (float) $value;

		return $number >= $min && $number <= 2 ** 53 ? (int) $number : null;
	}

	/**
	 * Parses a raw list value into a list of strings.
	 *
	 * Like schema validation, it also accepts a scalar or a comma-separated string, and
	 * parses it the same way, with wp_parse_list().
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $value The raw list value.
	 * @return list<string> The parsed string values; empty when absent or unparseable.
	 */
	private function parse_list_value( $value ): array {
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Returns a raw object value as an associative array, or null when it is not an object.
	 *
	 * Nested values are not converted by rest_sanitize_object() on the input, so a caller
	 * that decodes JSON into objects, such as a direct WP_Ability::execute() call, may pass
	 * a nested stdClass.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $value The raw value.
	 * @return array<mixed>|null The object's properties, or null when the value is not an object.
	 */
	private function get_object_value( $value ): ?array {
		return is_array( $value ) || $value instanceof stdClass ? rest_sanitize_object( $value ) : null;
	}

	/**
	 * Checks whether the selection asks for the stored source of any text field.
	 *
	 * The stored source is an edit-context value. Fields that are not selected are not
	 * treated as edit intent.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, array<string, mixed>> $select The normalized selection.
	 * @return bool True if the stored source of a text field was requested.
	 */
	private function requires_edit( array $select ): bool {
		foreach ( array_keys( self::TEXT_FIELDS ) as $field ) {
			if ( isset( $select[ $field ] ) && self::SOURCE_FORMAT === $select[ $field ]['format'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether the current user may query the requested statuses.
	 *
	 * This mirrors the REST posts controller's conservative collection-status gate:
	 * requesting non-default statuses requires edit access, except `private`, which
	 * may be queried by users who can read private posts.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $statuses         The requested statuses.
	 * @param WP_Post_Type $post_type_object The post type object.
	 * @return bool True if the requested statuses may be queried.
	 */
	private function can_query_statuses( array $statuses, WP_Post_Type $post_type_object ): bool {
		foreach ( $statuses as $status ) {
			if ( 'publish' === $status ) {
				continue;
			}

			if ( 'private' === $status && current_user_can( $post_type_object->cap->read_private_posts ) ) {
				continue;
			}

			if ( current_user_can( $post_type_object->cap->edit_posts ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if a post can be read by the current user.
	 *
	 * Mirrors the REST posts controller's read permission, while the permission callbacks
	 * keep the content abilities authenticated-only.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post          $post             Post object.
	 * @param array<int, true> $checked_post_ids Post IDs already checked while walking inherited parents.
	 * @return bool Whether the post can be read.
	 */
	private function check_read_permission( WP_Post $post, array $checked_post_ids = array() ): bool {
		if ( isset( $checked_post_ids[ $post->ID ] ) ) {
			return false;
		}

		$checked_post_ids[ $post->ID ] = true;

		if ( ! $this->get_exposed_post_type( $post->post_type ) ) {
			return false;
		}

		/*
		 * Treat publicly viewable posts as readable. This checks both the post type
		 * and post status using Core's viewability helpers, which is stricter than
		 * checking the status object's `public` flag alone.
		 */
		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		/*
		 * Use the normalized status for the status object lookup. For attachments,
		 * get_post_status() resolves `inherit` through the parent before returning.
		 */
		$post_status = get_post_status( $post );
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status_object = get_post_status_object( $post_status );
		if ( ! $post_status_object instanceof stdClass ) {
			return false;
		}

		/*
		 * Core maps `read_post` for public statuses to the post type's plain `read`
		 * capability. Publicly viewable posts already returned above, so a remaining
		 * public status is public but not viewable and should require edit access.
		 */
		if ( $post_status_object->public ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		/*
		 * For non-public statuses, defer to Core's meta-capability mapping. This
		 * handles own drafts, private posts, and statuses that require edit access.
		 */
		if ( current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		/*
		 * Mirror the REST posts controller's inherited-parent behavior, but keep the
		 * ability fail-closed for missing parents or parent loops.
		 */
		if ( 'inherit' === $post->post_status && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return $this->check_read_permission( $parent, $checked_post_ids );
			}
		}

		return false;
	}

	/**
	 * Executes the `core/content-get` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error The post, or a WP_Error when it cannot be resolved.
	 */
	private function execute_content_get( $input = array() ) {
		$input = rest_sanitize_object( $input );
		$post  = $this->resolve_post_reference( $input['post'] ?? null );

		return $post ? $this->format_post( $post, $this->normalize_select( $input ) ) : $this->not_found_error();
	}

	/**
	 * Executes the `core/content-query` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error A `posts` list with totals, or a WP_Error.
	 */
	private function execute_content_query( $input = array() ) {
		$input         = rest_sanitize_object( $input );
		$where         = $this->get_object_value( $input['where'] ?? null ) ?? array();
		$order         = $this->get_object_value( $input['order'] ?? null ) ?? array();
		$paging        = $this->get_object_value( $input['page'] ?? null ) ?? array();
		$select        = $this->normalize_select( $input );
		$requires_edit = $this->requires_edit( $select );

		if ( count( $this->parse_list_value( $where['type'] ?? null ) ) > 1 ) {
			return $this->invalid_filter_error( 'where.type', __( 'Only one post type can be queried at a time.' ) );
		}

		$post_type_object = $this->get_queried_post_type( $where );
		if ( ! $post_type_object ) {
			return $this->not_found_error();
		}

		$post_type = $post_type_object->name;

		/*
		 * REST only registers the equivalent collection filters for post types that
		 * support them; a shared input schema cannot express that per post type. On
		 * transports that skip schema validation a malformed value would otherwise
		 * coerce to a benign default and silently *widen* the query (`author => 0`
		 * drops the author filter, an empty `post__in` is ignored, `post_parent => 0`
		 * becomes a top-level query). Reject unsupported filters and invalid filter
		 * values loudly so a filter that cannot be honored fails closed instead.
		 */
		$top_level = isset( $where['top_level'] ) && rest_sanitize_boolean( $where['top_level'] );
		$parent    = null;
		if ( isset( $where['parent'] ) || $top_level ) {
			$filter = isset( $where['parent'] ) ? 'where.parent' : 'where.top_level';

			if ( ! is_post_type_hierarchical( $post_type ) ) {
				return $this->invalid_filter_error(
					$filter,
					/* translators: %s: Parameter. */
					sprintf( __( 'The %s filter is only supported for hierarchical post types.' ), $filter )
				);
			}

			if ( isset( $where['parent'] ) && $top_level ) {
				return $this->invalid_filter_error(
					'where.top_level',
					/* translators: 1: Parameter, 2: Parameter. */
					sprintf( __( 'The %1$s filter cannot be combined with the %2$s filter.' ), 'where.top_level', 'where.parent' )
				);
			}

			$parent = $top_level ? 0 : $this->resolve_parent_filter( $where['parent'], $post_type_object );
			if ( null === $parent ) {
				return $this->invalid_filter_error(
					'where.parent',
					/* translators: %s: Parameter. */
					sprintf( __( 'The %s filter must reference a post by a positive integer ID, or by the path of a readable post of the queried post type.' ), 'where.parent' )
				);
			}
		}

		$author = null;
		if ( isset( $where['author'] ) ) {
			if ( ! post_type_supports( $post_type, 'author' ) ) {
				return $this->invalid_filter_error(
					'where.author',
					/* translators: %s: Parameter. */
					sprintf( __( 'The %s filter is only supported for post types that support authors.' ), 'where.author' )
				);
			}

			$author = $this->resolve_author_filter( $where['author'], $post_type_object );
			if ( ! $author ) {
				return $this->invalid_filter_error(
					'where.author',
					/* translators: %s: Parameter. */
					sprintf( __( 'The %s filter must reference an existing user.' ), 'where.author' )
				);
			}
		}

		$ids = $this->normalize_ids( $where );

		/*
		 * An `ids` filter that was supplied but parsed to no valid IDs must not fall
		 * through to an unrestricted query: WP_Query ignores an empty `post__in`, which
		 * would return every post of the type — the opposite of the caller's intent.
		 */
		if ( isset( $where['ids'] ) && array() === $ids ) {
			return $this->invalid_filter_error(
				'where.ids',
				/* translators: %s: Parameter. */
				sprintf( __( 'The %s filter must list one or more valid post IDs.' ), 'where.ids' )
			);
		}

		$per_page = $this->normalize_page_size( $paging, $ids );
		$page     = $this->parse_filter_int( $paging['number'] ?? 1, 1 ) ?? 1;

		$order_by  = is_string( $order['by'] ?? null ) && isset( self::ORDER_BY[ $order['by'] ] ) ? self::ORDER_BY[ $order['by'] ] : 'date';
		$direction = is_string( $order['direction'] ?? null ) && 'asc' === strtolower( $order['direction'] ) ? 'ASC' : 'DESC';

		$prime_post_caches = $this->should_prime_post_caches( $select );

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => $this->normalize_statuses( $where ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => array( $order_by => $direction ),
			'perm'                   => $requires_edit ? 'editable' : 'readable',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => $prime_post_caches,
			'update_post_term_cache' => $prime_post_caches,
		);

		if ( array() !== $ids ) {
			$query_args['post__in'] = $ids;
		}

		if ( null !== $author ) {
			$query_args['author'] = $author->ID;
		}

		if ( null !== $parent ) {
			$query_args['post_parent'] = $parent;
		}

		$query = new WP_Query( $query_args );
		$total = $this->get_query_total( $query, $query_args, $page );

		/*
		 * Count the pages with the page size the query ran with, as the REST posts controller
		 * does, since query filters such as `pre_get_posts` callbacks can change it. Without
		 * paging, the query returns every post on one page.
		 */
		$query_per_page = (int) $query->get( 'posts_per_page' );
		if ( $query->get( 'nopaging' ) || $query_per_page < 1 ) {
			$total_pages = $total > 0 ? 1 : 0;
		} else {
			$total_pages = (int) ceil( $total / $query_per_page );
		}

		/*
		 * A page past the last one does not exist, so report it as not found instead of
		 * returning a bare empty list. A genuinely empty result set still returns zero
		 * totals and no error.
		 */
		if ( $total > 0 && $page > $total_pages ) {
			return new WP_Error(
				'content_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Prime the parent and author caches with a single query each instead of one
		 * lookup per post, as the REST posts controller does. Hierarchical permalinks,
		 * inherited read permissions, and the `parent` relation read the parent, and
		 * format_post() sets up every post with setup_postdata(), which reads the author.
		 */
		update_post_parent_caches( $query->posts );
		update_post_author_caches( $query->posts );

		$posts = array();
		foreach ( $query->posts as $post ) {
			/*
			 * Skip posts of other post types, which query filters can add, such as a
			 * `pre_get_posts` callback that adds post types to the blog home without an
			 * is_main_query() check.
			 */
			if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
				continue;
			}
			if ( $requires_edit && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			if ( ! $requires_edit && ! $this->check_read_permission( $post ) ) {
				continue;
			}
			$posts[] = $this->format_post( $post, $select );
		}

		/*
		 * Mirror the REST posts controller: totals come from the underlying WP_Query,
		 * while the row-level checks above may withhold individual returned rows.
		 */
		return array(
			'posts'       => $posts,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Normalizes the requested page size to the supported bounds.
	 *
	 * An explicit `page.size` always wins. Otherwise a `where.ids` request pages to the
	 * number of requested IDs, so a caller loading a known set of posts receives all of
	 * them in one call rather than silently losing the ones past the default page size.
	 * The input schema caps `where.ids` at {@see self::MAX_PAGE_SIZE} so it always fits.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $paging The `page` input.
	 * @param list<int>    $ids    Normalized post IDs to limit the query to; empty when not requested.
	 * @return int The clamped page size.
	 */
	private function normalize_page_size( array $paging, array $ids ): int {
		$size = $this->parse_filter_int( $paging['size'] ?? null, 1 );
		if ( null === $size ) {
			$size = array() === $ids ? self::DEFAULT_PAGE_SIZE : count( $ids );
		}

		return min( self::MAX_PAGE_SIZE, $size );
	}

	/**
	 * Returns the query total, recovering it when WP_Query skipped the count.
	 *
	 * WP_Query leaves `found_posts` at 0 when a requested page has no rows. Re-run a
	 * minimal unpaged query so the caller can distinguish an out-of-range page from
	 * an empty result set, matching the REST posts controller behavior.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Query     $query      The executed query.
	 * @param array<mixed> $query_args The arguments used for the executed query.
	 * @param int          $page       The requested page.
	 * @return int Total matching rows across all pages.
	 */
	private function get_query_total( WP_Query $query, array $query_args, int $page ): int {
		$total = (int) $query->found_posts;

		if ( $total > 0 || $page <= 1 ) {
			return $total;
		}

		$count_args                           = $query_args;
		$count_args['fields']                 = 'ids';
		$count_args['posts_per_page']         = 1;
		$count_args['update_post_meta_cache'] = false;
		$count_args['update_post_term_cache'] = false;
		unset( $count_args['paged'], $count_args['no_found_rows'] );

		$count_query = new WP_Query( $count_args );

		return (int) $count_query->found_posts;
	}

	/**
	 * Checks whether the selected fields benefit from page-level cache priming.
	 *
	 * Rendered excerpts and content, in HTML or as plain text, may read post meta or
	 * terms, and permalinks read terms when the permalink structure contains
	 * `%category%`. Other fields, such as the title or the stored source, do not need
	 * that cache priming.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, array<string, mixed>> $select The normalized selection.
	 * @return bool True when post meta and term caches should be primed.
	 */
	private function should_prime_post_caches( array $select ): bool {
		if ( isset( $select['url'] ) ) {
			return true;
		}

		foreach ( array( 'excerpt', 'content' ) as $field ) {
			if ( isset( $select[ $field ] ) && self::SOURCE_FORMAT !== $select[ $field ]['format'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a post reference to the post it names.
	 *
	 * A reference names a post in exactly one way:
	 *
	 *   - `id`, optionally guarded by `type`. As with the integer filters, the ID is read
	 *     with {@see self::parse_filter_int()}, so a malformed ID or one beyond the integer
	 *     range cannot be coerced onto another post.
	 *   - `type` and `slug`, resolved by {@see self::get_post_by_slug()}.
	 *   - `type` and `path`, resolved by {@see self::get_post_by_path()}.
	 *   - `url`, resolved with url_to_postid(), so only URLs of this site resolve.
	 *
	 * The post must belong to a post type exposed to abilities. Whether the current user
	 * may read it is checked by the permission callback.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $reference The raw post reference.
	 * @return WP_Post|null The post, or null when the reference cannot be resolved.
	 */
	private function resolve_post_reference( $reference ): ?WP_Post {
		$reference = $this->get_object_value( $reference );
		if ( null === $reference ) {
			return null;
		}

		if ( isset( $reference['id'] ) ) {
			$post_id = $this->parse_filter_int( $reference['id'], 1 );

			return null === $post_id ? null : $this->get_exposed_post( get_post( $post_id ), $reference['type'] ?? null );
		}

		if ( isset( $reference['url'] ) ) {
			$post_id = is_string( $reference['url'] ) ? url_to_postid( $reference['url'] ) : 0;

			return $post_id > 0 ? $this->get_exposed_post( get_post( $post_id ), null ) : null;
		}

		$post_type_object = $this->get_exposed_post_type( $reference['type'] ?? null );
		if ( ! $post_type_object ) {
			return null;
		}

		if ( isset( $reference['slug'] ) ) {
			return is_string( $reference['slug'] ) ? $this->get_post_by_slug( $post_type_object->name, $reference['slug'] ) : null;
		}

		if ( isset( $reference['path'] ) ) {
			return is_string( $reference['path'] ) ? $this->get_post_by_path( $post_type_object, $reference['path'] ) : null;
		}

		return null;
	}

	/**
	 * Returns a post when it belongs to a post type exposed to abilities and matches an optional type guard.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $post      The post, as get_post() returned it.
	 * @param mixed $post_type Optional post type the post must belong to; empty for none.
	 * @return WP_Post|null The post, or null when it does not qualify.
	 */
	private function get_exposed_post( $post, $post_type ): ?WP_Post {
		if ( ! $post instanceof WP_Post || ! $this->get_exposed_post_type( $post->post_type ) ) {
			return null;
		}

		return empty( $post_type ) || $post->post_type === $post_type ? $post : null;
	}

	/**
	 * Returns the post type a `core/content-query` request queries.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $where The `where` input.
	 * @return WP_Post_Type|null The post type object, or null unless exactly one exposed post type is requested.
	 */
	private function get_queried_post_type( array $where ): ?WP_Post_Type {
		$post_types = $this->parse_list_value( $where['type'] ?? null );

		return 1 === count( $post_types ) ? $this->get_exposed_post_type( $post_types[0] ) : null;
	}

	/**
	 * Resolves the `where.parent` filter to a parent post ID.
	 *
	 * A parent referenced by ID is not looked up: a query for the children of a post that
	 * does not exist, or that the current user cannot read, returns the readable children
	 * there are, as the REST API's `parent` filter does. A parent referenced by path has to
	 * be looked up, so it must be a readable post of the queried post type; one the current
	 * user cannot read is reported like a missing one.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed        $reference        The raw parent reference.
	 * @param WP_Post_Type $post_type_object The queried post type.
	 * @return int|null The parent post ID, or null when the reference is invalid.
	 */
	private function resolve_parent_filter( $reference, WP_Post_Type $post_type_object ): ?int {
		$reference = $this->get_object_value( $reference );
		if ( null === $reference ) {
			return null;
		}

		if ( isset( $reference['id'] ) ) {
			return $this->parse_filter_int( $reference['id'], 1 );
		}

		if ( ! isset( $reference['path'] ) || ! is_string( $reference['path'] ) ) {
			return null;
		}

		$parent = $this->get_post_by_path( $post_type_object, $reference['path'] );

		return $parent && $this->check_read_permission( $parent ) ? $parent->ID : null;
	}

	/**
	 * Resolves the `where.author` filter to the user it references.
	 *
	 * A user is referenced by ID, by slug, or as the current user. The slug is the user's
	 * nicename, which the REST API users endpoint returns as `slug`. It is looked up as
	 * given, like that endpoint's `slug` filter, so the database collation decides whether
	 * a variant in another case matches.
	 *
	 * A user the current user may not see is reported like a missing one; see
	 * {@see self::can_see_author()}.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed        $reference        The raw author reference.
	 * @param WP_Post_Type $post_type_object The post type the author is looked up for.
	 * @return WP_User|null The user, or null when the reference does not name a visible user.
	 */
	private function resolve_author_filter( $reference, WP_Post_Type $post_type_object ): ?WP_User {
		$reference = $this->get_object_value( $reference );
		if ( null === $reference ) {
			return null;
		}

		if ( isset( $reference['current_user'] ) ) {
			$user = rest_sanitize_boolean( $reference['current_user'] ) ? wp_get_current_user() : null;

			return $user instanceof WP_User && $user->exists() ? $user : null;
		}

		$user = false;
		if ( isset( $reference['id'] ) ) {
			$user_id = $this->parse_filter_int( $reference['id'], 1 );
			$user    = null === $user_id ? false : get_userdata( $user_id );
		} elseif ( isset( $reference['slug'] ) && is_string( $reference['slug'] ) ) {
			$user = get_user_by( 'slug', $reference['slug'] );
		}

		return $user instanceof WP_User && $this->can_see_author( $user, $post_type_object ) ? $user : null;
	}

	/**
	 * Checks whether the current user may see a user, so it can name them in an author filter.
	 *
	 * The current user can see themselves, any user of the site when they can list users,
	 * and authors with posts in a publicly viewable post type. A user who can edit others'
	 * posts of the post type can also see any user of the site, since they may make any of
	 * them the author, so for them the lookup does tell whether such an account exists.
	 *
	 * On multisite the lookup searches the whole network, so posts by authors who are not
	 * members of the site, such as super admins, can still be filtered. Those users are only
	 * visible as the current user or as authors with posts in a publicly viewable post type, so
	 * the lookup does not tell whether an account exists elsewhere on the network.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_User      $user             The user.
	 * @param WP_Post_Type $post_type_object The post type the author is looked up for.
	 * @return bool Whether the current user may see the user.
	 */
	private function can_see_author( WP_User $user, WP_Post_Type $post_type_object ): bool {
		if ( get_current_user_id() === $user->ID ) {
			return true;
		}

		// The capabilities only reveal users of the site, not of the whole network.
		if ( ( ! is_multisite() || is_user_member_of_blog( $user->ID ) )
			&& ( current_user_can( 'list_users' ) || current_user_can( $post_type_object->cap->edit_others_posts ) )
		) {
			return true;
		}

		$public_post_types = array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );

		return array() !== $public_post_types && count_user_posts( $user->ID, $public_post_types ) > 0;
	}

	/**
	 * Looks up the single post a slug request resolves to.
	 *
	 * Slugs are not unique across statuses (drafts skip slug uniqueness), so the
	 * lookup returns the newest match the current user can read, preferring
	 * publicly viewable posts — a newer draft sharing the slug cannot shadow a
	 * published post. This mirrors the REST API, where slug queries default to
	 * the `publish` status. Which post a slug resolves to is independent of the
	 * selected fields; requests for the stored source are gated afterwards on the
	 * resolved post by {@see self::check_content_get_permission()}.
	 *
	 * In hierarchical post types, posts under different parents can also share a
	 * slug. The lookup cannot tell them apart and resolves to one of them by the
	 * same rules, so callers that need a specific post should look it up by path or ID.
	 *
	 * @since 7.2.0
	 *
	 * @param string $post_type The post type.
	 * @param string $slug      The post slug.
	 * @return WP_Post|null The matching readable post, or null when none exists.
	 */
	private function get_post_by_slug( string $post_type, string $slug ): ?WP_Post {
		$name = sanitize_title( $slug );
		if ( '' === $name ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'name'                   => $name,
				'post_status'            => array_values( get_post_stati( array( 'internal' => false ) ) ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		// Candidates come newest first; a publicly viewable post is always readable here.
		$readable = null;
		foreach ( $query->posts as $candidate ) {
			/*
			 * Skip posts of other post types, which query filters can add, such as a
			 * `pre_get_posts` callback that adds post types to single views without an
			 * is_main_query() check, since a `name` query is a single view.
			 */
			if ( ! $candidate instanceof WP_Post || $post_type !== $candidate->post_type ) {
				continue;
			}

			if ( is_post_publicly_viewable( $candidate ) ) {
				return $candidate;
			}

			if ( null === $readable && $this->check_read_permission( $candidate ) ) {
				$readable = $candidate;
			}
		}

		return $readable;
	}

	/**
	 * Looks up the single post a path request resolves to.
	 *
	 * A path is the slash-separated slugs of a post and its ancestors, such as
	 * `about/team`, so unlike a slug it tells apart posts under different parents.
	 * get_page_by_path() resolves it and prefers a published post when several match.
	 * Posts in internal statuses, such as `trash`, are not resolved, as in a slug lookup.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post_Type $post_type_object The post type, which must be hierarchical.
	 * @param string       $path             The post path.
	 * @return WP_Post|null The matching post, or null when none exists.
	 */
	private function get_post_by_path( WP_Post_Type $post_type_object, string $path ): ?WP_Post {
		$path = trim( $path, '/' );
		if ( ! $post_type_object->hierarchical || '' === $path ) {
			return null;
		}

		$post = get_page_by_path( $path, OBJECT, $post_type_object->name );
		if ( ! $post instanceof WP_Post || $post_type_object->name !== $post->post_type ) {
			return null;
		}

		return in_array( $post->post_status, get_post_stati( array( 'internal' => true ) ), true ) ? null : $post;
	}

	/**
	 * Returns a post type exposed through the Abilities API.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $post_type The post type name.
	 * @return WP_Post_Type|null The post type object, or null when the post type is not exposed.
	 */
	private function get_exposed_post_type( $post_type ): ?WP_Post_Type {
		$post_type_object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;

		return empty( $post_type_object->show_in_abilities ) ? null : $post_type_object;
	}

	/**
	 * Normalizes the requested statuses to a non-empty, sanitized list defaulting to publish.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $where The `where` input.
	 * @return list<string> Normalized list of post status slugs.
	 */
	private function normalize_statuses( array $where ): array {
		$statuses = $this->parse_list_value( $where['status'] ?? null );

		return array() === $statuses ? array( 'publish' ) : array_map( 'sanitize_key', $statuses );
	}

	/**
	 * Normalizes the post IDs a query is limited to.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $where The `where` input.
	 * @return list<int> Unique positive post IDs.
	 */
	private function normalize_ids( array $where ): array {
		$ids = $where['ids'] ?? null;
		if ( ! is_array( $ids ) && ! is_string( $ids ) && ! is_int( $ids ) ) {
			return array();
		}

		/*
		 * wp_parse_id_list() also parses a single ID or a comma-separated string, as
		 * schema validation does.
		 */
		return array_values( array_filter( wp_parse_id_list( $ids ) ) );
	}

	/**
	 * Normalizes the `select` input into the fields to return and their options.
	 *
	 * An absent or empty `select` returns a lean set of common fields. Otherwise each
	 * field set to true, or to an object of options, is returned, and fields set to
	 * false are not. A text field gets its chosen format, or its default format when none
	 * is chosen. A relation gets its chosen fields, or all of them when none are chosen,
	 * and always its `id`. The `id` of the post itself is always returned, so it is not
	 * part of the selection.
	 *
	 * Schema validation has already rejected unknown fields, formats, and relation fields
	 * on validated transports. Callers that skip it get the defaults instead.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, array<string, mixed>> The selected fields, keyed by name, with their options.
	 */
	private function normalize_select( array $input ): array {
		$select = $this->get_object_value( $input['select'] ?? null );
		if ( null === $select || array() === $select ) {
			return self::DEFAULT_SELECT;
		}

		$normalized = array();

		foreach ( self::VALUE_FIELDS as $field ) {
			if ( $this->is_selected( $select, $field ) ) {
				$normalized[ $field ] = array();
			}
		}

		foreach ( self::TEXT_FIELDS as $field => $definition ) {
			if ( ! $this->is_selected( $select, $field ) ) {
				continue;
			}

			$options = $this->get_object_value( $select[ $field ] ) ?? array();
			$format  = $options['format'] ?? null;

			$normalized[ $field ] = array(
				'format' => in_array( $format, $definition['formats'], true ) ? $format : $definition['default'],
			);
		}

		foreach ( self::RELATION_FIELDS as $field => $relation_fields ) {
			if ( ! $this->is_selected( $select, $field ) ) {
				continue;
			}

			$options   = $this->get_object_value( $select[ $field ] ) ?? array();
			$requested = $this->parse_list_value( $options['fields'] ?? null );

			$normalized[ $field ] = array(
				'fields' => array() === $requested
					? $relation_fields
					: array_values( array_intersect( $relation_fields, array_merge( array( 'id' ), $requested ) ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Checks whether a field is selected in a raw `select` input.
	 *
	 * A field is selected when it is set to true, or to an object of options. Like schema
	 * validation, it accepts the string forms of booleans that GET requests deliver, and
	 * reads an empty string as an empty object.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $select The raw `select` input.
	 * @param string       $field  The field name.
	 * @return bool Whether the field is selected.
	 */
	private function is_selected( array $select, string $field ): bool {
		if ( ! array_key_exists( $field, $select ) ) {
			return false;
		}

		$value = $select[ $field ];

		return null !== $this->get_object_value( $value ) || '' === $value || rest_sanitize_boolean( $value );
	}

	/**
	 * Builds the schema of the `select` input, shared by all content abilities.
	 *
	 * Each field takes true, or an object of options for fields that have them. The
	 * `boolean` type is listed before `object` on purpose: the validator picks the first
	 * type a value matches, and an empty string also counts as an object.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The `select` JSON Schema.
	 */
	private function get_select_input_schema(): array {
		$value_descriptions = array(
			'type'               => __( 'Return the post type.' ),
			'status'             => __( 'Return the post status.' ),
			'slug'               => __( 'Return the post slug.' ),
			'url'                => __( 'Return the permalink URL.' ),
			'published_at'       => __( 'Return the publication date.' ),
			'modified_at'        => __( 'Return the last modified date.' ),
			'password_protected' => __( 'Return whether the post is protected with a password.' ),
		);

		$text_descriptions = array(
			'title'   => __( 'Return the title. True returns plain text. Choose a `format` for another one: `text`, `html` as the site renders it, or `source`, the stored title, which requires edit access.' ),
			'excerpt' => __( 'Return the excerpt. True returns plain text. Choose a `format` for another one: `text`, `html` as the site renders it, or `source`, the stored excerpt, which requires edit access.' ),
			'content' => __( 'Return the content. True returns HTML as the site renders it. Choose a `format` for another one: `html`, `text` with the markup removed, or `source`, the stored block markup, which requires edit access.' ),
		);

		$relation_descriptions = array(
			'author' => __( 'Return the author. True returns all of its fields; choose them with `fields`. The `id` is always included.' ),
			'parent' => __( 'Return the parent post, or null for a top-level post. True returns all of its fields; choose them with `fields`. The `id` is always included, and the other fields only when the parent is readable.' ),
		);

		$properties = array();

		foreach ( self::VALUE_FIELDS as $field ) {
			$properties[ $field ] = array(
				'type'        => 'boolean',
				'description' => $value_descriptions[ $field ],
			);
		}

		foreach ( self::TEXT_FIELDS as $field => $definition ) {
			$properties[ $field ] = array(
				'type'                 => array( 'boolean', 'object' ),
				'additionalProperties' => false,
				'properties'           => array(
					'format' => array(
						'type' => 'string',
						'enum' => $definition['formats'],
					),
				),
				'description'          => $text_descriptions[ $field ],
			);
		}

		foreach ( self::RELATION_FIELDS as $field => $relation_fields ) {
			$properties[ $field ] = array(
				'type'                 => array( 'boolean', 'object' ),
				'additionalProperties' => false,
				'properties'           => array(
					'fields' => array(
						'type'        => 'array',
						'uniqueItems' => true,
						'items'       => array(
							'type' => 'string',
							'enum' => $relation_fields,
						),
					),
				),
				'description'          => $relation_descriptions[ $field ],
			);
		}

		// List the fields in output order, as the post output schema does.
		$output_order = array_flip( array_keys( $this->get_post_output_schema()['properties'] ) );
		uksort(
			$properties,
			static function ( string $a, string $b ) use ( $output_order ): int {
				return $output_order[ $a ] <=> $output_order[ $b ];
			}
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'description'          => __( 'The fields to return for each post. The `id` is always returned. If omitted or empty, the type, status, slug, URL, plain-text title, and publication date are returned. Fields a post type does not support are left out.' ),
		);
	}

	/**
	 * Builds the schema of a post reference for the `core/content-get` ability.
	 *
	 * The branches cannot overlap: each requires a different key, and none allows the
	 * others' keys. The path branch is only included when a hierarchical post type is
	 * exposed.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The post reference JSON Schema.
	 */
	private function get_post_reference_schema( array $post_types ): array {
		$hierarchical_types = array_values( array_filter( $post_types, 'is_post_type_hierarchical' ) );

		$branches = array(
			array(
				'title'                => __( 'By ID' ),
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => __( 'Optional. The post is returned only if it is of this post type.' ),
					),
				),
			),
			array(
				'title'                => __( 'By slug' ),
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'type', 'slug' ),
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => __( 'Post type containing the slug. Slugs are not unique across post types.' ),
					),
					'slug' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Resolves to the newest readable match, preferring published posts. In hierarchical post types, posts under different parents can share a slug; use `path` to get a specific one.' ),
					),
				),
			),
		);

		if ( array() !== $hierarchical_types ) {
			$branches[] = array(
				'title'                => __( 'By path' ),
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'type', 'path' ),
				'properties'           => array(
					'type' => array(
						'type' => 'string',
						'enum' => $hierarchical_types,
					),
					'path' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The slugs of the post and its ancestors, separated by slashes, such as `about/team`.' ),
					),
				),
			);
		}

		$branches[] = array(
			'title'                => __( 'By URL' ),
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'url' ),
			'properties'           => array(
				'url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'minLength'   => 1,
					'description' => __( 'A URL of this site that points to the post, such as its permalink.' ),
				),
			),
		);

		return array(
			'type'        => 'object',
			'oneOf'       => $branches,
			'description' => __( 'The post to get, referenced in exactly one way.' ),
		);
	}

	/**
	 * Builds the input schema for the `core/content-get` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_get_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'post' ),
			'properties'           => array(
				'post'   => $this->get_post_reference_schema( $post_types ),
				'select' => $this->get_select_input_schema(),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/content-query` ability.
	 *
	 * Filters are grouped under `where`, sorting under `order`, and paging under `page`.
	 * No property declares a `default`: the validator never applies nested defaults, so
	 * the ability applies them at runtime, and the descriptions state them.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @param list<string> $statuses   Requestable post status slugs.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_query_input_schema( array $post_types, array $statuses ): array {
		$where = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'type' ),
			'properties'           => array(
				'type'      => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 1,
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $post_types,
					),
					'description' => __( 'The post type to query. Exactly one post type can be queried at a time.' ),
				),
				'status'    => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $statuses,
					),
					'description' => __( 'Limit the results to posts with one of these statuses. Defaults to publish. Non-published statuses require the appropriate capabilities.' ),
				),
				'author'    => array(
					'type'        => 'object',
					'oneOf'       => array(
						array(
							'title'                => __( 'By ID' ),
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'id' ),
							'properties'           => array(
								'id' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
						),
						array(
							'title'                => __( 'By slug' ),
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'slug' ),
							'properties'           => array(
								'slug' => array(
									'type'        => 'string',
									'minLength'   => 1,
									'description' => __( "The user's slug (nicename), as the REST API users endpoint returns it in `slug`." ),
								),
							),
						),
						array(
							'title'                => __( 'Current user' ),
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'current_user' ),
							'properties'           => array(
								'current_user' => array(
									'type' => 'boolean',
									'enum' => array( true ),
								),
							),
						),
					),
					'description' => __( 'Limit the results to posts by this author. Only supported for post types that support authors.' ),
				),
				'parent'    => array(
					'type'        => 'object',
					'oneOf'       => array(
						array(
							'title'                => __( 'By ID' ),
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'id' ),
							'properties'           => array(
								'id' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
						),
						array(
							'title'                => __( 'By path' ),
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'path' ),
							'properties'           => array(
								'path' => array(
									'type'        => 'string',
									'minLength'   => 1,
									'description' => __( 'The slugs of the parent and its ancestors, separated by slashes, such as `about/team`.' ),
								),
							),
						),
					),
					'description' => __( 'Limit the results to children of this post. Only supported for hierarchical post types. Use `top_level` for posts without a parent.' ),
				),
				'top_level' => array(
					'type'        => 'boolean',
					'enum'        => array( true ),
					'description' => __( 'Limit the results to posts without a parent. Only supported for hierarchical post types, and cannot be combined with `parent`.' ),
				),
				'ids'       => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_PAGE_SIZE,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'description' => __( 'Limit the results to these post IDs. The order of the IDs does not affect the order of the results. If `page.size` is omitted, the page size defaults to the number of IDs.' ),
				),
			),
			'description'          => __( 'Filters that narrow the results. Every filter given must match.' ),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'where' ),
			'properties'           => array(
				'where'  => $where,
				'order'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'by'        => array(
							'type'        => 'string',
							'enum'        => array_keys( self::ORDER_BY ),
							'description' => __( 'The field to sort by. Defaults to published.' ),
						),
						'direction' => array(
							'type'        => 'string',
							'enum'        => array( 'desc', 'asc' ),
							'description' => __( 'The sort direction. Defaults to desc.' ),
						),
					),
					'description'          => __( 'How to sort the results. Defaults to the newest published first.' ),
				),
				'page'   => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'number' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The page of results to return. Defaults to 1. Requesting a page beyond the last one is an error; check `total_pages` before requesting later pages.' ),
						),
						'size'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::MAX_PAGE_SIZE,
							'description' => __( 'The maximum number of posts per page. Defaults to 10, or to the number of `where.ids` when they are given.' ),
						),
					),
					'description'          => __( 'Which page of results to return.' ),
				),
				'select' => $this->get_select_input_schema(),
			),
		);
	}

	/**
	 * Builds the output schema of a single post, shared by all content abilities.
	 *
	 * Only `id` is required, because it is always returned. The other fields are optional
	 * because the `select` input lets the caller choose any subset, and a field is only
	 * present when its post type supports it. A field that applies but is withheld or
	 * unknown is null.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The post JSON Schema.
	 */
	private function get_post_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'                 => array(
					'type'        => 'integer',
					'description' => __( 'The post ID.' ),
				),
				'type'               => array(
					'type'        => 'string',
					'description' => __( 'The post type.' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'The post status.' ),
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => __( 'The post slug.' ),
				),
				'url'                => array(
					'type'        => 'string',
					'description' => __( 'The permalink URL.' ),
				),
				'title'              => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The title, in the selected format. Present when the post type supports titles. Null when the stored source was selected without edit access.' ),
				),
				'excerpt'            => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The excerpt, in the selected format. Present when the post type supports excerpts. Null when withheld: for a password-protected post the current user cannot edit, or when the stored source was selected without edit access.' ),
				),
				'content'            => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The content, in the selected format. Present when the post type supports the editor. Null when withheld: for a password-protected post the current user cannot edit, or when the stored source was selected without edit access.' ),
				),
				'published_at'       => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( "The publication date, in ISO 8601 format with the site's UTC offset. A future date for a scheduled post. Null for a draft or pending post whose date has not been set, or when the date cannot be resolved." ),
				),
				'modified_at'        => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( "The last modified date, in ISO 8601 format with the site's UTC offset. Null when the date cannot be resolved." ),
				),
				'password_protected' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post is protected with a password.' ),
				),
				'author'             => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => __( 'The user ID.' ),
						),
						'slug' => array(
							'type'        => 'string',
							'description' => __( "The user's slug (nicename), as the REST API users endpoint returns it in `slug`." ),
						),
						'name' => array(
							'type'        => 'string',
							'description' => __( "The user's display name." ),
						),
						'url'  => array(
							'type'        => 'string',
							'description' => __( "The URL of the user's author archive." ),
						),
					),
					'description'          => __( 'The author. Present when the post type supports authors. Null when the author no longer exists.' ),
				),
				'parent'             => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'The parent post ID.' ),
						),
						'slug'  => array(
							'type'        => 'string',
							'description' => __( 'The parent post slug.' ),
						),
						'title' => array(
							'type'        => 'string',
							'description' => __( 'The parent post title, as plain text.' ),
						),
						'url'   => array(
							'type'        => 'string',
							'description' => __( 'The parent post permalink URL.' ),
						),
					),
					'description'          => __( 'The parent post. Present for hierarchical post types. Null for a top-level post. Only the `id` is returned when the current user cannot read the parent.' ),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/content-query` ability.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_content_query_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'posts', 'total', 'total_pages' ),
			'properties'           => array(
				'posts'       => array(
					'type'        => 'array',
					'description' => __( 'The readable posts matching the query, in the requested order.' ),
					'items'       => $this->get_post_output_schema(),
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of posts matching the underlying query, across all pages. May exceed the number of returned posts when row-level permission checks withhold some of them.' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of query result pages available for the underlying query. May include pages whose rows are withheld by row-level permission checks.' ),
				),
			),
		);
	}

	/**
	 * Formats a post into the ability output shape.
	 *
	 * As the REST posts controller does, the post is set up as the global post while its
	 * fields are built, so filters that rely on loop globals, including title filters, see
	 * the requested post. For an editor of a password-protected post, the cookie-based
	 * password gate is also suspended, so rendered fields resolve to real values instead of
	 * protected-post placeholders. The field projection itself is delegated to
	 * {@see self::build_post_fields()}.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post                             $post   The post object.
	 * @param array<string, array<string, mixed>> $select The normalized selection.
	 * @return array<string, mixed> The formatted post data.
	 */
	private function format_post( WP_Post $post, array $select ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );
		$unlock_password   = $password_required && $can_edit;
		$previous_context  = $this->set_up_post_context( $post );

		/*
		 * The filter unlocks only posts the current user can edit, mirroring the REST posts
		 * controller's check_password_required(): an unconditional bypass (e.g. __return_false)
		 * would also expose other protected posts that the content filter may render, such as
		 * posts pulled in by a Query Loop block. The closure is kept in a variable, so the same
		 * instance can be removed again.
		 */
		$allow_password_content = function ( $required, $checked_post ): bool {
			return $this->allow_password_content( $required, $checked_post );
		};
		if ( $unlock_password ) {
			add_filter( 'post_password_required', $allow_password_content, 10, 2 );
		}

		/*
		 * Undo both in a finally block, so a throw mid-render cannot leave the password gate
		 * disabled or the global post pointing at this post for the rest of the request.
		 */
		try {
			return $this->build_post_fields( $post, $select, $can_edit, $password_required && ! $can_edit );
		} finally {
			if ( $unlock_password ) {
				remove_filter( 'post_password_required', $allow_password_content, 10 );
			}

			$this->restore_post_context( $previous_context );
		}
	}

	/**
	 * Builds the selected field projection for a post.
	 *
	 * Only the selected fields that the post type supports are included. A field that
	 * applies but is withheld or unknown is null: the stored source of a text field
	 * without edit access, the rendered excerpt and content of a password-protected post
	 * the current user cannot edit, mirroring the REST API behavior, a publication date
	 * that has not been set, or an author who no longer exists.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post                             $post         The post object.
	 * @param array<string, array<string, mixed>> $select       The normalized selection.
	 * @param bool                                $can_edit     Whether the current user can edit the post.
	 * @param bool                                $is_protected Whether rendered fields must be withheld as password-protected.
	 * @return array<string, mixed> The formatted post data.
	 */
	private function build_post_fields( WP_Post $post, array $select, bool $can_edit, bool $is_protected ): array {
		$post_type = $post->post_type;
		$data      = array( 'id' => (int) $post->ID );

		if ( isset( $select['type'] ) ) {
			$data['type'] = $post_type;
		}
		if ( isset( $select['status'] ) ) {
			$data['status'] = $post->post_status;
		}
		if ( isset( $select['slug'] ) ) {
			$data['slug'] = $post->post_name;
		}
		if ( isset( $select['url'] ) ) {
			$data['url'] = (string) get_permalink( $post );
		}

		foreach ( self::TEXT_FIELDS as $field => $definition ) {
			if ( isset( $select[ $field ] ) && post_type_supports( $post_type, $definition['supports'] ) ) {
				$data[ $field ] = $this->format_text_field( $post, $field, $select[ $field ]['format'], $can_edit, $is_protected );
			}
		}

		if ( isset( $select['published_at'] ) ) {
			$data['published_at'] = $this->format_datetime( $post, 'date' );
		}
		if ( isset( $select['modified_at'] ) ) {
			$data['modified_at'] = $this->format_datetime( $post, 'modified' );
		}
		if ( isset( $select['password_protected'] ) ) {
			$data['password_protected'] = '' !== $post->post_password;
		}

		if ( isset( $select['author'] ) && post_type_supports( $post_type, 'author' ) ) {
			$data['author'] = $this->format_author( $post, $select['author']['fields'] );
		}

		if ( isset( $select['parent'] ) && is_post_type_hierarchical( $post_type ) ) {
			$data['parent'] = $this->format_parent( $post, $select['parent']['fields'] );
		}

		return $data;
	}

	/**
	 * Formats a text field of a post in the selected format.
	 *
	 * The `source` format returns the stored value, which requires edit access. The `html`
	 * format returns the value as the site renders it, and the `text` format returns that
	 * rendering as plain text. Titles are not withheld for password-protected posts, as in
	 * the REST API.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post         The post object.
	 * @param string  $field        The text field: `title`, `excerpt`, or `content`.
	 * @param string  $format       The format: `text`, `html`, or `source`.
	 * @param bool    $can_edit     Whether the current user can edit the post.
	 * @param bool    $is_protected Whether rendered fields must be withheld as password-protected.
	 * @return string|null The formatted value, or null when it is withheld.
	 */
	private function format_text_field( WP_Post $post, string $field, string $format, bool $can_edit, bool $is_protected ): ?string {
		if ( self::SOURCE_FORMAT === $format ) {
			return $can_edit ? (string) $post->{self::TEXT_FIELDS[ $field ]['column']} : null;
		}

		if ( 'title' === $field ) {
			$html = $this->get_title( $post );
		} elseif ( $is_protected ) {
			return null;
		} else {
			$html = 'excerpt' === $field ? $this->get_rendered_excerpt( $post ) : $this->get_rendered_content( $post );
		}

		return 'text' === $format ? $this->to_plain_text( $html ) : $html;
	}

	/**
	 * Converts rendered HTML to plain text.
	 *
	 * Removes tags, including the contents of scripts and styles, decodes entities, and
	 * collapses the runs of blank lines that block markup leaves behind.
	 *
	 * @since 7.2.0
	 *
	 * @param string $html The rendered HTML.
	 * @return string The plain text.
	 */
	private function to_plain_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( "/\n\s*\n/", "\n\n", $text ) );
	}

	/**
	 * Formats the author relation of a post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post      $post   The post object.
	 * @param list<string> $fields The selected author fields, which include `id`.
	 * @return array<string, mixed>|null The author fields, or null when the author no longer exists.
	 */
	private function format_author( WP_Post $post, array $fields ): ?array {
		$author = get_userdata( (int) $post->post_author );
		if ( ! $author ) {
			return null;
		}

		$values = array(
			'id'   => static function () use ( $author ): int {
				return (int) $author->ID;
			},
			'slug' => static function () use ( $author ): string {
				return (string) $author->user_nicename;
			},
			'name' => static function () use ( $author ): string {
				return (string) $author->display_name;
			},
			'url'  => static function () use ( $author ): string {
				return get_author_posts_url( $author->ID, $author->user_nicename );
			},
		);

		$data = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = $values[ $field ]();
		}

		return $data;
	}

	/**
	 * Formats the parent relation of a post.
	 *
	 * The parent's `id` is part of the post itself, as in the REST API. The other fields
	 * describe the parent post, so they are only returned when the current user can read
	 * it.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post      $post   The post object.
	 * @param list<string> $fields The selected parent fields, which include `id`.
	 * @return array<string, mixed>|null The parent fields, or null for a top-level post.
	 */
	private function format_parent( WP_Post $post, array $fields ): ?array {
		$parent_id = (int) $post->post_parent;
		if ( $parent_id < 1 ) {
			return null;
		}

		$data   = array( 'id' => $parent_id );
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post || ! $this->check_read_permission( $parent ) ) {
			return $data;
		}

		foreach ( $fields as $field ) {
			if ( 'slug' === $field ) {
				$data['slug'] = $parent->post_name;
			} elseif ( 'title' === $field ) {
				$data['title'] = $this->to_plain_text( $this->get_title( $parent ) );
			} elseif ( 'url' === $field ) {
				$data['url'] = (string) get_permalink( $parent );
			}
		}

		return $data;
	}

	/**
	 * Filters {@see post_password_required()} to unlock only posts the current user can edit.
	 *
	 * Added by {@see self::format_post()} while formatting a password-protected post the
	 * current user can edit, so rendered fields resolve to real values without also unlocking
	 * other protected posts that the content filter may render. Mirrors the REST posts
	 * controller's check_password_required().
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $required Whether the post currently requires a password.
	 * @param mixed $post     The post being checked; a WP_Post when invoked by the core filter.
	 * @return bool Whether the post still requires a password.
	 */
	private function allow_password_content( $required, $post ): bool {
		if ( ! $required || ! $post instanceof WP_Post ) {
			return (bool) $required;
		}

		return ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Returns the post title with the protected/private prefixes stripped.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string The post title.
	 */
	private function get_title( WP_Post $post ): string {
		$strip = static function (): string {
			return '%s';
		};
		add_filter( 'protected_title_format', $strip );
		add_filter( 'private_title_format', $strip );

		/*
		 * The format filters are removed in a finally block so a throw from a title
		 * filter cannot leave them attached for the rest of the request.
		 */
		try {
			$title = get_the_title( $post );

			/*
			 * A title filter that returns a non-string would fail the return type, so guard
			 * it as the excerpt and content are.
			 */
			return is_string( $title ) ? $title : '';
		} finally {
			remove_filter( 'protected_title_format', $strip );
			remove_filter( 'private_title_format', $strip );
		}
	}

	/**
	 * Returns the post excerpt transformed for display.
	 *
	 * Applies the `get_the_excerpt` and `the_excerpt` filter chains, as the REST posts
	 * controller does. {@see self::format_post()} has set the post up as the global post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string Rendered post excerpt.
	 */
	private function get_rendered_excerpt( WP_Post $post ): string {
		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'the_excerpt', $excerpt );

		return is_string( $excerpt ) ? $excerpt : '';
	}

	/**
	 * Returns post content transformed for display.
	 *
	 * Applies `the_content`, as the REST posts controller does. {@see self::format_post()}
	 * has set the post up as the global post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string Rendered post content.
	 */
	private function get_rendered_content( WP_Post $post ): string {
		/** This filter is documented in wp-includes/post-template.php */
		$content = apply_filters( 'the_content', $post->post_content );

		return is_string( $content ) ? $content : '';
	}

	/**
	 * Sets up the global post context for rendering a post.
	 *
	 * Sets the global post and calls setup_postdata(), as the REST posts controller does
	 * before it renders a post, so filters that rely on loop globals render against the
	 * requested post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post to render.
	 * @return array<string, mixed> The previous loop globals, keyed by name, leaving out those that were not set.
	 */
	private function set_up_post_context( WP_Post $post ): array {
		/*
		 * Copy each global by value. A calling function that binds a global with `global`,
		 * as load_template() and WP_Block::render() do, makes it a reference, which
		 * array_intersect_key( $GLOBALS, ... ) would keep. The saved copy would then follow
		 * the global to this post, and the restore would put this post back.
		 */
		$previous_context = array();
		foreach ( self::LOOP_GLOBALS as $name ) {
			if ( ! array_key_exists( $name, $GLOBALS ) ) {
				continue;
			}

			$previous_context[ $name ] = $GLOBALS[ $name ];
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		return $previous_context;
	}

	/**
	 * Restores the global post context saved by {@see self::set_up_post_context()}.
	 *
	 * Each loop global gets its previous value back, or is unset again when it was not set.
	 * wp_reset_postdata() alone is not enough: it does nothing when the main query has no
	 * post, which would leave the rendered post's data in the globals. When a post was set
	 * up before, setup_postdata() first runs for it again, as wp_reset_postdata() would, so
	 * callbacks on the `the_post` action can restore their own globals too. A global post
	 * that was never set up keeps the loop globals that setup_postdata() gives it.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $previous_context The loop globals that set_up_post_context() returned.
	 */
	private function restore_post_context( array $previous_context ): void {
		$previous_post = $previous_context['post'] ?? null;
		if ( $previous_post instanceof WP_Post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );

			/*
			 * A global post that was never set up, such as the main post before the loop
			 * starts, keeps what setup_postdata() just gave it. Do not put back the values
			 * saved before: `the_post` has fired now, so get_the_content() called without a
			 * post, as the Post Content block and the_content() outside the loop do, reads the
			 * loop globals instead of the post, and an unset `$pages` makes it throw a
			 * TypeError that breaks the page being rendered.
			 */
			if ( ! is_array( $previous_context['pages'] ?? null ) ) {
				return;
			}
		}

		foreach ( self::LOOP_GLOBALS as $name ) {
			if ( array_key_exists( $name, $previous_context ) ) {
				$GLOBALS[ $name ] = $previous_context[ $name ];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	/**
	 * Formats a post date field as an ISO 8601 string with the site's UTC offset.
	 *
	 * It reads the stored GMT date, so the value names the right moment even if the site's
	 * timezone changed since, and derives it from the local date when it is missing.
	 *
	 * A draft or pending post whose date has not been set has a floating date: its local
	 * date follows the last save until it is published, and no GMT date is stored. It has
	 * not been published or scheduled, so it reports no publication date.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post  The post object.
	 * @param string  $field Either 'date' or 'modified'.
	 * @return string|null The ISO 8601 date, or null if unavailable.
	 */
	private function format_datetime( WP_Post $post, string $field ): ?string {
		if ( 'date' === $field
			&& '0000-00-00 00:00:00' === $post->post_date_gmt
			&& in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true )
		) {
			return null;
		}

		$datetime = get_post_datetime( $post, $field, 'gmt' );
		if ( ! $datetime ) {
			$datetime = get_post_datetime( $post, $field );
		}

		if ( ! $datetime ) {
			return null;
		}

		/*
		 * A malformed stored date can still parse: a zero month formats with a negative
		 * year. The `date-time` format rejects it, which would fail output validation for
		 * the whole call, so it is reported as null too.
		 */
		$date = $datetime->setTimezone( wp_timezone() )->format( 'c' );

		return rest_parse_date( $date ) ? $date : null;
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * Unreachable through gated transports, which run the permission callbacks first
	 * and deny the same lookups. It is kept so that a direct call to the execute
	 * callback still fails closed on a structural lookup failure: a missing post, a post
	 * type that is not exposed, or a post type that does not match the requested one.
	 *
	 * This is not a permission check. The execute callback deliberately does not repeat
	 * the read/edit checks that the permission callback already performed, so a
	 * direct call bypasses them. Only invoke the callback through
	 * {@see WP_Ability::execute()}, which always runs the permission callback first.
	 *
	 * @since 7.2.0
	 *
	 * @return WP_Error The not-found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'content_not_found',
			__( 'The requested content was not found.' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the error for a query filter that cannot be honored.
	 *
	 * As in the REST API's `rest_invalid_param` errors, the error data maps the filter to
	 * the message under `params`, so callers can tell which filter failed without parsing
	 * the translated message.
	 *
	 * @since 7.2.0
	 *
	 * @param string $filter  The filter's input name.
	 * @param string $message The error message.
	 * @return WP_Error The invalid filter error.
	 */
	private function invalid_filter_error( string $filter, string $message ): WP_Error {
		return new WP_Error(
			'content_invalid_filter',
			$message,
			array(
				'status' => 400,
				'params' => array( $filter => $message ),
			)
		);
	}
}
