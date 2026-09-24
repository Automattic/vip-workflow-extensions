<?php
/** Public HTML and robots.txt inspection for the AEO audit.
 * @package WorkflowToolAeoAudit
 */
declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Bounded, offline parsers. Never load remote JSON-LD contexts or entities. */
final class AEO_Document {
	/** Parse server-rendered HTML and collect schema.org nodes. */
	public static function parse( string $html ): array {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$result          = array(
			'title'       => '',
			'description' => '',
			'canonicals'  => array(),
			'robots'      => array(),
			'nodes'       => array(),
			'json_count'  => 0,
			'json_errors' => 0,
		);
		$result['title'] = trim( $dom->getElementsByTagName( 'title' )->item( 0 )?->textContent ?? '' );
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name    = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			$content = trim( $meta->getAttribute( 'content' ) );
			if ( 'description' === $name ) {
				$result['description'] = $content;
			}
			if ( in_array( $name, array( 'robots', 'googlebot', 'bingbot', 'oai-searchbot' ), true ) ) {
				$result['robots'][] = $content;
			}
		}
		foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
			if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ), true ) ) {
				$result['canonicals'][] = trim( $link->getAttribute( 'href' ) );
			}
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( explode( ';', $script->getAttribute( 'type' ) )[0] ) ) ) {
				continue;
			}
			++$result['json_count'];
			// Script contents are raw text: entity decoding would corrupt valid JSON.
			$data = json_decode( $script->textContent, true, 64 );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || empty( $data ) ) {
				++$result['json_errors'];
				continue;
			}
			self::collect( $data, false, $result['nodes'] );
		}
		return $result;
	}

	/** Collect nested nodes, including @graph, inheriting supported schema contexts. */
	private static function collect( array $data, bool $schema, array &$nodes ): void {
		if ( array_key_exists( '@context', $data ) ) {
			$context = $data['@context'];
			$schema  = is_string( $context ) && (bool) preg_match( '~^https?://schema\.org/?$~i', $context );
			if ( is_array( $context ) && isset( $context['@vocab'] ) ) {
				$schema = is_string( $context['@vocab'] ) && (bool) preg_match( '~^https?://schema\.org/?$~i', $context['@vocab'] );
			}
		}
		if ( $schema && isset( $data['@type'] ) && count( array_diff( array_keys( $data ), array( '@id', '@type', '@context' ) ) ) > 0 ) {
			$nodes[] = $data;
		}
		foreach ( $data as $key => $value ) {
			if ( '@context' !== $key && is_array( $value ) ) {
				self::collect( $value, $schema, $nodes );
			}
		}
	}

	/** Compare page identity, retaining query strings and trailing slash semantics. */
	public static function same_page( string $candidate, string $url ): bool {
		if ( '' === trim( $candidate ) ) {
			return false;
		}
		$candidate = \WP_Http::make_absolute_url( $candidate, $url );
		return explode( '#', $candidate )[0] === explode( '#', $url )[0];
	}

	/** Find a primary node tied to this page, never a recommended/related article. */
	public static function primaries( array $nodes, string $url, bool $article ): array {
		$found = array();
		$types = $article ? array( 'Article', 'NewsArticle', 'BlogPosting', 'Report', 'ScholarlyArticle', 'TechArticle' ) : array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'ItemPage', 'ProfilePage', 'QAPage' );
		foreach ( $nodes as $node ) {
			$node_types = array_map( static fn( $type ) => is_string( $type ) ? preg_replace( '~^https?://schema\.org/~', '', $type ) : '', (array) $node['@type'] );
			if ( ! array_intersect( $types, $node_types ) ) {
				continue;
			}
			foreach ( array( $node['url'] ?? '', $node['@id'] ?? '', $node['mainEntityOfPage'] ?? '' ) as $identity ) {
				if ( is_array( $identity ) ) {
					$identity = $identity['@id'] ?? $identity['url'] ?? '';
				}
				if ( is_string( $identity ) && self::same_page( $identity, $url ) ) {
					$found[] = $node;
					break;
				}
			}
		}
		return $found;
	}

	/** Resolve a local @id reference; do not dereference network URLs. */
	public static function resolve( mixed $value, array $nodes ): mixed {
		if ( is_array( $value ) && isset( $value['@id'] ) ) {
			foreach ( $nodes as $node ) {
				if ( ( $node['@id'] ?? null ) === $value['@id'] ) {
					return array_replace( $node, $value );
				}
			}
		}
		return $value;
	}

	/** A populated property with a basic type/format check, not a full schema validator. */
	public static function field_valid( string $field, mixed $value, array $nodes ): bool {
		$value = self::resolve( $value, $nodes );
		if ( is_array( $value ) && array_is_list( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}
			foreach ( $value as $item ) {
				if ( ! self::field_valid( $field, $item, $nodes ) ) {
					return false;
				}
			}
			return true;
		}
		if ( in_array( $field, array( 'author', 'publisher' ), true ) ) {
			return is_array( $value ) && self::field_valid( 'name', $value['name'] ?? null, $nodes );
		}
		if ( 'image' === $field && is_array( $value ) ) {
			$value = $value['contentUrl'] ?? $value['url'] ?? null;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) || preg_match( '/%[a-z_]+%|\{\{|\b(?:TODO|TK)\b/', $value ) ) {
			return false;
		}
		if ( in_array( $field, array( 'url', 'image' ), true ) ) {
			return false !== filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'https', 'http' ), true );
		}
		if ( in_array( $field, array( 'datePublished', 'dateModified' ), true ) ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/', $value, $parts ) ) {
				return false;
			}
			return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && false !== strtotime( $value );
		}
		return true;
	}

	/** Normalize percent encoding of unreserved characters for REP matching. */
	private static function robots_path( string $path ): string {
		$path = preg_replace_callback( '/[^\x00-\x7F]/', static fn( $m ) => rawurlencode( $m[0] ), $path );
		return preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $m ) {
				$char = chr( hexdec( substr( $m[0], 1 ) ) );
				return preg_match( '/[A-Za-z0-9._~-]/', $char ) ? $char : strtoupper( $m[0] );
			},
			$path
		);
	}

	/** REP groups, specific-agent precedence, merged groups, longest rule and allow ties. */
	public static function robots_allowed( string $text, string $url, string $agent ): bool {
		$groups    = array();
		$agents    = array();
		$rules     = array();
		$has_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text . "\nUser-agent: __end__" ) as $line ) {
			$line = trim( explode( '#', $line )[0], " \t\xEF\xBB\xBF" );
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $key, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$key             = strtolower( $key );
			if ( 'user-agent' === $key ) {
				if ( $has_rules ) {
					$groups[]  = array( $agents, $rules );
					$agents    = $rules = array();
					$has_rules = false;
				}
				$agents[] = strtolower( $value );
			} elseif ( in_array( $key, array( 'allow', 'disallow' ), true ) && $agents ) {
				$has_rules = true;
				if ( str_starts_with( $value, '/' ) ) {
					$rules[] = array( $key, self::robots_path( $value ) );
				}
			}
		}
		$selected   = array();
		$best_agent = -1;
		foreach ( $groups as [ $group_agents, $group_rules ] ) {
			$specificity = -1;
			foreach ( $group_agents as $name ) {
				if ( '*' === $name ) {
					$specificity = max( $specificity, 0 );
				} elseif ( '' !== $name && str_starts_with( strtolower( $agent ), $name ) ) {
					$specificity = max( $specificity, strlen( $name ) );
				}
			}
			if ( $specificity < 0 || $specificity < $best_agent ) {
				continue;
			}
			if ( $specificity > $best_agent ) {
				$selected   = array();
				$best_agent = $specificity;
			}
			$selected = array_merge( $selected, $group_rules );
		}
		$path    = ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
		$query   = wp_parse_url( $url, PHP_URL_QUERY );
		$path    = self::robots_path( $path . ( null !== $query ? '?' . $query : '' ) );
		$length  = -1;
		$allowed = true;
		foreach ( $selected as [ $rule, $pattern ] ) {
			$end         = str_ends_with( $pattern, '$' );
			$pattern     = $end ? substr( $pattern, 0, -1 ) : $pattern;
			$regex       = '~^' . str_replace( '\*', '.*', preg_quote( $pattern, '~' ) ) . ( $end ? '$' : '' ) . '~';
			$specificity = strlen( str_replace( '*', '', $pattern ) );
			$match       = preg_match( $regex, $path );
			if ( false === $match ) {
				return false; // A pattern evaluation failure must not silently permit crawling.
			}
			if ( $match && ( $specificity > $length || ( $specificity === $length && 'allow' === $rule ) ) ) {
				$length  = $specificity;
				$allowed = 'allow' === $rule;
			}
		}
		return $allowed;
	}
}
