<?php
/**
 * Foresight News JWT authentication.
 *
 * Handles token acquisition, caching (23h transient), and refresh.
 *
 * @package WorkflowDiscoveryForesight
 */

declare( strict_types=1 );

namespace WorkflowDiscoveryForesight;

class ForesightAuth {

	const OPTION_KEY       = 'vip_discovery_provider_foresight-news';
	const TOKEN_TRANSIENT  = 'vip_foresight_auth_token';
	const TOKEN_TTL        = 23 * HOUR_IN_SECONDS;

	/**
	 * Get a valid Bearer token, authenticating if needed.
	 *
	 * @return string JWT token.
	 * @throws \RuntimeException On missing credentials or failed auth.
	 */
	public function get_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->authenticate();
	}

	/**
	 * Discard the cached token so the next call re-authenticates.
	 */
	public function invalidate(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Authenticate against the Foresight API and cache the token.
	 *
	 * @return string Fresh JWT token.
	 * @throws \RuntimeException On failure.
	 */
	private function authenticate(): string {
		$credentials = $this->get_credentials();

		$response = wp_remote_post(
			$this->get_api_url() . '/authentication/authenticate/v1',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'email'    => $credentials['email'],
						'password' => $credentials['password'],
					) 
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Foresight auth request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException( sprintf( 'Foresight auth returned HTTP %d.', $code ) );
		}

		$body  = wp_remote_retrieve_body( $response );
		$token = $this->extract_token( $body );

		set_transient( self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Extract the JWT from the auth response body.
	 *
	 * Handles both a bare string token and a JSON `{ "token": "..." }` shape.
	 *
	 * @param string $body Raw response body.
	 * @return string Token string.
	 * @throws \RuntimeException If no token can be extracted.
	 */
	private function extract_token( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) && ! empty( $decoded['accessToken'] ) ) {
			return (string) $decoded['accessToken'];
		}

		$trimmed = trim( $body, " \t\n\r\0\x0B\"" );
		if ( '' !== $trimmed && str_contains( $trimmed, '.' ) ) {
			return $trimmed;
		}

		throw new \RuntimeException( 'Foresight auth response did not contain a usable token.' );
	}

	/**
	 * Read stored credentials.
	 *
	 * @return array{ email: string, password: string }
	 * @throws \RuntimeException If credentials are missing.
	 */
	private function get_credentials(): array {
		$config   = get_option( self::OPTION_KEY, array() );
		$email    = $config['email'] ?? '';
		$password = $config['password'] ?? '';

		if ( '' === $email || '' === $password ) {
			throw new \RuntimeException( 'Foresight News credentials are not configured.' );
		}

		return array(
			'email'    => $email,
			'password' => $password,
		);
	}

	private function get_api_url(): string {
		return get_foresight_api_url();
	}
}
