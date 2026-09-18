<?php
/**
 * Plugin Name: Workflow Agent: Reformat to Template
 * Description: Stage-capable agent that reformats posts to follow a structural template.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-agent-reformat-to-template
 *
 * @package WorkflowAgentReformatToTemplate
 */

declare( strict_types=1 );

namespace WorkflowAgentReformatToTemplate;

use VIPWorkflows\Abilities\Agents\StageAgent;
use VIPWorkflows\Abilities\AiAvailability;
use VIPWorkflows\Abilities\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REFORMAT_DEFAULT_TEMPLATE = 'Short punchy paragraphs (1-2 sentences each), with an image after roughly every two paragraphs.';

add_action( 'vip_workflows_register_abilities', __NAMESPACE__ . '\register' );
add_action( 'vip_workflows_register_assistant_meta', __NAMESPACE__ . '\register_agent_meta' );

/**
 * Register the reformat-to-template stage agent ability.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'vip_workflows_register_ability' ) || ! class_exists( StageAgent::class ) ) {
		return;
	}

	vip_workflows_register_ability(
		'workflow-agent-reformat-to-template/reformat-to-template',
		array(
			'label'               => __( 'Reformat to Template', 'workflow-agent-reformat-to-template' ),
			'description'         => __( 'Reformats a post body to follow a fixed structural template, saving changes as a revision.', 'workflow-agent-reformat-to-template' ),
			'category'            => 'vip-workflows',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to reformat.', 'workflow-agent-reformat-to-template' ),
					),
					'template' => array(
						'type'        => 'string',
						'description' => __( 'Description of the target structural template.', 'workflow-agent-reformat-to-template' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'status', 'summary' ),
				'properties'           => array(
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'pass', 'fail' ),
						'description' => __( 'Outcome the stage routing maps to a transition.', 'workflow-agent-reformat-to-template' ),
					),
					'summary' => array(
						'type'        => 'string',
						'description' => __( 'Human-readable summary of what the agent did.', 'workflow-agent-reformat-to-template' ),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\execute',
			'permission_callback' => __NAMESPACE__ . '\can_execute',
			'meta'                => array(
				'show_in_rest'          => true,
				'show_in_commands'      => false,
				'transition_eligible'   => false,
				'icon'                  => 'align-wide',
				'type'                  => 'agent',
				'availability_callback' => __NAMESPACE__ . '\check_availability',
				'supports'              => array( 'workflow', 'stage' ),
				'stage_eligible'        => true,
				'settings_schema'       => array(
					'template' => array(
						'type'        => 'string',
						'default'     => REFORMAT_DEFAULT_TEMPLATE,
						'label'       => __( 'Target template', 'workflow-agent-reformat-to-template' ),
						'description' => __( 'Describe the structural template the agent should enforce.', 'workflow-agent-reformat-to-template' ),
					),
				),
				'annotations'           => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);
}

/**
 * Register unified Agents tab metadata.
 *
 * @param object $registry Assistant registry.
 * @return void
 */
function register_agent_meta( $registry ): void {
	$registry->register(
		'workflow-agent-reformat-to-template',
		array(
			'label'        => __( 'Reformat to Template', 'workflow-agent-reformat-to-template' ),
			'description'  => __( 'Reformats a post body to follow a fixed structural template, saving changes as a revision.', 'workflow-agent-reformat-to-template' ),
			'icon'         => 'align-wide',
			'ability_ids'  => array( 'workflow-agent-reformat-to-template/reformat-to-template' ),
			'capabilities' => array( 'stage' ),
		)
	);
}

/**
 * Execute the reformat-to-template agent.
 *
 * @param array|null $input Input parameters.
 * @return array|\WP_Error Result contract or error.
 */
function execute( ?array $input = null ) {
	$input   = $input ?? array();
	$post_id = (int) ( $input['post_id'] ?? 0 );

	if ( ! $post_id ) {
		return new \WP_Error( 'missing_post_id', __( 'A post_id is required.', 'workflow-agent-reformat-to-template' ) );
	}

	$post = StageAgent::read_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$template = trim( (string) ( $input['template'] ?? '' ) );
	if ( '' === $template ) {
		$template = REFORMAT_DEFAULT_TEMPLATE;
	}

	$token = StageAgent::verdict_token();

	$prompt = sprintf(
		"You are a meticulous production editor. Reformat an article's body to follow a fixed structural template.\n\n" .
		"TARGET TEMPLATE:\n%s\n\n" .
		"ARTICLE TITLE: %s\n\nARTICLE BODY:\n%s\n\n" .
		"Rules:\n" .
		"- If the body already follows the template, reply with exactly the single word: %s\n" .
		"- If you cannot safely reformat it (e.g. the content is unusable), reply with exactly: CANNOT_REFORMAT\n" .
		'- Otherwise reply with ONLY the reformatted article body (no preamble, no explanation). Preserve all facts, quotes, and meaning; only change structure and formatting.',
		$template,
		$post['title'],
		StageAgent::wrap_untrusted( $post['content'], 'post body' ),
		$token
	);

	// Reformatting is mechanical, so this stage used to pin temperature to 0 and
	// promise the same structure on every run. It no longer can: the models this
	// plugin runs against reject the option outright, and the AI Client's metadata
	// does not reliably say which ones, so no temperature is requested anywhere.
	// The same article and template may now be reformatted differently run to run.

	/*
	 * Like the copy editor, this replies with the whole article body, so it is sized
	 * the same way and for the same reason: reasoning measured ~3,980 tokens on a
	 * ~1,190-token body against claude-sonnet-5, with the body reproduced in full on
	 * top of that. Restructuring to a template also has to be reasoned about against
	 * the template, so if anything it thinks more than the copy editor rather than
	 * less. 12,000 leaves room for a body around 2.5x the measured one.
	 */
	$response = StageAgent::generate( $prompt, 12000 );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( StageAgent::is_verdict( $response, $token ) ) {
		return StageAgent::result( 'pass', __( 'Article already follows the template.', 'workflow-agent-reformat-to-template' ) );
	}

	if ( StageAgent::is_sentinel( $response, 'CANNOT_REFORMAT' ) ) {
		return StageAgent::result( 'fail', __( 'The article could not be reformatted automatically and needs a human.', 'workflow-agent-reformat-to-template' ) );
	}

	if ( StageAgent::is_implausibly_short( $post['content'], $response ) ) {
		return new \WP_Error(
			'implausible_rewrite',
			__( 'The agent returned a body far shorter than the original; refusing to overwrite the post.', 'workflow-agent-reformat-to-template' )
		);
	}

	$written = StageAgent::write_content( $post_id, $response, $post['modified'] );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return StageAgent::result( 'pass', __( 'Reformatted the article to match the template.', 'workflow-agent-reformat-to-template' ) );
}

/**
 * Whether AI text generation is configured for this agent.
 *
 * Asks about the admin-selected provider, because `StageAgent::generate()`
 * resolves its model through `AiInference`. Without this the agent presented as
 * working on an unconfigured site and failed only once a post reached the stage.
 *
 * @since 0.0.1
 *
 * @return bool|Availability True when generation is configured, otherwise the unmet requirements.
 */
function check_availability(): bool|Availability {
	return AiAvailability::for_selected_provider( array( __( 'Reformat to Template', 'workflow-agent-reformat-to-template' ) ) );
}

/**
 * Permission callback.
 *
 * Scoped to the post the agent will act on. The read path enforces this too,
 * but the callback is what core's abilities endpoint, WP-CLI and MCP consult,
 * so it states the rule rather than inheriting it from a helper it does not
 * name.
 *
 * @param  array $input Ability input.
 * @return bool|\WP_Error
 */
function can_execute( array $input ): bool|\WP_Error {
	if ( empty( $input['post_id'] ) ) {
		return new \WP_Error(
			'missing_post_id',
			__( 'Post ID is required.', 'workflow-agent-reformat-to-template' )
		);
	}

	$permission_error = \VIPWorkflows\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
	if ( $permission_error ) {
		return $permission_error;
	}

	return true;
}
