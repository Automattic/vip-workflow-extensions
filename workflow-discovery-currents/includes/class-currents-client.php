<?php
/**
 * Currents API client.
 *
 * Simple by comparison with most news APIs: a single API key sent as an
 * `Authorization` header, no token exchange, no refresh.
 *
 * @package WorkflowDiscoveryCurrents
 *
 * @link https://currentsapi.services/en/docs/
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryCurrents;

/**
 * Talks to the Currents API.
 */
class CurrentsClient {

	const API_ROOT = 'https://api.currentsapi.services/v2';

	/**
	 * Latest news, filtered by the configured feed settings.
	 *
	 * The endpoint itself only documents `language`, but country and category
	 * narrow it usefully, so anything set is passed and anything empty is
	 * dropped rather than sent blank.
	 *
	 * @param array $args Language, country and category filters.
	 * @return array[] News items.
	 * @throws \RuntimeException On request failure.
	 */
	public function latest_news( array $args = array() ): array {
		$response = $this->request( '/latest-news', $args );

		return (array) ( $response['news'] ?? array() );
	}

	/**
	 * Search news.
	 *
	 * @param array $args Keywords, language, country, category and date bounds.
	 * @return array[] News items.
	 * @throws \RuntimeException On request failure.
	 */
	public function search( array $args = array() ): array {
		$response = $this->request( '/search', $args );

		return (array) ( $response['news'] ?? array() );
	}

	/**
	 * A list the service publishes: languages, regions, or categories.
	 *
	 * Cached for a day — these change rarely, and every discovery search modal
	 * open would otherwise cost two extra round trips.
	 *
	 * @param string $what One of languages, regions, categories.
	 * @return array The list, shape varying by endpoint.
	 * @throws \RuntimeException On request failure.
	 */
	public function available( string $what ): array {
		$cache_key = 'vip_currents_available_' . $what;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->request( '/available/' . $what );
		$list     = (array) ( $response[ $what ] ?? array() );

		set_transient( $cache_key, $list, DAY_IN_SECONDS );

		return $list;
	}

	/**
	 * The stored API key.
	 *
	 * @return string
	 * @throws \RuntimeException When unset.
	 */
	private function api_key(): string {
		$key = trim( get_api_key() );

		if ( '' === $key ) {
			throw new \RuntimeException( 'Currents API key is not configured.' );
		}

		return $key;
	}

	/**
	 * How long responses are held.
	 *
	 * @return int Seconds.
	 */
	private function cache_seconds(): int {
		return max( 1, (int) config()['cache_minutes'] ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Make a request and decode the response.
	 *
	 * @param string $path  API path.
	 * @param array  $query Query parameters; empties are dropped.
	 * @return array Decoded body.
	 * @throws \RuntimeException On failure.
	 */
	private function request( string $path, array $query = array() ): array {
		$key = $this->api_key();

		$query = array_filter(
			$query,
			static fn( $value ): bool => null !== $value && '' !== $value
		);

		/*
		 * Not pre-encoded. add_query_arg() encodes what it is handed, so
		 * encoding first produced %2520 for a space and %253A in an ISO date —
		 * meaning every multi-word search and every date filter was sent as a
		 * literal escaped string and answered with an empty result set that
		 * reads as "the wire has no coverage".
		 */
		$url = add_query_arg( $query, self::API_ROOT . $path );

		// The key is a header, so it is safely absent from the cache key.
		$cache_key = 'vip_currents_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args = array(

			/*
			 * Three seconds, per VIP's remote-request guidance. This value is
			 * only used by the plain wp_remote_get fallback — under
			 * vip_safe_wp_remote_get the positional timeout below wins.
			 */
			'timeout' => 3,
			'headers' => array(
				'Authorization' => $key,
				'Accept'        => 'application/json',
			),
		);

		$response = function_exists( 'vip_safe_wp_remote_get' )
			? vip_safe_wp_remote_get( $url, '', 3, 5, 20, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( 'Currents request failed: ' . $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			throw new \RuntimeException( 'Currents rejected the API key.' );
		}

		if ( 429 === $code ) {
			throw new \RuntimeException( 'Currents rate limit reached. Try again shortly.' );
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Currents returned HTTP %d for %s.', $code, $path ) ) );
		}

		/*
		 * The service reports application errors inside a 200 body, so a status
		 * of anything but "ok" is a failure even though the transport succeeded.
		 */
		if ( isset( $body['status'] ) && 'ok' !== $body['status'] ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Currents reported: %s', (string) ( $body['message'] ?? $body['status'] ) ) )
			);
		}

		set_transient( $cache_key, $body, $this->cache_seconds() );

		return $body;
	}
}
