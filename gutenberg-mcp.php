<?php
/**
 * Plugin Name: Gutenberg MCP
 * Description: REST API endpoints for the Gutenberg MCP server — supports innerBlocks, patterns, theme styles, block schema.
 * Version: 2.0.0
 * Author: Claude Code
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {

	$ns = 'gutenberg-mcp/v1';

	register_rest_route( $ns, '/pages', [
		[ 'methods' => 'GET',  'callback' => 'gmcp_list_pages',   'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'POST', 'callback' => 'gmcp_create_page',  'permission_callback' => 'gmcp_permission' ],
	] );

	register_rest_route( $ns, '/pages/(?P<id>\d+)', [
		[ 'methods' => 'GET',    'callback' => 'gmcp_get_page',    'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'PUT',    'callback' => 'gmcp_update_page', 'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'DELETE', 'callback' => 'gmcp_delete_page', 'permission_callback' => 'gmcp_permission' ],
	] );

	register_rest_route( $ns, '/block-types', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_list_block_types',
		'permission_callback' => 'gmcp_permission',
	] );

	register_rest_route( $ns, '/block-schema/(?P<name>[a-zA-Z0-9\-_\/]+)', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_get_block_schema',
		'permission_callback' => 'gmcp_permission',
	] );

	register_rest_route( $ns, '/render-blocks', [
		'methods'             => 'POST',
		'callback'            => 'gmcp_render_blocks',
		'permission_callback' => 'gmcp_permission',
	] );

	register_rest_route( $ns, '/analyze-reference', [
		'methods'             => 'POST',
		'callback'            => 'gmcp_analyze_reference',
		'permission_callback' => 'gmcp_permission',
	] );

	// Theme styles from theme.json
	register_rest_route( $ns, '/theme-styles', [
		'methods'             => 'GET',
		'callback'            => 'gmcp_get_theme_styles',
		'permission_callback' => 'gmcp_permission',
	] );

	// Block patterns (wp_block post type)
	register_rest_route( $ns, '/patterns', [
		[ 'methods' => 'GET',  'callback' => 'gmcp_list_patterns',  'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'POST', 'callback' => 'gmcp_create_pattern', 'permission_callback' => 'gmcp_permission' ],
	] );

	register_rest_route( $ns, '/patterns/(?P<id>\d+)', [
		[ 'methods' => 'GET',    'callback' => 'gmcp_get_pattern',    'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'PUT',    'callback' => 'gmcp_update_pattern', 'permission_callback' => 'gmcp_permission' ],
		[ 'methods' => 'DELETE', 'callback' => 'gmcp_delete_pattern', 'permission_callback' => 'gmcp_permission' ],
	] );
} );

function gmcp_permission() {
	return current_user_can( 'edit_pages' );
}

// ---------------------------------------------------------------------------
// Core helpers
// ---------------------------------------------------------------------------

function gmcp_format_page( $post ) {
	$blocks = parse_blocks( $post->post_content );
	$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

	$data = [
		'id'          => $post->ID,
		'title'       => $post->post_title,
		'status'      => $post->post_status,
		'slug'        => $post->post_name,
		'link'        => get_permalink( $post->ID ),
		'modified'    => $post->post_modified,
		'template'    => get_page_template_slug( $post->ID ),
		'block_count' => count( $blocks ),
		'blocks'      => $blocks,
	];

	$parent = $post->post_parent;
	if ( $parent ) {
		$data['parent'] = $parent;
	}

	return $data;
}

/**
 * Recursively converts block descriptors (including innerBlocks) to Gutenberg comment markup.
 */
function gmcp_blocks_to_content( array $blocks ): string {
	$content = '';
	foreach ( $blocks as $block ) {
		$name         = $block['blockName'] ?? 'core/freeform';
		$attrs        = $block['attrs'] ?? [];
		$inner_blocks = $block['innerBlocks'] ?? [];
		$inner        = $block['innerContent'] ?? '';

		if ( is_array( $inner ) ) {
			$inner = implode( '', array_filter( $inner, fn( $c ) => $c !== null ) );
		}

		// Recursively render innerBlocks and append to inner content
		if ( ! empty( $inner_blocks ) ) {
			$nested = gmcp_blocks_to_content( $inner_blocks );
			// If the outer HTML wraps the inner content (e.g. <div class="wp-block-group">...INNER...</div>)
			// try to splice nested content inside; otherwise append after inner
			if ( ! empty( $inner ) && strpos( $inner, 'INNER_BLOCKS_PLACEHOLDER' ) !== false ) {
				$inner = str_replace( 'INNER_BLOCKS_PLACEHOLDER', "\n" . $nested . "\n", $inner );
			} else {
				// For layout blocks, build the markup from innerBlocks only when no outer HTML provided
				if ( empty( trim( $inner ) ) ) {
					$inner = $nested;
				} else {
					// Inject innerBlocks content before closing tag of wrapping element
					$inner = gmcp_inject_inner_blocks( $inner, $nested );
				}
			}
		}

		$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs ) : '';
		$content   .= "<!-- wp:{$name}{$attrs_json} -->\n{$inner}\n<!-- /wp:{$name} -->\n\n";
	}
	return trim( $content );
}

/**
 * Inject nested block content inside the last closing tag of the wrapper HTML.
 * Falls back to appending if no suitable closing tag is found.
 */
function gmcp_inject_inner_blocks( string $outer_html, string $inner_content ): string {
	// Find the last closing tag and insert before it
	if ( preg_match( '/(.*?)(<\/[a-zA-Z][a-zA-Z0-9]*>\s*)$/s', $outer_html, $m ) ) {
		return $m[1] . "\n" . $inner_content . "\n" . rtrim( $m[2] );
	}
	return $outer_html . "\n" . $inner_content;
}

/**
 * Prepare a block descriptor for render_block(), supporting nested innerBlocks.
 */
function gmcp_prepare_block( array $block ): array {
	$inner        = $block['innerContent'] ?? '';
	$inner_blocks = $block['innerBlocks'] ?? [];

	if ( is_array( $inner ) ) {
		$inner = implode( '', array_filter( $inner, fn( $c ) => $c !== null ) );
	}

	$prepared_inner = array_map( 'gmcp_prepare_block', $inner_blocks );

	return [
		'blockName'    => $block['blockName'] ?? 'core/freeform',
		'attrs'        => $block['attrs'] ?? [],
		'innerBlocks'  => $prepared_inner,
		'innerHTML'    => $inner,
		'innerContent' => $prepared_inner ? array_merge( [ $inner ], array_fill( 0, count( $prepared_inner ), null ) ) : [ $inner ],
	];
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

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
	if ( $req->get_param( 'parent' ) ) {
		$args['post_parent'] = (int) $req->get_param( 'parent' );
	}

	$query = new WP_Query( $args );
	return rest_ensure_response( [
		'pages'       => array_map( 'gmcp_format_page', $query->posts ),
		'total'       => $query->found_posts,
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
		? $body['status'] : 'draft';

	$content = isset( $body['blocks'] ) ? gmcp_blocks_to_content( $body['blocks'] ) : '';

	$post_data = [
		'post_type'    => 'page',
		'post_title'   => $title,
		'post_status'  => $status,
		'post_content' => $content,
	];

	if ( ! empty( $body['parent'] ) ) {
		$post_data['post_parent'] = (int) $body['parent'];
	}

	$id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'create_failed', $id->get_error_message(), [ 'status' => 500 ] );
	}

	if ( ! empty( $body['template'] ) ) {
		update_post_meta( $id, '_wp_page_template', sanitize_text_field( $body['template'] ) );
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
	if ( isset( $body['parent'] ) ) {
		$update['post_parent'] = (int) $body['parent'];
	}

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
	}

	if ( isset( $body['template'] ) ) {
		update_post_meta( $id, '_wp_page_template', sanitize_text_field( $body['template'] ) );
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

// ---------------------------------------------------------------------------
// Block types & schema
// ---------------------------------------------------------------------------

function gmcp_list_block_types() {
	$registry = WP_Block_Type_Registry::get_instance();
	$result   = [];
	foreach ( $registry->get_all_registered() as $name => $type ) {
		$result[] = [
			'name'        => $name,
			'title'       => $type->title ?? $name,
			'description' => $type->description ?? '',
			'category'    => $type->category ?? '',
			'supports'    => $type->supports ?? [],
		];
	}
	return rest_ensure_response( $result );
}

function gmcp_get_block_schema( WP_REST_Request $req ) {
	$name     = urldecode( $req['name'] );
	$registry = WP_Block_Type_Registry::get_instance();
	$type     = $registry->get_registered( $name );

	if ( ! $type ) {
		return new WP_Error( 'not_found', "Block type '{$name}' not registered", [ 'status' => 404 ] );
	}

	return rest_ensure_response( [
		'name'        => $type->name,
		'title'       => $type->title ?? $name,
		'description' => $type->description ?? '',
		'category'    => $type->category ?? '',
		'attributes'  => $type->attributes ?? [],
		'supports'    => $type->supports ?? [],
		'example'     => $type->example ?? null,
		'keywords'    => $type->keywords ?? [],
	] );
}

// ---------------------------------------------------------------------------
// Render blocks
// ---------------------------------------------------------------------------

function gmcp_render_blocks( WP_REST_Request $req ) {
	$body   = $req->get_json_params();
	$blocks = $body['blocks'] ?? [];
	$html   = '';
	foreach ( $blocks as $block ) {
		$html .= render_block( gmcp_prepare_block( $block ) );
	}
	return rest_ensure_response( [ 'html' => $html ] );
}

// ---------------------------------------------------------------------------
// Theme styles
// ---------------------------------------------------------------------------

function gmcp_get_theme_styles() {
	$theme_json_path = get_template_directory() . '/theme.json';
	$styles          = [];

	if ( file_exists( $theme_json_path ) ) {
		$json    = file_get_contents( $theme_json_path );
		$decoded = json_decode( $json, true );
		if ( $decoded ) {
			$settings       = $decoded['settings'] ?? [];
			$styles['colors']     = $settings['color']['palette'] ?? [];
			$styles['gradients']  = $settings['color']['gradients'] ?? [];
			$styles['typography'] = [
				'fontFamilies' => $settings['typography']['fontFamilies'] ?? [],
				'fontSizes'    => $settings['typography']['fontSizes'] ?? [],
			];
			$styles['spacing']    = $settings['spacing']['spacingSizes'] ?? [];
			$styles['layout']     = $settings['layout'] ?? [];
		}
	}

	// Supplement with registered editor color palette from add_theme_support
	$editor_palette = get_theme_support( 'editor-color-palette' );
	if ( $editor_palette && empty( $styles['colors'] ) ) {
		$styles['colors'] = $editor_palette[0] ?? [];
	}

	$styles['available_templates'] = gmcp_get_page_templates();
	$styles['theme_name']          = wp_get_theme()->get( 'Name' );

	return rest_ensure_response( $styles );
}

function gmcp_get_page_templates() {
	$templates = [];
	foreach ( wp_get_theme()->get_page_templates() as $file => $name ) {
		$templates[] = [ 'file' => $file, 'name' => $name ];
	}
	return $templates;
}

// ---------------------------------------------------------------------------
// Block patterns (wp_block post type = reusable blocks / synced patterns)
// ---------------------------------------------------------------------------

function gmcp_format_pattern( $post ) {
	$blocks = parse_blocks( $post->post_content );
	$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

	return [
		'id'          => $post->ID,
		'title'       => $post->post_title,
		'status'      => $post->post_status,
		'content'     => $post->post_content,
		'block_count' => count( $blocks ),
		'blocks'      => $blocks,
		'modified'    => $post->post_modified,
	];
}

function gmcp_list_patterns( WP_REST_Request $req ) {
	$args = [
		'post_type'      => 'wp_block',
		'post_status'    => [ 'publish', 'draft' ],
		'posts_per_page' => (int) ( $req->get_param( 'per_page' ) ?: 50 ),
		'paged'          => (int) ( $req->get_param( 'page' ) ?: 1 ),
	];
	if ( $req->get_param( 'search' ) ) {
		$args['s'] = sanitize_text_field( $req->get_param( 'search' ) );
	}

	$query = new WP_Query( $args );
	return rest_ensure_response( [
		'patterns'     => array_map( 'gmcp_format_pattern', $query->posts ),
		'total'        => $query->found_posts,
		'pages_count'  => $query->max_num_pages,
	] );
}

function gmcp_get_pattern( WP_REST_Request $req ) {
	$post = get_post( (int) $req['id'] );
	if ( ! $post || $post->post_type !== 'wp_block' ) {
		return new WP_Error( 'not_found', 'Pattern not found', [ 'status' => 404 ] );
	}
	return rest_ensure_response( gmcp_format_pattern( $post ) );
}

function gmcp_create_pattern( WP_REST_Request $req ) {
	$body    = $req->get_json_params();
	$title   = sanitize_text_field( $body['title'] ?? 'Untitled Pattern' );
	$status  = in_array( $body['status'] ?? 'publish', [ 'publish', 'draft' ], true ) ? $body['status'] : 'publish';
	$content = isset( $body['blocks'] ) ? gmcp_blocks_to_content( $body['blocks'] ) : ( $body['content'] ?? '' );

	$id = wp_insert_post( [
		'post_type'    => 'wp_block',
		'post_title'   => $title,
		'post_status'  => $status,
		'post_content' => $content,
	], true );

	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'create_failed', $id->get_error_message(), [ 'status' => 500 ] );
	}

	return rest_ensure_response( gmcp_format_pattern( get_post( $id ) ) );
}

function gmcp_update_pattern( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'wp_block' ) {
		return new WP_Error( 'not_found', 'Pattern not found', [ 'status' => 404 ] );
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
	} elseif ( isset( $body['content'] ) ) {
		$update['post_content'] = $body['content'];
	}

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
	}

	return rest_ensure_response( gmcp_format_pattern( get_post( $id ) ) );
}

function gmcp_delete_pattern( WP_REST_Request $req ) {
	$id    = (int) $req['id'];
	$force = filter_var( $req->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
	$post  = get_post( $id );
	if ( ! $post || $post->post_type !== 'wp_block' ) {
		return new WP_Error( 'not_found', 'Pattern not found', [ 'status' => 404 ] );
	}

	$result = wp_delete_post( $id, $force );
	if ( ! $result ) {
		return new WP_Error( 'delete_failed', 'Could not delete pattern', [ 'status' => 500 ] );
	}

	return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
}

// ---------------------------------------------------------------------------
// Analyze reference page
// ---------------------------------------------------------------------------

function gmcp_analyze_reference( WP_REST_Request $req ) {
	$body = $req->get_json_params();
	$url  = $body['url'] ?? '';

	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'URL is required', [ 'status' => 400 ] );
	}

	$response = wp_remote_get( $url, [
		'timeout' => 30,
		'headers' => [ 'User-Agent' => 'WordPress/Gutenberg-MCP-Analyzer' ],
	] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'fetch_failed', $response->get_error_message(), [ 'status' => 500 ] );
	}

	$html    = wp_remote_retrieve_body( $response );
	$content = gmcp_extract_wp_content( $html, $url );

	if ( empty( $content ) ) {
		return new WP_Error( 'no_content', 'Could not extract content from URL', [ 'status' => 400 ] );
	}

	$blocks = parse_blocks( $content );
	$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

	return rest_ensure_response( [
		'url'         => $url,
		'title'       => gmcp_extract_title( $html ),
		'blocks'      => $blocks,
		'block_count' => count( $blocks ),
		'raw_content' => $content,
	] );
}

function gmcp_extract_wp_content( $html, $url ) {
	$dom = new DOMDocument();
	@$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$xpath = new DOMXPath( $dom );

	$selectors = [
		'//article[contains(@class, "post")]',
		'//*[contains(@class, "entry-content")]',
		'//*[contains(@class, "post-content")]',
		'//*[contains(@class, "content-area")]',
		'//main[contains(@class, "site-main")]',
		'//div[contains(@class, "wp-block-post-content")]',
		'//article',
		'//main',
	];

	$content_node = null;
	foreach ( $selectors as $selector ) {
		$nodes = $xpath->query( $selector );
		if ( $nodes && $nodes->length > 0 ) {
			$content_node = $nodes->item( 0 );
			break;
		}
	}

	if ( ! $content_node ) {
		return '';
	}

	$content_html = gmcp_get_inner_html( $content_node );
	return gmcp_html_to_blocks( $content_html );
}

function gmcp_get_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

function gmcp_html_to_blocks( $html ) {
	if ( strpos( $html, '<!-- wp:' ) !== false ) {
		return $html;
	}

	$dom = new DOMDocument();
	@$dom->loadHTML( mb_convert_encoding( '<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

	$blocks = '';
	$body   = $dom->getElementsByTagName( 'div' )->item( 0 );

	if ( $body ) {
		foreach ( $body->childNodes as $node ) {
			$blocks .= gmcp_node_to_block( $node );
		}
	}

	return $blocks;
}

function gmcp_node_to_block( $node ) {
	if ( $node->nodeType === XML_TEXT_NODE ) {
		$text = trim( $node->textContent );
		return $text ? "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n" : '';
	}

	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return '';
	}

	$tag       = strtolower( $node->nodeName );
	$html      = $node->ownerDocument->saveHTML( $node );
	$attrs     = [];
	$attrs_json = '';

	if ( $node->hasAttribute( 'class' ) ) {
		$classes          = $node->getAttribute( 'class' );
		$attrs['className'] = $classes;
		if ( preg_match( '/has-text-align-(\w+)/', $classes, $m ) ) {
			$attrs['align'] = $m[1];
		}
		if ( preg_match( '/has-(\w+)-color(?!\-)/', $classes, $m ) ) {
			$attrs['textColor'] = $m[1];
		}
		if ( preg_match( '/has-(\w+)-background-color/', $classes, $m ) ) {
			$attrs['backgroundColor'] = $m[1];
		}
	}

	if ( $node->hasAttribute( 'style' ) ) {
		$attrs['style'] = $node->getAttribute( 'style' );
	}

	if ( ! empty( $attrs ) ) {
		$attrs_json = ' ' . wp_json_encode( $attrs );
	}

	switch ( $tag ) {
		case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
			$level = (int) ltrim( $tag, 'h' );
			return "<!-- wp:heading {\"level\":{$level}{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'p':
			return "<!-- wp:paragraph{$attrs_json} -->\n{$html}\n<!-- /wp:paragraph -->\n\n";
		case 'img':
			$src      = $node->getAttribute( 'src' );
			$alt      = $node->getAttribute( 'alt' );
			$img_data = array_merge( $attrs, [ 'url' => $src, 'alt' => $alt ] );
			if ( $node->hasAttribute( 'width' ) )  $img_data['width']  = (int) $node->getAttribute( 'width' );
			if ( $node->hasAttribute( 'height' ) ) $img_data['height'] = (int) $node->getAttribute( 'height' );
			return '<!-- wp:image ' . wp_json_encode( $img_data ) . " -->\n{$html}\n<!-- /wp:image -->\n\n";
		case 'ul':
			return "<!-- wp:list{$attrs_json} -->\n{$html}\n<!-- /wp:list -->\n\n";
		case 'ol':
			return "<!-- wp:list {\"ordered\":true{$attrs_json}} -->\n{$html}\n<!-- /wp:list -->\n\n";
		case 'blockquote':
			return "<!-- wp:quote{$attrs_json} -->\n{$html}\n<!-- /wp:quote -->\n\n";
		case 'pre': case 'code':
			return "<!-- wp:code{$attrs_json} -->\n{$html}\n<!-- /wp:code -->\n\n";
		case 'hr':
			return "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";
		case 'div': case 'section':
			$classes = $node->getAttribute( 'class' );
			if ( strpos( $classes, 'wp-block-columns' ) !== false ) {
				return "<!-- wp:columns{$attrs_json} -->\n{$html}\n<!-- /wp:columns -->\n\n";
			}
			if ( strpos( $classes, 'wp-block-column' ) !== false ) {
				return "<!-- wp:column{$attrs_json} -->\n{$html}\n<!-- /wp:column -->\n\n";
			}
			if ( strpos( $classes, 'wp-block-group' ) !== false ) {
				return "<!-- wp:group{$attrs_json} -->\n{$html}\n<!-- /wp:group -->\n\n";
			}
			return "<!-- wp:group{$attrs_json} -->\n<div class=\"wp-block-group\">{$html}</div>\n<!-- /wp:group -->\n\n";
		default:
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
	}
}

function gmcp_extract_title( $html ) {
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
		$title = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = preg_replace( '/\s*[-–|]\s*.*$/', '', $title );
		return trim( $title );
	}
	return 'Untitled Page';
}
