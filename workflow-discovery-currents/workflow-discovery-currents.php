<?php
/**
 * Plugin Name: Workflow Discovery: Currents
 * Description: Story discovery provider for Currents API news — breaking and recent coverage across categories, regions and languages.
 * Version: 0.1.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com/
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-discovery-currents
 *
 * @package WorkflowDiscoveryCurrents
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCurrents;

use VIPWorkflow\Abilities\Availability;
use VIPWorkflow\Abilities\RequirementFactory;
use VIPWorkflow\Abilities\RequirementGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-currents-client.php';
require_once __DIR__ . '/includes/class-prompt-formatter.php';

const OPTION_KEY = 'vip_discovery_provider_currents';
const SLUG       = 'currents';

/**
 * Connector id, and the three places its key may be stored.
 *
 * The key is held as a Connection rather than in this plugin's own settings, so
 * it sits with every other service credential under Settings → Connectors and is
 * set once for the site. That also means it can arrive by environment variable or
 * wp-config constant, which is how a VIP site supplies a secret it does not want
 * in the database.
 */
/**
 * Feed defaults.
 *
 * Held here as well as in the settings schema because the two do different
 * jobs: the schema's `default` populates the settings *form*, and does nothing
 * for a site whose settings have never been saved. Reading the option directly
 * on such a site yields an empty array, so an unconfigured install silently
 * requested a worldwide feed — the first live run returned a worldwide
 * feed to a title that had configured a single country.
 */
const DEFAULTS = array(
	'language'         => 'en',
	'default_country'  => 'GB',
	'default_category' => '',
	'cache_minutes'    => '15',
);

/**
 * Provider settings, with defaults applied.
 *
 * @return array
 */
function config(): array {
	$stored = get_option( OPTION_KEY, array() );

	return array_merge( DEFAULTS, is_array( $stored ) ? array_filter( $stored ) : array() );
}

const CONNECTOR_ID   = 'currents';
const SETTING_NAME   = 'vip_workflow_currents_api_key';
const CONSTANT_NAME  = 'VIP_WORKFLOW_CURRENTS_KEY';
const ENV_VAR_NAME   = 'VIP_WORKFLOW_CURRENTS_KEY';
const CREDENTIALS_URL = 'https://currentsapi.services/en/register';

// ── Connection ───────────────────────────────────────────────────────

add_action( 'wp_connectors_init', __NAMESPACE__ . '\register_connector' );

/**
 * Register Currents as an api_key connector.
 *
 * Registered by this plugin rather than by core, so that core carries no
 * knowledge of a provider it does not ship. The registry is global, so the
 * connector still appears on the same Settings → Connectors screen as the rest.
 *
 * @param object $registry The WP_Connector_Registry instance.
 */
function register_connector( $registry ): void {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
		return;
	}

	if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( CONNECTOR_ID ) ) {
		return;
	}

	$registry->register(
		CONNECTOR_ID,
		array(
			'name'           => __( 'Currents', 'workflow-discovery-currents' ),
			'description'    => __( 'Recent news from across the web, as story candidates for ideation.', 'workflow-discovery-currents' ),
			'type'           => 'service',
			'authentication' => array(
				'method'          => 'api_key',
				'credentials_url' => CREDENTIALS_URL,
				'setting_name'    => SETTING_NAME,
				'constant_name'   => CONSTANT_NAME,
				'env_var_name'    => ENV_VAR_NAME,
			),
		)
	);
}

/**
 * The API key, from whichever source holds it.
 *
 * Reads the connector first so a site that manages credentials centrally gets
 * what it configured. The direct fallbacks matter on an install where the
 * Connectors API is absent — it is WordPress 7.0 and later only, and this plugin
 * should not simply stop working on 6.9.
 *
 * @return string The key, or '' when unset.
 */
function get_api_key(): string {
	if ( function_exists( 'wp_get_connector' ) ) {
		$connector = wp_get_connector( CONNECTOR_ID );

		if ( is_array( $connector ) && 'api_key' === ( $connector['authentication']['method'] ?? '' ) ) {
			$auth = $connector['authentication'];

			$key = resolve_key(
				(string) ( $auth['setting_name'] ?? SETTING_NAME ),
				(string) ( $auth['env_var_name'] ?? ENV_VAR_NAME ),
				(string) ( $auth['constant_name'] ?? CONSTANT_NAME )
			);

			if ( '' !== $key ) {
				return $key;
			}
		}
	}

	return resolve_key( SETTING_NAME, ENV_VAR_NAME, CONSTANT_NAME );
}

/**
 * Read a key from environment, constant, then option.
 *
 * Ordered so an environment override wins, which is what makes a per-environment
 * key possible without touching the database.
 *
 * @param string $setting_name  Option name.
 * @param string $env_var_name  Environment variable name.
 * @param string $constant_name Constant name.
 * @return string
 */
function resolve_key( string $setting_name, string $env_var_name, string $constant_name ): string {
	if ( '' !== $env_var_name ) {
		$env = getenv( $env_var_name );

		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
	}

	if ( '' !== $constant_name && defined( $constant_name ) ) {
		$value = constant( $constant_name );

		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
	}

	if ( '' !== $setting_name ) {
		$value = get_option( $setting_name, '' );

		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
	}

	return '';
}

// ── Discovery Provider ───────────────────────────────────────────────

add_action( 'vip_workflow_register_discovery_providers', __NAMESPACE__ . '\register_provider' );

/**
 * Register the discovery provider.
 *
 * @param object $registry Registry instance.
 */
function register_provider( $registry ): void {
	$registry->register(
		SLUG,
		array(
			'label'                 => __( 'Currents', 'workflow-discovery-currents' ),
			'description'           => __( 'Recent news from across the web, as raw material for a story.', 'workflow-discovery-currents' ),
			'icon'                  => 'megaphone',
			'features'              => array( 'recommend', 'search' ),
			'callbacks'             => array(
				'recommend' => __NAMESPACE__ . '\get_recommendations',
				'search'    => __NAMESPACE__ . '\search',
				'filters'   => __NAMESPACE__ . '\get_search_filters',
				'seed'      => __NAMESPACE__ . '\generate_seed',
			),
			'availability_callback' => __NAMESPACE__ . '\check_availability',
		)
	);
}

// ── Unified Assistants Tab ───────────────────────────────────────────

add_action( 'vip_workflow_register_assistant_meta', __NAMESPACE__ . '\register_assistant_meta' );

/**
 * Group this plugin's capabilities into one Integrations card.
 *
 * @param object $registry Assistant registry instance.
 */
function register_assistant_meta( $registry ): void {
	$registry->register(
		SLUG,
		array(
			'label'           => __( 'Currents', 'workflow-discovery-currents' ),
			'description'     => __( 'Recent news from the Currents API, as story candidates.', 'workflow-discovery-currents' ),
			'icon'            => 'megaphone',
			'provider_slugs'  => array( SLUG ),
			'settings_schema' => array(
				'language'         => array(
					'type'        => 'string',
					'label'       => __( 'Language', 'workflow-discovery-currents' ),
					'description' => __( 'Two-letter language code for the feed.', 'workflow-discovery-currents' ),
					'default'     => 'en',
				),
				'default_country'  => array(
					'type'        => 'string',
					'label'       => __( 'Country', 'workflow-discovery-currents' ),
					'description' => __( 'Two-letter country code to focus the feed on. Leave blank for all.', 'workflow-discovery-currents' ),
					'default'     => 'GB',
				),
				'default_category' => array(
					'type'        => 'string',
					'label'       => __( 'Category', 'workflow-discovery-currents' ),
					'description' => __( 'Restrict the feed to one category. Leave blank for all.', 'workflow-discovery-currents' ),
					'default'     => '',
				),
				'cache_minutes'    => array(
					'type'        => 'string',
					'label'       => __( 'Cache Duration (minutes)', 'workflow-discovery-currents' ),
					'description' => __( 'How long to hold results before refetching.', 'workflow-discovery-currents' ),
					'enum'        => array( '5', '15', '30', '60' ),
					'default'     => '15',
				),
			),
		)
	);
}

// ── Availability ─────────────────────────────────────────────────────

/**
 * Whether an API key is available from any source.
 *
 * @return bool
 */
function is_configured(): bool {
	return '' !== get_api_key();
}

/**
 * Report availability for the provider.
 *
 * `dependency` rather than `in_card`: the key is a Connection, so this plugin
 * has no field of its own to point at and the only honest instruction is the
 * screen that actually owns the value. Naming a field here would send an
 * administrator looking for something that is not on this card.
 *
 * @return bool|Availability True when configured, otherwise the unmet requirement.
 */
function check_availability(): bool|Availability {
	if ( is_configured() ) {
		return true;
	}

	return Availability::unmet(
		RequirementGroup::all(
			RequirementFactory::dependency(
				'credentials:currents',
				sprintf(
					/* translators: %s: wp-config constant name. */
					__( 'Currents has no API key. Add one under Settings → Connectors, or define %s in wp-config.php.', 'workflow-discovery-currents' ),
					CONSTANT_NAME
				),
				__( 'Currents is not connected. Ask an administrator to finish setting it up.', 'workflow-discovery-currents' ),
				array( __( 'Currents', 'workflow-discovery-currents' ) )
			)
		)
	);
}

// ── Callbacks ────────────────────────────────────────────────────────

/**
 * Recent news for the discovery landing page.
 *
 * @param array $config Provider settings.
 * @return array[] Story prompts.
 */
function get_recommendations( array $config = array() ): array {
	$config = array_merge( config(), array_filter( $config ) );
	$client = new CurrentsClient();

	try {
		$items = $client->latest_news(
			array(
				'language' => $config['language'],
				'country'  => $config['default_country'],
				'category' => $config['default_category'],
			)
		);
	} catch ( \Throwable $e ) {
		return array();
	}

	return array_map( PromptFormatter::format( ... ), array_slice( $items, 0, 18 ) );
}

/**
 * Search recent news.
 *
 * @param array $params Search text and filter selections.
 * @return array[] Story prompts.
 */
function search( array $params ): array {
	$config  = config();
	$filters = (array) ( $params['filters'] ?? array() );
	$client  = new CurrentsClient();

	$args = array(
		'keywords' => (string) ( $params['text'] ?? '' ),
		'language' => $config['language'],
		'country'  => $filters['country'] ?? $config['default_country'],
		'category' => $filters['category'] ?? $config['default_category'],
	);

	if ( ! empty( $filters['date_range']['from'] ) ) {
		$args['start_date'] = gmdate( 'c', (int) strtotime( (string) $filters['date_range']['from'] ) );
	}

	if ( ! empty( $filters['date_range']['to'] ) ) {
		$args['end_date'] = gmdate( 'c', (int) strtotime( (string) $filters['date_range']['to'] ) );
	}

	try {
		$items = $client->search( $args );
	} catch ( \Throwable $e ) {
		return array();
	}

	return array_map( PromptFormatter::format( ... ), $items );
}

/**
 * Filters offered in the discovery search modal.
 *
 * Categories and countries are fetched from the service rather than hardcoded,
 * since both change without notice and a stale hardcoded list silently returns
 * nothing.
 *
 * @return array[]
 */
function get_search_filters(): array {
	$client = new CurrentsClient();

	try {
		$categories = $client->available( 'categories' );
		$regions    = $client->available( 'regions' );
	} catch ( \Throwable $e ) {
		$categories = array();
		$regions    = array();
	}

	return array(
		array(
			'key'     => 'date_range',
			'label'   => __( 'Published', 'workflow-discovery-currents' ),
			'type'    => 'date_range',
			'default' => array(
				'from' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				'to'   => gmdate( 'Y-m-d' ),
			),
		),
		array(
			'key'     => 'category',
			'label'   => __( 'Category', 'workflow-discovery-currents' ),
			'type'    => 'select',
			'options' => to_options( $categories ),
		),
		array(
			'key'     => 'country',
			'label'   => __( 'Country', 'workflow-discovery-currents' ),
			'type'    => 'select',
			'options' => to_options( $regions ),
		),
	);
}

/**
 * Turn a flat list of names into select options.
 *
 * @param array $values Names as the service returns them.
 * @return array[]
 */
function to_options( array $values ): array {
	$options = array();

	foreach ( $values as $key => $value ) {
		// Regions arrive keyed by code, categories as a plain list.
		$option_value = is_string( $key ) ? $key : (string) $value;

		$options[] = array(
			'value' => $option_value,
			'label' => ucfirst( (string) $value ),
		);
	}

	return $options;
}

/**
 * Compose an ideation seed from a selected news item.
 *
 * @param array $prompt The selected story prompt.
 * @return string
 */
function generate_seed( array $prompt ): string {
	$parts = array( (string) ( $prompt['title'] ?? '' ) );

	if ( ! empty( $prompt['description'] ) ) {
		$parts[] = (string) $prompt['description'];
	}

	if ( ! empty( $prompt['date'] ) ) {
		$timestamp = strtotime( (string) $prompt['date'] );

		if ( false !== $timestamp ) {
			$parts[] = sprintf(
				/* translators: %s: publication date. */
				__( 'Reported %s.', 'workflow-discovery-currents' ),
				wp_date( 'F j, Y', $timestamp )
			);
		}
	}

	if ( ! empty( $prompt['url'] ) ) {
		$parts[] = sprintf(
			/* translators: %s: source URL. */
			__( 'Source: %s', 'workflow-discovery-currents' ),
			$prompt['url']
		);
	}

	return implode( ' ', array_filter( $parts ) );
}
