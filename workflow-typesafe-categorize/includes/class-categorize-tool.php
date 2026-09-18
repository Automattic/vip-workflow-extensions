<?php
/**
 * The command-palette tool: suggest categories for a post.
 *
 * @package WorkflowTypeSafeCategorize
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeCategorize;

use VIPWorkflows\Abilities\Availability;
use VIPWorkflows\Abilities\RequirementFactory;
use VIPWorkflows\Abilities\RequirementGroup;

/**
 * Suggests categories and says how sure TypeSafe is. It never changes the post.
 *
 * Suggest-only is a limit of the extension point, not a preference. A tool result can be applied to
 * a post field through `meta.apply_field`, but that writes one string with editPost(), and categories
 * are a list of term IDs. So the tool reports and the agent (see CategorizeAgent) is what writes.
 */
final class CategorizeTool {

	/**
	 * Register the ability. Shown in Integrations → Tools; "Show in Command Palette" turns it on for ⌘K.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Registered through the VIP Workflows wrapper, not core's wp_register_ability(): only the
		// wrapper sets ability_class, and only a VIPWorkflows\Abilities\Ability consults availability_callback.
		if ( ! function_exists( 'vip_workflows_register_ability' ) ) {
			return;
		}

		vip_workflows_register_ability(
			Categorizer::TOOL_ID,
			array(
				'label'               => __( 'Suggest Categories', 'workflow-typesafe-categorize' ),
				'description'         => __( 'Suggests categories for the post from its content, with how confident TypeSafe is in each. Does not change the post.', 'workflow-typesafe-categorize' ),
				'category'            => 'vip-workflows',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'post_id' ),
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID to categorize.', 'workflow-typesafe-categorize' ),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'required'             => array( 'status', 'summary' ),
					'properties'           => array(
						'status'      => array(
							'type'        => 'string',
							'enum'        => array( 'pass', 'warning', 'fail' ),
							'description' => __( 'pass when TypeSafe is confident enough to assign, warning when it is not.', 'workflow-typesafe-categorize' ),
						),
						'summary'     => array( 'type' => 'string' ),
						'suggestions' => array(
							'type'        => 'array',
							'description' => __( 'Suggested categories, best first.', 'workflow-typesafe-categorize' ),
						),
						'analysis'    => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_execute' ),
				'meta'                => array(
					'show_in_rest'          => true,
					'show_in_commands'      => true,
					'icon'                  => 'tag',
					'type'                  => 'helper',
					// Several options shown to the editor. Rows are described rows, so none carries an apply action.
					'result_type'           => 'list',
					'supports'              => array( 'workflow' ),
					'transition_eligible'   => false,
					'settings_schema'       => array(
						'taxonomy'            => array(
							'type'        => 'string',
							'default'     => Categorizer::DEFAULTS['taxonomy'],
							'label'       => __( 'Taxonomy', 'workflow-typesafe-categorize' ),
							'description' => __( 'The taxonomy slug to choose terms from. Shared by the Categorize agent.', 'workflow-typesafe-categorize' ),
						),
						'min_confidence'      => array(
							'type'        => 'integer',
							'default'     => Categorizer::DEFAULTS['min_confidence'],
							'label'       => __( 'Minimum confidence (%)', 'workflow-typesafe-categorize' ),
							'description' => __( 'How sure TypeSafe must be of the main category before the agent assigns anything. Below this the agent assigns nothing and leaves a note.', 'workflow-typesafe-categorize' ),
							'minimum'     => 0,
							'maximum'     => 100,
						),
						'secondary_threshold' => array(
							'type'        => 'integer',
							'default'     => Categorizer::DEFAULTS['secondary_threshold'],
							'label'       => __( 'Additional category threshold (%)', 'workflow-typesafe-categorize' ),
							'description' => __( 'How likely an extra category must be to apply before it is added beside the main one.', 'workflow-typesafe-categorize' ),
							'minimum'     => 0,
							'maximum'     => 100,
						),
						'max_categories'      => array(
							'type'        => 'integer',
							'default'     => Categorizer::DEFAULTS['max_categories'],
							'label'       => __( 'Maximum categories per post', 'workflow-typesafe-categorize' ),
							'minimum'     => 1,
							'maximum'     => 5,
						),
					),
					'annotations'           => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'availability_callback' => array( self::class, 'check_availability' ),
				),
			)
		);
	}

	/**
	 * Whether TypeSafe is reachable: the connector is installed and a key is set.
	 *
	 * Two separate requirements, because the fixes differ: one is installing a plugin, the other is entering a key.
	 * Shared with the agent. Reads the environment only; it makes no network request, since availability is
	 * read on every Tools-page load.
	 *
	 * @return bool|Availability True when configured, otherwise the unmet requirements.
	 */
	public static function check_availability(): bool|Availability {
		$sources = array( __( 'Categorize', 'workflow-typesafe-categorize' ) );

		if ( ! TypeSafeClient::has_connector() ) {
			return Availability::unmet(
				RequirementGroup::all(
					RequirementFactory::dependency(
						'dependency:typesafe-connector',
						__( 'The TypeSafe connector is not registered. Install and activate the TypeSafe Connector plugin (WordPress 7.0 or later).', 'workflow-typesafe-categorize' ),
						__( 'TypeSafe is not set up on this site. Ask an administrator to install the TypeSafe connector.', 'workflow-typesafe-categorize' ),
						$sources
					)
				)
			);
		}

		if ( '' === TypeSafeClient::api_key() ) {
			return Availability::unmet(
				RequirementGroup::all(
					RequirementFactory::dependency(
						'dependency:typesafe-key',
						__( 'TypeSafe has no API key. Add one in Settings → Connectors, or set TYPESAFE_API_KEY.', 'workflow-typesafe-categorize' ),
						__( 'TypeSafe is not connected. Ask an administrator to add the API key.', 'workflow-typesafe-categorize' ),
						$sources
					)
				)
			);
		}

		return true;
	}

	/**
	 * Suggest categories.
	 *
	 * @param  array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( ?array $input = null ) {
		$post_id = (int) ( ( $input ?? array() )['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'A post_id is required.', 'workflow-typesafe-categorize' ) );
		}

		$result = Categorizer::classify( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = $result['confident'] ? $result['assign'] : $result['considered'];

		$summary = $result['confident']
			? sprintf(
				/* translators: %s: comma-separated category names. */
				__( 'Suggested: %s.', 'workflow-typesafe-categorize' ),
				implode( ', ', wp_list_pluck( $result['assign'], 'label' ) )
			)
			: $result['reason'];

		return array(
			'status'      => $result['confident'] ? 'pass' : 'warning',
			'summary'     => $summary,
			'suggestions' => array_map( array( self::class, 'to_row' ), $rows ),
			'analysis'    => array(
				'taxonomy'  => $result['taxonomy'],
				'confident' => $result['confident'],
			),
		);
	}

	/**
	 * Shape one decision as a described row for the result modal.
	 *
	 * @param  array $row A decision from Categorizer::decide().
	 * @return array{label: string, meta: string}
	 */
	private static function to_row( array $row ): array {
		$percent = (int) round( $row['score'] * 100 );

		switch ( $row['role'] ) {
			case 'primary':
				/* translators: %d: percent. */
				$meta = sprintf( __( 'Main category · %d%%', 'workflow-typesafe-categorize' ), $percent );
				break;
			case 'secondary':
				/* translators: %d: percent. */
				$meta = sprintf( __( 'Also applies · %d%%', 'workflow-typesafe-categorize' ), $percent );
				break;
			default:
				/* translators: %d: percent. */
				$meta = sprintf( __( 'Closest match · %d%%, below the confidence needed to assign', 'workflow-typesafe-categorize' ), $percent );
		}

		return array(
			'label' => $row['label'],
			'meta'  => $meta,
		);
	}

	/**
	 * Permission callback, scoped to the post being categorized.
	 *
	 * The tool sends the post body to a third party, so a caller who may edit some post but not this one
	 * must not be able to make that happen.
	 *
	 * @param  array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		if ( empty( $input['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'Post ID is required.', 'workflow-typesafe-categorize' ) );
		}

		$permission_error = \VIPWorkflows\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
		if ( $permission_error ) {
			return $permission_error;
		}

		return true;
	}
}
