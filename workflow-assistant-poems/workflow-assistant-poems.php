<?php
/**
 * Plugin Name: Workflow Assistant: Poems
 * Description: Research assistant that generates short poems inspired by the search terms during ideation.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-assistant-poems
 *
 * @package WorkflowAssistantPoems
 */

declare( strict_types=1 );

namespace WorkflowAssistantPoems;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );

/**
 * Register the poems assistant ability.
 */
function register(): void {
	if ( ! function_exists( 'vip_workflows_register_ability' ) ) {
		return;
	}

	vip_workflows_register_ability(
		'workflow-assistant-poems/poems',
		array(
			'label'               => __( 'Poems', 'workflow-assistant-poems' ),
			'description'         => __( 'Generates five short poems inspired by the search terms for creative inspiration.', 'workflow-assistant-poems' ),
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
				'icon'                => 'pencil',
				'thinking_message'    => __( 'Writing poems...', 'workflow-assistant-poems' ),
				'success_message'     => __( 'Poems written.', 'workflow-assistant-poems' ),
			),
		)
	);
}

/**
 * Generate five short poems using the AI client.
 *
 * @param  array $input Ability input: seed, optional seed_analysis, query and brand_context.
 * @return array Ability result: cards and summary.
 */
function execute( array $input ): array {
	$query = $input['query'] ?? $input['seed'] ?? '';
	if ( empty( $query ) ) {
		return array(
			'cards' => array(),
			'summary' => 'No search query provided.',
		);
	}

	$keywords = build_keywords( $query, $input['seed_analysis'] ?? array() );
	$prompt   = build_prompt( $keywords );
	$poems    = generate_poems( $prompt );

	$cards = array();
	foreach ( $poems as $index => $poem ) {
		/*
		 * The excerpt is a genuine preview, not the first line. A card renders the
		 * body when it is short enough and preserves its line breaks, so sending a
		 * single line here threw away the poem — and this plugin is the reference
		 * an extension author copies, so the shape it models matters more than the
		 * poems do.
		 */
		$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $poem['body'] ) ) ) );
		$excerpt = implode( "\n", array_slice( $lines, 0, 2 ) );

		$cards[] = array(
			'type'        => 'article',
			'source_type' => 'article',
			'origin'      => 'poems',
			'title'       => $poem['title'],
			'url'         => '',
			'excerpt'     => $excerpt,
			'content'     => $poem['body'],
			'domain'      => 'ai-generated',
			'author'      => 'Poem Generator',
			'source'      => 'poems',
		);
	}

	return array(
		'cards'   => $cards,
		'summary' => sprintf( 'Wrote %d poems inspired by "%s".', count( $cards ), $query ),
	);
}

/**
 * Extract keywords from the seed and analysis for poem generation.
 *
 * @param string $seed     Raw seed text.
 * @param array  $analysis Seed analysis with entities, topics, etc.
 * @return string[]
 */
function build_keywords( string $seed, array $analysis ): array {
	$keywords = array( $seed );

	$entities = $analysis['entities'] ?? array();
	foreach ( $entities as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}
		foreach ( $group as $name ) {
			if ( ! empty( $name ) && ! in_array( $name, $keywords, true ) ) {
				$keywords[] = $name;
			}
		}
	}

	$tags = $analysis['tags'] ?? array();
	foreach ( array_slice( $tags, 0, 3 ) as $tag ) {
		if ( ! empty( $tag ) && ! in_array( $tag, $keywords, true ) ) {
			$keywords[] = $tag;
		}
	}

	return $keywords;
}

/**
 * Build the AI prompt for poem generation.
 *
 * @param string[] $keywords Keywords to inspire the poems.
 * @return string
 */
function build_prompt( array $keywords ): string {
	$keyword_list = implode( ', ', $keywords );

	return <<<PROMPT
Write exactly 5 short poems (4-6 lines each) inspired by these themes: {$keyword_list}

Each poem should have a distinct mood or angle. Return valid JSON only, no markdown, no explanation.

Format:
[
  { "title": "Poem Title", "body": "Line one\\nLine two\\nLine three\\nLine four" },
  ...
]
PROMPT;
}

/**
 * Call the AI client to generate poems.
 *
 * @param string $prompt The generation prompt.
 * @return array Array of { title: string, body: string }.
 */
function generate_poems( string $prompt ): array {
	if ( ! class_exists( '\WordPress\AI\Client' ) ) {
		return fallback_poems();
	}

	try {
		$client   = new \WordPress\AI\Client();
		$response = $client->create_text(
			array(
				'prompt'      => $prompt,
				'max_tokens'  => 1024,
				'temperature' => 0.9,
			)
		);

		$text = $response->get_text();
		$data = json_decode( $text, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return fallback_poems();
		}

		$poems = array();
		foreach ( array_slice( $data, 0, 5 ) as $item ) {
			if ( ! empty( $item['title'] ) && ! empty( $item['body'] ) ) {
				$poems[] = array(
					'title' => sanitize_text_field( $item['title'] ),
					'body'  => sanitize_textarea_field( $item['body'] ),
				);
			}
		}

		return ! empty( $poems ) ? $poems : fallback_poems();
	} catch ( \Throwable $e ) {
		return fallback_poems();
	}
}

/**
 * Static fallback poems when the AI client is unavailable.
 *
 * @return array
 */
function fallback_poems(): array {
	return array(
		array(
			'title' => 'Dawn',
			'body'  => "Light cracks the horizon,\nsilence stirs to sound.\nThe world remakes itself\nin gold upon the ground.",
		),
		array(
			'title' => 'Current',
			'body'  => "Words move like water,\nfinding every crack.\nThey shape the stone beneath\nand never travel back.",
		),
		array(
			'title' => 'Signal',
			'body'  => "A thought sent outward\nthrough the tangled wire,\narrives as something new,\nrecast in someone's fire.",
		),
		array(
			'title' => 'Margin',
			'body'  => "Between the lines we wrote\nand those we meant to say,\na quiet truth persists,\nunchanged by either way.",
		),
		array(
			'title' => 'Seed',
			'body'  => "One idea, planted small,\nsplits open in the dark.\nWhat rises needs no plan,\njust soil and a spark.",
		),
	);
}
