<?php
/**
 * Foresight Event to story prompt normalization.
 *
 * @package WorkflowDiscoveryForesight
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryForesight;

class PromptFormatter {

	/**
	 * Convert a Foresight API Event into a normalized story prompt.
	 *
	 * @param array $event          Raw event from the API.
	 * @param array $category_map   ID => name lookup for categories.
	 * @param array $event_type_map ID => name lookup for event types.
	 * @return array Story prompt in the discovery framework shape.
	 */
	public static function format( array $event, array $category_map = array(), array $event_type_map = array() ): array {
		$category_ids   = $event['categoryIds'] ?? array();
		$event_type_ids = $event['eventTypeIds'] ?? array();

		$category_names   = self::resolve_ids( $category_ids, $category_map );
		$event_type_names = self::resolve_ids( $event_type_ids, $event_type_map );

		$content     = $event['content'] ?? '';
		$description = self::truncate( $content, 250 );

		return array(
			'id'          => 'foresight-' . ( $event['id'] ?? 0 ),
			'provider'    => 'foresight-news',
			'title'       => $event['headline'] ?? '',
			'description' => $description,
			'url'         => self::first_link_url( $event ),
			'date'        => $event['startDateInUtc'] ?? null,
			'date_end'    => $event['endDateInUtc'] ?? null,
			'tags'        => $category_names,
			'importance'  => self::resolve_importance( $event ),
			'meta'        => array(
				'content'        => $content,
				'event_types'    => $event_type_names,
				'address'        => $event['address'] ?? null,
				'is_embargoed'   => ! empty( $event['embargoDate'] ),
				'embargo_date'   => $event['embargoDate'] ?? null,
				'is_operational' => $event['isOperational'] ?? false,
				'date_confirmed' => ! ( $event['monthTbc'] ?? false ) && ! ( $event['yearTbc'] ?? false ),
				'start_has_time' => $event['startDateHasTime'] ?? false,
				'end_has_time'   => $event['endDateHasTime'] ?? false,
				'month_tbc'      => $event['monthTbc'] ?? false,
				'year_tbc'       => $event['yearTbc'] ?? false,
				'links'          => $event['links'] ?? array(),
				'contacts'       => $event['contacts'] ?? array(),
				'category_ids'   => $category_ids,
				'event_type_ids' => $event_type_ids,
			),
		);
	}

	/**
	 * Map importance from Foresight's boolean flags.
	 */
	private static function resolve_importance( array $event ): string {
		if ( ! empty( $event['isTopStoryUK'] ) || ! empty( $event['isTopStoryUSA'] ) ) {
			return 'top_story';
		}

		return 'normal';
	}

	/**
	 * Resolve an array of IDs to their display names.
	 *
	 * @param array $ids      Integer IDs from the event.
	 * @param array $name_map ID => name lookup.
	 * @return string[] Resolved names (unresolved IDs are omitted).
	 */
	private static function resolve_ids( array $ids, array $name_map ): array {
		$names = array();
		foreach ( $ids as $id ) {
			if ( isset( $name_map[ $id ] ) ) {
				$names[] = $name_map[ $id ];
			}
		}
		return $names;
	}

	/**
	 * Get the first link URL from event links, if any.
	 */
	private static function first_link_url( array $event ): string {
		$links = $event['links'] ?? array();
		if ( ! empty( $links[0]['url'] ) ) {
			return (string) $links[0]['url'];
		}
		return '';
	}

	/**
	 * Truncate text to a maximum length with ellipsis.
	 */
	private static function truncate( string $text, int $max = 250 ): string {
		$text = wp_strip_all_tags( $text );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return mb_substr( $text, 0, $max - 1 ) . "\u{2026}";
	}
}
