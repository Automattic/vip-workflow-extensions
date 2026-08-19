<?php
/**
 * Fan out to the source providers, merge, enrich, rank.
 *
 * @package WorkflowDiscoveryStream
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryStream;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the merged feed.
 */
class StreamMerger {

	/**
	 * Scorer class, when the Parse.ly bridge is installed.
	 *
	 * Referenced by name rather than imported so this plugin loads without it.
	 * The stream is still useful unscored — it is one feed instead of three —
	 * and hard-depending on another extension would make that impossible.
	 */
	private const SCORER = '\WorkflowParsely\Discovery\PromptScorer';

	/**
	 * Which of the configured sources are registered and available right now.
	 *
	 * @return string[] Provider slugs.
	 */
	public static function available_sources(): array {
		$registry  = registry();
		$available = array();

		foreach ( (array) config()['sources'] as $slug ) {
			$slug = (string) $slug;

			if ( SLUG === $slug || null === $registry->get( $slug ) ) {
				continue;
			}

			// A source with no credentials is skipped, not an error.
			if ( ! $registry->is_available( $slug ) ) {
				continue;
			}

			$available[] = $slug;
		}

		return $available;
	}

	/**
	 * Build the merged, enriched, ranked feed.
	 *
	 * @param array $config Resolved configuration.
	 * @return array[] Story prompts.
	 */
	public static function build( array $config ): array {
		$registry = registry();
		$items    = array();

		foreach ( self::available_sources() as $slug ) {
			foreach ( self::fetch( $registry, $slug ) as $prompt ) {
				if ( is_array( $prompt ) && '' !== trim( (string) ( $prompt['title'] ?? '' ) ) ) {
					$items[] = self::normalize( $prompt, $slug );
				}
			}
		}

		if ( array() === $items ) {
			return array();
		}

		$items = self::dedupe( $items );

		/*
		 * Sorted by date here, not by score.
		 *
		 * The discovery controller caches a provider's output, so anything
		 * decided at this point is frozen for the life of that cache — and the
		 * scores arrive later, warmed in the background. Ranking here produced a
		 * feed permanently ordered as it was the moment nothing had been scored.
		 *
		 * Fetching and merging are cacheable; ranking is not. See rank(), which
		 * runs on every response after the scores have been attached.
		 */
		usort(
			$items,
			static fn( array $a, array $b ): int =>
				strtotime( (string) ( $b['date'] ?? '' ) ) <=> strtotime( (string) ( $a['date'] ?? '' ) )
		);

		$items = array_slice( $items, 0, max( 1, (int) $config['limit'] ) );

		self::queue( $items );

		return $items;
	}

	/**
	 * Ask one source for its recommendations.
	 *
	 * A source that throws is dropped from the feed rather than taking the feed
	 * down with it — the whole value of merging is that the reader still gets
	 * everything else.
	 *
	 * @param \VIPWorkflow\Discovery\DiscoveryProviderRegistry $registry Registry instance.
	 * @param string                                           $slug     Provider slug.
	 * @return array[] Raw prompts.
	 */
	private static function fetch( $registry, string $slug ): array {
		try {
			$config  = self::source_config( $slug );
			$prompts = $registry->execute( $slug, 'recommend', $config );
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $prompts ) ? $prompts : array();
	}

	/**
	 * A source provider's own saved settings.
	 *
	 * Read from where each provider stores them, so merging a source does not
	 * quietly ignore the region, language or category it was configured with.
	 *
	 * @param string $slug Provider slug.
	 * @return array
	 */
	private static function source_config( string $slug ): array {
		$config = get_option( 'vip_discovery_provider_' . $slug, array() );

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Give an item the fields the stream needs, keeping the original intact.
	 *
	 * @param array  $prompt Raw prompt from a source.
	 * @param string $slug   Source provider slug.
	 * @return array
	 */
	private static function normalize( array $prompt, string $slug ): array {
		$meta = is_array( $prompt['meta'] ?? null ) ? $prompt['meta'] : array();

		$meta['stream_source'] = $slug;

		/*
		 * The source's own record, kept so seed composition can be handed back
		 * to it. A merged shape cannot carry an embargo time or an agency
		 * credit, and those are exactly what a seed needs.
		 */
		$meta['stream_original'] = $prompt;

		$prompt['meta']     = $meta;
		$prompt['provider'] = SLUG;

		return $prompt;
	}

	/**
	 * Drop items two sources both carry.
	 *
	 * A scheduled announcement in the diary and the news story reporting it are
	 * the same subject arriving twice, and a feed that lists both looks broken.
	 * Matched on the significant words of the headline rather than the whole
	 * string, since two sources rarely word a headline identically.
	 *
	 * The earlier item wins, which means the diary entry beats the news story
	 * covering it — the one that gives a reporter more time.
	 *
	 * @param array[] $items Normalized items.
	 * @return array[]
	 */
	private static function dedupe( array $items ): array {
		$seen = array();
		$kept = array();

		foreach ( $items as $item ) {
			$fingerprint = self::fingerprint( (string) $item['title'] );

			if ( '' !== $fingerprint && isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$kept[]               = $item;
		}

		return $kept;
	}

	/**
	 * A headline reduced to its distinguishing words.
	 *
	 * @param string $title Headline.
	 * @return string
	 */
	private static function fingerprint( string $title ): string {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $title ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return '';
		}

		$words = array_values(
			array_filter(
				$words,
				static fn( string $word ): bool => mb_strlen( $word ) > 3
			)
		);

		sort( $words );

		return implode( '-', array_slice( $words, 0, 6 ) );
	}

	/**
	 * Ask for scores on anything not yet warmed.
	 *
	 * @param array[] $items Normalized items.
	 */
	private static function queue( array $items ): void {
		$scorer = self::SCORER;

		if ( class_exists( $scorer ) ) {
			$scorer::queue_prompts( $items );
		}
	}

	/**
	 * Rank the stream and mark its strong items, on every response.
	 *
	 * Runs as a late listener on the same filter the Parse.ly decorator uses, so
	 * the scores are already attached by the time this sorts on them. This is
	 * what keeps ordering live while the merge itself stays cached.
	 *
	 * @param array $grouped Prompts grouped by provider.
	 * @return array
	 */
	public static function rank( array $grouped ): array {
		$threshold = (float) config()['highlight_threshold'];
		$rebuilt   = array();

		foreach ( $grouped as $group ) {
			if ( SLUG !== ( $group['provider']['slug'] ?? '' ) ) {
				$rebuilt[] = $group;
				continue;
			}

			$items = (array) ( $group['prompts'] ?? array() );

			foreach ( $items as $index => $item ) {
				$multiplier = $item['performance']['multiplier'] ?? null;

				if ( null === $multiplier ) {
					continue;
				}

				$items[ $index ]['meta']['stream_multiplier'] = (float) $multiplier;

				/*
				* Carried as its own flag rather than by promoting `importance`.
				*
				* Reusing `importance` was the obvious move and it is wrong: sources
				* already set it to mean their own thing. Foresight marks a diary
				* date a top story because it is nationally significant, which has
				* nothing to do with how the paper's coverage performed. Promoting
				* on score would put one badge on the card meaning two different
				* things, and no way for a reader to tell which.
				*
				* The threshold stays server-side so it remains configurable; the
				* screen only reads the boolean.
				*/
				$items[ $index ]['performance']['highlight'] = (float) $multiplier >= $threshold;
			}

			usort( $items, array( self::class, 'compare' ) );

			$rebuilt = array_merge( $rebuilt, self::split_by_tier( $group, $items ) );
		}

		return $rebuilt;
	}

	/**
	 * Split the merged feed into one section per tier.
	 *
	 * The tiers come from the Parse.ly signal, so a stream running without it
	 * gets one unsplit section rather than three empty ones.
	 *
	 * Every section keeps the provider's real slug, because selecting a prompt
	 * posts that slug back and it has to resolve in the registry. The distinct
	 * `key` exists only so the screen can tell three sections apart.
	 *
	 * @param array   $group The original stream group.
	 * @param array[] $items Its ranked prompts.
	 * @return array[] One group per populated tier, in tier order.
	 */
	private static function split_by_tier( array $group, array $items ): array {
		$buckets = array();
		$scored  = false;

		foreach ( $items as $item ) {
			$tier = $item['performance']['tier'] ?? null;

			if ( null !== $tier ) {
				$scored = true;
			}

			/*
			 * Anything without a tier goes to the bottom band rather than to a
			 * section of its own. It reads as one list an editor works down, and
			 * "we have not compared this yet" is not a finding worth its own
			 * heading. It does mean the bottom section holds both weak topics and
			 * unknown ones — the card's own evidence is what separates them.
			 */
			$buckets[ null === $tier ? 3 : (int) $tier ][] = $item;
		}

		// Nothing scored at all: one plain section rather than a lone "Tier 3".
		if ( ! $scored ) {
			$group['prompts'] = $items;

			return array( $group );
		}

		$sections = array();

		foreach ( array( 1, 2, 3 ) as $tier ) {
			if ( empty( $buckets[ $tier ] ) ) {
				continue;
			}

			$section                      = $group;
			$section['provider']['key']   = SLUG . '-tier-' . $tier;
			$section['provider']['label'] = self::tier_label( $tier );
			$section['prompts']           = $buckets[ $tier ];

			$sections[] = $section;
		}

		return $sections;
	}

	/**
	 * A tier's section heading, taken from the Parse.ly signal where available so
	 * the stream and the comparison tool never disagree about what to call it.
	 *
	 * @param int $tier Tier number.
	 * @return string
	 */
	private static function tier_label( int $tier ): string {
		$lens = '\\WorkflowParsely\\PerformanceLens';

		if ( class_exists( $lens ) && method_exists( $lens, 'tier_label' ) ) {
			return (string) $lens::tier_label( $tier );
		}

		/* translators: %d: tier number. */
		return sprintf( __( 'Tier %d', 'workflow-discovery-stream' ), $tier );
	}

	/**
	 * Rank: scored above unscored, better above worse, newer above older.
	 *
	 * Unscored items sink rather than disappearing. They are usually the newest
	 * arrivals — nothing has warmed them yet — and dropping them would make the
	 * feed lag the news by however long warming takes.
	 *
	 * @param array $a First item.
	 * @param array $b Second item.
	 * @return int
	 */
	private static function compare( array $a, array $b ): int {
		$a_score = $a['meta']['stream_multiplier'] ?? null;
		$b_score = $b['meta']['stream_multiplier'] ?? null;

		if ( null !== $a_score && null !== $b_score && $a_score !== $b_score ) {
			return $b_score <=> $a_score;
		}

		if ( null !== $a_score && null === $b_score ) {
			return -1;
		}

		if ( null === $a_score && null !== $b_score ) {
			return 1;
		}

		return strtotime( (string) ( $b['date'] ?? '' ) ) <=> strtotime( (string) ( $a['date'] ?? '' ) );
	}
}
