<?php
/**
 * Foresight News API client.
 *
 * Wraps all HTTP calls with Bearer auth, 401 retry, and caching.
 *
 * @package WorkflowDiscoveryForesight
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryForesight;

class ForesightClient {

	private ForesightAuth $auth;
	private bool $retried_auth = false;

	public function __construct( ForesightAuth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Search events using structured filters. Sorted by StartDate.
	 *
	 * @param array $params API query params (DateFrom, DateTo, CategoryIds, etc.).
	 * @return array{ totalFound: int, events: array[] }
	 */
	public function filter_events( array $params = array() ): array {
		return $this->request( '/events/filter/v1', $params );
	}

	/**
	 * Free-text event search. Sorted by relevancy.
	 * Rate limit: 2 requests per 5 seconds.
	 *
	 * @param string $text Search query.
	 * @return array{ totalFound: int, events: array[] }
	 */
	public function freetext_search( string $text ): array {
		return $this->request( '/events/freetext/v1', array( 'Text' => $text ) );
	}

	/**
	 * Get a single event by ID.
	 *
	 * @param int $event_id Foresight CalendarId.
	 * @return array Event data.
	 */
	public function get_event( int $event_id ): array {
		return $this->request( '/v1/' . $event_id );
	}

	/**
	 * Get all categories (parent/child hierarchy).
	 *
	 * @return array[] Category objects.
	 */
	public function get_categories(): array {
		$cache_key = 'vip_foresight_categories';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request( '/filters/categories' );
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Get regions, optionally filtered by country.
	 *
	 * @param int|null $country_id Filter by country.
	 * @return array[] Region objects.
	 */
	public function get_regions( ?int $country_id = null ): array {
		$cache_key = 'vip_foresight_regions' . ( $country_id ? '_' . $country_id : '' );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$params = $country_id ? array( 'countryId' => $country_id ) : array();
		$data   = $this->request( '/filters/regions', $params );
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Get all event types.
	 *
	 * @return array[] EventType objects.
	 */
	public function get_event_types(): array {
		$cache_key = 'vip_foresight_event_types';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request( '/filters/eventTypes' );
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Get all countries.
	 *
	 * @return array[] Country objects.
	 */
	public function get_countries(): array {
		$cache_key = 'vip_foresight_countries';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request( '/filters/countries' );
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Get all content lists.
	 *
	 * @return array[] ContentList objects.
	 */
	public function get_content_lists(): array {
		$cache_key = 'vip_foresight_content_lists';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $this->request( '/filters/contentLists' );
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Make an authenticated GET request to the Foresight API.
	 *
	 * Retries once on 401 (token expired).
	 *
	 * @param string $path  API path (e.g. '/events/filter/v1').
	 * @param array  $query Query parameters.
	 * @return mixed Decoded JSON response.
	 * @throws \RuntimeException On request failure.
	 */
	private function request( string $path, array $query = array() ): mixed {
		$url = get_foresight_api_url() . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $this->prepare_query( $query ), $url );
		}

		$token    = $this->auth->get_token();
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			) 
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Foresight API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code && ! $this->retried_auth ) {
			$this->retried_auth = true;
			$this->auth->invalidate();
			$result = $this->request( $path, $query );
			$this->retried_auth = false;
			return $result;
		}

		if ( 429 === $code ) {
			throw new \RuntimeException( 'Foresight API rate limit reached. Try again shortly.' );
		}

		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( sprintf( 'Foresight API returned HTTP %d for %s.', $code, $path ) );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded && '' !== $body ) {
			throw new \RuntimeException( 'Foresight API returned non-JSON response for ' . $path );
		}

		return $decoded ?? array();
	}

	/**
	 * Prepare query params for add_query_arg, handling arrays.
	 *
	 * Foresight expects repeated keys for array params (e.g. CategoryIds=1&CategoryIds=2).
	 * WordPress's add_query_arg doesn't handle this natively, so we build the
	 * query string manually for array values.
	 *
	 * @param array $params Raw params.
	 * @return array Flattened params for add_query_arg.
	 */
	private function prepare_query( array $params ): array {
		$flat = array();

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$flat[ $key ] = implode( ',', $value );
			} elseif ( is_bool( $value ) ) {
				$flat[ $key ] = $value ? 'true' : 'false';
			} else {
				$flat[ $key ] = (string) $value;
			}
		}

		return $flat;
	}
}
