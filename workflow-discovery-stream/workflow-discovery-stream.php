<?php
/**
 * Plugin Name: Workflow Discovery: Stream
 * Description: One merged feed of every story source — diary dates, wires and news — enriched with past performance and ranked by it.
 * Version: 0.1.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com/
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-discovery-stream
 *
 * WHAT THIS IS
 * ------------
 * A discovery provider that fetches from no API of its own. It fans out to the
 * other registered providers, merges what they return into one ranked feed, and
 * enriches each item with how comparable past coverage performed.
 *
 * WHY MERGE AT ALL
 * ----------------
 * Two sources side by side is two lists to read and no way to compare across
 * them — a diary date and a breaking story sit in separate columns with nothing
 * to say which deserves a reporter first. Merged and ranked by past performance
 * they answer that question directly, which is the whole point of scoring them.
 *
 * THE COPYTASTE STATES ARE NOT HERE YET
 * -------------------------------------
 * Marking an item taste, held or embargoed is the intended next step, and it
 * does not fit the current shape: story prompts are fetched fresh on every load
 * and never stored, so there is nowhere to put a state. That needs a persisted
 * record per item, which is a deliberate piece of work rather than a field to
 * bolt on here.
 *
 * @package WorkflowDiscoveryStream
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryStream;

use VIPWorkflow\Discovery\DiscoveryProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-stream-merger.php';

const SLUG       = 'stream';
const OPTION_KEY = 'vip_discovery_provider_stream';

/**
 * Default configuration.
 *
 * `sources` names the providers to merge. Anything registered can be added; a
 * source that is not registered, or is unavailable, is skipped rather than
 * failing the feed.
 */
const DEFAULTS = array(
	'sources'             => array( 'cycling-desk', 'currents', 'workflow-parsely' ),
	'limit'               => 24,

	/*
	 * The multiplier at which an item is marked a top story.
	 *
	 * Two is a deliberate choice rather than a round number: it means "twice
	 * what an ordinary story does", which is a claim an editor can check and
	 * argue with. Lowering it toward 1.0 makes the highlight meaningless, since
	 * roughly half of everything clears parity by definition.
	 */
	'highlight_threshold' => 2.0,
);

/**
 * Resolved configuration.
 *
 * @return array
 */
function config(): array {
	$stored = get_option( OPTION_KEY, array() );
	// Filtered on null only: '' and 0 are meaningful values here, not absences.
	$config = array_merge(
		DEFAULTS,
		is_array( $stored ) ? array_filter( $stored, static fn( $value ): bool => null !== $value ) : array()
	);

	/**
	 * Filters the stream configuration.
	 *
	 * @param array $config Merged defaults and stored settings.
	 */
	return (array) apply_filters( 'vip_workflow_stream_config', $config );
}

add_action( 'vip_workflow_register_discovery_providers', __NAMESPACE__ . '\register_provider', 20 );

/*
 * Everything off this stream is wire copy, so the sequence is known before the
 * ideation project exists. Naming it here is what lets the ideation screen ask
 * for the copytaste decision and the embargo, and end in Commission rather than
 * Write Draft.
 */
add_filter( 'vip_workflow_discovery_sequence', __NAMESPACE__ . '\wire_copy_sequence', 10, 2 );

/**
 * Point items from this stream at the wire-copy sequence.
 *
 * Resolved by slug rather than a stored id: the sequence is installed by the
 * copytaste plugin and its id differs per environment, so an id hard-coded here
 * would be right on one site and silently wrong on the next.
 *
 * @param int    $blueprint_id Sequence id resolved so far.
 * @param string $slug         Provider slug.
 * @return int
 */
function wire_copy_sequence( int $blueprint_id, string $slug ): int {
	if ( SLUG !== $slug ) {
		return $blueprint_id;
	}

	if ( ! class_exists( '\\WorkflowToolCopytaste\\WireBlueprint' ) ) {
		return $blueprint_id;
	}

	$id = \WorkflowToolCopytaste\WireBlueprint::find();

	return null === $id ? $blueprint_id : (int) $id;
}

/*
 * Priority 20 puts this after the Parse.ly decorator, which attaches scores at
 * the default 10. Ranking has to see them.
 */
add_filter( 'vip_workflow_discovery_prompts', array( StreamMerger::class, 'rank' ), 20 );

/**
 * Register the stream, and keep the registry for later.
 *
 * Priority 20 so the sources it merges have registered first. A source
 * registering later still works — the registry is read at request time, not
 * here — but the ordering makes the dependency explicit.
 *
 * @param object $registry Registry instance.
 */
function register_provider( $registry ): void {
	$registry->register(
		SLUG,
		array(
			'label'       => __( 'Stream', 'workflow-discovery-stream' ),
			'description' => __( 'Every story source in one feed, ranked by how comparable coverage performed.', 'workflow-discovery-stream' ),
			'icon'        => 'rss',

			/*
			 * Recommend only. Searching a merged feed would mean fanning a query
			 * out to every source and interleaving results whose relevance
			 * rankings are not comparable with each other. The per-source search
			 * each provider already offers is the honest version of that, so this
			 * declares no search rather than faking one.
			 */
			'features'    => array( 'recommend' ),
			'callbacks'   => array(
				'recommend' => __NAMESPACE__ . '\recommend',
				'seed'      => __NAMESPACE__ . '\seed',
			),
			'availability_callback' => __NAMESPACE__ . '\check_availability',
		)
	);
}

/**
 * The discovery registry.
 *
 * A singleton owned by core, which this plugin hard-depends on via
 * `Requires Plugins`, so it can be asked for directly rather than captured from
 * the registration hook and held.
 *
 * @return DiscoveryProviderRegistry
 */
function registry(): DiscoveryProviderRegistry {
	return DiscoveryProviderRegistry::get_instance();
}

// ── Availability ─────────────────────────────────────────────────────

/**
 * Available whenever at least one source is.
 *
 * The stream holds no credentials of its own, so it has no requirement to
 * report. Its sources report theirs on their own cards, and repeating them here
 * would double every notice on the Integrations page.
 *
 * @return bool
 */
function check_availability(): bool {
	return array() !== StreamMerger::available_sources();
}

// ── Callbacks ────────────────────────────────────────────────────────

/**
 * The merged feed.
 *
 * @param array $config Provider settings.
 * @return array[] Story prompts.
 */
function recommend( array $config = array() ): array {
	return StreamMerger::build( array_merge( config(), array_filter( $config ) ) );
}

/**
 * Compose a seed by delegating to whichever source the item came from.
 *
 * The stream normalizes prompts for display, but a source knows things about its
 * own items that a common shape cannot carry — an embargo time, an event's
 * location, a wire's agency. Passing the item back to its origin keeps that.
 *
 * @param array $prompt The selected story prompt.
 * @return string
 */
function seed( array $prompt ): string {
	$origin   = (string) ( $prompt['meta']['stream_source'] ?? '' );
	$registry = registry();

	if ( '' !== $origin && null !== $registry->get( $origin ) ) {
		$original = $prompt['meta']['stream_original'] ?? $prompt;

		try {
			$seed = $registry->execute( $origin, 'seed', (array) $original );

			if ( is_string( $seed ) && '' !== trim( $seed ) ) {
				return $seed;
			}
		} catch ( \Throwable $e ) {
			// Fall through to the generic seed below.
			unset( $e );
		}
	}

	return trim(
		(string) ( $prompt['title'] ?? '' ) . ' ' . (string) ( $prompt['description'] ?? '' )
	);
}
