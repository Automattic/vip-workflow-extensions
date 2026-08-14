<?php
/**
 * Composes a rich seed string from a Foresight story prompt.
 *
 * The seed is fed to the ideation Seed Analyst, so it should be a dense
 * natural-language paragraph with enough context to extract entities,
 * topics, and search queries.
 *
 * @package WorkflowDiscoveryForesight
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryForesight;

class SeedComposer {

	/**
	 * Build a seed string from a normalized story prompt.
	 *
	 * @param array $prompt Prompt as returned by PromptFormatter::format().
	 * @return string Natural-language seed text.
	 */
	public static function compose( array $prompt ): string {
		$parts = array();
		$meta  = $prompt['meta'] ?? array();

		$parts[] = $prompt['title'];

		$date_str = self::format_date_range(
			$prompt['date'] ?? null,
			$prompt['date_end'] ?? null,
			$meta
		);
		if ( '' !== $date_str ) {
			$parts[] = $date_str;
		}

		if ( ! empty( $meta['address'] ) ) {
			$parts[] = sprintf( 'Location: %s.', $meta['address'] );
		}

		$content = $meta['content'] ?? '';
		if ( '' !== $content ) {
			$clean = wp_strip_all_tags( $content );
			if ( mb_strlen( $clean ) > 500 ) {
				$clean = mb_substr( $clean, 0, 497 ) . '...';
			}
			$parts[] = $clean;
		}

		if ( 'top_story' === ( $prompt['importance'] ?? '' ) ) {
			$parts[] = 'This is a Top Story.';
		}

		if ( ! empty( $meta['is_embargoed'] ) && ! empty( $meta['embargo_date'] ) ) {
			$embargo = date( 'F j, Y', strtotime( $meta['embargo_date'] ) );
			$parts[] = sprintf( 'Embargoed until %s.', $embargo );
		}

		if ( ! empty( $prompt['tags'] ) ) {
			$parts[] = sprintf( 'Topics: %s.', implode( ', ', $prompt['tags'] ) );
		}

		if ( ! empty( $meta['event_types'] ) ) {
			$parts[] = sprintf( 'Event type: %s.', implode( ', ', $meta['event_types'] ) );
		}

		$links = $meta['links'] ?? array();
		$link_urls = array();
		foreach ( array_slice( $links, 0, 3 ) as $link ) {
			if ( ! empty( $link['url'] ) ) {
				$label      = ! empty( $link['text'] ) ? $link['text'] : $link['url'];
				$link_urls[] = $label;
			}
		}
		if ( ! empty( $link_urls ) ) {
			$parts[] = sprintf( 'Related: %s.', implode( ', ', $link_urls ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Format the date range into a human-readable string.
	 */
	private static function format_date_range( ?string $start, ?string $end, array $meta ): string {
		if ( ! empty( $meta['year_tbc'] ) ) {
			return 'Date: year to be confirmed.';
		}

		if ( ! empty( $meta['month_tbc'] ) ) {
			return 'Date: month to be confirmed.';
		}

		if ( empty( $start ) ) {
			return '';
		}

		$start_ts   = strtotime( $start );
		$has_time   = $meta['start_has_time'] ?? false;
		$start_fmt  = $has_time ? 'F j, Y \a\t g:i A' : 'F j, Y';
		$start_str  = date( $start_fmt, $start_ts );

		if ( empty( $end ) ) {
			return sprintf( 'Scheduled for %s.', $start_str );
		}

		$end_ts    = strtotime( $end );
		$end_fmt   = ( $meta['end_has_time'] ?? false ) ? 'F j, Y \a\t g:i A' : 'F j, Y';
		$end_str   = date( $end_fmt, $end_ts );

		if ( date( 'Y-m-d', $start_ts ) === date( 'Y-m-d', $end_ts ) ) {
			return sprintf( 'Scheduled for %s.', $start_str );
		}

		return sprintf( 'Runs %s to %s.', $start_str, $end_str );
	}
}
