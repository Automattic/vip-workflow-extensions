<?php
/**
 * The stage agent: assign categories when TypeSafe is confident enough.
 *
 * @package WorkflowTypeSafeCategorize
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeCategorize;

use VIPWorkflows\Abilities\Agents\StageAgent;

/**
 * Runs the same classification as the tool, then acts on it.
 *
 * Confident: the categories are added to the post and the stage returns `pass`.
 * Not confident: nothing is written to the post's categories, a note says what was close and why it was
 * not enough, and the stage returns `fail` so the routing sends the post to a person.
 *
 * The agent never removes a category an editor chose (see Categorizer::assign()), so running it on a post
 * that is already filed correctly changes nothing.
 */
final class CategorizeAgent {

	public const ABILITY_ID = 'workflow-typesafe-categorize/categorize-agent';

	/**
	 * Comment-meta key tagging notes written by this agent, so a re-run replaces them without touching human notes.
	 */
	private const NOTE_MARKER = '_vip_typesafe_categorize_agent';

	/**
	 * Register the stage ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'vip_workflows_register_ability' ) || ! class_exists( StageAgent::class ) ) {
			return;
		}

		vip_workflows_register_ability(
			self::ABILITY_ID,
			array(
				'label'               => __( 'Categorize', 'workflow-typesafe-categorize' ),
				'description'         => __( 'Assigns categories from the post\'s content when TypeSafe is confident, and otherwise leaves a note and sends the post to a person.', 'workflow-typesafe-categorize' ),
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
					'additionalProperties' => false,
					'required'             => array( 'status', 'summary' ),
					'properties'           => array(
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'pass', 'fail' ),
						),
						'summary'  => array( 'type' => 'string' ),
						'assigned' => array(
							'type'        => 'array',
							'description' => __( 'The categories added to the post.', 'workflow-typesafe-categorize' ),
						),
						'issues'   => array(
							'type'        => 'array',
							'description' => __( 'Why nothing was assigned, and what was closest.', 'workflow-typesafe-categorize' ),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( CategorizeTool::class, 'can_execute' ),
				'meta'                => array(
					'show_in_rest'          => true,
					'show_in_commands'      => false,
					'transition_eligible'   => false,
					'icon'                  => 'tag',
					'type'                  => 'agent',
					'availability_callback' => array( CategorizeTool::class, 'check_availability' ),
					'display_order'         => 40,
					'supports'              => array( 'workflow', 'stage' ),
					'stage_eligible'        => true,
					// Writes categories and notes, so it is not read-only. Re-running is safe: it only adds, never removes.
					'annotations'           => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Register the card on the Agents tab.
	 *
	 * @param object $registry Assistant registry.
	 * @return void
	 */
	public static function register_agent_meta( $registry ): void {
		$registry->register(
			'workflow-typesafe-categorize',
			array(
				'label'        => __( 'Categorize', 'workflow-typesafe-categorize' ),
				'description'  => __( 'Assigns categories from the post\'s content when TypeSafe is confident, and otherwise leaves a note and sends the post to a person.', 'workflow-typesafe-categorize' ),
				'icon'         => 'tag',
				'ability_ids'  => array( self::ABILITY_ID ),
				'capabilities' => array( 'stage' ),
			)
		);
	}

	/**
	 * Run the stage.
	 *
	 * @param  array|null $input Ability input.
	 * @return array|\WP_Error Result contract or error.
	 */
	public static function execute( ?array $input = null ) {
		$post_id = (int) ( ( $input ?? array() )['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'A post_id is required.', 'workflow-typesafe-categorize' ) );
		}

		$post = StageAgent::read_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$decision = Categorizer::classify( $post_id );
		if ( is_wp_error( $decision ) ) {
			return $decision;
		}

		// TypeSafe answers in well under a second, but do not write over an edit made in the meantime.
		$current = get_post( $post_id );
		if ( ! $current || (string) $current->post_modified_gmt !== $post['modified'] ) {
			return new \WP_Error(
				'concurrent_edit',
				__( 'The post was edited while the agent was working; the agent did not change its categories.', 'workflow-typesafe-categorize' )
			);
		}

		$label    = __( 'Categorize', 'workflow-typesafe-categorize' );
		$numbered = StageAgent::number_blocks( parse_blocks( $post['content'] ) );
		// Notes anchor to a block. The first one stands in for the whole post.
		$anchor = $numbered ? $numbered[0]['number'] : 1;

		if ( ! $decision['confident'] ) {
			$issue = $decision['reason'];
			if ( $decision['considered'] ) {
				$issue .= ' ' . sprintf(
					/* translators: %s: list of "Category (nn%)" entries. */
					__( 'Closest: %s. Nothing was assigned.', 'workflow-typesafe-categorize' ),
					self::describe( $decision['considered'] )
				);
			}

			$written = StageAgent::write_block_notes( $post_id, array( $anchor => array( $issue ) ), null, $post['modified'], self::NOTE_MARKER, $label );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			return StageAgent::result( 'fail', $decision['reason'], array( 'issues' => array( $issue ) ) );
		}

		$assigned = Categorizer::assign( $post_id, $decision['taxonomy'], wp_list_pluck( $decision['assign'], 'term_id' ) );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		$summary = sprintf(
			/* translators: %s: list of "Category (nn%)" entries. */
			__( 'Assigned: %s.', 'workflow-typesafe-categorize' ),
			self::describe( $decision['assign'] )
		);

		// A clean pass leaves one note recording what was done, so an editor can see it and undo it.
		// It is written against the post as it now stands: assigning terms does not touch post_modified_gmt.
		$written = StageAgent::write_block_notes( $post_id, array(), $summary, $post['modified'], self::NOTE_MARKER, $label );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return StageAgent::result(
			'pass',
			$summary,
			array(
				'assigned' => array_map(
					static function ( array $row ): array {
						return array(
							'term_id' => $row['term_id'],
							'label'   => $row['label'],
							'score'   => round( $row['score'], 3 ),
						);
					},
					$decision['assign']
				),
			)
		);
	}

	/**
	 * "Category (91%), Other (84%)".
	 *
	 * @param  array $rows Decisions from Categorizer::decide().
	 * @return string
	 */
	private static function describe( array $rows ): string {
		return implode(
			', ',
			array_map(
				static function ( array $row ): string {
					return sprintf( '%s (%d%%)', $row['label'], (int) round( $row['score'] * 100 ) );
				},
				$rows
			)
		);
	}
}
