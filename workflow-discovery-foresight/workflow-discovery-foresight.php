<?php
/**
 * Plugin Name: Workflow Discovery: Foresight News
 * Description: Story discovery provider and research assistant for Foresight News events, diary dates, and scheduled announcements.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com/
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-discovery-foresight
 *
 * @package WorkflowDiscoveryForesight
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryForesight;

use VIPWorkflows\Abilities\Availability;
use VIPWorkflows\Abilities\RequirementFactory;
use VIPWorkflows\Abilities\RequirementGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-foresight-auth.php';
require_once __DIR__ . '/includes/class-foresight-client.php';
require_once __DIR__ . '/includes/class-prompt-formatter.php';
require_once __DIR__ . '/includes/class-seed-composer.php';

/**
 * Get the Foresight API base URL.
 *
 * Override with the FORESIGHT_API_URL constant in wp-config.php or
 * the `vip_foresight_api_url` filter.
 */
function get_foresight_api_url(): string {
	$default = defined( 'FORESIGHT_API_URL' ) ? FORESIGHT_API_URL : 'https://api.foresightnews.com';
	return (string) apply_filters( 'vip_foresight_api_url', $default );
}

/**
 * Shared client singleton.
 */
function get_client(): ForesightClient {
	static $client = null;

	if ( null === $client ) {
		$client = new ForesightClient( new ForesightAuth() );
	}

	return $client;
}

// ── Discovery Provider ───────────────────────────────────────────────

add_action( 'vip_workflows_register_discovery_providers', __NAMESPACE__ . '\register_provider' );

function register_provider( $registry ): void {
	$registry->register(
		'foresight-news',
		array(
			'label'       => __( 'Foresight News', 'workflow-discovery-foresight' ),
			'description' => __( 'Upcoming events, diary dates, and scheduled announcements.', 'workflow-discovery-foresight' ),
			'icon'        => 'calendar-alt',
			'features'    => array( 'recommend', 'search' ),
			'callbacks'   => array(
				'recommend' => __NAMESPACE__ . '\get_recommendations',
				'search'    => __NAMESPACE__ . '\search_events',
				'filters'   => __NAMESPACE__ . '\get_search_filters',
				'seed'      => __NAMESPACE__ . '\generate_seed',
			),
			'availability_callback' => __NAMESPACE__ . '\check_availability',
		) 
	);
}

// ── Research Assistant ────────────────────────────────────────────────

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_ability' );

function register_ability(): void {
	if ( ! function_exists( 'vip_workflows_register_ability' ) ) {
		return;
	}

	vip_workflows_register_ability(
		'workflow-discovery-foresight/foresight-research',
		array(
			'label'               => __( 'Foresight News', 'workflow-discovery-foresight' ),
			'description'         => __( 'Finds upcoming events, diary dates, and announcements relevant to this story.', 'workflow-discovery-foresight' ),
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
			'execute_callback'    => __NAMESPACE__ . '\research_execute',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'type'                  => 'research',
				'display_order'         => 15,
				'show_in_rest'          => true,
				'show_in_commands'      => false,
				'transition_eligible'   => false,
				'icon'                  => 'calendar-alt',
				'thinking_message'      => __( 'Checking upcoming events...', 'workflow-discovery-foresight' ),
				'success_message'       => __( 'Found upcoming events.', 'workflow-discovery-foresight' ),
				'availability_callback' => __NAMESPACE__ . '\check_availability',
			),
		)
	);
}

// ── Unified Assistants Tab ───────────────────────────────────────────

add_action( 'vip_workflows_register_assistant_meta', __NAMESPACE__ . '\register_assistant_meta' );

function register_assistant_meta( $registry ): void {
	$registry->register(
		'foresight-news',
		array(
			'label'           => __( 'Foresight News', 'workflow-discovery-foresight' ),
			'description'     => __( 'Upcoming events, diary dates, and scheduled announcements from Foresight News.', 'workflow-discovery-foresight' ),
			'icon'            => 'calendar-alt',
			'ability_ids'     => array( 'workflow-discovery-foresight/foresight-research' ),
			'provider_slugs'  => array( 'foresight-news' ),
			'settings_schema' => array(
				'email'    => array(
					'type'     => 'string',
					'label'    => __( 'Email', 'workflow-discovery-foresight' ),
					'required' => true,
				),
				'password' => array(
					'type'     => 'string',
					'label'    => __( 'Password', 'workflow-discovery-foresight' ),
					'required' => true,
					'secret'   => true,
				),
				'default_region' => array(
					'type'        => 'string',
					'label'       => __( 'Default Region', 'workflow-discovery-foresight' ),
					'description' => __( 'Region focus for landing page recommendations.', 'workflow-discovery-foresight' ),
					'enum'        => array( 'all', 'uk', 'us' ),
					'default'     => 'all',
				),
				'time_horizon' => array(
					'type'        => 'string',
					'label'       => __( 'Time Horizon (days)', 'workflow-discovery-foresight' ),
					'description' => __( 'How far ahead to look for events.', 'workflow-discovery-foresight' ),
					'enum'        => array( '30', '60', '90', '180' ),
					'default'     => '90',
				),
				'default_importance' => array(
					'type'        => 'string',
					'label'       => __( 'Default Importance', 'workflow-discovery-foresight' ),
					'description' => __( 'Filter recommendations by importance.', 'workflow-discovery-foresight' ),
					'enum'        => array( 'all', 'top_story' ),
					'default'     => 'all',
				),
				'cache_minutes' => array(
					'type'        => 'string',
					'label'       => __( 'Cache Duration (minutes)', 'workflow-discovery-foresight' ),
					'description' => __( 'How long to cache recommendations and search results.', 'workflow-discovery-foresight' ),
					'enum'        => array( '5', '15', '30', '60', '120' ),
					'default'     => '30',
				),
			),
		) 
	);
}

// ── Availability ─────────────────────────────────────────────────────

/**
 * Whether the Foresight credentials are stored.
 *
 * @return bool
 */
function is_configured(): bool {
	$config = get_option( ForesightAuth::OPTION_KEY, array() );
	return ! empty( $config['email'] ) && ! empty( $config['password'] );
}

/**
 * Report availability for both the discovery provider and the ability.
 *
 * Registered as the `availability_callback` from both surfaces on purpose: the
 * requirement is the same one, so it carries the same id from both and the
 * Agents card renders a single row naming both capabilities.
 *
 * The destination is `in_card` because these credentials are entered in this
 * agent's own `settings_schema` fields, which the card renders directly beneath
 * the notice. Any link — to Connectors or anywhere else — would send the user
 * away from the only place the values can be set.
 *
 * It does carry a credentials URL, which answers the other half of the question.
 * Foresight News signs in with an email and password, which core's connector API
 * models no method for, so this plugin cannot register a connector and pick up the
 * `credentials_url` an API-key service gets for free — leaving an administrator
 * told their sign-in details are missing with no way to go and get them. The URL
 * is the publication's own site, the same host the plugin already reports as this
 * provider's `domain`; guessing at a `/signup` or `/pricing` path would be exactly
 * the dead link the destination contract exists to prevent.
 *
 * @return bool|Availability True when configured, otherwise the unmet requirement.
 */
function check_availability(): bool|Availability {
	if ( is_configured() ) {
		return true;
	}

	return Availability::unmet(
		RequirementGroup::all(
			RequirementFactory::in_card(
				'settings:foresight-news',
				__( 'Foresight News sign-in details are missing. Add the email and password below.', 'workflow-discovery-foresight' ),
				__( 'Foresight News is not connected. Ask an administrator to add its sign-in details.', 'workflow-discovery-foresight' ),
				__( 'Complete the email and password fields below.', 'workflow-discovery-foresight' ),
				array( __( 'Foresight News', 'workflow-discovery-foresight' ) ),
				'https://foresightnews.com'
			)
		)
	);
}

// ── Recommend ────────────────────────────────────────────────────────

function get_recommendations( array $config ): array {
	$region     = $config['default_region'] ?? 'all';
	$horizon    = (int) ( $config['time_horizon'] ?? 90 );
	$importance = $config['default_importance'] ?? 'all';

	$params = array(
		'DateFrom' => gmdate( 'Ymd' ),
		'DateTo'   => gmdate( 'Ymd', strtotime( '+' . $horizon . ' days' ) ),
	);

	if ( 'top_story' === $importance ) {
		if ( 'uk' === $region ) {
			$params['IsTopStoryUK'] = true;
		} elseif ( 'us' === $region ) {
			$params['IsTopStoryUSA'] = true;
		}
	}

	$response = get_client()->filter_events( $params );
	$events   = $response['events'] ?? array();
	$maps     = get_taxonomy_maps();

	return array_map(
		fn( array $event ): array => PromptFormatter::format( $event, $maps['categories'], $maps['event_types'] ),
		array_slice( $events, 0, 18 )
	);
}

// ── Search ───────────────────────────────────────────────────────────

function search_events( array $params ): array {
	$text    = $params['text'] ?? '';
	$filters = $params['filters'] ?? array();

	$has_structured = ! empty( $filters['categories'] )
		|| ! empty( $filters['regions'] )
		|| ! empty( $filters['event_types'] )
		|| ! empty( $filters['date_range'] )
		|| ! empty( $filters['importance'] );

	if ( '' !== $text && ! $has_structured ) {
		$response = get_client()->freetext_search( $text );
	} else {
		$api_params = build_filter_params( $filters );
		if ( '' !== $text ) {
			$api_params['Text'] = $text;
		}
		$response = get_client()->filter_events( $api_params );
	}

	$events = $response['events'] ?? array();
	$maps   = get_taxonomy_maps();

	return array_map(
		fn( array $event ): array => PromptFormatter::format( $event, $maps['categories'], $maps['event_types'] ),
		$events
	);
}

// ── Filters ──────────────────────────────────────────────────────────

function get_search_filters(): array {
	$client = get_client();

	$categories  = $client->get_categories();
	$regions     = $client->get_regions();
	$event_types = $client->get_event_types();

	return array(
		array(
			'key'     => 'date_range',
			'label'   => __( 'Date range', 'workflow-discovery-foresight' ),
			'type'    => 'date_range',
			'default' => array(
				'from' => gmdate( 'Y-m-d' ),
				'to'   => gmdate( 'Y-m-d', strtotime( '+90 days' ) ),
			),
		),
		array(
			'key'     => 'categories',
			'label'   => __( 'Topics', 'workflow-discovery-foresight' ),
			'type'    => 'multi_select',
			'options' => build_select_options( $categories ),
		),
		array(
			'key'     => 'regions',
			'label'   => __( 'Regions', 'workflow-discovery-foresight' ),
			'type'    => 'multi_select',
			'options' => build_select_options( $regions ),
		),
		array(
			'key'     => 'event_types',
			'label'   => __( 'Event type', 'workflow-discovery-foresight' ),
			'type'    => 'multi_select',
			'options' => build_select_options( $event_types ),
		),
		array(
			'key'     => 'importance',
			'label'   => __( 'Importance', 'workflow-discovery-foresight' ),
			'type'    => 'select',
			'options' => array(
				array(
					'value' => 'all',
					'label' => __( 'All events', 'workflow-discovery-foresight' ),
				),
				array(
					'value' => 'top_story_uk',
					'label' => __( 'Top stories (UK)', 'workflow-discovery-foresight' ),
				),
				array(
					'value' => 'top_story_us',
					'label' => __( 'Top stories (US)', 'workflow-discovery-foresight' ),
				),
			),
		),
	);
}

// ── Seed ─────────────────────────────────────────────────────────────

function generate_seed( array $prompt ): string {
	return SeedComposer::compose( $prompt );
}

// ── Research Execute ─────────────────────────────────────────────────

/**
 * Execute a Foresight research search inside an ideation project.
 *
 * Uses search_queries from seed analysis when available, falls back to
 * the raw seed. Searches Foresight for upcoming events and returns cards
 * for the mood board.
 *
 * @param array $input { seed, seed_analysis, query?, brand_context? }
 * @return array { cards: array, summary: string }
 */
function research_execute( array $input ): array {
	$seed_analysis = $input['seed_analysis'] ?? array();

	if ( ! empty( $input['query'] ) ) {
		$queries = array( $input['query'] );
	} else {
		$queries = build_research_queries( $seed_analysis, $input['seed'] ?? '' );
	}

	$queries = array_filter( $queries );
	if ( empty( $queries ) ) {
		return array(
			'cards' => array(),
			'summary' => 'No search query provided.',
		);
	}

	$config  = get_option( ForesightAuth::OPTION_KEY, array() );
	$horizon = (int) ( $config['time_horizon'] ?? 90 );
	$client  = get_client();
	$maps    = get_taxonomy_maps();

	$all_events = array();
	$seen_ids   = array();

	foreach ( array_slice( $queries, 0, 3 ) as $query ) {
		try {
			$response = $client->filter_events(
				array(
					'Text'     => $query,
					'DateFrom' => gmdate( 'Ymd' ),
					'DateTo'   => gmdate( 'Ymd', strtotime( '+' . $horizon . ' days' ) ),
				) 
			);
			foreach ( $response['events'] ?? array() as $event ) {
				$id = $event['id'] ?? 0;
				if ( isset( $seen_ids[ $id ] ) ) {
					continue;
				}
				$seen_ids[ $id ] = true;
				$all_events[]    = $event;
			}
		} catch ( \Throwable $e ) {
			continue;
		}
	}

	$all_events = array_slice( $all_events, 0, 10 );
	$cards      = array();

	foreach ( $all_events as $event ) {
		$cards[] = event_to_research_card( $event, $maps );
	}

	return array(
		'cards'   => $cards,
		'summary' => sprintf(
			'Found %d upcoming events on Foresight News.',
			count( $cards )
		),
	);
}

/**
 * Map a Foresight event to a research card for the mood board.
 *
 * @param array $event Raw Foresight event.
 * @param array $maps  Taxonomy maps from get_taxonomy_maps().
 * @return array Research card.
 */
function event_to_research_card( array $event, array $maps ): array {
	$headline   = $event['headline'] ?? '';
	$content    = wp_strip_all_tags( $event['content'] ?? '' );
	$first_link = '';

	if ( ! empty( $event['links'][0]['url'] ) ) {
		$first_link = $event['links'][0]['url'];
	}

	$cat_names = array();
	foreach ( $event['categoryIds'] ?? array() as $id ) {
		if ( isset( $maps['categories'][ $id ] ) ) {
			$cat_names[] = $maps['categories'][ $id ];
		}
	}

	$et_names = array();
	foreach ( $event['eventTypeIds'] ?? array() as $id ) {
		if ( isset( $maps['event_types'][ $id ] ) ) {
			$et_names[] = $maps['event_types'][ $id ];
		}
	}

	$excerpt_parts = array();
	if ( ! empty( $event['startDateInUtc'] ) ) {
		$excerpt_parts[] = date( 'M j, Y', strtotime( $event['startDateInUtc'] ) );
	}
	if ( ! empty( $cat_names ) ) {
		$excerpt_parts[] = implode( ', ', array_slice( $cat_names, 0, 3 ) );
	}
	if ( ! empty( $et_names ) ) {
		$excerpt_parts[] = implode( ', ', $et_names );
	}

	return array(
		'type'        => 'article',
		'source_type' => 'article',
		'origin'      => 'foresight-news',
		'title'       => $headline,
		'url'         => $first_link,
		'excerpt'     => implode( ' · ', $excerpt_parts ),
		'content'     => mb_strlen( $content ) > 3000 ? mb_substr( $content, 0, 3000 ) : $content,
		'domain'      => 'foresightnews.com',
		'date'        => $event['startDateInUtc'] ?? null,
		'source'      => 'foresight-news',
	);
}

// ── Research Query Builder ───────────────────────────────────────────

/**
 * Build effective Foresight search queries from seed analysis.
 *
 * Foresight's filter Text param works best with short entity names and
 * topic keywords, not full web-search-style phrases. This extracts
 * entity names and topics first, falling back to search_queries and
 * the raw seed only if nothing better is available.
 *
 * @param array  $seed_analysis Analysis from the ideation system.
 * @param string $seed          Raw seed text.
 * @return string[] Up to 3 queries.
 */
function build_research_queries( array $seed_analysis, string $seed ): array {
	$queries = array();

	$entities = $seed_analysis['entities'] ?? array();
	foreach ( $entities as $key => $value ) {
		if ( is_array( $value ) && ! isset( $value['name'] ) ) {
			foreach ( $value as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$queries[] = $name;
				}
			}
		} elseif ( is_array( $value ) && ! empty( $value['name'] ) ) {
			$queries[] = (string) $value['name'];
		} elseif ( is_string( $value ) && '' !== $value ) {
			$queries[] = $value;
		}
	}

	$topics = $seed_analysis['topics'] ?? array();
	foreach ( $topics as $topic ) {
		if ( is_string( $topic ) && '' !== $topic ) {
			$queries[] = $topic;
		} elseif ( is_array( $topic ) && ! empty( $topic['name'] ) ) {
			$queries[] = (string) $topic['name'];
		}
	}

	if ( empty( $queries ) ) {
		$search_queries = $seed_analysis['search_queries'] ?? array();
		foreach ( $search_queries as $sq ) {
			$queries[] = $sq;
		}
	}

	if ( empty( $queries ) && '' !== $seed ) {
		$words = str_word_count( $seed, 1 );
		$queries[] = implode( ' ', array_slice( $words, 0, 5 ) );
	}

	return array_unique( array_slice( array_filter( $queries ), 0, 3 ) );
}

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Build API filter params from the search modal's filter selections.
 *
 * @param array $filters User-selected filters keyed by filter key.
 * @return array Params for ForesightClient::filter_events().
 */
function build_filter_params( array $filters ): array {
	$params = array();

	if ( ! empty( $filters['date_range'] ) ) {
		$range = $filters['date_range'];
		if ( ! empty( $range['from'] ) ) {
			$from = 'today' === $range['from'] ? time() : strtotime( $range['from'] );
			$params['DateFrom'] = gmdate( 'Ymd', $from );
		}
		if ( ! empty( $range['to'] ) ) {
			$to = strtotime( $range['to'] );
			$params['DateTo'] = gmdate( 'Ymd', $to );
		}
	}

	if ( ! empty( $params ) || empty( $filters['date_range'] ) ) {
		if ( ! isset( $params['DateFrom'] ) ) {
			$params['DateFrom'] = gmdate( 'Ymd' );
		}
		if ( ! isset( $params['DateTo'] ) ) {
			$config  = get_option( ForesightAuth::OPTION_KEY, array() );
			$horizon = (int) ( $config['time_horizon'] ?? 90 );
			$params['DateTo'] = gmdate( 'Ymd', strtotime( '+' . $horizon . ' days' ) );
		}
	}

	if ( ! empty( $filters['categories'] ) ) {
		$params['CategoryIds'] = array_map( 'intval', (array) $filters['categories'] );
	}

	if ( ! empty( $filters['regions'] ) ) {
		$params['RegionIds'] = array_map( 'intval', (array) $filters['regions'] );
	}

	if ( ! empty( $filters['event_types'] ) ) {
		$params['EventTypeIds'] = array_map( 'intval', (array) $filters['event_types'] );
	}

	if ( ! empty( $filters['importance'] ) && 'all' !== $filters['importance'] ) {
		if ( 'top_story_uk' === $filters['importance'] ) {
			$params['IsTopStoryUK'] = true;
		} elseif ( 'top_story_us' === $filters['importance'] ) {
			$params['IsTopStoryUSA'] = true;
		}
	}

	return $params;
}

/**
 * Build select options from a Foresight filter list.
 *
 * @param array $items Items with 'id' and 'name' keys.
 * @return array[] Options for the search modal UI.
 */
function build_select_options( array $items ): array {
	$options = array();

	foreach ( $items as $item ) {
		if ( ! empty( $item['name'] ) ) {
			$options[] = array(
				'value' => (string) ( $item['id'] ?? '' ),
				'label' => $item['name'],
			);
		}
	}

	return $options;
}

/**
 * Get cached ID => name maps for categories and event types.
 *
 * @return array{ categories: array<int, string>, event_types: array<int, string> }
 */
function get_taxonomy_maps(): array {
	static $maps = null;

	if ( null !== $maps ) {
		return $maps;
	}

	$client    = get_client();
	$cat_map   = array();
	$et_map    = array();

	try {
		foreach ( $client->get_categories() as $cat ) {
			if ( isset( $cat['id'], $cat['name'] ) ) {
				$cat_map[ $cat['id'] ] = $cat['name'];
			}
		}

		foreach ( $client->get_event_types() as $et ) {
			if ( isset( $et['id'], $et['name'] ) ) {
				$et_map[ $et['id'] ] = $et['name'];
			}
		}
	} catch ( \Throwable $e ) {
		// Taxonomy resolution is best-effort; prompts still work without names.
	}

	$maps = array(
		'categories'  => $cat_map,
		'event_types' => $et_map,
	);

	return $maps;
}
