<?php
/**
 * Abilities API: WP_Abilities_Content_Get class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 7.2.0
 */

declare( strict_types = 1 );

/**
 * Core class used to register the `core/content-get` ability.
 *
 * The ability gets one readable post of a post type exposed to abilities via
 * `show_in_abilities`, through a post reference: by ID, by post type and slug, by post type
 * and path, or by URL. It takes a `select` object that chooses the returned fields, shared
 * with `core/content-query` through {@see WP_Abilities_Content_Posts}.
 *
 * The input is a plain object at the top level, with the `oneOf` of reference forms inside
 * its `post` property, since some tool-calling clients reject a `oneOf` at the top level of
 * a tool's input schema.
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
final class WP_Abilities_Content_Get {

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
	 * Registers the read-only `core/content-get` ability.
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

		wp_register_ability(
			'core/content-get',
			array(
				'label'               => __( 'Get Content' ),
				'description'         => __( 'Gets one readable post, page, or other content item of a post type exposed to abilities. Reference it by ID, by post type and slug, by post type and path, or by URL. Use `select` to choose the returned fields and the format of text fields. Requires an authenticated user.' ),
				'category'            => 'content',
				'input_schema'        => $this->get_input_schema( $post_types ),
				'output_schema'       => $this->posts->get_post_output_schema(),
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
	 * This gate is the authoritative permission decision: it resolves the referenced post
	 * and denies missing, mismatched, or unreadable posts before execution. Requests that
	 * select the stored source of a text field require edit access to the post.
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

		$post = $this->posts->resolve_post_reference( $input['post'] ?? null );
		if ( ! $post ) {
			return false;
		}

		if ( $this->posts->requires_edit( $this->posts->normalize_select( $input['select'] ?? null ) ) ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		return $this->posts->can_read_post( $post );
	}

	/**
	 * Executes the ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error The post, or a WP_Error when it cannot be resolved.
	 */
	private function execute( $input = array() ) {
		$input = rest_sanitize_object( $input );
		$post  = $this->posts->resolve_post_reference( $input['post'] ?? null );
		if ( ! $post ) {
			return $this->not_found_error();
		}

		return $this->posts->format_post( $post, $this->posts->normalize_select( $input['select'] ?? null ) );
	}

	/**
	 * Builds the input schema for the ability.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'post' ),
			'properties'           => array(
				'post'   => $this->posts->get_post_reference_schema( $post_types ),
				'select' => $this->posts->get_select_input_schema(),
			),
		);
	}

	/**
	 * Builds the not-found error.
	 *
	 * Unreachable through gated transports, which run the permission callback first and
	 * deny the same lookups. It is kept so that a direct call to the execute callback still
	 * fails closed on a structural lookup failure: a missing post, a post type that is not
	 * exposed, or a post type that does not match the requested one.
	 *
	 * This is not a permission check. The execute callback deliberately does not repeat
	 * the read/edit checks that the permission callback already performed, so a direct call
	 * bypasses them. Only invoke the callback through {@see WP_Ability::execute()}, which
	 * always runs the permission callback first.
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
}
