<?php
/**
 * Abilities API: WP_Abilities_Content_Posts class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 7.2.0
 */

declare( strict_types = 1 );

/**
 * Core class used by the content abilities to find, authorize, and format posts.
 *
 * The content abilities, {@see WP_Abilities_Content_Get} and {@see WP_Abilities_Content_Query},
 * share how they see posts: which post types are exposed to abilities, which posts the
 * current user can read, how a post reference resolves, how the `select` input chooses
 * the returned fields, and the shape a post is returned in. This class holds that shared
 * behavior, so each ability class only holds what is its own. Future write-oriented content
 * abilities can reuse it as well.
 *
 * Its methods are public only so that the ability classes can call them. Callers outside
 * Core should go through the Abilities API, such as
 * `wp_get_ability( 'core/content-get' )->execute()`, which validates the input and checks
 * permissions first.
 *
 * @since 7.2.0
 *
 * @access private
 */
final class WP_Abilities_Content_Posts {

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
	 * Globals that rendering a post changes: the global post and the globals that
	 * setup_postdata() populates.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	private const LOOP_GLOBALS = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );

	/**
	 * Returns the names of the post types exposed to abilities via `show_in_abilities`.
	 *
	 * Post types must be registered with `show_in_abilities` before the content abilities
	 * are registered, so they are included in the input schemas.
	 *
	 * @since 7.2.0
	 *
	 * @return list<string> Exposed post type names.
	 */
	public function get_exposed_post_types(): array {
		return array_values( get_post_types( array( 'show_in_abilities' => true ) ) );
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
	public function get_exposed_post_type( $post_type ): ?WP_Post_Type {
		$post_type_object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;

		return empty( $post_type_object->show_in_abilities ) ? null : $post_type_object;
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
	public function resolve_post_reference( $reference ): ?WP_Post {
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
	 * Looks up the single post a slug request resolves to.
	 *
	 * Slugs are not unique across statuses (drafts skip slug uniqueness), so the
	 * lookup returns the newest match the current user can read, preferring
	 * publicly viewable posts — a newer draft sharing the slug cannot shadow a
	 * published post. This mirrors the REST API, where slug queries default to
	 * the `publish` status. Which post a slug resolves to is independent of the
	 * selected fields; requests for the stored source are gated afterwards on the
	 * resolved post by the `core/content-get` permission callback.
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
	public function get_post_by_path( WP_Post_Type $post_type_object, string $path ): ?WP_Post {
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
	 * Checks if a post can be read by the current user.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post Post object.
	 * @return bool Whether the post can be read.
	 */
	public function can_read_post( WP_Post $post ): bool {
		return $this->check_read_permission( $post );
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
	public function parse_filter_int( $value, int $min ): ?int {
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
	public function parse_list_value( $value ): array {
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
	public function get_object_value( $value ): ?array {
		return is_array( $value ) || $value instanceof stdClass ? rest_sanitize_object( $value ) : null;
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
	 * @param mixed $select The raw `select` input.
	 * @return array<string, array<string, mixed>> The selected fields, keyed by name, with their options.
	 */
	public function normalize_select( $select ): array {
		$select = $this->get_object_value( $select );
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
	public function requires_edit( array $select ): bool {
		foreach ( array_keys( self::TEXT_FIELDS ) as $field ) {
			if ( isset( $select[ $field ] ) && self::SOURCE_FORMAT === $select[ $field ]['format'] ) {
				return true;
			}
		}

		return false;
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
	public function should_prime_post_caches( array $select ): bool {
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
	public function get_select_input_schema(): array {
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
	public function get_post_reference_schema( array $post_types ): array {
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
	public function get_post_output_schema(): array {
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
	public function format_post( WP_Post $post, array $select ): array {
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
}
