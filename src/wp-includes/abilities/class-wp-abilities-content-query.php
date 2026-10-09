<?php
/**
 * Abilities API: WP_Abilities_Content_Query class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 7.2.0
 */

declare( strict_types = 1 );

/**
 * Core class used to register the `core/content-query` ability.
 *
 * The ability lists readable posts of one post type exposed to abilities via
 * `show_in_abilities`, narrowed by the filters under `where`, sorted by `order`, and paged by
 * page number under `page`. It takes a `select` object that chooses the returned fields,
 * shared with `core/content-get` through {@see WP_Abilities_Content_Posts}.
 *
 * The input nests only where a group has its own rules, its own alternatives, or arguments
 * that belong to one field; single values stay flat.
 *
 * Only register() is public. The ability callbacks are closures that call private
 * methods, so callers go through the Abilities API, such as
 * `wp_get_ability( 'core/content-query' )->execute()`, which validates the input and checks
 * permissions before running them.
 *
 * @since 7.2.0
 *
 * @access private
 */
final class WP_Abilities_Content_Query {

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
	 * The shared post lookup, permission, selection, and formatting helpers.
	 *
	 * @since 7.2.0
	 * @var WP_Abilities_Content_Posts
	 */
	private $posts;

	/**
	 * Constructor.
	 *
	 * @since 7.2.0
	 */
	public function __construct() {
		$this->posts = new WP_Abilities_Content_Posts();
	}

	/**
	 * Registers the read-only `core/content-query` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook. Nothing is registered when no post type
	 * is exposed to abilities.
	 *
	 * @since 7.2.0
	 */
	public function register(): void {
		$post_types = $this->posts->get_exposed_post_types();
		if ( empty( $post_types ) ) {
			return;
		}

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
				'category'            => 'content',
				'input_schema'        => $this->get_input_schema( $post_types, $statuses ),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => function ( $input = array() ) {
					return $this->execute( $input );
				},
				'permission_callback' => function ( $input = array() ): bool {
					return $this->check_permission( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'      => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the ability.
	 *
	 * The query is only gated coarsely here (collection status capabilities);
	 * {@see self::execute()} enforces row-level read/edit permissions, since individual
	 * rows are unknown until the query runs. Requests that select the stored source of a
	 * text field require edit access to the post type.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	private function check_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$where            = $this->posts->get_object_value( $input['where'] ?? null ) ?? array();
		$post_type_object = $this->get_queried_post_type( $where );
		if ( ! $post_type_object ) {
			return false;
		}

		if ( $this->posts->requires_edit( $this->posts->normalize_select( $input['select'] ?? null ) ) ) {
			return current_user_can( $post_type_object->cap->edit_posts );
		}

		return $this->can_query_statuses( $this->normalize_statuses( $where ), $post_type_object );
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
	 * Executes the ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error A `posts` list with totals, or a WP_Error.
	 */
	private function execute( $input = array() ) {
		$input         = rest_sanitize_object( $input );
		$where         = $this->posts->get_object_value( $input['where'] ?? null ) ?? array();
		$order         = $this->posts->get_object_value( $input['order'] ?? null ) ?? array();
		$paging        = $this->posts->get_object_value( $input['page'] ?? null ) ?? array();
		$select        = $this->posts->normalize_select( $input['select'] ?? null );
		$requires_edit = $this->posts->requires_edit( $select );

		if ( count( $this->posts->parse_list_value( $where['type'] ?? null ) ) > 1 ) {
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
		$page     = $this->posts->parse_filter_int( $paging['number'] ?? 1, 1 ) ?? 1;

		$order_by  = is_string( $order['by'] ?? null ) && isset( self::ORDER_BY[ $order['by'] ] ) ? self::ORDER_BY[ $order['by'] ] : 'date';
		$direction = is_string( $order['direction'] ?? null ) && 'asc' === strtolower( $order['direction'] ) ? 'ASC' : 'DESC';

		$prime_post_caches = $this->posts->should_prime_post_caches( $select );

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
			if ( ! $requires_edit && ! $this->posts->can_read_post( $post ) ) {
				continue;
			}
			$posts[] = $this->posts->format_post( $post, $select );
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
		$size = $this->posts->parse_filter_int( $paging['size'] ?? null, 1 );
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
	 * Returns the post type a request queries.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $where The `where` input.
	 * @return WP_Post_Type|null The post type object, or null unless exactly one exposed post type is requested.
	 */
	private function get_queried_post_type( array $where ): ?WP_Post_Type {
		$post_types = $this->posts->parse_list_value( $where['type'] ?? null );

		return 1 === count( $post_types ) ? $this->posts->get_exposed_post_type( $post_types[0] ) : null;
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
		$reference = $this->posts->get_object_value( $reference );
		if ( null === $reference ) {
			return null;
		}

		if ( isset( $reference['id'] ) ) {
			return $this->posts->parse_filter_int( $reference['id'], 1 );
		}

		if ( ! isset( $reference['path'] ) || ! is_string( $reference['path'] ) ) {
			return null;
		}

		$parent = $this->posts->get_post_by_path( $post_type_object, $reference['path'] );

		return $parent && $this->posts->can_read_post( $parent ) ? $parent->ID : null;
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
		$reference = $this->posts->get_object_value( $reference );
		if ( null === $reference ) {
			return null;
		}

		if ( isset( $reference['current_user'] ) ) {
			$user = rest_sanitize_boolean( $reference['current_user'] ) ? wp_get_current_user() : null;

			return $user instanceof WP_User && $user->exists() ? $user : null;
		}

		$user = false;
		if ( isset( $reference['id'] ) ) {
			$user_id = $this->posts->parse_filter_int( $reference['id'], 1 );
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
	 * Normalizes the requested statuses to a non-empty, sanitized list defaulting to publish.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $where The `where` input.
	 * @return list<string> Normalized list of post status slugs.
	 */
	private function normalize_statuses( array $where ): array {
		$statuses = $this->posts->parse_list_value( $where['status'] ?? null );

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
	 * Builds the input schema for the ability.
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
	private function get_input_schema( array $post_types, array $statuses ): array {
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
				'select' => $this->posts->get_select_input_schema(),
			),
		);
	}

	/**
	 * Builds the output schema for the ability.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'posts', 'total', 'total_pages' ),
			'properties'           => array(
				'posts'       => array(
					'type'        => 'array',
					'description' => __( 'The readable posts matching the query, in the requested order.' ),
					'items'       => $this->posts->get_post_output_schema(),
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
	 * Builds the not-found error.
	 *
	 * Unreachable through gated transports, which run the permission callback first and
	 * deny the same request. It is kept so that a direct call to the execute callback still
	 * fails closed when the queried post type is missing or not exposed.
	 *
	 * This is not a permission check. The execute callback deliberately does not repeat
	 * the collection checks that the permission callback already performed, so a direct
	 * call bypasses them. Only invoke the callback through {@see WP_Ability::execute()},
	 * which always runs the permission callback first.
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
