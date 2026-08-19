<?php
/**
 * Minimum Pins Checker class.
 *
 * @package WorkflowToolMinimumPins
 */

declare( strict_types=1 );

namespace WorkflowToolMinimumPins;

use VIPWorkflow\Abilities\AbilitySettings;

class MinimumPinsChecker {

	private const DEFAULT_MINIMUM = 3;

	public static function register(): void {
		if ( ! function_exists( 'vip_workflow_register_ability' ) ) {
			return;
		}

		vip_workflow_register_ability(
			'workflow-tool-minimum-pins/minimum-pins',
			[
				'label'               => __( 'Minimum Pins', 'workflow-tool-minimum-pins' ),
				'description'         => __( 'Requires a minimum number of pinned research sources before transitioning out of ideation.', 'workflow-tool-minimum-pins' ),
				'category'            => 'vip-workflow',
				'input_schema'        => self::get_input_schema(),
				'output_schema'       => self::get_output_schema(),
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => [ self::class, 'can_execute' ],
				'meta'                => [
					'show_in_rest'        => true,
					'show_in_commands'    => false,
					'icon'                => 'sticky',
					'type'                => 'check',
					'supports'            => [ 'phase' ],
					'transition_eligible' => true,
					'settings_schema'     => [
						'minimum_pins' => [
							'type'        => 'integer',
							'default'     => self::DEFAULT_MINIMUM,
							'label'       => __( 'Minimum pinned sources', 'workflow-tool-minimum-pins' ),
							'description' => __( 'Required number of pinned research sources before transitioning.', 'workflow-tool-minimum-pins' ),
							'minimum'     => 1,
							'maximum'     => 50,
							'enforceable' => true,
						],
					],
					'annotations'         => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Permission callback.
	 *
	 * The named project has to be authorized, not merely present: this ability
	 * reads the project's pin state, and a callback that only checks for a
	 * parameter lets any caller probe arbitrary IDs. Ideation projects register
	 * with `capability_type => 'post'` and `map_meta_cap`, so `edit_post` is the
	 * right question to ask about one.
	 *
	 * @param  array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		if ( empty( $input['project_id'] ) ) {
			return new \WP_Error(
				'missing_project_id',
				__( 'Project ID is required.', 'workflow-tool-minimum-pins' )
			);
		}

		/*
		 * A project the caller cannot reach and one that does not exist answer
		 * identically, so the refusal cannot be used to enumerate which IDs are
		 * real.
		 */
		$permission_error = \VIPWorkflow\Abilities\Tools\require_post_edit_permission( (int) $input['project_id'] );
		if ( $permission_error ) {
			return $permission_error;
		}

		return true;
	}

	private static function get_input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'project_id' => [
					'type'        => 'integer',
					'description' => 'The ideation project ID.',
					'required'    => true,
				],
			],
		];
	}

	private static function get_output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'passed'  => [ 'type' => 'boolean' ],
				'status'  => [ 'type' => 'string' ],
				'summary' => [ 'type' => 'string' ],
				'issues'  => [ 'type' => 'array' ],
			],
		];
	}

	/**
	 * Count pinned sources on an ideation project and compare against the configured minimum.
	 *
	 * @param array $input { project_id: int }
	 * @return array|WP_Error
	 */
	public static function execute( array $input ): array|\WP_Error {
		$project_id = (int) ( $input['project_id'] ?? 0 );
		$project    = get_post( $project_id );

		if ( ! $project ) {
			return new \WP_Error( 'not_found', __( 'Ideation project not found.', 'workflow-tool-minimum-pins' ) );
		}

		$settings = AbilitySettings::get_instance();
		$options  = $settings->get_options( 'workflow-tool-minimum-pins/minimum-pins' );
		$minimum  = (int) ( $options['minimum_pins'] ?? self::DEFAULT_MINIMUM );
		$pinned_ids   = json_decode( get_post_meta( $project_id, '_vip_ideation_pinned_cards', true ) ?: '[]', true );
		$pinned_count = count( $pinned_ids );

		if ( $pinned_count >= $minimum ) {
			return [
				'passed'  => true,
				'status'  => 'pass',
				'summary' => sprintf(
					/* translators: 1: pinned count, 2: minimum required */
					__( '%1$d of %2$d required sources pinned.', 'workflow-tool-minimum-pins' ),
					$pinned_count,
					$minimum
				),
				'issues'  => [],
			];
		}

		return [
			'passed'  => false,
			'status'  => 'fail',
			'summary' => sprintf(
				/* translators: 1: pinned count, 2: minimum required */
				__( 'Only %1$d of %2$d required sources pinned.', 'workflow-tool-minimum-pins' ),
				$pinned_count,
				$minimum
			),
			'issues'  => [
				[
					'check_key' => 'minimum_pins',
					'type'      => 'pin_count',
					'severity'  => 'error',
					'message'   => sprintf(
						/* translators: 1: pinned count, 2: minimum required */
						__( 'This project has %1$d pinned source(s), but at least %2$d are required before transitioning.', 'workflow-tool-minimum-pins' ),
						$pinned_count,
						$minimum
					),
				],
			],
		];
	}
}
