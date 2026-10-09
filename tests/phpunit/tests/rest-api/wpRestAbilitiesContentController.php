<?php

declare( strict_types=1 );

/**
 * Tests dispatching the core/content-get and core/content-query abilities through the Abilities REST run endpoint.
 *
 * @covers WP_Abilities_Content
 *
 * @group abilities-api
 * @group restapi
 */
class Tests_REST_API_WpRestAbilitiesContentController extends WP_UnitTestCase {

	/**
	 * The REST server instance for the current test.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * The run route for the core/content-get ability.
	 *
	 * @var string
	 */
	const CONTENT_GET_ROUTE = '/wp-abilities/v1/abilities/core/content-get/run';

	/**
	 * The run route for the core/content-query ability.
	 *
	 * @var string
	 */
	const CONTENT_QUERY_ROUTE = '/wp-abilities/v1/abilities/core/content-query/run';

	/**
	 * Sets up users and registers the core abilities.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		remove_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		remove_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );

		foreach ( wp_get_abilities() as $ability ) {
			wp_unregister_ability( $ability->get_name() );
		}
		foreach ( wp_get_ability_categories() as $ability_category ) {
			wp_unregister_ability_category( $ability_category->get_slug() );
		}

		add_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
		add_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		/*
		 * Restore the hooks right away instead of after the class. The first test of a run
		 * snapshots the hooks and every test resets them to that snapshot, so changes left
		 * here would leak into every later test whenever this class runs first.
		 */
		remove_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
		remove_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );
		add_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		add_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );
	}

	/**
	 * Cleans up registered abilities and categories.
	 *
	 * @since 7.2.0
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( wp_get_abilities() as $ability ) {
			wp_unregister_ability( $ability->get_name() );
		}
		foreach ( wp_get_ability_categories() as $ability_category ) {
			wp_unregister_ability_category( $ability_category->get_slug() );
		}
	}

	/**
	 * Sets up a fresh REST server and logs in as the administrator.
	 *
	 * @since 7.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Resets the REST server.
	 *
	 * @since 7.2.0
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Builds a GET run request with the given ability input.
	 *
	 * The input is set as nested query parameters, as a query string such as
	 * `input[where][type][]=post` would deliver it.
	 *
	 * @param string               $route The run route, such as self::CONTENT_QUERY_ROUTE.
	 * @param array<string, mixed> $input The ability input.
	 * @return WP_REST_Request The request.
	 */
	private function run_request( string $route, array $input ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( array( 'input' => $input ) );
		return $request;
	}

	/**
	 * Tests that a logged-out user cannot run either content ability.
	 *
	 * @ticket 66268
	 */
	public function test_logged_out_user_receives_401(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( 0 );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where' => array( 'type' => array( 'post' ) ),
				)
			)
		);

		$this->assertSame( 401, $response->get_status(), 'A logged-out user should not be able to query posts.' );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_GET_ROUTE,
				array(
					'post' => array( 'id' => $post_id ),
				)
			)
		);

		$this->assertSame( 401, $response->get_status(), 'A logged-out user should not be able to get a published post.' );
	}

	/**
	 * Tests that a subscriber cannot query draft posts.
	 *
	 * @ticket 66268
	 */
	public function test_subscriber_requesting_drafts_receives_403(): void {
		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where' => array(
						'type'   => array( 'post' ),
						'status' => array( 'draft' ),
					),
				)
			)
		);

		$this->assertSame( 403, $response->get_status(), 'A subscriber should not be able to query draft posts.' );
	}

	/**
	 * Tests that a subscriber receives the rendered fields of published posts.
	 *
	 * @ticket 66268
	 */
	public function test_subscriber_requesting_published_posts_receives_readable_fields(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Published for subscriber via REST',
				'post_content' => 'Subscriber REST body.',
				'post_status'  => 'publish',
			)
		);

		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where'  => array( 'type' => array( 'post' ) ),
					'select' => array(
						'title'   => array( 'format' => 'html' ),
						'content' => array( 'format' => 'html' ),
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'A subscriber should be able to query published posts.' );
		$this->assertContains( $post_id, wp_list_pluck( $data['posts'], 'id' ), 'The published post should be among the returned posts.' );

		$post_index = array_search( $post_id, wp_list_pluck( $data['posts'], 'id' ), true );
		$this->assertIsInt( $post_index, 'Precondition: the published post should have a position in the posts list.' );

		$post = $data['posts'][ $post_index ];
		$this->assertSame( array( 'id', 'title', 'content' ), array_keys( $post ), 'Only the selected fields should be returned to a subscriber.' );
		$this->assertSame( 'Published for subscriber via REST', $post['title'], 'The HTML title should be returned to a subscriber.' );
		$this->assertStringContainsString( '<p>Subscriber REST body.</p>', $post['content'], 'The content should be returned to a subscriber as rendered HTML, not as its stored source.' );
	}

	/**
	 * Tests that a subscriber cannot select the stored source of a text field.
	 *
	 * @ticket 66268
	 */
	public function test_subscriber_requesting_source_format_receives_403(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where'  => array( 'type' => array( 'post' ) ),
					'select' => array(
						'content' => array( 'format' => 'source' ),
					),
				)
			)
		);

		$this->assertSame( 403, $response->get_status(), 'A subscriber should not be able to query the stored source of post content.' );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_GET_ROUTE,
				array(
					'post'   => array( 'id' => $post_id ),
					'select' => array(
						'content' => array( 'format' => 'source' ),
					),
				)
			)
		);

		$this->assertSame( 403, $response->get_status(), 'A subscriber should not be able to get the stored source of a published post the subscriber cannot edit.' );
	}

	/**
	 * Tests that an administrator can query published posts.
	 *
	 * @ticket 66268
	 */
	public function test_admin_query_returns_published_posts(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Published via REST',
				'post_status' => 'publish',
			)
		);

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where' => array( 'type' => array( 'post' ) ),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'An administrator should be able to query published posts.' );
		$this->assertArrayHasKey( 'posts', $data, 'The content-query ability should return a posts list.' );
		$this->assertContains( $post_id, wp_list_pluck( $data['posts'], 'id' ), 'The published post should be among the returned posts.' );
	}

	/**
	 * Tests that the where.ids filter limits the results to the listed posts, in the default order.
	 *
	 * @ticket 66268
	 */
	public function test_admin_query_where_ids_limits_results(): void {
		$first  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);
		$second = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-02-01 10:00:00',
			)
		);
		$third  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-03-01 10:00:00',
			)
		);

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					// Deliberately list the IDs in the opposite of the expected date order.
					'where' => array(
						'type' => array( 'post' ),
						'ids'  => array( $first, $third ),
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The where.ids query should succeed.' );
		$this->assertSame( array( $third, $first ), wp_list_pluck( $data['posts'], 'id' ), 'The listed posts should be returned newest first, regardless of the order of where.ids.' );
		$this->assertNotContains( $second, wp_list_pluck( $data['posts'], 'id' ), 'Posts outside where.ids should not be returned.' );
	}

	/**
	 * Tests getting a single post by ID.
	 *
	 * @ticket 66268
	 */
	public function test_get_single_post_by_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_GET_ROUTE,
				array(
					'post' => array( 'id' => $post_id ),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The ID lookup should succeed.' );
		$this->assertSame( $post_id, $data['id'], 'The ID lookup should return the requested post.' );
		$this->assertArrayNotHasKey( 'posts', $data, 'A single post lookup should not return a posts list.' );
		$this->assertArrayNotHasKey( 'total', $data, 'A single post lookup should not return query totals.' );
	}

	/**
	 * Tests getting a single post by post type and slug.
	 *
	 * @ticket 66268
	 */
	public function test_get_single_post_by_slug(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'rest-content-slug',
				'post_status' => 'publish',
			)
		);

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_GET_ROUTE,
				array(
					'post' => array(
						'type' => 'post',
						'slug' => 'rest-content-slug',
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The slug lookup should succeed.' );
		$this->assertSame( $post_id, $data['id'], 'The slug lookup should return the matching post.' );
		$this->assertSame( 'rest-content-slug', $data['slug'], 'The slug lookup should return the post slug.' );
		$this->assertArrayNotHasKey( 'posts', $data, 'A slug lookup should not return a posts list.' );
	}

	/**
	 * Tests that the read-only content abilities reject POST requests.
	 *
	 * @ticket 66268
	 */
	public function test_wrong_http_method_returns_405(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$inputs  = array(
			self::CONTENT_GET_ROUTE   => array(
				'post' => array( 'id' => $post_id ),
			),
			self::CONTENT_QUERY_ROUTE => array(
				'where' => array( 'type' => array( 'post' ) ),
			),
		);

		foreach ( $inputs as $route => $input ) {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

			$response = $this->server->dispatch( $request );

			$this->assertSame( 405, $response->get_status(), "A POST request to the read-only ability at {$route} should be rejected." );
			$this->assertSame( 'rest_ability_invalid_method', $response->get_data()['code'], "The error for {$route} should identify the invalid HTTP method." );
		}
	}

	/**
	 * Tests that a paged query returns the totals in the response body.
	 *
	 * @ticket 66268
	 */
	public function test_pagination_returns_totals_in_body(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where' => array( 'type' => array( 'post' ) ),
					'page'  => array(
						'number' => 1,
						'size'   => 2,
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The paginated query should succeed.' );
		$this->assertCount( 2, $data['posts'], 'The first page should be limited to page.size posts.' );
		$this->assertGreaterThanOrEqual( 3, $data['total'], 'The total should count posts across all pages.' );
		$this->assertSame( (int) ceil( $data['total'] / 2 ), $data['total_pages'], 'The total pages should match the total and page.size.' );
	}

	/**
	 * Tests that requesting a page past the last one returns a 404 error.
	 *
	 * @ticket 66268
	 */
	public function test_out_of_range_page_returns_404(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where' => array( 'type' => array( 'post' ) ),
					'page'  => array(
						'number' => 999,
						'size'   => 1,
					),
				)
			)
		);

		$this->assertSame( 404, $response->get_status(), 'Requesting a page past the last one should return a 404 error.' );
		$this->assertSame( 'content_invalid_page_number', $response->get_data()['code'], 'The error should identify the invalid page number.' );
	}

	/**
	 * Tests that query-string style string values in nested input are coerced to the schema types.
	 *
	 * A GET query string delivers every scalar as a string, such as `input[page][size]=2` or
	 * `input[select][title]=true`. The run endpoint should accept them for nested properties,
	 * for list properties given as a single or comma-separated value, and for both the boolean
	 * and the object form of multi-type `select` entries, and pass the ability native values.
	 *
	 * @ticket 66268
	 */
	public function test_query_string_values_are_coerced_for_nested_input(): void {
		$first  = self::factory()->post->create(
			array(
				'post_author' => self::$admin_id,
				'post_status' => 'publish',
				'post_date'   => '2025-01-01 10:00:00',
			)
		);
		$second = self::factory()->post->create(
			array(
				'post_author' => self::$admin_id,
				'post_status' => 'publish',
				'post_date'   => '2025-02-01 10:00:00',
			)
		);
		$third  = self::factory()->post->create(
			array(
				'post_author'  => self::$admin_id,
				'post_title'   => 'Coerced <em>query</em> title',
				'post_excerpt' => 'Coerced excerpt.',
				'post_status'  => 'publish',
				'post_date'    => '2025-03-01 10:00:00',
			)
		);

		$received_input = null;
		add_action(
			'wp_ability_invoked',
			static function ( $ability_name, $input ) use ( &$received_input ) {
				if ( 'core/content-query' === $ability_name ) {
					$received_input = $input;
				}
			},
			10,
			2
		);

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where'  => array(
						'type' => 'post',
						'ids'  => implode( ',', array( $first, $second, $third ) ),
					),
					'page'   => array(
						'number' => '1',
						'size'   => '2',
					),
					'select' => array(
						'title'   => 'true',
						'slug'    => 'false',
						'excerpt' => array( 'format' => 'html' ),
						'author'  => array( 'fields' => 'slug' ),
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'A query with string values for nested input should succeed.' );
		$this->assertSame(
			array(
				'where'  => array(
					'type' => array( 'post' ),
					'ids'  => array( $first, $second, $third ),
				),
				'page'   => array(
					'number' => 1,
					'size'   => 2,
				),
				'select' => array(
					'title'   => true,
					'slug'    => false,
					'excerpt' => array( 'format' => 'html' ),
					'author'  => array( 'fields' => array( 'slug' ) ),
				),
			),
			$received_input,
			'The run endpoint should pass the ability its input coerced to the types in the input schema.'
		);
		$this->assertSame( array( $third, $second ), wp_list_pluck( $data['posts'], 'id' ), 'A page size of "2" should return the two newest of the listed posts.' );
		$this->assertSame( 3, $data['total'], 'The total should count every listed post.' );
		$this->assertSame( 2, $data['total_pages'], 'The total pages should be counted with a page size of "2".' );

		$post = $data['posts'][0];
		$this->assertSame( array( 'id', 'title', 'excerpt', 'author' ), array_keys( $post ), 'Fields set to "true" or to an options object should be returned, and fields set to "false" should not.' );
		$this->assertSame( 'Coerced query title', $post['title'], 'A title selected with "true" should be returned as plain text.' );
		$this->assertStringContainsString( '<p>Coerced excerpt.</p>', $post['excerpt'], 'An excerpt selected with the html format should be returned as rendered HTML.' );
		$this->assertSame(
			array(
				'id'   => self::$admin_id,
				'slug' => get_userdata( self::$admin_id )->user_nicename,
			),
			$post['author'],
			'An author selected with a comma-separated fields value should return the requested fields and the ID.'
		);

		$parent_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$child_page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $parent_page,
			)
		);

		$response = $this->server->dispatch(
			$this->run_request(
				self::CONTENT_QUERY_ROUTE,
				array(
					'where'  => array(
						'type'      => 'page',
						'top_level' => 'true',
						'ids'       => implode( ',', array( $parent_page, $child_page ) ),
					),
					'select' => array(
						'parent' => array( 'fields' => 'id' ),
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'A where.top_level filter sent as the string "true" should be accepted for a hierarchical post type.' );
		$this->assertTrue( $received_input['where']['top_level'], 'The where.top_level string "true" should reach the ability as a boolean.' );
		$this->assertSame( array( $parent_page ), wp_list_pluck( $data['posts'], 'id' ), 'Only the top-level page should be returned.' );
		$this->assertNull( $data['posts'][0]['parent'], 'A top-level page should have a null parent.' );
	}
}
