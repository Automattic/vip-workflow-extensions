<?php
/** Narrow Secure MCP settings adapter for the AEO validator.
 * @package WorkflowToolAeoAudit
 */
declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Manage this one tool without allowing arbitrary WordPress option writes. */
final class AEO_Settings {
	public const ID = 'workflow-tool-aeo-audit/aeo-settings';

	/** Register outside the Workflows tool list: this is an administrator operation. */
	public static function register(): void {
		wp_register_ability(
			self::ID,
			array(
				'label'               => __( 'AEO audit settings', 'workflow-tool-aeo-audit' ),
				'description'         => __( 'Read or update only the AEO audit settings on the current site. Requires manage_options and a matching site_url. Omit settings to read. audit_mode changes what the audit inspects, never actual crawler access.', 'workflow-tool-aeo-audit' ),
				'category'            => 'vip-workflows',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'site_url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'site_url' => array( 'type' => 'string' ),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'min_score'        => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 100,
								),
								'audit_mode'       => array(
									'type' => 'string',
									'enum' => AEO_Audit::MODES,
								),
								'enabled'          => array( 'type' => 'boolean' ),
								'show_in_commands' => array( 'type' => 'boolean' ),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site_url' => array( 'type' => 'string' ),
						'ability'  => array( 'type' => 'string' ),
						'settings' => array( 'type' => 'object' ),
						'updated'  => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/** Validate again for callers using the PHP callback directly. */
	public static function execute( array $input ): array|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'aeo_settings_forbidden', 'Only an administrator can configure the AEO audit.' );
		}
		$url = rtrim( home_url( '/' ), '/' );
		if ( ! isset( $input['site_url'] ) || ! is_string( $input['site_url'] ) || rtrim( $input['site_url'], '/' ) !== $url ) {
			return new \WP_Error( 'aeo_settings_wrong_site', 'site_url must match the current site.' );
		}
		$settings = $input['settings'] ?? array();
		if ( ! is_array( $settings ) || array_diff( array_keys( $settings ), array( 'min_score', 'audit_mode', 'enabled', 'show_in_commands' ) ) ) {
			return new \WP_Error( 'aeo_settings_invalid', 'Only the documented AEO settings may be updated.' );
		}
		foreach ( $settings as $key => $value ) {
			$valid = match ( $key ) {
				'min_score' => is_int( $value ) && $value >= 0 && $value <= 100,
				'audit_mode' => in_array( $value, AEO_Audit::MODES, true ),
				default => is_bool( $value ),
			};
			if ( ! $valid ) {
				return new \WP_Error( 'aeo_settings_invalid', 'Use an integer score from 0 to 100, audit_mode saved-content or public, and boolean toggles.' );
			}
		}
		$manager = \VIPWorkflows\Abilities\AbilitySettings::get_instance();
		$before  = $manager->get( AEO_Audit::ID );
		$changes = array();
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, array( 'enabled', 'show_in_commands' ), true ) ) {
				if ( ( $before[ $key ] ?? null ) !== $value ) {
					$changes[ $key ] = $value;
				}
			} elseif ( ( $before['options'][ $key ] ?? null ) !== $value ) {
				$changes['options'][ $key ] = $value;
			}
		}
		if ( $changes && ! $manager->update( AEO_Audit::ID, $changes ) ) {
			return new \WP_Error( 'aeo_settings_write_failed', 'AEO settings could not be saved. Read settings again before retrying.' );
		}
		return array(
			'site_url' => $url,
			'ability'  => AEO_Audit::ID,
			'settings' => $manager->get( AEO_Audit::ID ),
			'updated'  => ! empty( $changes ),
		);
	}
}
