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

	// POST /analyze-reference
	register_rest_route( $ns, '/analyze-reference', [
		'methods'             => 'POST',
		'callback'            => 'gmcp_analyze_reference',
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
		
		// Handle innerContent as array or string
		if ( is_array( $inner ) ) {
			$inner = implode( '', $inner );
		}
		
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

function gmcp_analyze_reference( WP_REST_Request $req ) {
	$body = $req->get_json_params();
	$url  = $body['url'] ?? '';

	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'URL is required', [ 'status' => 400 ] );
	}

	// Fetch the page content
	$response = wp_remote_get( $url, [
		'timeout' => 30,
		'headers' => [
			'User-Agent' => 'WordPress/Gutenberg-MCP-Analyzer',
		],
	] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'fetch_failed', $response->get_error_message(), [ 'status' => 500 ] );
	}

	$html = wp_remote_retrieve_body( $response );
	
	// Try to extract the post content from the HTML
	// Look for common WordPress content containers
	$content = gmcp_extract_wp_content( $html, $url );

	if ( empty( $content ) ) {
		return new WP_Error( 'no_content', 'Could not extract content from URL', [ 'status' => 400 ] );
	}

	// Parse the blocks from the content
	$blocks = parse_blocks( $content );
	$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

	// Extract additional metadata
	$title = gmcp_extract_title( $html );
	
	return rest_ensure_response( [
		'url'         => $url,
		'title'       => $title,
		'blocks'      => $blocks,
		'block_count' => count( $blocks ),
		'raw_content' => $content,
	] );
}

function gmcp_extract_wp_content( $html, $url ) {
	// Parse the HTML
	$dom = new DOMDocument();
	@$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$xpath = new DOMXPath( $dom );

	// Try multiple selectors to find the main content
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

	// Get the inner HTML
	$content_html = gmcp_get_inner_html( $content_node );

	// Convert HTML back to Gutenberg block comments
	$content = gmcp_html_to_blocks( $content_html );

	return $content;
}

function gmcp_get_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

function gmcp_html_to_blocks( $html ) {
	// If the HTML already contains Gutenberg block comments, return as-is
	if ( strpos( $html, '<!-- wp:' ) !== false ) {
		return $html;
	}

	// Otherwise, try to intelligently convert HTML to blocks
	$dom = new DOMDocument();
	@$dom->loadHTML( mb_convert_encoding( '<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	
	$blocks = '';
	$body = $dom->getElementsByTagName( 'div' )->item( 0 );
	
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
		if ( empty( $text ) ) {
			return '';
		}
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";
	}

	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return '';
	}

	$tag = strtolower( $node->nodeName );
	$html = $node->ownerDocument->saveHTML( $node );
	$attrs = [];

	// Extract common attributes
	if ( $node->hasAttribute( 'class' ) ) {
		$classes = $node->getAttribute( 'class' );
		$attrs['className'] = $classes;
		
		// Extract alignment from classes
		if ( preg_match( '/has-text-align-(\w+)/', $classes, $matches ) ) {
			$attrs['align'] = $matches[1];
		}
		
		// Extract text color
		if ( preg_match( '/has-(\w+)-color/', $classes, $matches ) ) {
			$attrs['textColor'] = $matches[1];
		}
		
		// Extract background color
		if ( preg_match( '/has-(\w+)-background-color/', $classes, $matches ) ) {
			$attrs['backgroundColor'] = $matches[1];
		}
	}

	if ( $node->hasAttribute( 'style' ) ) {
		$attrs['style'] = $node->getAttribute( 'style' );
	}

	$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs ) : '';

	// Map HTML tags to Gutenberg blocks
	switch ( $tag ) {
		case 'h1':
			return "<!-- wp:heading {\"level\":1{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'h2':
			return "<!-- wp:heading {\"level\":2{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'h3':
			return "<!-- wp:heading {\"level\":3{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'h4':
			return "<!-- wp:heading {\"level\":4{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'h5':
			return "<!-- wp:heading {\"level\":5{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'h6':
			return "<!-- wp:heading {\"level\":6{$attrs_json}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		case 'p':
			return "<!-- wp:paragraph{$attrs_json} -->\n{$html}\n<!-- /wp:paragraph -->\n\n";
		case 'img':
			$src = $node->getAttribute( 'src' );
			$alt = $node->getAttribute( 'alt' );
			$img_attrs = [ 'url' => $src, 'alt' => $alt ];
			if ( $node->hasAttribute( 'width' ) ) {
				$img_attrs['width'] = (int) $node->getAttribute( 'width' );
			}
			if ( $node->hasAttribute( 'height' ) ) {
				$img_attrs['height'] = (int) $node->getAttribute( 'height' );
			}
			$img_json = wp_json_encode( array_merge( $attrs, $img_attrs ) );
			return "<!-- wp:image {$img_json} -->\n{$html}\n<!-- /wp:image -->\n\n";
		case 'ul':
			return "<!-- wp:list{$attrs_json} -->\n{$html}\n<!-- /wp:list -->\n\n";
		case 'ol':
			return "<!-- wp:list {\"ordered\":true{$attrs_json}} -->\n{$html}\n<!-- /wp:list -->\n\n";
		case 'blockquote':
			return "<!-- wp:quote{$attrs_json} -->\n{$html}\n<!-- /wp:quote -->\n\n";
		case 'pre':
		case 'code':
			return "<!-- wp:code{$attrs_json} -->\n{$html}\n<!-- /wp:code -->\n\n";
		case 'div':
		case 'section':
			// Check if it's a columns or group block based on classes
			$classes = $node->getAttribute( 'class' );
			if ( strpos( $classes, 'wp-block-columns' ) !== false ) {
				return "<!-- wp:columns{$attrs_json} -->\n{$html}\n<!-- /wp:columns -->\n\n";
			} elseif ( strpos( $classes, 'wp-block-column' ) !== false ) {
				return "<!-- wp:column{$attrs_json} -->\n{$html}\n<!-- /wp:column -->\n\n";
			} elseif ( strpos( $classes, 'wp-block-group' ) !== false ) {
				return "<!-- wp:group{$attrs_json} -->\n{$html}\n<!-- /wp:group -->\n\n";
			}
			// For generic divs, use group block
			return "<!-- wp:group{$attrs_json} -->\n<div class=\"wp-block-group\">{$html}</div>\n<!-- /wp:group -->\n\n";
		default:
			// For unknown tags, wrap in HTML block
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
	}
}

function gmcp_extract_title( $html ) {
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $matches ) ) {
		$title = html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Remove common suffixes
		$title = preg_replace( '/\s*[-–|]\s*.*$/', '', $title );
		return trim( $title );
	}
	return 'Untitled Page';
}
