<?php
/**
 * Plugin Name: Gutenberg MCP
 * Description: REST API endpoints for the Gutenberg MCP server.
 * Version: 1.0.0
 * Author: Claude Code
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {

	$ns = 'gutenberg-mcp/v1';

	// GET /pages
	register_rest_route( $ns, '/pages', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_list_pages',
		'permission_callback' => 'gmcp_permission',
	] );

	// POST /pages
	register_rest_route( $ns, '/pages', [
		'methods'             => 'POST',
		'callback'            => 'gmcp_create_page',
		'permission_callback' => 'gmcp_permission',
	] );

	// GET /pages/{id}
	register_rest_route( $ns, '/pages/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_get_page',
		'permission_callback' => 'gmcp_permission',
	] );

	// PUT /pages/{id}
	register_rest_route( $ns, '/pages/(?P<id>\d+)', [
		'methods'             => 'PUT',
		'callback'            => 'gmcp_update_page',
		'permission_callback' => 'gmcp_permission',
	] );

	// DELETE /pages/{id}
	register_rest_route( $ns, '/pages/(?P<id>\d+)', [
		'methods'             => 'DELETE',
		'callback'            => 'gmcp_delete_page',
		'permission_callback' => 'gmcp_permission',
	] );

	// GET /block-types
	register_rest_route( $ns, '/block-types', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_list_block_types',
		'permission_callback' => 'gmcp_permission',
	] );

	// POST /render-blocks
	register_rest_route( $ns, '/render-blocks', [
		'methods'             => 'POST',
		'callback'            => 'gmcp_render_blocks',
		'permission_callback' => 'gmcp_permission',
	] );
} );

function gmcp_permission() {
	return current_user_can( 'edit_pages' );
}

function gmcp_format_page( $post ) {
	$blocks = parse_blocks( $post->post_content );
	// Remove empty/whitespace-only blocks
	$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

	return [
		'id'          => $post->ID,
		'title'       => $post->post_title,
		'status'      => $post->post_status,
		'slug'        => $post->post_name,
		'link'        => get_permalink( $post->ID ),
		'modified'    => $post->post_modified,
		'block_count' => count( $blocks ),
		'blocks'      => $blocks,
	];
}

function gmcp_blocks_to_content( array $blocks ): string {
	$content = '';
	foreach ( $blocks as $block ) {
		$name         = $block['blockName'] ?? 'core/freeform';
		$attrs        = $block['attrs'] ?? [];
		$inner        = $block['innerContent'] ?? '';
		$attrs_json   = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs ) : '';
		$content     .= "<!-- wp:{$name}{$attrs_json} -->\n{$inner}\n<!-- /wp:{$name} -->\n\n";
	}
	return trim( $content );
}

function gmcp_list_pages( WP_REST_Request $req ) {
	$args = [
		'post_type'      => 'page',
		'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
		'posts_per_page' => (int) ( $req->get_param( 'per_page' ) ?: 50 ),
		'paged'          => (int) ( $req->get_param( 'page' ) ?: 1 ),
	];
	if ( $req->get_param( 'search' ) ) {
		$args['s'] = sanitize_text_field( $req->get_param( 'search' ) );
	}

	$query = new WP_Query( $args );
	$pages = array_map( 'gmcp_format_page', $query->posts );

	return rest_ensure_response( [
		'pages' => $pages,
		'total' => $query->found_posts,
		'pages_count' => $query->max_num_pages,
	] );
}

function gmcp_get_page( WP_REST_Request $req ) {
	$post = get_post( (int) $req['id'] );
	if ( ! $post || $post->post_type !== 'page' ) {
		return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
	}
	return rest_ensure_response( gmcp_format_page( $post ) );
}

function gmcp_create_page( WP_REST_Request $req ) {
	$body   = $req->get_json_params();
	$title  = sanitize_text_field( $body['title'] ?? '' );
	$status = in_array( $body['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'private' ], true )
		? $body['status']
		: 'draft';

	$content = isset( $body['blocks'] ) ? gmcp_blocks_to_content( $body['blocks'] ) : '';

	$id = wp_insert_post( [
		'post_type'    => 'page',
		'post_title'   => $title,
		'post_status'  => $status,
		'post_content' => $content,
	], true );

	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'create_failed', $id->get_error_message(), [ 'status' => 500 ] );
	}

	return rest_ensure_response( gmcp_format_page( get_post( $id ) ) );
}

function gmcp_update_page( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'page' ) {
		return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
	}

	$body   = $req->get_json_params();
	$update = [ 'ID' => $id ];

	if ( isset( $body['title'] ) ) {
		$update['post_title'] = sanitize_text_field( $body['title'] );
	}
	if ( isset( $body['status'] ) ) {
		$update['post_status'] = $body['status'];
	}
	if ( isset( $body['blocks'] ) ) {
		$update['post_content'] = gmcp_blocks_to_content( $body['blocks'] );
	}

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
	}

	return rest_ensure_response( gmcp_format_page( get_post( $id ) ) );
}

function gmcp_delete_page( WP_REST_Request $req ) {
	$id    = (int) $req['id'];
	$force = filter_var( $req->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
	$post  = get_post( $id );
	if ( ! $post || $post->post_type !== 'page' ) {
		return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
	}

	$result = wp_delete_post( $id, $force );
	if ( ! $result ) {
		return new WP_Error( 'delete_failed', 'Could not delete page', [ 'status' => 500 ] );
	}

	return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
}

function gmcp_list_block_types() {
	$registry = WP_Block_Type_Registry::get_instance();
	$types    = $registry->get_all_registered();
	$result   = [];
	foreach ( $types as $name => $type ) {
		$result[] = [
			'name'        => $name,
			'title'       => $type->title ?? $name,
			'description' => $type->description ?? '',
			'category'    => $type->category ?? '',
		];
	}
	return rest_ensure_response( $result );
}

function gmcp_render_blocks( WP_REST_Request $req ) {
	$body   = $req->get_json_params();
	$blocks = $body['blocks'] ?? [];
	$html   = '';
	foreach ( $blocks as $block ) {
		$name   = $block['blockName'] ?? 'core/freeform';
		$attrs  = $block['attrs'] ?? [];
		$inner  = $block['innerContent'] ?? '';
		$parsed = [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner,
			'innerContent' => [ $inner ],
		];
		$html .= render_block( $parsed );
	}
	return rest_ensure_response( [ 'html' => $html ] );
}
