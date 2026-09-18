<?php
/**
 * Minimal TypeSafe System One client.
 *
 * @package WorkflowTypeSafeCategorize
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeCategorize;

/**
 * Sends a batch of questions about some state to TypeSafe and returns the answers.
 *
 * Two things live here and nowhere else: where the key comes from, and what a
 * failed call looks like. The rest of the plugin deals only in answers.
 */
final class TypeSafeClient {

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
	 * The names come from the connector rather than being hardcoded, so this keeps working if
	 * whichever plugin registered the connector changes them.
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
				__( 'TypeSafe has no API key. Add one in Settings → Connectors, or set TYPESAFE_API_KEY.', 'workflow-typesafe-categorize' )
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
					__( 'Could not reach TypeSafe: %s', 'workflow-typesafe-categorize' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status ) {
			return new \WP_Error(
				'typesafe_unauthorized',
				__( 'TypeSafe rejected the API key. Check the key in Settings → Connectors, or the TYPESAFE_API_KEY value that overrides it.', 'workflow-typesafe-categorize' )
			);
		}

		if ( 429 === $status || 529 === $status ) {
			return new \WP_Error(
				'typesafe_busy',
				__( 'TypeSafe is rate limiting or overloaded right now. Try again in a moment.', 'workflow-typesafe-categorize' )
			);
		}

		if ( 422 === $status ) {
			return new \WP_Error(
				'typesafe_invalid_request',
				sprintf(
					/* translators: %s: the validation message TypeSafe returned. */
					__( 'TypeSafe could not process the request: %s', 'workflow-typesafe-categorize' ),
					mb_substr( $body, 0, 300 )
				)
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'typesafe_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'TypeSafe returned an unexpected response (HTTP %d).', 'workflow-typesafe-categorize' ),
					$status
				)
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['answers'] ?? null ) ) {
			return new \WP_Error(
				'typesafe_bad_response',
				__( 'TypeSafe returned a response this extension could not read.', 'workflow-typesafe-categorize' )
			);
		}

		return $decoded['answers'];
	}
}
