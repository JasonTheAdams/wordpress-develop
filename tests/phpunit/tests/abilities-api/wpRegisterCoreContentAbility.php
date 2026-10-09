<?php

declare( strict_types=1 );

/**
 * Tests for the core/content-get and core/content-query abilities shipped with the Abilities API.
 *
 * @covers WP_Abilities_Content_Get
 * @covers WP_Abilities_Content_Query
 * @covers WP_Abilities_Content_Posts
 *
 * @group abilities-api
 */
class Tests_Abilities_API_WpRegisterCoreContentAbility extends WP_UnitTestCase {

	/**
	 * Globals that rendering a post changes: the global post and the globals that
	 * setup_postdata() populates.
	 *
	 * @since 7.2.0
	 *
	 * @var list<string>
	 */
	private const LOOP_GLOBALS = array(
		'post',
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * A post ID that tests create a post with through `import_id`, so data providers can
	 * write the ID in other forms.
	 *
	 * @since 7.2.0
	 *
	 * @var int
	 */
	private const IMPORTED_POST_ID = 1234567;

	/**
	 * Names of the content abilities under test.
	 *
	 * @since 7.2.0
	 *
	 * @var list<string>
	 */
	private const ABILITY_NAMES = array( 'core/content-get', 'core/content-query' );

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since 7.2.0
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Shared post IDs keyed by fixture name.
	 *
	 * @since 7.2.0
	 *
	 * @var array<string, int>
	 */
	private static $post_ids = array();

	/**
	 * Creates shared users and posts for the content ability tests.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'           => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'       => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'contributor'      => $factory->user->create( array( 'role' => 'contributor' ) ),
			'author'           => $factory->user->create( array( 'role' => 'author' ) ),
			'author_secondary' => $factory->user->create( array( 'role' => 'author' ) ),
		);

		self::$post_ids = array(
			'published'            => $factory->post->create( array( 'post_status' => 'publish' ) ),
			'published_content'    => $factory->post->create(
				array(
					'post_title'   => 'Hello Content',
					'post_content' => 'Body here.',
					'post_status'  => 'publish',
				)
			),
			'limited_role_content' => $factory->post->create(
				array(
					'post_author'  => self::$user_ids['administrator'],
					'post_title'   => 'Readable title',
					'post_content' => 'Readable body for limited role.',
					'post_excerpt' => 'Readable excerpt.',
					'post_status'  => 'publish',
				)
			),
			'password_protected'   => $factory->post->create(
				array(
					'post_author'   => self::$user_ids['administrator'],
					'post_status'   => 'publish',
					'post_password' => 'secret',
					'post_content'  => 'Top secret body.',
				)
			),
		);
	}

	/**
	 * Sets up the content ability category for each test.
	 *
	 * @since 7.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->unregister_abilities();
		$this->ensure_ability_category( 'content' );
	}

	/**
	 * Removes the abilities and their category, and resets the post types, after each test.
	 *
	 * @since 7.2.0
	 */
	public function tear_down(): void {
		$this->unregister_abilities();

		if ( wp_has_ability_category( 'content' ) ) {
			wp_unregister_ability_category( 'content' );
		}

		/*
		 * Keep this reset even though the parent set_up() also resets the post types. A test
		 * may turn off `show_in_abilities` on `post` and `page`, and the next class's
		 * wpSetUpBeforeClass() runs before the next set_up(). A class that registers the core
		 * abilities there, such as Tests_REST_API_WpRestAbilitiesContentController, would then
		 * register no content abilities, and all of its tests would fail.
		 */
		$this->reset_post_types();

		parent::tear_down();
	}

	/**
	 * Unregisters the content abilities when they are registered.
	 *
	 * @since 7.2.0
	 */
	private function unregister_abilities(): void {
		foreach ( self::ABILITY_NAMES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
	}

	/**
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since 7.2.0
	 *
	 * @param string $slug The ability category slug.
	 */
	private function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		wp_register_ability_category(
			$slug,
			array(
				'label'       => ucfirst( $slug ),
				'description' => ucfirst( $slug ) . '.',
			)
		);
	}

	/**
	 * Registers the content abilities inside a faked init action.
	 *
	 * @since 7.2.0
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		( new WP_Abilities_Content_Get() )->register();
		( new WP_Abilities_Content_Query() )->register();
	}

	/**
	 * Registers the content abilities and returns the callbacks one was registered with.
	 *
	 * The callbacks call private methods, so tests that skip input validation or the
	 * permission check capture them from the registration arguments.
	 *
	 * @since 7.2.0
	 *
	 * @param string $ability_name The ability name: `core/content-get` or `core/content-query`.
	 * @return array<string, callable> The permission and execute callbacks, keyed by argument name.
	 */
	private function get_ability_callbacks( string $ability_name ): array {
		$callbacks = array();

		add_filter(
			'wp_register_ability_args',
			static function ( array $args, string $name ) use ( &$callbacks, $ability_name ): array {
				if ( $ability_name === $name ) {
					$callbacks = array(
						'permission_callback' => $args['permission_callback'],
						'execute_callback'    => $args['execute_callback'],
					);
				}

				return $args;
			},
			10,
			2
		);

		$this->register_abilities();

		return $callbacks;
	}

	/**
	 * Logs in as a user with the given role and returns the user ID.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::$user_ids[ $role ] ?? self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Returns roles that can read public posts but cannot edit another user's post.
	 *
	 * @return array<string, array{role: string}> Role test cases.
	 */
	public function data_roles_without_edit_access_to_other_users_posts(): array {
		return array(
			'subscriber'  => array(
				'role' => 'subscriber',
			),
			'contributor' => array(
				'role' => 'contributor',
			),
			'author'      => array(
				'role' => 'author',
			),
		);
	}

	/**
	 * Both content abilities are registered in the `content` category and flagged read-only.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_registers_core_content_abilities(): void {
		$this->register_abilities();

		foreach ( self::ABILITY_NAMES as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, "The {$name} ability should be registered." );
			$this->assertSame( $name, $ability->get_name(), "The {$name} ability should use the expected name." );
			$this->assertSame( 'content', $ability->get_category(), "The {$name} ability should use the content category." );
			$this->assertTrue( $ability->get_meta_item( 'public', false ), "The {$name} ability should be marked public." );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), "The {$name} ability should be exposed in REST." );

			$annotations = $ability->get_meta_item( 'annotations', array() );
			$this->assertTrue( $annotations['readonly'], "The {$name} ability should be marked read-only." );
			$this->assertFalse( $annotations['destructive'], "The {$name} ability should be marked non-destructive." );
			$this->assertTrue( $annotations['idempotent'], "The {$name} ability should be marked idempotent." );
		}
	}

	/**
	 * The content abilities are not registered when no post types are exposed to them.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_does_not_register_core_content_abilities_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			$this->assertInstanceOf( WP_Post_Type::class, $object, "Precondition: the {$post_type} post type should exist." );

			$object->show_in_abilities = false;
		}

		$this->register_abilities();

		$this->assertFalse( wp_has_ability( 'core/content-get' ), 'The content-get ability should not register without any exposed post types.' );
		$this->assertFalse( wp_has_ability( 'core/content-query' ), 'The content-query ability should not register without any exposed post types.' );
	}

	/**
	 * Neither input schema is a `oneOf` at the top level, since some tool-calling clients
	 * reject that. Each is a plain object, and the alternatives live inside properties.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_input_schemas_are_plain_objects_at_the_top_level(): void {
		$this->register_abilities();

		foreach ( self::ABILITY_NAMES as $name ) {
			$schema = wp_get_ability( $name )->get_input_schema();

			$this->assertSame( 'object', $schema['type'], "The {$name} input should be an object." );
			$this->assertFalse( $schema['additionalProperties'], "The {$name} input should reject unknown properties." );
			$this->assertArrayNotHasKey( 'oneOf', $schema, "The {$name} input should not be a top-level oneOf." );
			$this->assertArrayNotHasKey( 'anyOf', $schema, "The {$name} input should not be a top-level anyOf." );
		}
	}

	/**
	 * The content-get input requires a post reference, whose branches each name the post in
	 * exactly one way, and exposes only marked post types.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_input_schema_models_post_reference(): void {
		$this->register_abilities();

		$schema    = wp_get_ability( 'core/content-get' )->get_input_schema();
		$reference = $schema['properties']['post'];
		$titles    = wp_list_pluck( $reference['oneOf'], 'title' );

		$this->assertSame( array( 'post' ), $schema['required'], 'The post reference should be required.' );
		$this->assertSame( array( 'post', 'select' ), array_keys( $schema['properties'] ), 'The input should only accept a post reference and a selection.' );
		$this->assertSame( array( 'By ID', 'By slug', 'By path', 'By URL' ), $titles, 'The reference should offer ID, slug, path, and URL branches.' );

		foreach ( $reference['oneOf'] as $branch ) {
			$this->assertFalse( $branch['additionalProperties'], "The {$branch['title']} branch should reject other branches' keys." );
		}

		$this->assertSame( array( 'id' ), $reference['oneOf'][0]['required'], 'The ID branch should require the ID.' );
		$this->assertSame( array( 'type', 'slug' ), $reference['oneOf'][1]['required'], 'The slug branch should require the post type and slug.' );
		$this->assertSame( array( 'type', 'path' ), $reference['oneOf'][2]['required'], 'The path branch should require the post type and path.' );
		$this->assertSame( array( 'url' ), $reference['oneOf'][3]['required'], 'The URL branch should require the URL.' );
		$this->assertSame( array( 'page' ), $reference['oneOf'][2]['properties']['type']['enum'], 'The path branch should only list hierarchical post types.' );
		$this->assertEqualSets( array( 'post', 'page' ), $reference['oneOf'][1]['properties']['type']['enum'], 'The slug branch should list the exposed post types.' );
	}

	/**
	 * The content-get reference has no path branch when no hierarchical post type is exposed.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_input_schema_omits_path_branch_without_hierarchical_post_types(): void {
		get_post_type_object( 'page' )->show_in_abilities = false;

		$this->register_abilities();

		$reference = wp_get_ability( 'core/content-get' )->get_input_schema()['properties']['post'];

		$this->assertSame( array( 'By ID', 'By slug', 'By URL' ), wp_list_pluck( $reference['oneOf'], 'title' ), 'The path branch should be left out.' );
	}

	/**
	 * The content-query input groups filters under `where`, sorting under `order`, and paging
	 * under `page`, and requires exactly one post type.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_input_schema_groups_filters_order_and_paging(): void {
		$this->register_abilities();

		$schema = wp_get_ability( 'core/content-query' )->get_input_schema();
		$where  = $schema['properties']['where'];

		$this->assertSame( array( 'where' ), $schema['required'], 'The filters should be required, since they name the post type.' );
		$this->assertSame( array( 'where', 'order', 'page', 'select' ), array_keys( $schema['properties'] ), 'The input should group filters, sorting, paging, and selection.' );
		$this->assertSame( array( 'type' ), $where['required'], 'The post type filter should be required.' );
		$this->assertSame( array( 'type', 'status', 'author', 'parent', 'top_level', 'ids' ), array_keys( $where['properties'] ), 'The filters should be listed under where.' );
		$this->assertFalse( $where['additionalProperties'], 'Unknown filters should be rejected.' );
		$this->assertSame( 1, $where['properties']['type']['minItems'], 'One post type should be required.' );
		$this->assertSame( 1, $where['properties']['type']['maxItems'], 'Only one post type should be queryable at a time.' );
		$this->assertSame( array( 'By ID', 'By slug', 'Current user' ), wp_list_pluck( $where['properties']['author']['oneOf'], 'title' ), 'Authors should be referenced by ID, slug, or as the current user.' );
		$this->assertSame( array( 'By ID', 'By path' ), wp_list_pluck( $where['properties']['parent']['oneOf'], 'title' ), 'Parents should be referenced by ID or path.' );
		$this->assertSame( array( 'number', 'size' ), array_keys( $schema['properties']['page']['properties'] ), 'Paging should use a page number and size.' );
		$this->assertSame( array( 'published', 'modified', 'title', 'menu_order' ), $schema['properties']['order']['properties']['by']['enum'], 'The sort keys should be listed.' );
	}

	/**
	 * No input property declares a default, so the schemas can compile in the client-side
	 * Abilities API validator. The validator never applies nested defaults; the abilities
	 * apply them at runtime.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_input_schemas_declare_no_defaults(): void {
		$this->register_abilities();

		foreach ( self::ABILITY_NAMES as $name ) {
			$encoded = wp_json_encode( wp_get_ability( $name )->get_input_schema() );

			$this->assertStringNotContainsString( '"default"', $encoded, "The {$name} input schema should rely on runtime defaults, not schema defaults." );
		}
	}

	/**
	 * The `select` input takes true or an options object for each field, and lists formats
	 * for text fields and sub-fields for relations.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_select_input_schema_describes_fields_formats_and_relations(): void {
		$this->register_abilities();

		$get_select   = wp_get_ability( 'core/content-get' )->get_input_schema()['properties']['select'];
		$query_select = wp_get_ability( 'core/content-query' )->get_input_schema()['properties']['select'];
		$properties   = $get_select['properties'];

		$this->assertSame( $get_select, $query_select, 'Both abilities should share the same selection.' );
		$this->assertFalse( $get_select['additionalProperties'], 'Unknown fields should be rejected.' );
		$this->assertArrayNotHasKey( 'id', $properties, 'The always-returned ID should not be selectable.' );
		$this->assertSame( 'boolean', $properties['url']['type'], 'Single-value fields should take a boolean.' );
		$this->assertSame( array( 'boolean', 'object' ), $properties['title']['type'], 'Text fields should take true or an options object, in that order.' );
		$this->assertSame( array( 'text', 'html', 'source' ), $properties['title']['properties']['format']['enum'], 'Titles should offer text, HTML, and source formats.' );
		$this->assertSame( array( 'html', 'text', 'source' ), $properties['content']['properties']['format']['enum'], 'Content should offer HTML, text, and source formats.' );
		$this->assertSame( array( 'id', 'slug', 'name', 'url' ), $properties['author']['properties']['fields']['items']['enum'], 'The author relation should list its fields.' );
		$this->assertSame( array( 'id', 'slug', 'title', 'url' ), $properties['parent']['properties']['fields']['items']['enum'], 'The parent relation should list its fields.' );
	}

	/**
	 * A content-get request rejects keys that belong to content-query, rather than ignoring them.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_rejects_query_only_params(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'id' => 1 ),
				'page' => array( 'size' => 10 ),
			)
		);

		$this->assertWPError( $result, 'Combining a post reference with paging should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Unknown properties should return an input error.' );
	}

	/**
	 * A post reference that names the post in two ways matches no branch.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_rejects_a_reference_that_mixes_branches(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'id'   => self::$post_ids['published'],
					'slug' => 'hello',
				),
			)
		);

		$this->assertWPError( $result, 'A reference with both an ID and a slug should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'A mixed reference should return an input error.' );
		$this->assertStringContainsString( 'input[post]', $result->get_error_message(), 'The error should point at the post reference.' );
	}

	/**
	 * `type` is accepted alongside `id` as a guard: the by-ID reference still resolves the post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_id_accepts_post_type_guard(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'id'   => $post_id,
					'type' => 'post',
				),
			)
		);

		$this->assertIsArray( $result, 'A matching post type guard should allow the by-ID lookup.' );
		$this->assertSame( $post_id, $result['id'], 'The guarded by-ID lookup should return the requested post.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The content-get ability should return the post directly.' );
	}

	/**
	 * The output schemas describe a post for content-get and a paged list for content-query.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_output_schemas_describe_a_post_and_a_paged_list(): void {
		$this->register_abilities();

		$post_schema  = wp_get_ability( 'core/content-get' )->get_output_schema();
		$query_schema = wp_get_ability( 'core/content-query' )->get_output_schema();
		$select       = wp_get_ability( 'core/content-get' )->get_input_schema()['properties']['select'];

		$this->assertSame( 'object', $post_schema['type'], 'The content-get output should be an object.' );
		$this->assertArrayNotHasKey( 'oneOf', $post_schema, 'The content-get output should have one shape.' );
		$this->assertSame( array( 'id' ), $post_schema['required'], 'Only the always-returned id should be required in a post.' );
		$this->assertFalse( $post_schema['additionalProperties'], 'Returned posts should not allow unknown properties.' );
		$this->assertSame(
			array_merge( array( 'id' ), array_keys( $select['properties'] ) ),
			array_keys( $post_schema['properties'] ),
			'Every selectable field should be described in the output, in the same order.'
		);
		$this->assertSame( array( 'object', 'null' ), $post_schema['properties']['author']['type'], 'The author relation should be an object, or null when the author is gone.' );
		$this->assertSame( array( 'object', 'null' ), $post_schema['properties']['parent']['type'], 'The parent relation should be an object, or null for a top-level post.' );
		$this->assertSame( array( 'string', 'null' ), $post_schema['properties']['content']['type'], 'Content should be a string, or null when withheld.' );
		$this->assertArrayNotHasKey( 'oneOf', $query_schema, 'The content-query output should have one shape.' );
		$this->assertSame( array( 'posts', 'total', 'total_pages' ), $query_schema['required'], 'The paged list should require all of its properties.' );
		$this->assertSame( $post_schema, $query_schema['properties']['posts']['items'], 'Listed posts should share the content-get output schema.' );
	}

	/**
	 * A post type registered by another active plugin and flagged `show_in_abilities`
	 * is exposed by the abilities, both in the input enums and in query results.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_exposes_a_post_type_registered_by_another_plugin(): void {
		register_post_type(
			'content_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$enum = wp_get_ability( 'core/content-query' )->get_input_schema()['properties']['where']['properties']['type']['items']['enum'];
		$this->assertContains( 'content_cpt', $enum, 'Custom post types marked show_in_abilities should appear in the query enum.' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'content_cpt',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-query' )->execute( array( 'where' => array( 'type' => array( 'content_cpt' ) ) ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $post_id, $ids, 'The custom post type should be queryable through the content abilities.' );
	}

	/**
	 * Returns `show_in_abilities` values other than `true`.
	 *
	 * @return array<string, array{value: mixed}> Non-boolean `show_in_abilities` test cases.
	 */
	public function data_show_in_abilities_values_other_than_true(): array {
		return array(
			'array of operations' => array(
				'value' => array( 'create' ),
			),
			'string "false"'      => array(
				'value' => 'false',
			),
			'integer 1'           => array(
				'value' => 1,
			),
		);
	}

	/**
	 * Only a `show_in_abilities` value of `true` exposes a post type, so other values, such as
	 * arrays, can be given a meaning later.
	 *
	 * @ticket 66268
	 * @dataProvider data_show_in_abilities_values_other_than_true
	 * @since 7.2.0
	 *
	 * @param mixed $value The `show_in_abilities` value to register the post type with.
	 */
	public function test_does_not_expose_a_post_type_with_a_show_in_abilities_value_other_than_true( $value ): void {
		register_post_type(
			'content_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => $value,
			)
		);

		$this->assertFalse( get_post_type_object( 'content_cpt' )->show_in_abilities, 'The post type object should hold false.' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'content_cpt',
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$enum = wp_get_ability( 'core/content-query' )->get_input_schema()['properties']['where']['properties']['type']['items']['enum'];
		$this->assertNotContains( 'content_cpt', $enum, 'The post type should not appear in the query enum.' );

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );
		$this->assertWPError( $result, 'A post of the post type should not be readable by ID.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'The by-ID lookup should be denied.' );
	}

	/**
	 * A schema filter can expose a post type that is registered after the abilities.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_schema_filter_exposes_late_registered_post_type(): void {
		$this->login_as( 'administrator' );

		$amend_schema = static function ( array $args, string $name ): array {
			if ( 'core/content-query' === $name ) {
				$args['input_schema']['properties']['where']['properties']['type']['items']['enum'][] = 'late_cpt';
			}

			if ( 'core/content-get' === $name ) {
				foreach ( $args['input_schema']['properties']['post']['oneOf'] as $index => $branch ) {
					if ( isset( $branch['properties']['type']['enum'] ) ) {
						$args['input_schema']['properties']['post']['oneOf'][ $index ]['properties']['type']['enum'][] = 'late_cpt';
					}
				}
			}

			return $args;
		};
		add_filter( 'wp_register_ability_args', $amend_schema, 10, 2 );
		$this->register_abilities();

		$enum = wp_get_ability( 'core/content-query' )->get_input_schema()['properties']['where']['properties']['type']['items']['enum'];
		$this->assertContains( 'late_cpt', $enum, 'The ability args filter should amend the frozen schema enum.' );

		register_post_type(
			'late_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'late_cpt',
				'post_name'   => 'late-post',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array( 'type' => array( 'late_cpt' ) ),
				'select' => array( 'slug' => true ),
			)
		);

		$this->assertSame( array( $post_id ), wp_list_pluck( $result['posts'], 'id' ), 'The late post type should be queryable after it becomes exposed.' );

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'late_cpt',
					'slug' => 'late-post',
				),
			)
		);

		$this->assertSame( $post_id, $result['id'], 'A post of the late post type should be readable by slug after it becomes exposed.' );
	}

	/**
	 * A published post can be fetched by ID, with the lean default selection.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_published_post_by_id(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );

		$this->assertIsArray( $result, 'The by-ID lookup should return a post array.' );
		$this->assertSame( $post_id, $result['id'], 'The by-ID lookup should return the requested post directly.' );
		$this->assertSame( 'Hello Content', $result['title'], 'The plain-text title should be returned by default.' );
		$this->assertSame( get_permalink( $post_id ), $result['url'], 'The permalink should be returned by default.' );
		$this->assertSame(
			array( 'id', 'type', 'status', 'slug', 'url', 'title', 'published_at' ),
			array_keys( $result ),
			'An omitted selection should return the lean default fields.'
		);
	}

	/**
	 * Schema-valid object input behaves like its array form, including nested objects.
	 *
	 * WP_Ability validates `stdClass` as object input but does not coerce the value before
	 * passing it to the permission and execute callbacks, so both must preserve its fields.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_published_post_by_id_accepts_object_input(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published_content'];
		$ability = wp_get_ability( 'core/content-get' );
		$input   = (object) array(
			'post'   => (object) array( 'id' => $post_id ),
			'select' => (object) array( 'title' => (object) array( 'format' => 'html' ) ),
		);

		$this->assertTrue( $ability->validate_input( $input ), 'Object input should pass the registered schema.' );

		$result = $ability->execute( $input );

		$this->assertIsArray( $result, 'Object input should execute the by-ID lookup.' );
		$this->assertSame( $post_id, $result['id'], 'Object input should preserve the requested post ID.' );
		$this->assertSame( 'Hello Content', $result['title'], 'Object input should preserve the selected fields.' );
		$this->assertSame( array( 'id', 'title' ), array_keys( $result ), 'Object input should use the selected field projection.' );
	}

	/**
	 * A single post fetched by ID can return its content as rendered HTML and as the stored source.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_published_post_by_id_can_return_content_fields(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published_content'];
		$ability = wp_get_ability( 'core/content-get' );

		$rendered = $ability->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'type'    => true,
					'content' => true,
				),
			)
		);
		$source   = $ability->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( 'content' => array( 'format' => 'source' ) ),
			)
		);

		$this->assertSame( $post_id, $rendered['id'], 'The by-ID lookup should return the requested post.' );
		$this->assertSame( 'post', $rendered['type'], 'The by-ID lookup should return the post type as type.' );
		$this->assertStringContainsString( '<p>Body here.</p>', $rendered['content'], 'Selecting content should return rendered HTML by default.' );
		$this->assertSame( 'Body here.', $source['content'], 'The source format should return the stored content.' );
	}

	/**
	 * A missing post ID is denied before execution can probe the requested object.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_by_missing_id_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ) );

		$this->assertWPError( $result, 'Missing posts should be denied before execution probes object details.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Missing posts should fail closed as a permission error.' );
	}

	/**
	 * An ID beyond the integer range is denied instead of wrapping around onto another post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_by_id_beyond_the_integer_range_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		// Floats near 2^64 are 4096 apart, so 2^64 + N is exact for a multiple of 4096 and casts to N.
		$aliased_id = self::factory()->post->create(
			array(
				'import_id'   => 4096 * 1024,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 4096 * 1024, $aliased_id, 'The aliased post should have the requested ID.' );

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => 2 ** 64 + $aliased_id ) ) );

		$this->assertWPError( $result, 'An ID beyond the integer range should not resolve a post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'An ID beyond the integer range should fail closed as a permission error.' );
	}

	/**
	 * A post type guard mismatch is denied before execution can probe the requested object.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_by_id_with_mismatched_post_type_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'id'   => $post_id,
					'type' => 'page',
				),
			)
		);

		$this->assertWPError( $result, 'Mismatched post type guards should deny the lookup.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Mismatched post type guards should fail closed as a permission error.' );
	}

	/**
	 * A post from a post type not exposed to abilities is denied.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_by_id_for_unexposed_post_type_is_denied(): void {
		register_post_type(
			'hidden_cpt',
			array(
				'public'       => true,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		$this->login_as( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'hidden_cpt',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id, 'The hidden custom post should be created for the denial check.' );

		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );

		$this->assertWPError( $result, 'Posts from unexposed post types should be denied.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Unexposed post types should fail closed as a permission error.' );
	}

	/**
	 * A status that is public but not viewable is not exposed to read-only users.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_public_non_viewable_status_is_denied_for_read_only_users(): void {
		register_post_status(
			'public_hidden',
			array(
				'label'              => 'Public hidden',
				'public'             => true,
				'publicly_queryable' => false,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'public_hidden',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );

		$this->assertWPError( $result, 'Read-only users should not receive public statuses that are not viewable.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Non-viewable public statuses should fail closed for read-only users.' );
	}

	/**
	 * A status that is public but not viewable remains available to users who can edit it.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_public_non_viewable_status_is_readable_with_edit_access(): void {
		register_post_status(
			'public_hidden',
			array(
				'label'              => 'Public hidden',
				'public'             => true,
				'publicly_queryable' => false,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden public status',
				'post_status' => 'public_hidden',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );

		$this->assertIsArray( $result, 'Editors should be able to access posts they can edit even when the status is not publicly viewable.' );
		$this->assertSame( $post_id, $result['id'], 'The editable post should be returned.' );
		$this->assertSame( 'Hidden public status', $result['title'], 'The editable post should include normal default fields.' );
	}

	/**
	 * A post that inherits its status from a readable parent is readable.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_inherited_post_is_readable_when_parent_is_readable(): void {
		register_post_type(
			'inherit_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$parent_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['administrator'],
				'post_type'   => 'inherit_cpt',
				'post_status' => 'publish',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['administrator'],
				'post_type'   => 'inherit_cpt',
				'post_parent' => $parent_id,
				'post_status' => 'inherit',
				'post_title'  => 'Inherited child',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $child_id ) ) );

		$this->assertIsArray( $result, 'Inherited posts should be readable when their parent is readable.' );
		$this->assertSame( $child_id, $result['id'], 'The inherited child should be returned.' );
		$this->assertSame( 'Inherited child', $result['title'], 'The inherited child should include normal default fields.' );
	}

	/**
	 * A post with an inherited status but no readable parent is denied.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_inherited_post_without_parent_is_denied_for_read_only_users(): void {
		register_post_type(
			'inherit_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['administrator'],
				'post_type'   => 'inherit_cpt',
				'post_status' => 'inherit',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'id' => $post_id ) ) );

		$this->assertWPError( $result, 'Inherited posts without a readable parent should be denied.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Orphaned inherited posts should fail closed.' );
	}

	/**
	 * A query returns only published posts by default.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_returns_only_published_by_default(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = wp_get_ability( 'core/content-query' )->execute( array( 'where' => array( 'type' => array( 'post' ) ) ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $published, $ids, 'Published posts should be returned by default.' );
		$this->assertNotContains( $draft, $ids, 'Draft posts should not be returned by default.' );
	}

	/**
	 * Query results are ordered by publication date, newest first, whatever order `where.ids` uses.
	 *
	 * Pins both halves of what the schema advertises. The default order matches the REST
	 * posts controller, and `where.ids` only filters the query, so a caller that passes IDs
	 * in a chosen order must not expect them back in it.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_orders_posts_newest_first_regardless_of_ids_order(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$oldest = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		$middle = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-02-01 10:00:00',
			)
		);
		$newest = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-03-01 10:00:00',
			)
		);

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					// Deliberately neither date order nor ID order.
					'ids'  => array( $middle, $newest, $oldest ),
				),
				'select' => array( 'slug' => true ),
			)
		);

		$this->assertSame(
			array( $newest, $middle, $oldest ),
			wp_list_pluck( $result['posts'], 'id' ),
			'Results should be ordered by publication date, newest first, not by the order of the IDs.'
		);
	}

	/**
	 * The `where.ids` filter still respects the requested post type.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_ids_respect_requested_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => array( $page_id, $post_id ),
				),
				'select' => array( 'slug' => true ),
			)
		);

		$this->assertSame( array( $post_id ), wp_list_pluck( $result['posts'], 'id' ), 'The IDs filter should not leak posts from other post types.' );
	}

	/**
	 * The `where.ids` filter accepts a single post ID, as the input schema does.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_ids_accept_a_single_post_id(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => $post_id,
				),
				'select' => array( 'slug' => true ),
			)
		);

		$this->assertSame( array( $post_id ), wp_list_pluck( $result['posts'], 'id' ), 'A single post ID should limit the query to that post.' );
	}

	/**
	 * The `where.ids` filter still respects row-level permissions.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_ids_respect_row_level_permissions(): void {
		$author_a = self::$user_ids['author'];
		$author_b = self::$user_ids['author_secondary'];

		$draft_a = self::factory()->post->create(
			array(
				'post_author' => $author_a,
				'post_status' => 'draft',
			)
		);
		$draft_b = self::factory()->post->create(
			array(
				'post_author' => $author_b,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $author_b );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
					'ids'    => array( $draft_a, $draft_b ),
				),
				'select' => array( 'slug' => true ),
			)
		);

		$this->assertSame( array( $draft_b ), wp_list_pluck( $result['posts'], 'id' ), 'The IDs filter should not bypass row-level draft permissions.' );
	}

	/**
	 * A query can return drafts with their content as rendered HTML and as the stored source.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_draft_ids_can_return_content_fields(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$draft = self::factory()->post->create(
			array(
				'post_title'   => 'Draft content fields',
				'post_content' => 'Draft body for content fields.',
				'post_status'  => 'draft',
			)
		);

		$where   = array(
			'type'   => array( 'post' ),
			'status' => array( 'draft' ),
			'ids'    => array( $draft ),
		);
		$ability = wp_get_ability( 'core/content-query' );

		$rendered = $ability->execute(
			array(
				'where'  => $where,
				'select' => array(
					'type'    => true,
					'status'  => true,
					'content' => true,
				),
			)
		);
		$source   = $ability->execute(
			array(
				'where'  => $where,
				'select' => array( 'content' => array( 'format' => 'source' ) ),
			)
		);

		$this->assertSame( array( $draft ), wp_list_pluck( $rendered['posts'], 'id' ), 'The draft query should return only the requested draft.' );
		$this->assertSame( 'post', $rendered['posts'][0]['type'], 'Query responses should return the post type as type.' );
		$this->assertSame( 'draft', $rendered['posts'][0]['status'], 'The draft query should expose the requested draft status.' );
		$this->assertStringContainsString( 'Draft body for content fields.', $rendered['posts'][0]['content'], 'Draft query results should include rendered content when selected.' );
		$this->assertSame( 'Draft body for content fields.', $source['posts'][0]['content'], 'Draft query results should include the stored content in the source format.' );
	}

	/**
	 * A post reference by slug without a post type is rejected by the input schema.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_slug_requires_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'slug' => 'whatever' ),
			)
		);

		$this->assertWPError( $result, 'A post reference by slug without a post type should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Invalid slug references should return an input error.' );
	}

	/**
	 * A post referenced by post type and slug is returned directly.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_published_post_by_slug(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'content-slug-mode',
				'post_title'  => 'Content Slug Mode',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'content-slug-mode',
				),
			)
		);

		$this->assertIsArray( $result, 'The slug lookup should return a post array.' );
		$this->assertSame( $post_id, $result['id'], 'The slug lookup should return the requested post directly.' );
		$this->assertSame( 'content-slug-mode', $result['slug'], 'The slug lookup should return the matching slug.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The slug lookup should return the post itself, not a list of posts.' );
		$this->assertArrayNotHasKey( 'total', $result, 'The slug lookup should not return query totals.' );
	}

	/**
	 * A post fetched by slug can return its content as rendered HTML and as the stored source.
	 *
	 * Only one format can be selected per text field in a call, so each format is requested
	 * in its own call.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_published_post_by_slug_can_return_html_and_source_content(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_name'    => 'content-slug-fields',
				'post_title'   => 'Content Slug Fields',
				'post_content' => 'Slug body for content fields.',
				'post_status'  => 'publish',
			)
		);

		$reference = array(
			'type' => 'post',
			'slug' => 'content-slug-fields',
		);

		$html   = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => $reference,
				'select' => array(
					'type'    => true,
					'slug'    => true,
					'content' => array( 'format' => 'html' ),
				),
			)
		);
		$source = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => $reference,
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$this->assertIsArray( $html, 'The slug lookup should return a post array when the html format is selected.' );
		$this->assertSame( $post_id, $html['id'], 'The slug lookup should return the requested post.' );
		$this->assertSame( 'post', $html['type'], 'The slug lookup should return the post type as type.' );
		$this->assertSame( 'content-slug-fields', $html['slug'], 'The slug lookup should return the matching slug.' );
		$this->assertStringContainsString( 'Slug body for content fields.', $html['content'], 'Slug lookups should return the rendered content when the html format is selected.' );
		$this->assertArrayNotHasKey( 'posts', $html, 'The slug lookup should return the post itself, not a list of posts.' );
		$this->assertIsArray( $source, 'The slug lookup should return a post array when the source format is selected.' );
		$this->assertSame( $post_id, $source['id'], 'The slug lookup should return the requested post when the source format is selected.' );
		$this->assertSame( 'Slug body for content fields.', $source['content'], 'Slug lookups should return the stored content when the source format is selected.' );
	}

	/**
	 * A published post is not hidden behind more same-slug drafts than a page holds.
	 *
	 * The slug lookup is a singular WP_Query, so it returns every matching row and no page
	 * size applies. Bounding it with `post_name__in` and a page size would page straight past
	 * an older published post, because the query is ordered newest first.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_slug_lookup_is_not_bounded_by_a_page_size(): void {
		global $wpdb;

		/*
		 * Author every post as the administrator, so the subscriber who reads them below can
		 * only see the published one.
		 */
		$this->login_as( 'administrator' );

		// The published post owns the slug and is the oldest of the group.
		$published = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'slug-not-bounded',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);

		/*
		 * Drafts skip slug uniqueness, so they can all share the slug. Create more of them
		 * than the largest page core/content-query will ever return.
		 */
		for ( $i = 0; $i < 110; $i++ ) {
			self::factory()->post->create(
				array(
					'post_status' => 'draft',
					'post_name'   => 'slug-not-bounded',
					'post_date'   => '2026-03-01 10:00:00',
				)
			);
		}

		$sharing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s", 'slug-not-bounded' ) ); // phpcs:ignore WordPress.DB
		$this->assertGreaterThan( 100, $sharing, 'Precondition: more posts share the slug than a single page holds.' );

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'slug-not-bounded',
				),
			)
		);

		$this->assertIsArray( $result, 'The published post should resolve even though the drafts fill more than a page.' );
		$this->assertSame( $published, $result['id'], 'The readable published post should still resolve.' );
	}

	/**
	 * A post whose slug is the literal string "0" is fetched by slug.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_get_single_post_by_slug_zero(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		/*
		 * Core regenerates an "empty" post_name from the title, so a post titled "0"
		 * ends up with the literal slug "0".
		 */
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => '0',
				'post_name'   => '0',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( '0', get_post( $post_id )->post_name, 'Precondition: the post slug should be the literal string "0".' );

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => '0',
				),
			)
		);

		$this->assertIsArray( $result, 'The slug lookup should return a post array.' );
		$this->assertSame( $post_id, $result['id'], 'The slug lookup should resolve the literal "0" slug to the post.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'A "0" slug lookup should return the post itself, not a list of posts.' );
	}

	/**
	 * Query-only arguments, such as paging, cannot be passed to core/content-get.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_slug_rejects_query_only_params(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'content-slug-mode',
				),
				'page' => array( 'size' => 10 ),
			)
		);

		$this->assertWPError( $result, 'Combining a post reference with query-only params should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Query-only params passed to core/content-get should return an input error.' );
	}

	/**
	 * Returns a role that cannot read another user's draft and a role that can.
	 *
	 * @return array<string, array{role: string}> Role test cases.
	 */
	public function data_roles_with_and_without_access_to_others_drafts(): array {
		return array(
			'subscriber' => array(
				'role' => 'subscriber',
			),
			'editor'     => array(
				'role' => 'editor',
			),
		);
	}

	/**
	 * A newer draft sharing a published post's slug does not shadow the published post.
	 *
	 * @ticket 66268
	 * @dataProvider data_roles_with_and_without_access_to_others_drafts
	 * @since 7.2.0
	 *
	 * @param string $role The role to look up the slug as.
	 */
	public function test_slug_lookup_prefers_published_post_over_newer_draft( string $role ): void {
		$published_id = self::factory()->post->create(
			array(
				'post_name'   => 'shadowed-slug',
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		// Drafts skip slug uniqueness, so a newer draft can share the published slug.
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['administrator'],
				'post_name'   => 'shadowed-slug',
				'post_status' => 'draft',
				'post_date'   => '2026-06-01 10:00:00',
			)
		);

		$this->assertSame( 'shadowed-slug', get_post( $draft_id )->post_name, 'Precondition: the draft should share the published slug.' );

		$this->register_abilities();
		$this->login_as( $role );

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'shadowed-slug',
				),
			)
		);

		$this->assertIsArray( $result, 'The slug lookup should succeed.' );
		$this->assertSame( $published_id, $result['id'], 'The slug lookup should resolve to the published post.' );
	}

	/**
	 * A slug held only by a draft resolves for its author and stays denied for readers.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_slug_lookup_resolves_draft_only_slug_by_readability(): void {
		$author_id = self::$user_ids['author'];
		$draft_id  = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_name'   => 'draft-only-slug',
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $author_id );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'draft-only-slug',
				),
			)
		);

		$this->assertIsArray( $result, 'The draft author should resolve their own draft by slug.' );
		$this->assertSame( $draft_id, $result['id'], 'The draft author should receive their own draft.' );

		$this->login_as( 'subscriber' );

		$denied = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'draft-only-slug',
				),
			)
		);

		$this->assertWPError( $denied, 'Readers should not resolve a slug held only by an unreadable draft.' );
		$this->assertSame( 'ability_invalid_permissions', $denied->get_error_code(), 'Unreadable slug lookups should fail closed as a permission error.' );
	}

	/**
	 * Returns field selections that a request is checked against with read or with edit access.
	 *
	 * Selecting the stored source of a text field is edit intent; any other format only
	 * needs read access.
	 *
	 * @return array<string, array{select: array<string, mixed>}> Field selection test cases.
	 */
	public function data_read_and_edit_selections(): array {
		return array(
			'read selection' => array(
				'select' => array(
					'content' => array( 'format' => 'html' ),
				),
			),
			'edit selection' => array(
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			),
		);
	}

	/**
	 * A slug lookup resolves only posts of the requested post type, also when a query filter
	 * adds post types to single views.
	 *
	 * WP_Query treats the lookup's `name` query as a single view, so a `pre_get_posts`
	 * callback without an is_main_query() check also runs for it. The newest post sharing
	 * the slug would otherwise win, even from a post type that is not exposed.
	 *
	 * @ticket 66268
	 * @dataProvider data_read_and_edit_selections
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $select The fields to select.
	 */
	public function test_slug_lookup_skips_posts_of_other_post_types( array $select ): void {
		register_post_type(
			'hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		$post_id   = self::factory()->post->create(
			array(
				'post_name'   => 'shared-slug',
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		$other_ids = array(
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_name'   => 'shared-slug',
					'post_status' => 'publish',
					'post_date'   => '2026-03-01 10:00:00',
				)
			),
			self::factory()->post->create(
				array(
					'post_type'   => 'hidden_cpt',
					'post_name'   => 'shared-slug',
					'post_status' => 'publish',
					'post_date'   => '2026-06-01 10:00:00',
				)
			),
		);

		foreach ( $other_ids as $other_id ) {
			$this->assertSame( 'shared-slug', get_post( $other_id )->post_name, 'Precondition: newer posts of other types should share the slug.' );
		}

		add_action(
			'pre_get_posts',
			static function ( WP_Query $query ): void {
				if ( $query->is_single() ) {
					$query->set( 'post_type', array( 'post', 'page', 'hidden_cpt' ) );
				}
			}
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array(
					'type' => 'post',
					'slug' => 'shared-slug',
				),
				'select' => $select,
			)
		);

		$this->assertIsArray( $result, 'The slug lookup should succeed.' );
		$this->assertSame( $post_id, $result['id'], 'The slug lookup should resolve to the post of the requested type.' );
	}

	/**
	 * The `where.ids` filter belongs to core/content-query and cannot be passed to core/content-get.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_rejects_the_ids_filter(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$by_id   = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'  => array( 'id' => self::$post_ids['published'] ),
				'where' => array( 'ids' => array( self::$post_ids['published'] ) ),
			)
		);
		$by_slug = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'  => array(
					'type' => 'post',
					'slug' => 'whatever',
				),
				'where' => array( 'ids' => array( self::$post_ids['published'] ) ),
			)
		);

		$this->assertWPError( $by_id, 'The ids filter should fail validation for a post referenced by ID.' );
		$this->assertSame( 'ability_invalid_input', $by_id->get_error_code(), 'A reference by ID plus the ids filter should return an input error.' );
		$this->assertWPError( $by_slug, 'The ids filter should fail validation for a post referenced by slug.' );
		$this->assertSame( 'ability_invalid_input', $by_slug->get_error_code(), 'A reference by slug plus the ids filter should return an input error.' );
	}

	/**
	 * The `select` input limits the returned keys.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_select_limits_returned_keys(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'slug'  => true,
					'title' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertSame(
			array( 'id', 'slug', 'title' ),
			array_keys( $result ),
			'The selection should limit the response to the ID and exactly the selected keys.'
		);
	}

	/**
	 * The `id` is returned even though it is not part of the selection.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_select_always_includes_the_id(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'title' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertSame( array( 'id', 'title' ), array_keys( $result ), 'The ID should be returned along with the selected fields.' );
		$this->assertSame( $post_id, $result['id'], 'The returned ID should be the requested post.' );
	}

	/**
	 * An unknown selected field name fails schema validation.
	 *
	 * Unlike fields a post type does not support, which are omitted per post, a field
	 * name that is not part of the supported set is rejected before the ability executes.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_unknown_requested_field_fails_schema_validation(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['published_content'] ),
				'select' => array(
					'title'       => true,
					'bogus_field' => true,
				),
			)
		);

		$this->assertWPError( $result, 'An unknown selected field should fail the request.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Unknown fields should use the invalid input error.' );
	}

	/**
	 * Logged-out users cannot run either content ability.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );
		$this->register_abilities();

		$queried = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array( 'type' => array( 'post' ) ),
			)
		);
		$fetched = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'id' => self::$post_ids['published'] ),
			)
		);

		$this->assertWPError( $queried, 'Logged-out users should not be allowed to query posts.' );
		$this->assertSame( 'ability_invalid_permissions', $queried->get_error_code(), 'Logged-out post queries should receive a permission error.' );
		$this->assertWPError( $fetched, 'Logged-out users should not be allowed to get a post, even a published one.' );
		$this->assertSame( 'ability_invalid_permissions', $fetched->get_error_code(), 'Logged-out post lookups should receive a permission error.' );
	}

	/**
	 * Subscribers can select the rendered title and content of published posts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_subscriber_can_request_published_content(): void {
		$post_id = self::$post_ids['published_content'];

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array( 'type' => array( 'post' ) ),
				'select' => array(
					'title'   => array( 'format' => 'html' ),
					'content' => true,
				),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $post_id, $ids, 'Subscribers should be able to query readable published posts.' );
		$post_index = array_search( $post_id, $ids, true );
		$this->assertIsInt( $post_index, 'The published post should be present in the subscriber query response.' );
		$post = $result['posts'][ $post_index ];
		$this->assertSame( 'Hello Content', $post['title'], 'Subscribers should receive rendered titles.' );
		$this->assertStringContainsString( 'Body here.', $post['content'], 'Subscribers should receive rendered content.' );
		$this->assertSame( array( 'id', 'title', 'content' ), array_keys( $post ), 'Subscribers should receive only the selected rendered fields, and no stored source.' );
	}

	/**
	 * Subscribers can fetch a published post by ID.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_subscriber_can_get_single_published_post_by_id(): void {
		$post_id = self::$post_ids['published_content'];

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'id' => $post_id ),
			)
		);

		$this->assertIsArray( $result, 'Subscribers should be able to fetch a readable published post by ID.' );
		$this->assertSame( 'Hello Content', $result['title'], 'Subscribers should receive the plain-text title by default.' );
		$this->assertSame(
			array( 'id', 'type', 'status', 'slug', 'url', 'title', 'published_at' ),
			array_keys( $result ),
			'The default selection should leave out the content, which requires an explicit selection, and return no stored source.'
		);
	}

	/**
	 * Subscribers cannot select the stored source of a text field when querying posts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_denies_source_format_to_subscribers(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array( 'type' => array( 'post' ) ),
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be able to select the source format when querying posts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber source-format queries should return a permission error.' );
	}

	/**
	 * Subscribers cannot select the stored source of a text field for a single post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_denies_source_format_to_subscribers(): void {
		$post_id = self::$post_ids['published'];

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be able to select the source format for a post referenced by ID.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber source-format lookups by ID should return a permission error.' );
	}

	/**
	 * Users who cannot edit another user's post do not receive the stored source by default.
	 *
	 * @ticket 66268
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 * @since 7.2.0
	 *
	 * @param string $role The role to test.
	 */
	public function test_content_get_default_selection_omits_source_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['limited_role_content'];

		$this->login_as( $role );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'id' => $post_id ),
			)
		);

		$this->assertIsArray( $result, 'The readable published post should be returned.' );
		$this->assertSame( 'Readable title', $result['title'], 'The plain-text title should remain visible.' );
		$this->assertSame(
			array( 'id', 'type', 'status', 'slug', 'url', 'title', 'published_at' ),
			array_keys( $result ),
			'The lean default selection should leave out the excerpt and content, and return no stored source.'
		);
	}

	/**
	 * Users who cannot edit another user's post cannot explicitly select the stored source.
	 *
	 * @ticket 66268
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 * @since 7.2.0
	 *
	 * @param string $role The role to test.
	 */
	public function test_content_get_source_format_is_denied_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['limited_role_content'];

		$this->login_as( $role );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$this->assertWPError( $result, 'Source format requests should fail for users without edit access.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Source format requests should require edit access to the post.' );
	}

	/**
	 * Subscribers cannot request draft posts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_subscriber_cannot_request_draft_status(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
				),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be allowed to query draft posts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber draft queries should return a permission error.' );
	}

	/**
	 * Subscribers cannot request private posts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_subscriber_cannot_request_private_status(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'private' ),
				),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be allowed to query private posts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber private queries should return a permission error.' );
	}

	/**
	 * An author can pass the draft gate but only sees their own drafts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_author_cannot_see_other_authors_drafts(): void {
		$author_a = self::$user_ids['author'];
		$author_b = self::$user_ids['author_secondary'];

		$draft_a = self::factory()->post->create(
			array(
				'post_author' => $author_a,
				'post_status' => 'draft',
			)
		);
		$draft_b = self::factory()->post->create(
			array(
				'post_author' => $author_b,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $author_b );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
				),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $draft_b, $ids, 'Authors should see their own drafts.' );
		$this->assertNotContains( $draft_a, $ids, 'Authors should not see another author\'s drafts.' );
	}

	/**
	 * Query totals mirror WP_Query even when row-level permissions withhold rows.
	 *
	 * This matches the REST posts controller: `posts` only contains rows the current
	 * user can read, while `total` and `total_pages` describe the underlying query.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_totals_may_include_rows_withheld_by_row_level_permissions(): void {
		$author_a = self::$user_ids['author'];
		$author_b = self::$user_ids['author_secondary'];

		$draft_a = self::factory()->post->create(
			array(
				'post_author' => $author_a,
				'post_status' => 'draft',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		$draft_b = self::factory()->post->create(
			array(
				'post_author' => $author_b,
				'post_status' => 'draft',
				'post_date'   => '2026-01-02 10:00:00',
			)
		);

		wp_set_current_user( $author_b );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
				),
				'page'  => array( 'size' => 1 ),
			)
		);

		$this->assertSame( array( $draft_b ), wp_list_pluck( $result['posts'], 'id' ), 'Authors should receive only drafts they can read.' );
		$this->assertNotContains( $draft_a, wp_list_pluck( $result['posts'], 'id' ), 'Rows withheld by row-level permissions should not be returned.' );
		$this->assertGreaterThan( count( $result['posts'] ), $result['total'], 'Totals may include rows withheld by row-level permission checks.' );
		$this->assertSame( 2, $result['total_pages'], 'Page counts should be based on the underlying query total, matching REST behavior.' );
	}

	/**
	 * The parent filters are rejected for non-hierarchical post types, mirroring REST.
	 *
	 * Both `where.parent` and `where.top_level`, which replaced a parent of 0, are only
	 * supported for hierarchical post types.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_rejects_parent_filters_for_non_hierarchical_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$by_parent = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'parent' => array( 'id' => self::$post_ids['published'] ),
				),
			)
		);
		$top_level = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'      => array( 'post' ),
					'top_level' => true,
				),
			)
		);

		$this->assertWPError( $by_parent, 'The where.parent filter should be rejected for non-hierarchical post types.' );
		$this->assertSame( 'content_invalid_filter', $by_parent->get_error_code(), 'Unsupported where.parent filters should return a filter error.' );
		$this->assertSame(
			array( 'where.parent' => $by_parent->get_error_message() ),
			$by_parent->get_error_data()['params'],
			'The error data should map the unsupported where.parent filter to the error message.'
		);
		$this->assertWPError( $top_level, 'The where.top_level filter should be rejected for non-hierarchical post types.' );
		$this->assertSame( 'content_invalid_filter', $top_level->get_error_code(), 'Unsupported where.top_level filters should return a filter error.' );
		$this->assertSame(
			array( 'where.top_level' => $top_level->get_error_message() ),
			$top_level->get_error_data()['params'],
			'The error data should map the unsupported where.top_level filter to the error message.'
		);
	}

	/**
	 * The `where.parent` filter narrows hierarchical queries to children of the given post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_filters_pages_by_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'page' ),
					'parent' => array( 'id' => $parent_id ),
				),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertSame( array( $child_id ), $ids, 'The where.parent filter should return only the children of the given page.' );
	}

	/**
	 * The `where.author` filter is rejected for post types without author support, mirroring REST.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_rejects_author_filter_for_post_type_without_author_support(): void {
		register_post_type(
			'no_author_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'no_author_cpt' ),
					'author' => array( 'slug' => get_userdata( self::$user_ids['author'] )->user_nicename ),
				),
			)
		);

		$this->assertWPError( $result, 'The where.author filter should be rejected for post types without author support.' );
		$this->assertSame( 'content_invalid_filter', $result->get_error_code(), 'Unsupported author filters should return a filter error.' );
		$this->assertSame(
			array( 'where.author' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the unsupported where.author filter to the error message.'
		);
	}

	/**
	 * The `where.author` filter narrows queries to posts by the author it references by slug,
	 * and each post returns its author's ID and slug.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_filters_posts_by_author(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$author_slug = get_userdata( self::$user_ids['author'] )->user_nicename;
		$mine_id     = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author'],
				'post_status' => 'publish',
			)
		);
		$other_id    = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type'   => array( 'post' ),
					'author' => array( 'slug' => $author_slug ),
				),
				'page'   => array( 'size' => 100 ),
				'select' => array(
					'author' => array( 'fields' => array( 'slug' ) ),
				),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $mine_id, $ids, 'The where.author filter should include the author\'s posts.' );
		$this->assertNotContains( $other_id, $ids, 'The where.author filter should exclude other authors\' posts.' );
		$this->assertSame(
			array(
				array(
					'id'   => self::$user_ids['author'],
					'slug' => $author_slug,
				),
			),
			array_unique( wp_list_pluck( $result['posts'], 'author' ), SORT_REGULAR ),
			'Each post should return its author\'s ID and slug.'
		);
	}

	/**
	 * Querying posts returns only posts of the requested post type, also when a query filter
	 * adds post types to the blog home.
	 *
	 * WP_Query treats the ability's query as the blog home, so a `pre_get_posts` callback
	 * without an is_main_query() check also runs for it. The read check would let through
	 * posts of other exposed post types, and the edit check posts of any post type.
	 *
	 * @ticket 66268
	 * @dataProvider data_read_and_edit_selections
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $select The fields to select.
	 */
	public function test_content_query_skips_posts_of_other_post_types( array $select ): void {
		register_post_type(
			'hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		// With several post types, an editable query keeps only the current user's posts.
		$ids = array();
		foreach ( array( 'post', 'page', 'hidden_cpt' ) as $post_type ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_author' => self::$user_ids['administrator'],
					'post_type'   => $post_type,
					'post_status' => 'publish',
				)
			);
		}

		add_action(
			'pre_get_posts',
			static function ( WP_Query $query ): void {
				if ( $query->is_home() ) {
					$query->set( 'post_type', array( 'post', 'page', 'hidden_cpt' ) );
				}
			}
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
				'select' => $select,
			)
		);

		$this->assertSame( 3, $result['total'], 'Precondition: the query filter should add the other post types.' );
		$this->assertSame( array( $ids[0] ), wp_list_pluck( $result['posts'], 'id' ), 'Querying posts should return only posts of the requested type.' );
	}

	/**
	 * The stored source of the content is available to users who can edit the post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_content_source_visible_to_editor(): void {
		$post_id = self::$post_ids['published_content'];

		$this->login_as( 'editor' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$this->assertSame(
			'Body here.',
			$result['content'],
			'Editors should receive the stored source of the content when they select the source format.'
		);
	}

	/**
	 * Password-protected content is visible to users who can edit the post, both as the
	 * stored source and as rendered HTML.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_password_protected_content_visible_to_editor(): void {
		$post_id = self::$post_ids['password_protected'];

		$this->login_as( 'editor' );
		$this->register_abilities();

		// One format per text field per call, so the source and the HTML are requested separately.
		$source = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'content' => array( 'format' => 'source' ),
				),
			)
		);

		$html = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'content' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertSame(
			'Top secret body.',
			$source['content'],
			'Editors should receive the stored source of password-protected content.'
		);
		$this->assertStringContainsString(
			'Top secret body.',
			$html['content'],
			'Editors should receive password-protected content rendered as HTML.'
		);
	}

	/**
	 * Password-protected content rendered as HTML or plain text is withheld as null from
	 * users who cannot edit the post.
	 *
	 * @ticket 66268
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 * @since 7.2.0
	 *
	 * @param string $role The role to test.
	 */
	public function test_content_get_password_protected_content_is_null_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['password_protected'];

		$this->login_as( $role );
		$this->register_abilities();

		foreach ( array( 'html', 'text' ) as $format ) {
			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post'   => array( 'id' => $post_id ),
					'select' => array(
						'content'            => array( 'format' => $format ),
						'password_protected' => true,
					),
				)
			);

			$this->assertArrayHasKey( 'content', $result, "Withheld content in the {$format} format should be present as null, not left out." );
			$this->assertNull( $result['content'], "Password-protected content in the {$format} format should be withheld." );
			$this->assertTrue( $result['password_protected'], 'The password_protected flag should reveal the post is password-protected.' );
		}
	}

	/**
	 * Password-protected excerpts render for users who can edit the post.
	 *
	 * The excerpt is generated from the content, which get_the_content() replaces with the
	 * password form unless the ability unlocks the post for its editor.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_password_protected_excerpt_visible_to_editor(): void {
		$this->login_as( 'editor' );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'Top secret body.',
				'post_excerpt'  => '',
			)
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'excerpt'            => array( 'format' => 'html' ),
					'password_protected' => true,
				),
			)
		);

		$this->assertSame(
			"<p>Top secret body.</p>\n",
			$result['excerpt'],
			'Editors should receive the real excerpt generated from a password-protected post.'
		);
		$this->assertTrue( $result['password_protected'], 'The password_protected flag should reveal the post is password-protected.' );
	}

	/**
	 * Password-protected excerpts rendered as HTML or plain text are withheld as null from
	 * users who cannot edit the post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_password_protected_excerpt_is_null_for_subscriber(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author'   => self::$user_ids['administrator'],
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_excerpt'  => 'Hidden excerpt.',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( array( 'html', 'text' ) as $format ) {
			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post'   => array( 'id' => $post_id ),
					'select' => array(
						'excerpt'            => array( 'format' => $format ),
						'password_protected' => true,
					),
				)
			);

			$this->assertArrayHasKey( 'excerpt', $result, "A withheld excerpt in the {$format} format should be present as null, not left out." );
			$this->assertNull( $result['excerpt'], "Password-protected excerpts in the {$format} format should be withheld." );
			$this->assertTrue( $result['password_protected'], 'The password_protected flag should reveal the post is password-protected.' );
		}
	}

	/**
	 * Excerpts in the HTML format carry the REST API's `the_excerpt` markup (paragraph wrapping).
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_html_excerpt_applies_the_excerpt_filters(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array(
					'excerpt' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertSame(
			"<p>Readable excerpt.</p>\n",
			$result['excerpt'],
			'HTML excerpts should match the REST API excerpt filter output.'
		);
	}

	/**
	 * A title filter that returns a non-string empties the HTML title instead of failing
	 * the request.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_html_title_is_empty_when_a_title_filter_returns_a_non_string(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		// Runs after core's own title filters, which expect a string.
		add_filter( 'the_title', '__return_null', 20 );

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['published'] ),
				'select' => array(
					'title' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertIsArray( $result, 'A non-string HTML title should not fail the request.' );
		$this->assertSame( '', $result['title'], 'A non-string HTML title should be returned as an empty string.' );
	}

	/**
	 * Title and excerpt filters for the HTML format run with the requested post as the global
	 * context, and the context that was active before the ability executed is restored.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_html_fields_use_and_restore_requested_post_context(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$target_id   = self::$post_ids['limited_role_content'];
		$surrounding = get_post( self::$post_ids['published'] );

		$this->assertInstanceOf( WP_Post::class, $surrounding, 'The surrounding post fixture should exist.' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Establishes a distinct context to verify the ability restores it.
		$GLOBALS['post'] = $surrounding;
		setup_postdata( $surrounding );

		$append_context_id = static function ( $text ): string {
			return (string) $text . '<!-- context:' . get_the_ID() . ' -->';
		};
		add_filter( 'the_title', $append_context_id, 20 );
		add_filter( 'the_excerpt', $append_context_id, 20 );

		$result              = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $target_id ),
				'select' => array(
					'title'   => array( 'format' => 'html' ),
					'excerpt' => array( 'format' => 'html' ),
				),
			)
		);
		$restored_context_id = get_the_ID();

		$this->assertStringContainsString(
			'<!-- context:' . $target_id . ' -->',
			$result['title'],
			'Title filters should see the requested post as the current post.'
		);
		$this->assertStringContainsString(
			'<!-- context:' . $target_id . ' -->',
			$result['excerpt'],
			'Excerpt filters should see the requested post as the current post.'
		);
		$this->assertSame(
			$surrounding->ID,
			$restored_context_id,
			'The surrounding post context should be restored after rendering.'
		);
	}

	/**
	 * Returns the text fields that render the post when selected in the HTML format, whose
	 * filters run with the requested post set up as the global post.
	 *
	 * @return array<string, array{field: string}> Rendered field test cases.
	 */
	public function data_rendered_fields(): array {
		return array(
			'content' => array(
				'field' => 'content',
			),
			'excerpt' => array(
				'field' => 'excerpt',
			),
		);
	}

	/**
	 * Rendering a field unsets the loop globals that were not set before.
	 *
	 * Without a surrounding post, as in a REST request, wp_reset_postdata() cannot restore
	 * the globals that setup_postdata() populates, because the main query has no post.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_fields
	 * @since 7.2.0
	 *
	 * @param string $field The text field to select in the HTML format.
	 */
	public function test_content_get_html_fields_unset_loop_globals_that_were_not_set( string $field ): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( self::LOOP_GLOBALS as $name ) {
			unset( $GLOBALS[ $name ] );
		}

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array(
					$field => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertArrayHasKey( $field, $result, 'Precondition: the rendered field should be returned.' );
		$this->assertSame( array(), $this->get_loop_globals(), 'Loop globals that were not set should be unset again after rendering.' );
	}

	/**
	 * Rendering a field restores the surrounding loop globals as they were, rather than as
	 * setup_postdata() computes them for the surrounding post.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_fields
	 * @since 7.2.0
	 *
	 * @param string $field The text field to select in the HTML format.
	 */
	public function test_content_get_html_fields_restore_surrounding_loop_globals( string $field ): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$surrounding = get_post( self::$post_ids['published'] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Establishes a surrounding context to verify the ability restores it.
		$GLOBALS['post'] = $surrounding;
		setup_postdata( $surrounding );

		/*
		 * A template can show the full content outside a single post view by setting `$more`,
		 * which running setup_postdata() for the surrounding post again would undo.
		 */
		$GLOBALS['more'] = 1;

		$surrounding_globals = $this->get_loop_globals();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array(
					$field => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertArrayHasKey( $field, $result, 'Precondition: the rendered field should be returned.' );
		$this->assertSame( $surrounding_globals, $this->get_loop_globals(), 'The surrounding loop globals should be restored as they were.' );
	}

	/**
	 * Rendering a field restores the surrounding loop globals when a calling function binds
	 * them with `global`, as load_template() and WP_Block::render() do.
	 *
	 * Such a binding makes the global a reference, so a saved copy that kept the reference
	 * would follow the global to the rendered post, and the rendered post would be restored.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_fields
	 * @since 7.2.0
	 *
	 * @param string $field The text field to select in the HTML format.
	 */
	public function test_content_get_html_fields_restore_loop_globals_bound_by_the_caller( string $field ): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$surrounding = get_post( self::$post_ids['published'] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Establishes a surrounding context to verify the ability restores it.
		$GLOBALS['post'] = $surrounding;
		setup_postdata( $surrounding );

		$surrounding_globals = $this->get_loop_globals();

		$render_in_template = static function ( string $field ): array {
			global $post, $id;

			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
					'select' => array(
						$field => array( 'format' => 'html' ),
					),
				)
			);

			return array( $result, $post->ID, $id );
		};

		list( $result, $bound_post_id, $bound_id ) = $render_in_template( $field );

		$this->assertArrayHasKey( $field, $result, 'Precondition: the rendered field should be returned.' );
		$this->assertSame( $surrounding->ID, $bound_post_id, 'The caller should see the surrounding post again after rendering.' );
		$this->assertSame( $surrounding->ID, $bound_id, 'The caller should see the surrounding post ID again after rendering.' );
		$this->assertSame( $surrounding_globals, $this->get_loop_globals(), 'The surrounding loop globals should be restored as they were.' );
	}

	/**
	 * Rendering a field sets up a global post that was not set up before, such as the main
	 * post before the loop starts, so get_the_content() without a post still works.
	 *
	 * Rendering fires `the_post`, after which get_the_content() without a post reads the loop
	 * globals. Unsetting `$pages` again, as for a post that was never set up, made it throw a
	 * TypeError.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_fields
	 * @since 7.2.0
	 *
	 * @param string $field The text field to select in the HTML format.
	 */
	public function test_content_get_html_fields_set_up_a_global_post_that_was_not_set_up( string $field ): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( self::LOOP_GLOBALS as $name ) {
			unset( $GLOBALS[ $name ] );
		}

		// WP::register_globals() sets the main post this way, before the loop sets it up.
		$main_post = get_post( self::$post_ids['published_content'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Establishes a global post that was not set up.
		$GLOBALS['post'] = $main_post;

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array(
					$field => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertArrayHasKey( $field, $result, 'Precondition: the rendered field should be returned.' );
		$this->assertSame( $main_post->ID, get_the_ID(), 'The main post should be the global post again.' );
		$this->assertSame( 'Body here.', get_the_content(), 'get_the_content() without a post should return the main post content.' );
	}

	/**
	 * Title and permalink filters run with the requested post as the global post, also when
	 * no post was set up before, as in a REST request.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_title_and_url_filters_see_the_requested_post(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( self::LOOP_GLOBALS as $name ) {
			unset( $GLOBALS[ $name ] );
		}

		$target_id = self::$post_ids['limited_role_content'];

		add_filter(
			'the_title',
			static function ( $title ): string {
				return (string) $title . '<!-- context:' . get_the_ID() . ' -->';
			},
			20
		);
		add_filter(
			'post_link',
			static function ( $link ): string {
				return add_query_arg( 'context', get_the_ID(), (string) $link );
			}
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $target_id ),
				'select' => array(
					'title' => array( 'format' => 'html' ),
					'url'   => true,
				),
			)
		);

		$this->assertStringContainsString( '<!-- context:' . $target_id . ' -->', $result['title'], 'Title filters should see the requested post as the current post.' );
		$this->assertStringContainsString( 'context=' . $target_id, $result['url'], 'Permalink filters should see the requested post as the current post.' );
	}

	/**
	 * Returns the loop globals that are set, keyed by name.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The loop globals that are set.
	 */
	private function get_loop_globals(): array {
		$globals = array();
		foreach ( self::LOOP_GLOBALS as $name ) {
			if ( ! array_key_exists( $name, $GLOBALS ) ) {
				continue;
			}

			$globals[ $name ] = $GLOBALS[ $name ];
		}

		return $globals;
	}

	/**
	 * The password gate is suspended only for posts the current user can edit.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_allow_password_content_only_unlocks_editable_posts(): void {
		$owned_id = self::factory()->post->create(
			array(
				'post_author'   => self::$user_ids['author'],
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->login_as( 'author' );

		// Read the password gate of each post while the editable protected post renders.
		$gates = array();
		add_filter(
			'the_content',
			static function ( $content ) use ( &$gates, $owned_id ) {
				$gates = array(
					'owned'  => post_password_required( $owned_id ),
					'other'  => post_password_required( self::$post_ids['password_protected'] ),
					'public' => post_password_required( self::$post_ids['published'] ),
				);

				return $content;
			}
		);

		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $owned_id ),
				'select' => array(
					'content' => array( 'format' => 'html' ),
				),
			)
		);

		$this->assertIsArray( $result, 'Precondition: the author should read their own protected post.' );
		$this->assertFalse( $gates['owned'], 'The filter should unlock a protected post the current user can edit.' );
		$this->assertTrue( $gates['other'], 'The filter should keep the gate on a protected post the current user cannot edit.' );
		$this->assertFalse( $gates['public'], 'The filter should leave posts that do not require a password ungated.' );
	}

	/**
	 * Rendering an editable protected post must not unlock other protected posts embedded in
	 * its content (e.g. through a shortcode or Query Loop block).
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_password_filter_does_not_leak_other_protected_posts(): void {
		$hidden_id = self::factory()->post->create(
			array(
				'post_author'   => self::$user_ids['administrator'],
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'NESTED_SECRET_MARKER',
			)
		);

		$author_id = $this->login_as( 'author' );

		$owned_id = self::factory()->post->create(
			array(
				'post_author'   => $author_id,
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => '[read_content_nested id="' . $hidden_id . '"]',
			)
		);

		add_shortcode(
			'read_content_nested',
			static function ( $atts ): string {
				$id = is_array( $atts ) && isset( $atts['id'] ) ? (int) $atts['id'] : 0;

				return post_password_required( $id ) ? 'GATED' : (string) get_post_field( 'post_content', $id );
			}
		);

		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $owned_id ),
				'select' => array(
					'content' => array( 'format' => 'html' ),
				),
			)
		);

		// Tear-down restores hooks, but not shortcodes.
		remove_shortcode( 'read_content_nested' );

		$this->assertStringNotContainsString(
			'NESTED_SECRET_MARKER',
			$result['content'],
			'Rendering an editable protected post must not unlock another protected post it embeds.'
		);
		$this->assertStringContainsString(
			'GATED',
			$result['content'],
			'The embedded protected post should still report as password-gated.'
		);
	}

	/**
	 * `core/content-query` reports a total that matches the returned posts for an uncapped query.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_total_matches_returned_posts_when_uncapped(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
				),
				'page'  => array( 'size' => 100 ),
			)
		);

		$this->assertNotEmpty( $result['posts'], 'An uncapped query should return the readable posts.' );
		$this->assertCount( $result['total'], $result['posts'], 'An uncapped query should report a total equal to the number of returned posts.' );
		$this->assertSame( 1, $result['total_pages'], 'An uncapped query should fit on a single page.' );
	}

	/**
	 * The last page still reports the totals of the underlying query.
	 *
	 * Guards the boundary next to the out-of-range page error: the final page must not be
	 * mistaken for an overshoot.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_last_page_reports_totals(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
				'page'  => array(
					'number' => 2,
					'size'   => 2,
				),
			)
		);

		$this->assertCount( 1, $result['posts'], 'The last page should return the remaining post.' );
		$this->assertSame( 3, $result['total'], 'The last page should report the full total.' );
		$this->assertSame( 2, $result['total_pages'], 'The last page should report the full page count.' );
	}

	/**
	 * Paging past the last page reports an error rather than an empty collection.
	 *
	 * `WP_Query::set_found_posts()` skips the count when a page yields no rows, so without
	 * recovering the total an out-of-range page would report `total: 0, total_pages: 0`,
	 * which is indistinguishable from an empty collection. Report it as a page that does not
	 * exist instead.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_out_of_range_page_is_rejected_rather_than_reported_as_empty(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
				'page'  => array(
					'number' => 99,
					'size'   => 2,
				),
			)
		);

		$this->assertWPError( $result, 'A page beyond the last one should fail rather than return an empty list.' );
		$this->assertSame( 'content_invalid_page_number', $result->get_error_code(), 'Out-of-range pages should report a dedicated error code.' );
		$this->assertSame( 404, $result->get_error_data()['status'], 'An out-of-range page should be reported as not found.' );
	}


	/**
	 * Returns whole numbers that the integer schema accepts but that are not PHP integers,
	 * as a page number, a page size, and a page number past the last page.
	 *
	 * @return array<string, array{0: float|string, 1: float|string, 2: float|string}> The values to send.
	 */
	public function data_whole_numbers_that_are_not_integers(): array {
		return array(
			'floats'               => array( 2.0, 2.0, 99.0 ),
			'decimal strings'      => array( '2.0', '2.0', '99.0' ),
			'signed strings'       => array( '+2', '+2', '+99' ),
			'strings with a space' => array( ' 2', ' 2', ' 99' ),
		);
	}

	/**
	 * A `page.number` and `page.size` that the integer schema accepts are honored even when
	 * they are not PHP integers, as when the MCP adapter passes the 2.0 a JSON encoder produced.
	 *
	 * Read as invalid, they would fall back to page 1 and the default page size, so a client
	 * paging with 2.0, 3.0, and so on would get page 1 every time and never reach the error for
	 * a page past the last one.
	 *
	 * @ticket 66268
	 * @dataProvider data_whole_numbers_that_are_not_integers
	 * @since 7.2.0
	 *
	 * @param float|string $page_number The page number to request.
	 * @param float|string $page_size   The page size to request.
	 * @param float|string $past_page   A page number past the last page.
	 */
	public function test_content_query_honors_a_page_number_and_size_that_are_not_integers( $page_number, $page_size, $past_page ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ability = wp_get_ability( 'core/content-query' );
		$where   = array(
			'type' => array( 'post' ),
			'ids'  => self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) ),
		);

		$result = $ability->execute(
			array(
				'where' => $where,
				'page'  => array(
					'number' => $page_number,
					'size'   => $page_size,
				),
			)
		);

		$this->assertIsArray( $result, 'The page should be returned.' );
		$this->assertCount( 1, $result['posts'], 'The second page of two posts each should hold the third post.' );
		$this->assertSame( 2, $result['total_pages'], 'The page count should follow the requested page size.' );

		$result = $ability->execute(
			array(
				'where' => $where,
				'page'  => array(
					'number' => $past_page,
					'size'   => $page_size,
				),
			)
		);

		$this->assertWPError( $result, 'A page past the last one should still fail.' );
		$this->assertSame( 'content_invalid_page_number', $result->get_error_code(), 'A page past the last one should report the out-of-range error.' );
	}

	/**
	 * Returns {@see self::IMPORTED_POST_ID} in forms that the integer schema accepts but that
	 * are not PHP integers.
	 *
	 * @return array<string, array{id: float|string}> The values to send for the post ID.
	 */
	public function data_post_ids_that_are_not_integers(): array {
		return array(
			'float'               => array(
				'id' => (float) self::IMPORTED_POST_ID,
			),
			'decimal string'      => array(
				'id' => self::IMPORTED_POST_ID . '.0',
			),
			'signed string'       => array(
				'id' => '+' . self::IMPORTED_POST_ID,
			),
			'string with a space' => array(
				'id' => ' ' . self::IMPORTED_POST_ID,
			),
		);
	}

	/**
	 * `core/content-get` honors a `post.id` that the integer schema accepts even when it is not
	 * a PHP integer, as when the MCP adapter passes a float that a JSON encoder produced.
	 *
	 * Read as invalid, the ID would deny a readable post as a permission error.
	 *
	 * @ticket 66268
	 * @dataProvider data_post_ids_that_are_not_integers
	 * @since 7.2.0
	 *
	 * @param float|string $id The value to send for the imported post ID.
	 */
	public function test_get_by_id_honors_an_id_that_is_not_an_integer( $id ): void {
		$post_id = self::factory()->post->create(
			array(
				'import_id'   => self::IMPORTED_POST_ID,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( self::IMPORTED_POST_ID, $post_id, 'Precondition: the post should have the requested ID.' );

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array( 'id' => $id ),
			)
		);

		$this->assertIsArray( $result, 'The post should be returned.' );
		$this->assertSame( $post_id, $result['id'], 'The lookup should resolve to the requested post.' );
	}

	/**
	 * `core/content-query` honors a `where.parent.id` that the integer schema accepts even when
	 * it is not a PHP integer.
	 *
	 * Read as invalid, the filter would be rejected as an invalid filter.
	 *
	 * @ticket 66268
	 * @dataProvider data_post_ids_that_are_not_integers
	 * @since 7.2.0
	 *
	 * @param float|string $id The value to send for the imported parent post ID.
	 */
	public function test_query_honors_a_parent_that_is_not_an_integer( $id ): void {
		$parent_id = self::factory()->post->create(
			array(
				'import_id'   => self::IMPORTED_POST_ID,
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( self::IMPORTED_POST_ID, $parent_id, 'Precondition: the parent page should have the requested ID.' );

		$child_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'page' ),
					'parent' => array( 'id' => $id ),
				),
			)
		);

		$this->assertIsArray( $result, 'The query should succeed.' );
		$this->assertSame( array( $child_id ), wp_list_pluck( $result['posts'], 'id' ), 'The query should return the children of the requested parent.' );
	}

	/**
	 * Returns fractions just above {@see self::IMPORTED_POST_ID}, which the integer schema rejects.
	 *
	 * @return array<string, array{id: float|string}> The values to send for the post ID.
	 */
	public function data_fractional_post_ids(): array {
		return array(
			'float'  => array(
				'id' => self::IMPORTED_POST_ID + 0.5,
			),
			'string' => array(
				'id' => self::IMPORTED_POST_ID . '.5',
			),
		);
	}

	/**
	 * A fractional `post.id` is rejected rather than truncated onto the post with the whole ID.
	 *
	 * Schema validation rejects it before either `core/content-get` callback runs through
	 * WP_Ability::execute(), but each callback still fails closed on its own.
	 *
	 * @ticket 66268
	 * @dataProvider data_fractional_post_ids
	 * @since 7.2.0
	 *
	 * @param float|string $id The value to send, a fraction just above the imported post ID.
	 */
	public function test_callbacks_reject_a_fractional_id( $id ): void {
		$post_id = self::factory()->post->create(
			array(
				'import_id'   => self::IMPORTED_POST_ID,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( self::IMPORTED_POST_ID, $post_id, 'Precondition: the post should have the ID the fraction truncates to.' );

		$this->login_as( 'administrator' );
		$callbacks = $this->get_ability_callbacks( 'core/content-get' );
		$input     = array(
			'post' => array( 'id' => $id ),
		);

		$this->assertFalse( $callbacks['permission_callback']( $input ), 'The permission callback should deny a fractional ID.' );

		$result = $callbacks['execute_callback']( $input );

		$this->assertWPError( $result, 'The execute callback should not resolve a fractional ID.' );
		$this->assertSame( 'content_not_found', $result->get_error_code(), 'A fractional ID should fail the lookup.' );
	}

	/**
	 * A genuinely empty result set beyond the first page reports zero totals, not an error.
	 *
	 * The out-of-range guard only fires when the underlying query actually matched rows.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_empty_result_beyond_first_page_reports_zero_totals(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => array( REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ),
				),
				'page'  => array( 'number' => 2 ),
			)
		);

		$this->assertIsArray( $result, 'An empty result set should not be treated as an out-of-range page.' );
		$this->assertSame( array(), $result['posts'], 'No posts match the query.' );
		$this->assertSame( 0, $result['total'], 'An empty result set reports a zero total.' );
		$this->assertSame( 0, $result['total_pages'], 'An empty result set reports zero pages.' );
	}

	/**
	 * Returns page sizes that a query filter can set, with the pages they make of five posts.
	 *
	 * @return array<string, array{posts_per_page: int, total_pages: int, last_page_count: int}> Page size test cases.
	 */
	public function data_page_sizes_set_by_a_query_filter(): array {
		return array(
			'smaller page size' => array(
				'posts_per_page'  => 2,
				'total_pages'     => 3,
				'last_page_count' => 1,
			),
			'no paging'         => array(
				'posts_per_page'  => -1,
				'total_pages'     => 1,
				'last_page_count' => 5,
			),
		);
	}

	/**
	 * `core/content-query` counts the pages with the page size the query ran with.
	 *
	 * WP_Query treats the ability's query as the blog home, so a `pre_get_posts` callback
	 * without an is_main_query() check can change its page size. Counting the pages with the
	 * requested `page.size` would then report the wrong number of pages, and reject pages that
	 * hold posts or serve the same posts again.
	 *
	 * @ticket 66268
	 * @dataProvider data_page_sizes_set_by_a_query_filter
	 * @since 7.2.0
	 *
	 * @param int $posts_per_page  The page size the query filter sets.
	 * @param int $total_pages     The expected number of pages.
	 * @param int $last_page_count The expected number of posts on the last page.
	 */
	public function test_query_counts_pages_with_the_page_size_the_query_ran_with( int $posts_per_page, int $total_pages, int $last_page_count ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 5, array( 'post_status' => 'publish' ) );

		add_action(
			'pre_get_posts',
			static function ( WP_Query $query ) use ( $posts_per_page ): void {
				if ( $query->is_home() ) {
					$query->set( 'posts_per_page', $posts_per_page );
				}
			}
		);

		$ability = wp_get_ability( 'core/content-query' );
		$input   = array(
			'where' => array(
				'type' => array( 'post' ),
				'ids'  => $ids,
			),
			'page'  => array( 'size' => 4 ),
		);

		$first_page = $ability->execute( $input );

		$this->assertSame( 5, $first_page['total'], 'The total should count every matching post.' );
		$this->assertSame( $total_pages, $first_page['total_pages'], 'The page count should follow the page size the query ran with.' );

		$last_page = $ability->execute( array_replace_recursive( $input, array( 'page' => array( 'number' => $total_pages ) ) ) );

		$this->assertIsArray( $last_page, 'The last page should be served.' );
		$this->assertCount( $last_page_count, $last_page['posts'], 'The last page should return the remaining posts.' );

		$past_last_page = $ability->execute( array_replace_recursive( $input, array( 'page' => array( 'number' => $total_pages + 1 ) ) ) );

		$this->assertWPError( $past_last_page, 'The page after the last one should be rejected.' );
		$this->assertSame( 'content_invalid_page_number', $past_last_page->get_error_code(), 'Paging past the last page should report the page number error.' );
	}

	/**
	 * The `where.ids` filter returns every requested post when `page.size` is omitted.
	 *
	 * Without this the default page size silently truncates a batch load: a caller asking
	 * for a known set of IDs would receive only the first page of them.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_ids_filter_returns_every_requested_post_without_a_page_size(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		// More than DEFAULT_PAGE_SIZE (10) so truncation would be visible.
		$ids = self::factory()->post->create_many( 15, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
			)
		);

		$returned = wp_list_pluck( $result['posts'], 'id' );
		sort( $returned );
		sort( $ids );

		$this->assertSame( $ids, $returned, 'Every requested post ID should be returned on a single page.' );
		$this->assertSame( 15, $result['total'], 'The total should cover every requested post.' );
		$this->assertSame( 1, $result['total_pages'], 'The posts listed in where.ids should fit on a single page by default.' );
	}

	/**
	 * An explicit `page.size` still paginates a `where.ids` request.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_ids_filter_honors_an_explicit_page_size(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 5, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
				'page'  => array( 'size' => 2 ),
			)
		);

		$this->assertCount( 2, $result['posts'], 'An explicit page.size should paginate the posts listed in where.ids.' );
		$this->assertSame( 5, $result['total'], 'The total should still cover every requested post.' );
		$this->assertSame( 3, $result['total_pages'], 'Page counts should follow the explicit page.size.' );
	}

	/**
	 * The `where.ids` list is capped at the maximum page size.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_ids_filter_is_capped_at_the_maximum_page_size(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$schema  = wp_get_ability( 'core/content-query' )->get_input_schema();
		$max_ids = $schema['properties']['where']['properties']['ids']['maxItems'];

		$this->assertSame( $schema['properties']['page']['properties']['size']['maximum'], $max_ids, 'The where.ids list should be capped at the maximum page size.' );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => range( 1, $max_ids + 1 ),
				),
			)
		);

		$this->assertWPError( $result, 'A where.ids list beyond the cap should be rejected as invalid input.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'The cap should be enforced by schema validation.' );
	}

	/**
	 * Returns selections of a rendered excerpt or content, as HTML or as plain text.
	 *
	 * Both formats render the field through its filter chains, with the post set up as the
	 * global post.
	 *
	 * @return array<string, array{select: array<string, array<string, string>>}> Rendered field selections.
	 */
	public function data_rendered_text_field_selections(): array {
		return array(
			'content as HTML' => array(
				'select' => array(
					'content' => array( 'format' => 'html' ),
				),
			),
			'content as text' => array(
				'select' => array(
					'content' => array( 'format' => 'text' ),
				),
			),
			'excerpt as HTML' => array(
				'select' => array(
					'excerpt' => array( 'format' => 'html' ),
				),
			),
			'excerpt as text' => array(
				'select' => array(
					'excerpt' => array( 'format' => 'text' ),
				),
			),
		);
	}

	/**
	 * Selecting a rendered excerpt or content primes the post meta cache for the whole page.
	 *
	 * The rendering filter chains may read post meta, so priming avoids one lazy meta
	 * query per returned row.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_text_field_selections
	 * @since 7.2.0
	 *
	 * @param array<string, array<string, string>> $select The selection to request.
	 */
	public function test_query_rendered_fields_prime_the_post_meta_cache( array $select ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$postmeta_queries = $this->count_queries(
			'postmeta',
			static function () use ( $ids, $select ) {
				return wp_get_ability( 'core/content-query' )->execute(
					array(
						'where'  => array(
							'type' => array( 'post' ),
							'ids'  => $ids,
						),
						'select' => $select,
					)
				);
			},
			$result
		);

		$this->assertCount( 3, $result['posts'], 'Precondition: the query should return the seeded posts.' );

		/*
		 * The rendering filter chains can read post meta per post. Without priming, each
		 * row lazily primes its own meta, which is one query per returned post.
		 */
		$this->assertSame( 1, $postmeta_queries, 'Rendered field requests should prime post meta with a single batched query, not one per returned post.' );
	}

	/**
	 * Selecting a rendered excerpt or content primes the authors of the whole page.
	 *
	 * Rendering sets each post up with setup_postdata(), which reads the post's author, so
	 * without priming, each author on the page runs its own query.
	 *
	 * @ticket 66268
	 * @dataProvider data_rendered_text_field_selections
	 * @since 7.2.0
	 *
	 * @param array<string, array<string, string>> $select The selection to request.
	 */
	public function test_query_rendered_fields_prime_the_authors_of_the_page( array $select ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = array();
		foreach ( array( 'author', 'author_secondary', 'editor' ) as $role ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_author' => self::$user_ids[ $role ],
					'post_status' => 'publish',
				)
			);
		}

		$users_queries = $this->count_queries(
			'users',
			static function () use ( $ids, $select ) {
				return wp_get_ability( 'core/content-query' )->execute(
					array(
						'where'  => array(
							'type' => array( 'post' ),
							'ids'  => $ids,
						),
						'select' => $select,
					)
				);
			},
			$result
		);

		$this->assertCount( 3, $result['posts'], 'Precondition: the query should return the seeded posts.' );
		$this->assertSame( 1, $users_queries, 'Rendered fields should read primed authors, not query once per author.' );
	}

	/**
	 * Returns selections that neither render an excerpt or content nor build a permalink.
	 *
	 * @return array<string, array{select: array<string, mixed>}> Lean selections.
	 */
	public function data_selections_that_do_not_render_posts(): array {
		return array(
			'value fields and plain-text title' => array(
				'select' => array(
					'type'         => true,
					'status'       => true,
					'slug'         => true,
					'title'        => true,
					'published_at' => true,
					'modified_at'  => true,
				),
			),
			'HTML title'                        => array(
				'select' => array(
					'title' => array( 'format' => 'html' ),
				),
			),
			'stored excerpt and content'        => array(
				'select' => array(
					'excerpt' => array( 'format' => 'source' ),
					'content' => array( 'format' => 'source' ),
				),
			),
		);
	}

	/**
	 * A lean projection does not ask for post meta priming.
	 *
	 * Nothing in these selections renders an excerpt or content or builds a permalink, so
	 * the ability leaves priming off when it builds the query. The default selection is not
	 * lean in this sense, since it includes the `url`.
	 *
	 * The check is on what the ability asks for, not on the queries that follow. Honoring
	 * the request belongs to whoever runs the query, and it may prime more for its own
	 * reasons, so counting queries here would describe that layer rather than this one.
	 *
	 * @ticket 66268
	 * @dataProvider data_selections_that_do_not_render_posts
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $select The selection to request.
	 */
	public function test_query_lean_projection_does_not_request_post_meta_priming( array $select ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		// A non-empty `post__in` identifies the query the ability built for this request.
		$priming = array();
		$spy     = static function ( $query ) use ( &$priming ): void {
			if ( array() === (array) $query->get( 'post__in' ) ) {
				return;
			}

			$priming[] = $query->get( 'update_post_meta_cache' );
		};

		add_action( 'pre_get_posts', $spy );

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => $ids,
				),
				'select' => $select,
			)
		);

		$this->assertCount( 3, $result['posts'], 'Precondition: the query should return the seeded posts.' );
		$this->assertNotEmpty( $priming, 'Precondition: the ability should query for the posts listed in where.ids.' );
		$this->assertSame( array(), array_filter( $priming ), 'A lean projection should leave post meta priming off.' );
	}

	/**
	 * Returns permalink structures that read data related to each post.
	 *
	 * @return array<string, array{permalink_structure: string, table: string}> Permalink test cases.
	 */
	public function data_permalink_structures_that_read_related_data(): array {
		return array(
			'category permalinks' => array(
				'permalink_structure' => '/%category%/%postname%/',
				'table'               => 'term_relationships',
			),
			'author permalinks'   => array(
				'permalink_structure' => '/%author%/%postname%/',
				'table'               => 'users',
			),
		);
	}

	/**
	 * Selecting `url` primes the caches that permalinks read for the whole page.
	 *
	 * Permalinks read the post's terms for `%category%` and its author for `%author%`,
	 * so without priming, each returned row runs its own query.
	 *
	 * @ticket 66268
	 * @dataProvider data_permalink_structures_that_read_related_data
	 * @since 7.2.0
	 *
	 * @param string $permalink_structure The permalink structure.
	 * @param string $table               The wpdb property naming the table the permalinks read.
	 */
	public function test_content_query_url_primes_the_caches_permalinks_read( string $permalink_structure, string $table ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();
		$this->set_permalink_structure( $permalink_structure );

		$category_id = self::factory()->category->create();
		$ids         = array();
		foreach ( array( 'author', 'author_secondary', 'editor' ) as $role ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_author'   => self::$user_ids[ $role ],
					'post_category' => array( $category_id ),
					'post_status'   => 'publish',
				)
			);
		}

		$queries = $this->count_queries(
			$table,
			static function () use ( $ids ) {
				return wp_get_ability( 'core/content-query' )->execute(
					array(
						'where'  => array(
							'type' => array( 'post' ),
							'ids'  => $ids,
						),
						'select' => array( 'url' => true ),
					)
				);
			},
			$result
		);

		$this->assertCount( 3, $result['posts'], 'Precondition: the query should return the seeded posts.' );
		$this->assertSame( 1, $queries, 'Permalinks should read primed caches, not query once per returned post.' );
	}

	/**
	 * Selecting `url` primes the parents that page permalinks read for the whole page.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_url_primes_the_parents_page_permalinks_read(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();
		$this->set_permalink_structure( '/%postname%/' );

		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_parent' => self::factory()->post->create( array( 'post_type' => 'page' ) ),
				)
			);
		}

		$queries = $this->count_queries(
			'posts',
			static function () use ( $ids ) {
				return wp_get_ability( 'core/content-query' )->execute(
					array(
						'where'  => array(
							'type' => array( 'page' ),
							'ids'  => $ids,
						),
						'select' => array( 'url' => true ),
					)
				);
			},
			$result
		);

		$this->assertCount( 3, $result['posts'], 'Precondition: the query should return the seeded pages.' );
		// One query finds the matching IDs, one loads those pages, and one loads their parents.
		$this->assertSame( 3, $queries, 'Page permalinks should read primed parents, not query once per returned page.' );
	}

	/**
	 * Counts the queries against a table issued while running the given callback.
	 *
	 * Counts during the call rather than checking the cache afterwards: the rendered
	 * filter chains prime meta lazily, so an after-the-fact cache check passes either way.
	 *
	 * @since 7.2.0
	 *
	 * @param string   $table    The wpdb property naming the table, such as `postmeta`.
	 * @param callable $callback Callback to run.
	 * @param mixed    $result   Set to the callback's return value.
	 * @return int Number of queries issued against the table.
	 */
	private function count_queries( string $table, callable $callback, &$result ): int {
		global $wpdb;

		$queries = 0;
		$pattern = '/(?:FROM|JOIN)\s+`?' . preg_quote( $wpdb->$table, '/' ) . '\b/i';
		$spy     = static function ( $query ) use ( &$queries, $pattern ) {
			if ( is_string( $query ) && preg_match( $pattern, $query ) ) {
				++$queries;
			}

			return $query;
		};

		wp_cache_flush();

		/*
		 * On multisite, capability checks load the current user to check whether they are a
		 * super admin. Load the user again after the flush so only the callback's own queries
		 * are counted.
		 */
		get_userdata( get_current_user_id() );

		add_filter( 'query', $spy );
		$result = $callback();
		remove_filter( 'query', $spy );

		return $queries;
	}

	/**
	 * Query rows are kept, with their ID, when none of the selected fields apply to them.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_keeps_posts_whose_selected_fields_do_not_apply(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		// `parent` never applies to the non-hierarchical `post` type.
		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array( 'type' => array( 'post' ) ),
				'page'   => array( 'size' => 100 ),
				'select' => array( 'parent' => true ),
			)
		);

		$this->assertNotEmpty( $result['posts'], 'Posts whose selected fields do not apply should still be returned.' );
		$this->assertCount( $result['total'], $result['posts'], 'The reported total should match the returned posts.' );

		foreach ( $result['posts'] as $post_entry ) {
			$this->assertSame( array( 'id' ), array_keys( $post_entry ), 'A post whose selected fields do not apply should return only its ID.' );
		}
	}

	/**
	 * A single post is returned with its ID when none of the selected fields apply to it.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_returns_its_id_when_selected_fields_do_not_apply(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::$post_ids['published'];

		// `parent` never applies to the non-hierarchical `post` type.
		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( 'parent' => true ),
			)
		);

		$this->assertSame( array( 'id' => $post_id ), $result, 'A post whose selected fields do not apply should return only its ID.' );
	}

	/**
	 * The publication and modified dates name the right instant and carry the site's UTC
	 * offset on non-UTC sites.
	 *
	 * They are read from the stored GMT dates, so after the site's timezone changes they
	 * still name the same instant, shown with the new offset.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_dates_carry_the_site_offset_on_non_utc_sites(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		update_option( 'timezone_string', 'America/New_York' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-01-15 10:00:00',
			)
		);

		/*
		 * On insert, `wp_insert_post()` copies the post date onto the modified columns. Give
		 * the modified columns their own instant, so a field that read the post date where it
		 * meant the modified date cannot pass.
		 */
		$this->replace_cached_post_date_columns(
			$post_id,
			array(
				'post_modified'     => '2026-01-16 11:00:00',
				'post_modified_gmt' => '2026-01-16 16:00:00',
			)
		);

		$input = array(
			'post'   => array( 'id' => $post_id ),
			'select' => array(
				'published_at' => true,
				'modified_at'  => true,
			),
		);

		$result = wp_get_ability( 'core/content-get' )->execute( $input );

		$this->assertIsArray( $result, 'The post should be returned.' );
		$this->assertSame( '2026-01-15T10:00:00-05:00', $result['published_at'], 'The publication date should be the local time with the site timezone offset.' );
		$this->assertSame( strtotime( '2026-01-15 15:00:00 UTC' ), ( new DateTimeImmutable( $result['published_at'] ) )->getTimestamp(), 'The publication date should name the stored GMT instant.' );
		$this->assertSame( '2026-01-16T11:00:00-05:00', $result['modified_at'], 'The modified date should be the local time with the site timezone offset.' );
		$this->assertSame( strtotime( '2026-01-16 16:00:00 UTC' ), ( new DateTimeImmutable( $result['modified_at'] ) )->getTimestamp(), 'The modified date should name the stored GMT instant.' );

		update_option( 'timezone_string', 'Asia/Tokyo' );

		$result = wp_get_ability( 'core/content-get' )->execute( $input );

		$this->assertIsArray( $result, 'The post should be returned after the timezone change.' );
		$this->assertSame( '2026-01-16T00:00:00+09:00', $result['published_at'], 'After a timezone change, the publication date should show the stored GMT instant with the new offset.' );
		$this->assertSame( '2026-01-17T01:00:00+09:00', $result['modified_at'], 'After a timezone change, the modified date should show the stored GMT instant with the new offset.' );
	}

	/**
	 * A draft whose date has not been set reports no publication date, and its modified date,
	 * which has no stored GMT date either, is derived from the local date and the site timezone.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_dates_of_a_draft_without_stored_gmt_dates(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		update_option( 'timezone_string', 'America/New_York' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_date'   => '2026-01-15 10:00:00',
			)
		);

		$this->assertSame(
			'0000-00-00 00:00:00',
			get_post( $post_id )->post_date_gmt,
			'Precondition: drafts should have no stored GMT date.'
		);
		$this->assertSame(
			'0000-00-00 00:00:00',
			get_post( $post_id )->post_modified_gmt,
			'Precondition: new drafts should have no stored GMT modified date.'
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'published_at' => true,
					'modified_at'  => true,
				),
			)
		);

		$this->assertIsArray( $result, 'The draft should be returned.' );
		$this->assertArrayHasKey( 'published_at', $result, 'The publication date should be returned even when it has not been set.' );
		$this->assertNull( $result['published_at'], 'A draft whose date has not been set should report no publication date.' );
		$this->assertSame(
			'2026-01-15T10:00:00-05:00',
			$result['modified_at'],
			'The modified date should be derived from the local date using the site timezone, not read the local wall-clock as UTC.'
		);
		$this->assertSame(
			strtotime( '2026-01-15 15:00:00 UTC' ),
			( new DateTimeImmutable( $result['modified_at'] ) )->getTimestamp(),
			'The derived modified date should name the instant of the local date in the site timezone.'
		);
	}

	/**
	 * Replaces cached post columns so the ability sees a post object with custom dates.
	 *
	 * Core's schema keeps the date columns `NOT NULL`, but a post object can still reach
	 * the ability from a filter or an in-memory row where a date is null or otherwise
	 * differs from the database row.
	 *
	 * @since 7.2.0
	 *
	 * @param int                  $post_id The post ID.
	 * @param array<string, mixed> $columns Post column values keyed by column name.
	 */
	private function replace_cached_post_date_columns( int $post_id, array $columns ): void {
		get_post( $post_id );

		$cached = wp_cache_get( $post_id, 'posts' );
		$this->assertInstanceOf( stdClass::class, $cached, 'Precondition: the raw post row should be cached.' );
		$this->assertSame( 'raw', $cached->filter, 'Precondition: the cached row should be unsanitized.' );

		foreach ( $columns as $column => $value ) {
			$cached->$column = $value;
		}

		wp_cache_set( $post_id, $cached, 'posts' );

		foreach ( $columns as $column => $value ) {
			$this->assertSame( $value, get_post( $post_id )->$column, "Precondition: {$column} should have the test value." );
		}
	}

	/**
	 * Data provider for date fields with the GMT and local columns they are read from.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, array{field: string, gmt_column: string, local_column: string, local_date: string, expected: string}>
	 */
	public function data_date_fields_and_columns(): array {
		return array(
			'published_at' => array(
				'field'        => 'published_at',
				'gmt_column'   => 'post_date_gmt',
				'local_column' => 'post_date',
				'local_date'   => '2026-01-15 10:00:00',
				'expected'     => '2026-01-15T10:00:00-05:00',
			),
			'modified_at'  => array(
				'field'        => 'modified_at',
				'gmt_column'   => 'post_modified_gmt',
				'local_column' => 'post_modified',
				'local_date'   => '2026-01-16 11:00:00',
				'expected'     => '2026-01-16T11:00:00-05:00',
			),
		);
	}

	/**
	 * A null stored GMT date falls back to the local date instead of the current time.
	 *
	 * `strtotime( ' UTC' )` resolves to the current time, so an unguarded null would
	 * report a fabricated "now" as the date.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 *
	 * @dataProvider data_date_fields_and_columns
	 *
	 * @param string $field        The field to select.
	 * @param string $gmt_column   The cached GMT post column to null out.
	 * @param string $local_column The cached local post column to derive the date from.
	 * @param string $local_date   The local date column value.
	 * @param string $expected     The expected output, in the site timezone.
	 */
	public function test_content_get_date_recovers_from_a_null_stored_gmt_date( string $field, string $gmt_column, string $local_column, string $local_date, string $expected ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		update_option( 'timezone_string', 'America/New_York' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-01-15 10:00:00',
			)
		);

		$this->replace_cached_post_date_columns(
			$post_id,
			array(
				$gmt_column   => null,
				$local_column => $local_date,
			)
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( $field => true ),
			)
		);

		$this->assertIsArray( $result, 'The post should be returned.' );
		$this->assertSame(
			$expected,
			$result[ $field ],
			'A null stored GMT date should be derived from the local date and the site timezone, not resolved to the current time.'
		);
	}

	/**
	 * A post with no usable date at all reports null.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 *
	 * @dataProvider data_date_fields_and_columns
	 *
	 * @param string $field        The field to select.
	 * @param string $gmt_column   The cached GMT post column to null out.
	 * @param string $local_column The cached local post column to null out.
	 * @param string $local_date   Unused. Present to match the shared data provider shape.
	 * @param string $expected     Unused. Present to match the shared data provider shape.
	 */
	public function test_content_get_date_is_null_when_no_usable_date_exists( string $field, string $gmt_column, string $local_column, string $local_date, string $expected ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->replace_cached_post_date_columns(
			$post_id,
			array(
				$gmt_column   => null,
				$local_column => null,
			)
		);

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( $field => true ),
			)
		);

		$this->assertIsArray( $result, 'An unresolvable date should pass output validation.' );
		$this->assertArrayHasKey( $field, $result, 'An unresolvable date should still be returned.' );
		$this->assertNull( $result[ $field ], 'An unresolvable date should be null.' );
	}

	/**
	 * Data provider for malformed stored dates.
	 *
	 * A date is read from the stored GMT date, or from the local date when no GMT date is
	 * stored, so either column can hold the malformed value the field reports.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, array{field: string, columns: array<string, string>}>
	 */
	public function data_malformed_date_columns(): array {
		return array(
			'published_at from the GMT date'   => array(
				'field'   => 'published_at',
				'columns' => array( 'post_date_gmt' => '0000-00-01 00:00:00' ),
			),
			'published_at from the local date' => array(
				'field'   => 'published_at',
				'columns' => array(
					'post_date_gmt' => '0000-00-00 00:00:00',
					'post_date'     => '0000-00-01 00:00:00',
				),
			),
			'modified_at from the GMT date'    => array(
				'field'   => 'modified_at',
				'columns' => array( 'post_modified_gmt' => '0000-00-01 00:00:00' ),
			),
			'modified_at from the local date'  => array(
				'field'   => 'modified_at',
				'columns' => array(
					'post_modified_gmt' => '0000-00-00 00:00:00',
					'post_modified'     => '0000-00-01 00:00:00',
				),
			),
		);
	}

	/**
	 * A malformed stored date reports null instead of failing output validation.
	 *
	 * A zero month still parses, but formats with a negative year, which the `date-time`
	 * format of the output schema rejects.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 *
	 * @dataProvider data_malformed_date_columns
	 *
	 * @param string                $field   The field to select.
	 * @param array<string, string> $columns The cached post columns to replace, with the malformed date.
	 */
	public function test_malformed_date_is_null( string $field, array $columns ): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->replace_cached_post_date_columns( $post_id, $columns );

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( $field => true ),
			)
		);

		$this->assertIsArray( $result, 'A malformed date should pass output validation.' );
		$this->assertArrayHasKey( $field, $result, 'A malformed date should still be returned.' );
		$this->assertNull( $result[ $field ], 'A malformed date should be null.' );
	}

	/**
	 * The `core/content-get` execute callback re-validates the post reference structurally when
	 * invoked directly.
	 *
	 * Gated transports never reach these branches: the permission callback resolves and
	 * denies the same references first. The registered callback still fails closed on
	 * structural lookup errors when invoked directly.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_execute_callback_returns_not_found_for_structural_lookup_failures(): void {
		$this->login_as( 'administrator' );

		$execute = $this->get_ability_callbacks( 'core/content-get' )['execute_callback'];

		$missing = $execute(
			array(
				'post' => array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ),
			)
		);
		$this->assertWPError( $missing, 'A nonexistent post ID should fail the lookup.' );
		$this->assertSame( 'content_not_found', $missing->get_error_code(), 'Missing posts should map to the uniform not-found error.' );

		$mismatched = $execute(
			array(
				'post' => array(
					'id'   => self::$post_ids['published'],
					'type' => 'page',
				),
			)
		);
		$this->assertWPError( $mismatched, 'A post type mismatch should fail the lookup.' );
		$this->assertSame( 'content_not_found', $mismatched->get_error_code(), 'Mismatched post types should map to the uniform not-found error.' );

		$missing_slug = $execute(
			array(
				'post' => array(
					'type' => 'post',
					'slug' => 'no-such-slug',
				),
			)
		);
		$this->assertWPError( $missing_slug, 'An unmatched slug should fail the lookup.' );
		$this->assertSame( 'content_not_found', $missing_slug->get_error_code(), 'Unmatched slugs should map to the uniform not-found error.' );

		$missing_path = $execute(
			array(
				'post' => array(
					'type' => 'page',
					'path' => 'no-such-parent/no-such-page',
				),
			)
		);
		$this->assertWPError( $missing_path, 'An unmatched path should fail the lookup.' );
		$this->assertSame( 'content_not_found', $missing_path->get_error_code(), 'Unmatched paths should map to the uniform not-found error.' );

		$missing_url = $execute(
			array(
				'post' => array( 'url' => home_url( '/?p=' . REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ),
			)
		);
		$this->assertWPError( $missing_url, 'A URL of a nonexistent post should fail the lookup.' );
		$this->assertSame( 'content_not_found', $missing_url->get_error_code(), 'URLs of missing posts should map to the uniform not-found error.' );
	}

	/**
	 * Returns `where.author` filter values that do not name a user.
	 *
	 * @return array<string, array{0: mixed}> The author filter value.
	 */
	public function data_author_filters_that_name_no_user(): array {
		return array(
			'unknown slug'          => array( array( 'slug' => 'no-such-user' ) ),
			'slug that is a number' => array( array( 'slug' => 5 ) ),
			'unknown ID'            => array( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ),
			'current user false'    => array( array( 'current_user' => false ) ),
			'bare slug'             => array( 'no-such-user' ),
		);
	}

	/**
	 * A `where.author` filter that does not name a user is rejected rather than silently
	 * dropped, which would widen the query to every author's posts.
	 *
	 * @ticket 66268
	 * @dataProvider data_author_filters_that_name_no_user
	 * @since 7.2.0
	 *
	 * @param mixed $author_filter The `where.author` filter value.
	 */
	public function test_execute_callback_rejects_an_author_filter_that_names_no_user( $author_filter ): void {
		wp_update_user(
			array(
				'ID'            => self::$user_ids['author'],
				'user_nicename' => 'author-slug',
			)
		);

		$this->login_as( 'administrator' );
		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];

		$result = $execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'author' => $author_filter,
				),
			)
		);

		$this->assertWPError( $result, 'A where.author filter that names no user must not silently widen the query to all authors.' );
		$this->assertSame( 'content_invalid_filter', $result->get_error_code(), 'An unhonorable where.author filter should fail closed as an invalid filter.' );
		$this->assertSame( 'The where.author filter must reference an existing user.', $result->get_error_message(), 'The error message should name the where.author filter.' );
		$this->assertSame(
			array( 'where.author' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the unmatched where.author filter to the error message.'
		);
	}

	/**
	 * A `where.author` slug is looked up as given, like the `slug` filter of the REST API users
	 * endpoint, so with the database's case-insensitive collation a slug in capitals names the
	 * author too.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_author_filter_matches_a_slug_in_capitals(): void {
		wp_update_user(
			array(
				'ID'            => self::$user_ids['author'],
				'user_nicename' => 'author-slug',
			)
		);

		$author_post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author'],
				'post_status' => 'publish',
			)
		);
		$other_post_id  = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['editor'],
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type'   => array( 'post' ),
					'author' => array( 'slug' => 'AUTHOR-SLUG' ),
					'ids'    => array( $author_post_id, $other_post_id ),
				),
				'select' => array(
					'author' => array( 'fields' => array( 'slug' ) ),
				),
			)
		);

		$this->assertIsArray( $result, 'The query should succeed.' );
		$this->assertSame( array( $author_post_id ), wp_list_pluck( $result['posts'], 'id' ), 'The filter should return only the author\'s posts.' );
		$this->assertSame(
			array(
				'id'   => self::$user_ids['author'],
				'slug' => 'author-slug',
			),
			$result['posts'][0]['author'],
			'Each post should return its author with the stored slug.'
		);
	}

	/**
	 * Returns `where.parent` filter values that do not reference a post by a positive
	 * integer ID or by the path of a readable post.
	 *
	 * @return array<string, array{0: mixed}> The parent filter value.
	 */
	public function data_invalid_parent_filters(): array {
		return array(
			'non-numeric ID'  => array( array( 'id' => 'not-a-number' ) ),
			'zero ID'         => array( array( 'id' => 0 ) ),
			'unresolved path' => array( array( 'path' => 'no-such-parent/no-such-page' ) ),
			'bare value'      => array( 'not-a-number' ),
		);
	}

	/**
	 * A `where.parent` filter that does not reference a post is rejected rather than
	 * coerced to 0, which would ask for top-level posts.
	 *
	 * Top-level posts are requested with `where.top_level`, so no `where.parent` value means
	 * top level, and garbage must not silently become a top-level query.
	 *
	 * @ticket 66268
	 * @dataProvider data_invalid_parent_filters
	 * @since 7.2.0
	 *
	 * @param mixed $parent_filter The `where.parent` filter value.
	 */
	public function test_execute_callback_rejects_an_invalid_parent_filter( $parent_filter ): void {
		$this->login_as( 'administrator' );
		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];

		$result = $execute(
			array(
				'where' => array(
					'type'   => array( 'page' ),
					'parent' => $parent_filter,
				),
			)
		);

		$this->assertWPError( $result, 'An invalid where.parent filter must not silently coerce to a top-level query.' );
		$this->assertSame( 'content_invalid_filter', $result->get_error_code(), 'An unhonorable where.parent filter should fail closed as an invalid filter.' );
		$this->assertSame(
			'The where.parent filter must reference a post by a positive integer ID, or by the path of a readable post of the queried post type.',
			$result->get_error_message(),
			'The error message should name the where.parent filter.'
		);
		$this->assertSame(
			array( 'where.parent' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the invalid where.parent filter to the error message.'
		);
	}

	/**
	 * A `where.ids` filter that parses to no valid IDs is rejected rather than returning an
	 * unrestricted result set.
	 *
	 * WP_Query ignores an empty `post__in`, so a `where.ids` list with no valid IDs would
	 * otherwise return every post of the type — the opposite of the caller's intent. The
	 * filter must fail closed instead.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_execute_callback_rejects_an_ids_filter_with_no_valid_ids(): void {
		$this->login_as( 'administrator' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];

		$result = $execute(
			array(
				'where' => array(
					'type' => array( 'post' ),
					'ids'  => array( 0 ),
				),
			)
		);

		$this->assertWPError( $result, 'A where.ids filter with no valid IDs must not fall through to an unrestricted query.' );
		$this->assertSame( 'content_invalid_filter', $result->get_error_code(), 'A where.ids filter that is empty after parsing should fail closed as an invalid filter.' );
		$this->assertSame( 'The where.ids filter must list one or more valid post IDs.', $result->get_error_message(), 'The error message should name the where.ids filter.' );
		$this->assertSame(
			array( 'where.ids' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the where.ids filter to the error message.'
		);
	}

	/**
	 * A `where.author` filter names only users the current user may see: a user without
	 * published posts is reported like a missing one to a subscriber, but not to an editor,
	 * who can edit their posts.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_query_author_filter_only_names_users_the_current_user_may_see(): void {
		$published_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['author'] ) );
		$draft_id     = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'draft',
			)
		);

		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];
		$query   = static function ( array $statuses, int $user_id ) use ( $execute ) {
			return $execute(
				array(
					'where' => array(
						'type'   => array( 'post' ),
						'status' => $statuses,
						'author' => array( 'slug' => get_userdata( $user_id )->user_nicename ),
					),
				)
			);
		};

		$this->login_as( 'subscriber' );
		$public = $query( array( 'publish' ), self::$user_ids['author'] );
		$this->assertIsArray( $public, 'A subscriber should filter by an author with published posts.' );
		$this->assertSame( array( $published_id ), wp_list_pluck( $public['posts'], 'id' ), 'The filter should return that author\'s posts.' );

		$hidden = $query( array( 'publish' ), self::$user_ids['author_secondary'] );
		$this->assertWPError( $hidden, 'A subscriber should not learn that an author without published posts exists.' );
		$this->assertSame( 'content_invalid_filter', $hidden->get_error_code(), 'A hidden author should be reported like a missing one.' );
		$this->assertSame(
			array( 'where.author' => $hidden->get_error_message() ),
			$hidden->get_error_data()['params'],
			'The error data should map the hidden where.author filter to the error message.'
		);

		$this->login_as( 'editor' );
		$drafts = $query( array( 'draft' ), self::$user_ids['author_secondary'] );
		$this->assertIsArray( $drafts, 'An editor should filter by an author without published posts.' );
		$this->assertSame( array( $draft_id ), wp_list_pluck( $drafts['posts'], 'id' ), 'The filter should return that author\'s drafts.' );
	}

	/**
	 * On multisite, a `where.author` filter does not tell an editor of one site whether a user
	 * of another site exists.
	 *
	 * @ticket 66268
	 * @group ms-required
	 * @since 7.2.0
	 */
	public function test_content_query_author_filter_hides_users_of_other_sites(): void {
		$other_user_id = self::factory()->user->create( array( 'user_nicename' => 'other-site-user' ) );
		$site_id       = self::factory()->blog->create();

		switch_to_blog( $site_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];
		$query   = static function ( string $author_slug ) use ( $execute ) {
			return $execute(
				array(
					'where' => array(
						'type'   => array( 'post' ),
						'author' => array( 'slug' => $author_slug ),
					),
				)
			);
		};

		$is_member = is_user_member_of_blog( $other_user_id, $site_id );
		$other     = $query( 'other-site-user' );
		$missing   = $query( 'no-such-user' );

		restore_current_blog();

		$this->assertFalse( $is_member, 'Precondition: the other user should not be a member of the site.' );
		$this->assertWPError( $missing, 'Precondition: a slug that names no user should be rejected.' );
		$this->assertWPError( $other, 'An editor should not learn that a user of another site exists.' );
		$this->assertSame( $missing->get_error_code(), $other->get_error_code(), 'A user of another site should be reported like a missing one.' );
	}

	/**
	 * On multisite, a `where.author` filter still names an author who is not a member of the
	 * site, such as a super admin, when they have published posts there.
	 *
	 * @ticket 66268
	 * @group ms-required
	 * @since 7.2.0
	 */
	public function test_content_query_author_filter_names_authors_who_are_not_site_members(): void {
		$super_admin_id = self::factory()->user->create( array( 'user_nicename' => 'network-author' ) );
		grant_super_admin( $super_admin_id );
		$site_id = self::factory()->blog->create();
		$execute = $this->get_ability_callbacks( 'core/content-query' )['execute_callback'];

		switch_to_blog( $site_id );
		$post_id = self::factory()->post->create( array( 'post_author' => $super_admin_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$is_member = is_user_member_of_blog( $super_admin_id, $site_id );
		$result    = $execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'author' => array( 'slug' => 'network-author' ),
				),
			)
		);

		restore_current_blog();
		revoke_super_admin( $super_admin_id );

		$this->assertFalse( $is_member, 'Precondition: the super admin should not be a member of the site.' );
		$this->assertIsArray( $result, 'An author with published posts on the site should be filterable without being a member.' );
		$this->assertSame( array( $post_id ), wp_list_pluck( $result['posts'], 'id' ), 'The filter should return the author\'s posts on the site.' );
	}

	/**
	 * Creates a published page hierarchy whose two children share a slug.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, int> The page IDs, keyed by `about`, `contact`, `about_team`, and `contact_team`.
	 */
	private function create_page_tree(): array {
		$about   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_status' => 'publish',
			)
		);
		$contact = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Contact',
				'post_name'   => 'contact',
				'post_status' => 'publish',
			)
		);

		return array(
			'about'        => $about,
			'contact'      => $contact,
			'about_team'   => self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => 'About team',
					'post_name'   => 'team',
					'post_parent' => $about,
					'post_status' => 'publish',
				)
			),
			'contact_team' => self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Contact team',
					'post_name'   => 'team',
					'post_parent' => $contact,
					'post_status' => 'publish',
				)
			),
		);
	}

	/**
	 * A path tells apart posts under different parents that share a slug.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_path_tells_apart_children_that_share_a_slug(): void {
		$pages = $this->create_page_tree();

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$ability = wp_get_ability( 'core/content-get' );
		$about   = $ability->execute(
			array(
				'post' => array(
					'type' => 'page',
					'path' => 'about/team',
				),
			)
		);
		$contact = $ability->execute(
			array(
				'post' => array(
					'type' => 'page',
					'path' => '/contact/team/',
				),
			)
		);

		$this->assertSame( $pages['about_team'], $about['id'], 'The about/team path should resolve to the child of About.' );
		$this->assertSame( $pages['contact_team'], $contact['id'], 'A path with surrounding slashes should resolve to the child of Contact.' );
	}

	/**
	 * A path that names no post, or a post the current user cannot read, is denied.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_path_denies_missing_and_unreadable_posts(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'secret',
				'post_author' => self::$user_ids['administrator'],
				'post_status' => 'draft',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( array( 'no/such/page', 'secret' ) as $path ) {
			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post' => array(
						'type' => 'page',
						'path' => $path,
					),
				)
			);

			$this->assertWPError( $result, "The {$path} path should not resolve a readable post." );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), "The {$path} path should fail closed as a permission error." );
		}
	}

	/**
	 * Paths are only accepted for hierarchical post types.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_path_rejects_non_hierarchical_post_types(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post' => array(
					'type' => 'post',
					'path' => 'hello-world',
				),
			)
		);

		$this->assertWPError( $result, 'A path for a non-hierarchical post type should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'The path branch should only list hierarchical post types.' );
	}

	/**
	 * A post can be referenced by its permalink, with pretty permalinks or without.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_url_resolves_permalinks(): void {
		$post_id = self::$post_ids['published_content'];

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$plain = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'url' => get_permalink( $post_id ) ) ) );

		$this->set_permalink_structure( '/%postname%/' );
		$pretty_url = get_permalink( $post_id );
		$pretty     = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'url' => $pretty_url ) ) );

		$this->assertSame( $post_id, $plain['id'], 'A plain permalink should resolve the post.' );
		$this->assertStringContainsString( '/hello-content/', $pretty_url, 'Precondition: the permalink should be pretty.' );
		$this->assertSame( $post_id, $pretty['id'], 'A pretty permalink should resolve the post.' );
	}

	/**
	 * A URL that does not point to a post of this site is denied.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_get_by_url_denies_urls_that_name_no_post(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		foreach ( array( 'https://example.com/elsewhere/', home_url( '/no-such-post/' ) ) as $url ) {
			$result = wp_get_ability( 'core/content-get' )->execute( array( 'post' => array( 'url' => $url ) ) );

			$this->assertWPError( $result, "The {$url} URL should not resolve a post." );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), "The {$url} URL should fail closed as a permission error." );
		}
	}

	/**
	 * A title can be returned as plain text, as rendered HTML, or as the stored source.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_title_formats(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Tom & Jerry <em>"live"</em>',
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$titles = array();
		foreach ( array( 'text', 'html', 'source' ) as $format ) {
			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post'   => array( 'id' => $post_id ),
					'select' => array( 'title' => array( 'format' => $format ) ),
				)
			);

			$titles[ $format ] = $result['title'];
		}

		$stored_title = get_post( $post_id )->post_title;

		$this->assertSame( get_the_title( $post_id ), $titles['html'], 'The HTML format should return the title as the site renders it.' );
		$this->assertStringContainsString( '&#8220;', $titles['html'], 'Precondition: the rendered title should hold entities.' );
		$this->assertSame( "Tom & Jerry \u{201C}live\u{201D}", $titles['text'], 'The text format should strip tags and decode entities.' );
		$this->assertSame( $stored_title, $titles['source'], 'The source format should return the stored title.' );
	}

	/**
	 * Selecting a text field with true returns its default format.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_text_fields_selected_with_true_use_their_default_formats(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Fish &amp; chips',
				'post_excerpt' => 'A <strong>short</strong> excerpt.',
				'post_content' => '<p>Rendered <em>body</em>.</p>',
				'post_status'  => 'publish',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array(
					'title'   => true,
					'excerpt' => true,
					'content' => true,
				),
			)
		);

		$this->assertSame( 'Fish & chips', $result['title'], 'Titles should default to plain text.' );
		$this->assertSame( 'A short excerpt.', $result['excerpt'], 'Excerpts should default to plain text.' );
		$this->assertStringContainsString( '<em>body</em>', $result['content'], 'Content should default to rendered HTML.' );
	}

	/**
	 * The text format of content removes markup, scripts, and the blank lines blocks leave.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_content_text_format_removes_markup(): void {
		// Store the script as is, as for a user with `unfiltered_html`.
		kses_remove_filters();

		$post_id = self::factory()->post->create(
			array(
				'post_content' => "<!-- wp:paragraph -->\n<p>First <strong>paragraph</strong>.</p>\n<!-- /wp:paragraph -->\n\n\n<!-- wp:html -->\n<script>alert( 1 );</script>\n<!-- /wp:html -->\n\n<!-- wp:paragraph -->\n<p>Second &amp; last.</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
			)
		);

		kses_init_filters();

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( 'content' => array( 'format' => 'text' ) ),
			)
		);

		$this->assertSame( "First paragraph.\n\nSecond & last.", $result['content'], 'The text format should keep only the readable text, one blank line between blocks.' );
	}

	/**
	 * Selecting the source of any text field is edit intent, even when another field is
	 * rendered.
	 *
	 * @ticket 66268
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 * @since 7.2.0
	 *
	 * @param string $role The role to test.
	 */
	public function test_source_format_of_any_text_field_requires_edit_access( string $role ): void {
		$this->login_as( $role );
		$this->register_abilities();

		foreach ( array( 'title', 'excerpt' ) as $field ) {
			$result = wp_get_ability( 'core/content-get' )->execute(
				array(
					'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
					'select' => array(
						'content' => true,
						$field    => array( 'format' => 'source' ),
					),
				)
			);

			$this->assertWPError( $result, "Selecting the {$field} source should require edit access." );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), "Selecting the {$field} source should return a permission error." );
		}
	}

	/**
	 * Fields set to false are left out, and string booleans are read as booleans, as a GET
	 * request delivers them.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_select_reads_false_and_string_booleans(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['published_content'] ),
				'select' => array(
					'slug'    => 'true',
					'status'  => '1',
					'url'     => false,
					'title'   => 'false',
					'content' => '0',
				),
			)
		);

		$this->assertSame( array( 'id', 'status', 'slug' ), array_keys( $result ), 'Only the fields set to a true value should be returned.' );
	}

	/**
	 * A selection with every field set to false returns only the ID.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_select_with_only_false_fields_returns_only_the_id(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['published_content'] ),
				'select' => array( 'title' => false ),
			)
		);

		$this->assertSame( array( 'id' => self::$post_ids['published_content'] ), $result, 'Only the always-returned ID should be returned.' );
	}

	/**
	 * Whether a post is password protected is reported once, for the post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_password_protected_is_reported_on_the_post(): void {
		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => array( self::$post_ids['password_protected'], self::$post_ids['published_content'] ),
				),
				'select' => array( 'password_protected' => true ),
			)
		);

		$flags = wp_list_pluck( $result['posts'], 'password_protected', 'id' );

		$this->assertTrue( $flags[ self::$post_ids['password_protected'] ], 'The protected post should be reported as protected.' );
		$this->assertFalse( $flags[ self::$post_ids['published_content'] ], 'The unprotected post should be reported as unprotected.' );
	}

	/**
	 * The author relation returns all of its fields, or the chosen ones with the ID.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_author_relation_returns_selected_fields_with_the_id(): void {
		$author_id = self::$user_ids['administrator'];
		$author    = get_userdata( $author_id );

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$ability = wp_get_ability( 'core/content-get' );
		$all     = $ability->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array( 'author' => true ),
			)
		);
		$chosen  = $ability->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['limited_role_content'] ),
				'select' => array( 'author' => array( 'fields' => array( 'name' ) ) ),
			)
		);

		$this->assertSame(
			array(
				'id'   => $author_id,
				'slug' => $author->user_nicename,
				'name' => $author->display_name,
				'url'  => get_author_posts_url( $author_id ),
			),
			$all['author'],
			'Selecting the author with true should return all of its fields.'
		);
		$this->assertSame(
			array(
				'id'   => $author_id,
				'name' => $author->display_name,
			),
			$chosen['author'],
			'Selecting author fields should return those fields and the ID.'
		);
	}

	/**
	 * The author relation is null when the author no longer exists.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_author_relation_is_null_when_the_author_no_longer_exists(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $post_id ),
				'select' => array( 'author' => true ),
			)
		);

		$this->assertArrayHasKey( 'author', $result, 'The author should be present for a post type that supports authors.' );
		$this->assertNull( $result['author'], 'A missing author should be null.' );
	}

	/**
	 * The parent relation returns the parent's fields when it is readable, and is null for a
	 * top-level post.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_parent_relation_returns_fields_of_a_readable_parent(): void {
		$pages = $this->create_page_tree();

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$ability = wp_get_ability( 'core/content-get' );
		$child   = $ability->execute(
			array(
				'post'   => array( 'id' => $pages['about_team'] ),
				'select' => array( 'parent' => true ),
			)
		);
		$top     = $ability->execute(
			array(
				'post'   => array( 'id' => $pages['about'] ),
				'select' => array( 'parent' => array( 'fields' => array( 'title' ) ) ),
			)
		);

		$this->assertSame(
			array(
				'id'    => $pages['about'],
				'slug'  => 'about',
				'title' => 'About',
				'url'   => get_permalink( $pages['about'] ),
			),
			$child['parent'],
			'A readable parent should return all of its fields.'
		);
		$this->assertArrayHasKey( 'parent', $top, 'The parent should be present for a hierarchical post type.' );
		$this->assertNull( $top['parent'], 'A top-level post should have a null parent.' );
	}

	/**
	 * Only the ID of a parent the current user cannot read is returned.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_parent_relation_returns_only_the_id_of_an_unreadable_parent(): void {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Private parent',
				'post_author' => self::$user_ids['administrator'],
				'post_status' => 'private',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => $child_id ),
				'select' => array( 'parent' => true ),
			)
		);

		$this->assertSame( array( 'id' => $parent_id ), $result['parent'], 'Only the ID of an unreadable parent should be returned.' );
	}

	/**
	 * The parent relation is left out for post types that are not hierarchical.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_parent_relation_is_absent_for_non_hierarchical_post_types(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-get' )->execute(
			array(
				'post'   => array( 'id' => self::$post_ids['published'] ),
				'select' => array( 'parent' => true ),
			)
		);

		$this->assertArrayNotHasKey( 'parent', $result, 'Posts should not report a parent.' );
	}

	/**
	 * The `where.top_level` filter returns only posts without a parent.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_top_level_returns_only_posts_without_a_parent(): void {
		$pages = $this->create_page_tree();

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'      => array( 'page' ),
					'top_level' => true,
					'ids'       => array_values( $pages ),
				),
			)
		);

		$this->assertEqualSets( array( $pages['about'], $pages['contact'] ), wp_list_pluck( $result['posts'], 'id' ), 'Only the top-level pages should be returned.' );
	}

	/**
	 * The `where.top_level` filter cannot be combined with `where.parent`, and is only
	 * supported for hierarchical post types.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_top_level_rejects_parent_and_non_hierarchical_post_types(): void {
		$this->login_as( 'administrator' );
		$this->register_abilities();

		$ability  = wp_get_ability( 'core/content-query' );
		$combined = $ability->execute(
			array(
				'where' => array(
					'type'      => array( 'page' ),
					'top_level' => true,
					'parent'    => array( 'id' => 1 ),
				),
			)
		);
		$posts    = $ability->execute(
			array(
				'where' => array(
					'type'      => array( 'post' ),
					'top_level' => true,
				),
			)
		);

		$this->assertWPError( $combined, 'Combining top_level with parent should be rejected.' );
		$this->assertSame( 'content_invalid_filter', $combined->get_error_code(), 'The combination should be an invalid filter.' );
		$this->assertArrayHasKey( 'where.top_level', $combined->get_error_data()['params'], 'The error should name the top_level filter.' );
		$this->assertWPError( $posts, 'top_level should be rejected for a non-hierarchical post type.' );
		$this->assertSame(
			'The where.top_level filter is only supported for hierarchical post types.',
			$posts->get_error_data()['params']['where.top_level'],
			'The error should explain that top_level needs a hierarchical post type.'
		);
	}

	/**
	 * The `where.parent` filter accepts a parent path.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_parent_filter_accepts_a_path(): void {
		$pages = $this->create_page_tree();

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-query' )->execute(
			array(
				'where' => array(
					'type'   => array( 'page' ),
					'parent' => array( 'path' => 'contact' ),
				),
			)
		);

		$this->assertSame( array( $pages['contact_team'] ), wp_list_pluck( $result['posts'], 'id' ), 'Only the children of the referenced parent should be returned.' );
	}

	/**
	 * A parent path that names no readable post is rejected, so it cannot widen the query
	 * or tell whether an unreadable post exists.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_parent_path_that_names_no_readable_post_is_rejected(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'hidden',
				'post_author' => self::$user_ids['administrator'],
				'post_status' => 'draft',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( array( 'missing', 'hidden' ) as $path ) {
			$result = wp_get_ability( 'core/content-query' )->execute(
				array(
					'where' => array(
						'type'   => array( 'page' ),
						'parent' => array( 'path' => $path ),
					),
				)
			);

			$this->assertWPError( $result, "The {$path} parent path should be rejected." );
			$this->assertSame( 'content_invalid_filter', $result->get_error_code(), "The {$path} parent path should be an invalid filter." );
			$this->assertArrayHasKey( 'where.parent', $result->get_error_data()['params'], "The {$path} error should name the parent filter." );
		}
	}

	/**
	 * The `where.author` filter accepts a user ID and the current user.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_author_filter_accepts_an_id_and_the_current_user(): void {
		$author_id = self::$user_ids['author'];
		$own       = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'author' );
		$this->register_abilities();

		$ability = wp_get_ability( 'core/content-query' );
		$mine    = $ability->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
					'author' => array( 'current_user' => true ),
				),
			)
		);
		$by_id   = $ability->execute(
			array(
				'where' => array(
					'type'   => array( 'post' ),
					'status' => array( 'draft' ),
					'author' => array( 'id' => $author_id ),
				),
			)
		);

		$this->assertSame( array( $own ), wp_list_pluck( $mine['posts'], 'id' ), 'The current user filter should return the current user\'s drafts.' );
		$this->assertSame( array( $own ), wp_list_pluck( $by_id['posts'], 'id' ), 'The ID filter should return the user\'s drafts.' );
	}

	/**
	 * An author ID that names no visible user is rejected rather than ignored.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_author_id_that_names_no_visible_user_is_rejected(): void {
		$hidden_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->login_as( 'subscriber' );
		$this->register_abilities();

		foreach ( array( REST_TESTS_IMPOSSIBLY_HIGH_NUMBER, $hidden_user ) as $user_id ) {
			$result = wp_get_ability( 'core/content-query' )->execute(
				array(
					'where' => array(
						'type'   => array( 'post' ),
						'author' => array( 'id' => $user_id ),
					),
				)
			);

			$this->assertWPError( $result, 'An author ID that names no visible user should be rejected.' );
			$this->assertSame(
				'The where.author filter must reference an existing user.',
				$result->get_error_data()['params']['where.author'],
				'The error should explain that the author must exist.'
			);
		}
	}

	/**
	 * The `order` input sorts by the chosen field and direction.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_order_sorts_by_field_and_direction(): void {
		$banana = self::factory()->post->create(
			array(
				'post_title'  => 'Banana',
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		$apple  = self::factory()->post->create(
			array(
				'post_title'  => 'Apple',
				'post_status' => 'publish',
				'post_date'   => '2026-02-01 10:00:00',
			)
		);
		$cherry = self::factory()->post->create(
			array(
				'post_title'  => 'Cherry',
				'post_status' => 'publish',
				'post_date'   => '2026-03-01 10:00:00',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_abilities();

		$query = static function ( array $order ) use ( $banana, $apple, $cherry ): array {
			$result = wp_get_ability( 'core/content-query' )->execute(
				array(
					'where' => array(
						'type' => array( 'post' ),
						'ids'  => array( $banana, $apple, $cherry ),
					),
					'order' => $order,
				)
			);

			return wp_list_pluck( $result['posts'], 'id' );
		};

		$this->assertSame(
			array( $apple, $banana, $cherry ),
			$query(
				array(
					'by'        => 'title',
					'direction' => 'asc',
				)
			),
			'Sorting by title ascending should be alphabetical.'
		);
		$this->assertSame( array( $cherry, $banana, $apple ), $query( array( 'by' => 'title' ) ), 'The direction should default to descending.' );
		$this->assertSame( array( $banana, $apple, $cherry ), $query( array( 'direction' => 'asc' ) ), 'The field should default to the publication date.' );
	}

	/**
	 * More than one post type is rejected, even when input validation is skipped.
	 *
	 * @ticket 66268
	 * @since 7.2.0
	 */
	public function test_query_rejects_more_than_one_post_type(): void {
		$this->login_as( 'administrator' );

		$callbacks = $this->get_ability_callbacks( 'core/content-query' );
		$input     = array( 'where' => array( 'type' => array( 'post', 'page' ) ) );

		$validated = wp_get_ability( 'core/content-query' )->execute( $input );
		$result    = $callbacks['execute_callback']( $input );

		$this->assertWPError( $validated, 'Two post types should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $validated->get_error_code(), 'The schema should allow only one post type.' );
		$this->assertFalse( $callbacks['permission_callback']( $input ), 'The permission callback should deny more than one post type.' );
		$this->assertWPError( $result, 'The execute callback should reject more than one post type.' );
		$this->assertSame( 'content_invalid_filter', $result->get_error_code(), 'More than one post type should be an invalid filter.' );
		$this->assertArrayHasKey( 'where.type', $result->get_error_data()['params'], 'The error should name the type filter.' );
	}
}
