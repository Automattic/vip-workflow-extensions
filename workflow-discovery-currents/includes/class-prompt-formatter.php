<?php
/**
 * Currents news item to story prompt normalization.
 *
 * @package WorkflowDiscoveryCurrents
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCurrents;

/**
 * Normalizes Currents news items into story prompts.
 */
class PromptFormatter {

	/**
	 * Convert a Currents news item into a story prompt.
	 *
	 * @param array $item Raw item from the API.
	 * @return array Story prompt in the discovery framework shape.
	 */
	public static function format( array $item ): array {
		$categories = self::categories( $item );

		return array(
			'id'          => 'currents-' . ( $item['id'] ?? md5( (string) ( $item['url'] ?? '' ) ) ),
			'provider'    => SLUG,
			'title'       => trim( (string) ( $item['title'] ?? '' ) ),
			'description' => self::truncate( (string) ( $item['description'] ?? '' ), 250 ),
			'url'         => self::url( $item ),
			'date'        => (string) ( $item['published'] ?? '' ),
			'date_end'    => null,
			'tags'        => $categories,
			'importance'  => 'normal',
			'meta'        => array(
				'author'    => (string) ( $item['author'] ?? '' ),
				'image'     => self::image( $item ),
				'language'  => (string) ( $item['language'] ?? '' ),
				'section'   => $categories[0] ?? '',
				'source_id' => (string) ( $item['id'] ?? '' ),
			),
		);
	}

	/**
	 * Categories as a flat list of names.
	 *
	 * @param array $item Raw item.
	 * @return string[]
	 */
	private static function categories( array $item ): array {
		$categories = $item['category'] ?? array();

		if ( is_string( $categories ) ) {
			$categories = array( $categories );
		}

		$clean = array();

		foreach ( (array) $categories as $category ) {
			if ( is_string( $category ) && '' !== trim( $category ) ) {
				$clean[] = ucfirst( trim( $category ) );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The item's canonical link.
	 *
	 * The published schema names this field `urls` while live responses use
	 * `url`; both are read rather than betting on one.
	 *
	 * @param array $item Raw item.
	 * @return string
	 */
	private static function url( array $item ): string {
		$url = $item['url'] ?? ( $item['urls'] ?? '' );

		if ( is_array( $url ) ) {
			$url = reset( $url );
		}

		return (string) $url;
	}

	/**
	 * The item's image, when it is a usable URL.
	 *
	 * Currents returns the string "None" rather than omitting the field when it
	 * has no image, which renders as a broken image if passed through.
	 *
	 * @param array $item Raw item.
	 * @return string
	 */
	private static function image( array $item ): string {
		$image = (string) ( $item['image'] ?? '' );

		if ( '' === $image || 0 !== stripos( $image, 'http' ) ) {
			return '';
		}

		return $image;
	}

	/**
	 * Truncate text to a maximum length with an ellipsis.
	 *
	 * @param string $text Source text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function truncate( string $text, int $max = 250 ): string {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return mb_substr( $text, 0, $max - 1 ) . "\u{2026}";
	}
}
