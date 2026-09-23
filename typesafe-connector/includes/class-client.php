<?php
/**
 * Minimal TypeSafe System One client.
 *
 * @package TypeSafeConnector
 */

declare( strict_types=1 );

namespace TypeSafeConnector;

/**
 * Sends a batch of questions about some state to TypeSafe and returns the answers.
 *
 * This is the one place in the repository that knows where the key comes from and what a
 * failed call looks like. Extensions that need TypeSafe list `typesafe-connector` in their
 * `Requires Plugins` header and call this class; they deal only in questions and answers.
 * Every method is static and reads nothing but the environment and the connector, so a
 * consumer can call it at any point after `plugins_loaded`.
 */
final class Client {

	public const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

	/**
	 * The model alias TypeSafe documents for the current Jev release.
	 */
	public const MODEL = 'jev-latest';

	public const CONNECTOR_ID = 'typesafe';

	/**
	 * Seconds to wait. Answers are typically well under a second; this is a ceiling for a bad day.
	 */
	private const TIMEOUT = 15;

	/**
	 * Whether a `typesafe` connector is registered at all.
	 *
	 * Distinct from "has a key": the fix for one is installing a plugin, the fix for the other is pasting a key.
	 *
	 * @return bool
	 */
	public static function has_connector(): bool {
		return function_exists( 'wp_get_connector' ) && is_array( wp_get_connector( self::CONNECTOR_ID ) );
	}

	/**
	 * Resolve the API key with the precedence Core uses: environment, constant, then the saved setting.
	 *
	 * The names come from the registered connector rather than being hardcoded here, so this stays
	 * correct if they ever change in one place.
	 *
	 * @return string The key, or '' when none is configured.
	 */
	public static function api_key(): string {
		if ( ! self::has_connector() ) {
			return '';
		}

		$connector = wp_get_connector( self::CONNECTOR_ID );
		$auth      = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();

		if ( 'api_key' !== ( $auth['method'] ?? '' ) ) {
			return '';
		}

		$env_name = (string) ( $auth['env_var_name'] ?? '' );
		if ( '' !== $env_name ) {
			$env = getenv( $env_name );
			if ( is_string( $env ) && '' !== $env ) {
				return $env;
			}
		}

		$constant = (string) ( $auth['constant_name'] ?? '' );
		if ( '' !== $constant && defined( $constant ) ) {
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$setting = (string) ( $auth['setting_name'] ?? '' );
		if ( '' !== $setting ) {
			$value = get_option( $setting, '' );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Ask a batch of questions about one piece of state.
	 *
	 * Independent questions over the same state belong in one request: they run in parallel
	 * on TypeSafe's side and cost far less than the same questions sent one at a time.
	 *
	 * @param  string|array $state     The content to judge.
	 * @param  array        $questions Question id => question definition.
	 * @return array|\WP_Error Question id => answer, or an error whose message says what to do about it.
	 */
	public static function ask( $state, array $questions ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'typesafe_not_configured',
				__( 'TypeSafe has no API key. Add one in Settings → Connectors, or set TYPESAFE_API_KEY.', 'typesafe-connector' )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'state'     => $state,
						'model'     => self::MODEL,
						'questions' => $questions,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'typesafe_unreachable',
				sprintf(
					/* translators: %s: the transport error message. */
					__( 'Could not reach TypeSafe: %s', 'typesafe-connector' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status ) {
			return new \WP_Error(
				'typesafe_unauthorized',
				__( 'TypeSafe rejected the API key. Check the key in Settings → Connectors, or the TYPESAFE_API_KEY value that overrides it.', 'typesafe-connector' )
			);
		}

		if ( 429 === $status || 529 === $status ) {
			return new \WP_Error(
				'typesafe_busy',
				__( 'TypeSafe is rate limiting or overloaded right now. Try again in a moment.', 'typesafe-connector' )
			);
		}

		if ( 422 === $status ) {
			return new \WP_Error(
				'typesafe_invalid_request',
				sprintf(
					/* translators: %s: the validation message TypeSafe returned. */
					__( 'TypeSafe could not process the request: %s', 'typesafe-connector' ),
					mb_substr( $body, 0, 300 )
				)
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'typesafe_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'TypeSafe returned an unexpected response (HTTP %d).', 'typesafe-connector' ),
					$status
				)
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['answers'] ?? null ) ) {
			return new \WP_Error(
				'typesafe_bad_response',
				__( 'TypeSafe returned a response the connector could not read.', 'typesafe-connector' )
			);
		}

		/**
		 * Fires after every successful request, with what TypeSafe reported it used.
		 *
		 * TypeSafe charges per input token and returns the count with every response, so this is how a caller
		 * that wants to know what a check cost can find out, without the client keeping a tally of its own.
		 *
		 * @param array{input_tokens?: int, output_tokens?: int} $usage Usage as TypeSafe reported it, or empty.
		 * @param string                                         $model The model that answered.
		 */
		do_action( 'typesafe_connector_usage', is_array( $decoded['usage'] ?? null ) ? $decoded['usage'] : array(), (string) ( $decoded['model'] ?? '' ) );

		return $decoded['answers'];
	}
}
