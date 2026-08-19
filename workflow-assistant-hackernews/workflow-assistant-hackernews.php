<?php
/**
 * Plugin Name: Workflow Assistant: Hacker News
 * Description: Research assistant that searches Hacker News for tech industry discussions and articles during ideation.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-assistant-hackernews
 *
 * @package WorkflowAssistantHackerNews
 */

declare( strict_types=1 );

namespace WorkflowAssistantHackerNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );

/**
 * Register the Hacker News assistant ability.
 */
function register(): void {
	if ( ! function_exists( 'vip_workflow_register_ability' ) ) {
		return;
	}

	vip_workflow_register_ability(
		'workflow-assistant-hackernews/hackernews',
		array(
			'label'               => __( 'Hacker News', 'workflow-assistant-hackernews' ),
			'description'         => __( 'Searches Hacker News for tech discussions, articles, and community commentary.', 'workflow-assistant-hackernews' ),
			'category'            => 'research',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seed'          => array( 'type' => 'string' ),
					'seed_analysis' => array( 'type' => 'object' ),
					'project_id'    => array( 'type' => 'integer' ),
					'query'         => array( 'type' => 'string' ),
					'brand_context' => array( 'type' => 'array' ),
				),
				'required'   => array( 'seed' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'cards'   => array( 'type' => 'array' ),
					'summary' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\execute',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'type'                => 'research',
				'display_order'       => 50,
				'show_in_rest'        => true,
				'show_in_commands'    => false,
				'transition_eligible' => false,
				'icon'                => 'comment',
				'thinking_message'    => __( 'Searching Hacker News...', 'workflow-assistant-hackernews' ),
				'success_message'     => __( 'Hacker News search complete.', 'workflow-assistant-hackernews' ),
			),
		)
	);
}

/**
 * Execute Hacker News search.
 *
 * Produces two cards per story: an article card (with fetched content)
 * and a discussion card (with top HN comments).
 *
 * @param array $input Input parameters.
 * @return array { cards: array, summary: string }
 */
function execute( array $input ): array {
	$seed_analysis = $input['seed_analysis'] ?? array();

	if ( ! empty( $input['query'] ) ) {
		$queries = array( $input['query'] );
	} else {
		$queries = $seed_analysis['search_queries'] ?? array();
		if ( empty( $queries ) ) {
			$queries = array( $input['seed'] ?? '' );
		}
	}

	$queries = array_filter( $queries );
	if ( empty( $queries ) ) {
		return array(
			'cards' => array(),
			'summary' => 'No search query provided.',
		);
	}

	$all_hits = array();
	foreach ( array_slice( $queries, 0, 3 ) as $query ) {
		$all_hits = array_merge( $all_hits, search_stories( $query ) );
		$all_hits = array_merge( $all_hits, search_popular( $query ) );
	}
	$all_hits = deduplicate_hits( $all_hits );
	$all_hits = array_slice( $all_hits, 0, 8 );

	$cards = array();
	foreach ( $all_hits as $hit ) {
		$pair = hit_to_cards( $hit );
		foreach ( $pair as $card ) {
			$cards[] = $card;
		}
	}

	$article_count    = count( array_filter( $cards, fn( $c ) => 'article' === $c['source_type'] ) );
	$discussion_count = count( array_filter( $cards, fn( $c ) => 'discussion' === $c['source_type'] ) );

	$parts = array();
	if ( $article_count > 0 ) {
		$parts[] = sprintf( '%d articles', $article_count );
	}
	if ( $discussion_count > 0 ) {
		$parts[] = sprintf( '%d discussions', $discussion_count );
	}

	return array(
		'cards'   => $cards,
		'summary' => sprintf( 'Found %s on Hacker News.', empty( $parts ) ? '0 results' : implode( ' and ', $parts ) ),
	);
}

/**
 * Search HN for recent stories.
 *
 * @param string $query Search query.
 * @return array Raw Algolia hits.
 */
function search_stories( string $query ): array {
	$url = add_query_arg(
		array(
			'query'            => $query,
			'tags'             => 'story',
			'hitsPerPage'      => 6,
			'attributesToRetrieve' => 'title,url,author,created_at,points,num_comments,objectID',
		),
		'https://hn.algolia.com/api/v1/search'
	);

	// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- editor-initiated ideation assistant request expected to take time.
	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return $body['hits'] ?? array();
}

/**
 * Search HN for popular stories (50+ points).
 *
 * @param string $query Search query.
 * @return array Raw Algolia hits.
 */
function search_popular( string $query ): array {
	$url = add_query_arg(
		array(
			'query'            => $query,
			'tags'             => 'story',
			'hitsPerPage'      => 4,
			'numericFilters'   => 'points>50',
			'attributesToRetrieve' => 'title,url,author,created_at,points,num_comments,objectID',
		),
		'https://hn.algolia.com/api/v1/search'
	);

	// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- editor-initiated ideation assistant request expected to take time.
	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return $body['hits'] ?? array();
}

/**
 * Convert an Algolia hit into one or two cards.
 *
 * Stories with an external URL produce an article card + a discussion card.
 * Ask HN / Show HN posts (no external URL) produce only a discussion card.
 *
 * @param array $hit Algolia search hit.
 * @return array Array of cards.
 */
function hit_to_cards( array $hit ): array {
	$title        = $hit['title'] ?? '';
	if ( empty( $title ) ) {
		return array();
	}

	$external_url = $hit['url'] ?? '';
	$object_id    = $hit['objectID'] ?? '';
	$hn_url       = 'https://news.ycombinator.com/item?id=' . $object_id;
	$has_external = ! empty( $external_url );
	$points       = (int) ( $hit['points'] ?? 0 );
	$num_comments = (int) ( $hit['num_comments'] ?? 0 );
	$date         = $hit['created_at'] ?? null;
	$author       = $hit['author'] ?? null;

	$domain = '';
	if ( $has_external ) {
		$domain = wp_parse_url( $external_url, PHP_URL_HOST );
		$domain = preg_replace( '/^www\./', '', $domain ?? '' );
	}

	$group_id = 'hn-' . $object_id;
	$cards    = array();

	if ( $has_external ) {
		$article_content = fetch_article_content( $external_url );

		$cards[] = array(
			'type'        => 'article',
			'source_type' => 'article',
			'origin'      => 'hackernews',
			'group_id'    => $group_id,
			'title'       => $title,
			'url'         => $external_url,
			'excerpt'     => $article_content['excerpt'] ?? '',
			'content'     => $article_content['content'] ?? '',
			'domain'      => $domain,
			'date'        => $date,
			'author'      => $article_content['author'] ?? $author,
			'score'       => $points,
			'source'      => 'hackernews',
		);
	}

	if ( $num_comments > 0 ) {
		$comments_data = fetch_top_comments( $object_id );
		$comment_text  = $comments_data['text'] ?? '';

		$meta_parts = array();
		if ( $points > 0 ) {
			$meta_parts[] = sprintf( '%d points', $points );
		}
		$meta_parts[] = sprintf( '%d comments', $num_comments );

		$cards[] = array(
			'type'        => 'discussion',
			'source_type' => 'discussion',
			'origin'      => 'hackernews',
			'group_id'    => $group_id,
			'title'       => $has_external ? $title . ' (Discussion)' : $title,
			'url'         => $hn_url,
			'excerpt'     => implode( ' · ', $meta_parts ),
			'content'     => $comment_text,
			'domain'      => 'news.ycombinator.com',
			'date'        => $date,
			'author'      => $author,
			'score'       => $points,
			'source'      => 'hackernews',
		);
	}

	return $cards;
}

/**
 * Fetch and extract readable content from a URL.
 *
 * @param string $url Article URL.
 * @return array { content: string, excerpt: string, author: string|null }
 */
function fetch_article_content( string $url ): array {
	$response = wp_remote_get(
		$url,
		array(
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- editor-initiated ideation assistant request expected to take time.
			'timeout'    => 8,
			'user-agent' => 'Mozilla/5.0 (compatible; VIPWorkflow/1.0)',
		) 
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return array(
			'content' => '',
			'excerpt' => '',
			'author' => null,
		);
	}

	$html = wp_remote_retrieve_body( $response );
	if ( empty( $html ) ) {
		return array(
			'content' => '',
			'excerpt' => '',
			'author' => null,
		);
	}

	$author = null;
	if ( preg_match( '/<meta[^>]+name=["\']author["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
		$author = trim( $m[1] );
	}

	$excerpt = '';
	if ( preg_match( '/<meta[^>]+(?:name=["\']description["\']|property=["\']og:description["\'])[^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
		$excerpt = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
	}

	$content = extract_body_text( $html );
	if ( empty( $excerpt ) && ! empty( $content ) ) {
		$excerpt = mb_substr( $content, 0, 300 ) . '...';
	}

	return array(
		'content' => $content,
		'excerpt' => $excerpt,
		'author'  => $author,
	);
}

/**
 * Extract readable body text from HTML.
 *
 * Strips scripts, styles, nav, footer, and HTML tags. Returns plain text
 * truncated to a reasonable length for AI summarization.
 *
 * @param string $html Raw HTML.
 * @return string Plain text content.
 */
function extract_body_text( string $html ): string {
	$html = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $html );
	$html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $html );
	$html = preg_replace( '/<(nav|footer|header|aside)[^>]*>.*?<\/\1>/si', '', $html );

	$text = wp_strip_all_tags( $html );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = trim( $text );

	if ( mb_strlen( $text ) > 5000 ) {
		$text = mb_substr( $text, 0, 5000 );
	}

	return $text;
}

/**
 * Fetch top comments for a HN story.
 *
 * Uses the Algolia items endpoint which returns the full comment tree.
 * Extracts the top-level comments sorted by points/order.
 *
 * @param string $object_id HN story object ID.
 * @return array { text: string } Combined comment text.
 */
function fetch_top_comments( string $object_id ): array {
	if ( empty( $object_id ) ) {
		return array( 'text' => '' );
	}

	$url      = 'https://hn.algolia.com/api/v1/items/' . $object_id;
	// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- editor-initiated ideation assistant request expected to take time.
	$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return array( 'text' => '' );
	}

	$data     = json_decode( wp_remote_retrieve_body( $response ), true );
	$children = $data['children'] ?? array();

	$comments = array();
	foreach ( array_slice( $children, 0, 30 ) as $child ) {
		$text = $child['text'] ?? '';
		if ( empty( $text ) ) {
			continue;
		}

		$clean = wp_strip_all_tags( $text );
		$clean = html_entity_decode( $clean, ENT_QUOTES, 'UTF-8' );
		$clean = preg_replace( '/\s+/', ' ', $clean );
		$clean = trim( $clean );

		if ( mb_strlen( $clean ) < 20 ) {
			continue;
		}

		$author = $child['author'] ?? 'anonymous';
		$comments[] = sprintf( '[%s]: %s', $author, $clean );
	}

	$combined = implode( "\n\n", array_slice( $comments, 0, 25 ) );

	if ( mb_strlen( $combined ) > 12000 ) {
		$combined = mb_substr( $combined, 0, 12000 );
	}

	return array( 'text' => $combined );
}

/**
 * Remove duplicate hits by objectID.
 *
 * @param array $hits Raw Algolia hits.
 * @return array Deduplicated hits.
 */
function deduplicate_hits( array $hits ): array {
	$seen   = array();
	$unique = array();

	foreach ( $hits as $hit ) {
		$key = $hit['objectID'] ?? '';
		if ( empty( $key ) || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$unique[]     = $hit;
	}

	return $unique;
}
