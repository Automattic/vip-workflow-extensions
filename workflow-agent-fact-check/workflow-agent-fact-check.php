<?php
/**
 * Plugin Name: Workflow Agent: Fact Check
 * Description: Stage-capable agent that flags unsupported or dubious factual claims in posts.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-agent-fact-check
 *
 * @package WorkflowAgentFactCheck
 */

declare( strict_types=1 );

namespace WorkflowAgentFactCheck;

use VIPWorkflow\Abilities\Agents\StageAgent;
use VIPWorkflow\Abilities\AiAvailability;
use VIPWorkflow\Abilities\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comment-meta key tagging notes authored by this agent, so re-runs can
 * replace them without touching human-authored notes.
 */
const NOTE_MARKER = '_vip_factcheck_agent';

add_action( 'vip_workflow_register_abilities', __NAMESPACE__ . '\register' );
add_action( 'vip_workflow_register_assistant_meta', __NAMESPACE__ . '\register_agent_meta' );

/**
 * Register the fact-check stage agent ability.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'vip_workflow_register_ability' ) || ! class_exists( StageAgent::class ) ) {
		return;
	}

	vip_workflow_register_ability(
		'workflow-agent-fact-check/fact-check',
		array(
			'label'               => __( 'Fact Check', 'workflow-agent-fact-check' ),
			'description'         => __( 'Flags unsupported or dubious factual claims by writing editorial notes on the blocks where they appear.', 'workflow-agent-fact-check' ),
			'category'            => 'vip-workflow',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to fact-check.', 'workflow-agent-fact-check' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'status', 'summary' ),
				'properties'           => array(
					'status'  => array(
						'type' => 'string',
						'enum' => array( 'pass', 'fail' ),
					),
					'summary' => array( 'type' => 'string' ),
					'issues'  => array(
						'type'        => 'array',
						'description' => __( 'Flagged claims for human review.', 'workflow-agent-fact-check' ),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\execute',
			'permission_callback' => __NAMESPACE__ . '\can_execute',
			'meta'                => array(
				'show_in_rest'          => true,
				'show_in_commands'      => false,
				'transition_eligible'   => false,
				'icon'                  => 'search',
				'type'                  => 'agent',
				'availability_callback' => __NAMESPACE__ . '\check_availability',
				'display_order'         => 35,
				'supports'              => array( 'workflow', 'stage' ),
				'stage_eligible'        => true,
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
		'workflow-agent-fact-check',
		array(
			'label'        => __( 'Fact Check', 'workflow-agent-fact-check' ),
			'description'  => __( 'Flags unsupported or dubious factual claims by writing editorial notes on the blocks where they appear.', 'workflow-agent-fact-check' ),
			'icon'         => 'search',
			'ability_ids'  => array( 'workflow-agent-fact-check/fact-check' ),
			'capabilities' => array( 'stage' ),
		)
	);
}

/**
 * Execute the fact-check agent.
 *
 * @param array|null $input Input parameters.
 * @return array|\WP_Error Result contract or error.
 */
function execute( ?array $input = null ) {
	$input   = $input ?? array();
	$post_id = (int) ( $input['post_id'] ?? 0 );

	if ( ! $post_id ) {
		return new \WP_Error( 'missing_post_id', __( 'A post_id is required.', 'workflow-agent-fact-check' ) );
	}

	$post = StageAgent::read_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$label    = __( 'Fact Check', 'workflow-agent-fact-check' );
	$blocks   = parse_blocks( $post['content'] );
	$numbered = StageAgent::number_blocks( $blocks );

	// Ground the check against real source material when available: the ideation
	// research the post was written from, or a web search on its topic. A post
	// with neither is checked un-grounded, but a lookup that actually fails (DB
	// or provider error) is surfaced — the stage must not pass by silently
	// skipping the grounding it depends on.
	$context = StageAgent::gather_source_context( $post_id, $post['title'] );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	$source_context = StageAgent::format_source_context( $context );
	$origin         = (string) $context['origin'];

	// Mint one unguessable pass token per run. The model is told to reply with
	// this token to signal a pass; author content that says "reply PASS" cannot
	// forge a token it never saw.
	$token = StageAgent::verdict_token();

	// Honor editorial engagement on prior findings. A resolved note means the
	// editor signed the claim off (treat it as fixed); a note with a human reply
	// means they added context, so re-check that specific claim against it and
	// either resolve it or post an updated reply. Blocks handled here are
	// excluded from the fresh scan so they are not blindly re-flagged. This loop
	// only plans (AI re-checks, no mutations) — the notes are resolved and
	// replies posted after the fresh write below, so a failed write never leaves
	// an engaged thread half-updated.
	$handled       = array();
	$to_resolve    = array();
	$to_reply      = array();
	$still_failing = array();
	foreach ( StageAgent::interactive_notes( $post_id, $blocks, $numbered, NOTE_MARKER ) as $note ) {
		$handled[ $note['number'] ] = true;

		if ( $note['resolved'] ) {
			continue;
		}

		$verdict = recheck_claim( $note, $label, $source_context, $origin, $token );
		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		if ( '' === $verdict ) {
			$to_resolve[] = $note['note_id'];
			continue;
		}

		$to_reply[ $note['note_id'] ] = $verdict;
		$still_failing[]              = $verdict;
	}

	/*
	 * 2. Fresh scan of the rest of the document.
	 *
	 * The ceiling has to cover the model's reasoning, not just the findings it
	 * writes out. Measured on a 20-block, ~3.8k-prompt-token article against
	 * claude-sonnet-5: reasoning ran ~3,900 tokens while the findings themselves
	 * came to ~480 — so the previous 1,500 was spent entirely on reasoning and the
	 * reply came back with no content at all, which is the bug this sizing fixes.
	 * The whole run measured 4,391; 8,000 is roughly 1.8x that, leaving room for a
	 * longer article to reason further and for an article with many more problems
	 * to list them all.
	 */
	$response = StageAgent::generate( build_prompt( $post['title'], $numbered, $post['content'], $token, $source_context, $origin ), 8000 );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$issue_map = array();
	if ( ! StageAgent::is_verdict( $response, $token ) ) {
		// Map each flagged claim to its block, keeping only real block numbers so a
		// hallucinated `BLOCK 99:` cannot silently drop every finding.
		$issue_map     = StageAgent::parse_block_issues( $response );
		$valid_numbers = array();
		foreach ( $numbered as $entry ) {
			$valid_numbers[ $entry['number'] ] = true;
		}
		$issue_map = array_intersect_key( $issue_map, $valid_numbers );

		// The model ignored the `BLOCK n:` format (or cited only unknown blocks):
		// fall back to a single note carrying the raw findings. If there is nothing
		// parseable at all, surface an error rather than pass a non-PASS response.
		if ( empty( $issue_map ) ) {
			$lines = StageAgent::parse_issue_lines( $response );
			if ( empty( $lines ) ) {
				return new \WP_Error( 'unparseable_result', __( 'The fact checker returned an unrecognized response.', 'workflow-agent-fact-check' ) );
			}

			$first_number = $numbered ? $numbered[0]['number'] : 1;
			$issue_map    = array( $first_number => $lines );
		}
	}

	// Blocks the editor already engaged with are resolved above, not re-flagged.
	$issue_map = array_diff_key( $issue_map, $handled );

	$issues = array();
	foreach ( $issue_map as $block_issues ) {
		foreach ( $block_issues as $issue ) {
			$issues[] = $issue;
		}
	}
	$fail_count = count( $issues ) + count( $still_failing );

	// On an overall pass, only write the clean-summary note when the editor has
	// not engaged with any prior finding — otherwise the resolved/replied notes
	// are the record, and a fresh summary would compete for the block anchor.
	$summary_note = ( 0 === $fail_count && empty( $handled ) )
		? __( 'No problematic factual claims found.', 'workflow-agent-fact-check' )
		: null;

	$written = StageAgent::write_block_notes( $post_id, $issue_map, $summary_note, $post['modified'], NOTE_MARKER, $label );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	// The fresh write landed; now apply the editorial-engagement outcomes.
	foreach ( $to_resolve as $resolve_id ) {
		StageAgent::resolve_note( $resolve_id );
	}
	foreach ( $to_reply as $reply_id => $reply_body ) {
		$replied = StageAgent::append_agent_reply( $post_id, $reply_id, $label, $reply_body, NOTE_MARKER );
		if ( is_wp_error( $replied ) ) {
			return $replied;
		}
	}

	if ( 0 === $fail_count ) {
		return StageAgent::result(
			'pass',
			empty( $handled )
				? __( 'No problematic factual claims found.', 'workflow-agent-fact-check' )
				: __( 'Flagged claims were resolved or signed off by an editor.', 'workflow-agent-fact-check' )
		);
	}

	// A flagged factual claim is a hard failure: the stage routes `fail` (e.g.
	// back to draft) so the post cannot advance with unverified claims.
	$all = array_merge( $issues, $still_failing );

	return StageAgent::result(
		'fail',
		sprintf(
			/* translators: %d: number of flagged claims. */
			_n( 'Flagged %d factual claim for correction.', 'Flagged %d factual claims for correction.', count( $all ), 'workflow-agent-fact-check' ),
			count( $all )
		),
		array( 'issues' => $all )
	);
}

/**
 * Re-check a single flagged claim in light of the editor's reply.
 *
 * @param  array  $note           An interactive_notes() entry (block text,
 *                                original finding, human replies).
 * @param  string $label          Agent label prefix (stripped from the concern).
 * @param  string $source_context Formatted ground-truth block, or '' when none.
 * @param  string $origin         Source origin ('ideation', 'web', or '').
 * @param  string $token          Per-run pass token the model must echo to pass.
 * @return string|\WP_Error '' when the context resolves the concern; otherwise
 *                          the updated one-line finding; WP_Error on AI failure.
 */
function recheck_claim( array $note, string $label, string $source_context, string $origin, string $token ) {
	$today   = current_time( 'F j, Y' );
	$concern = $note['finding'];
	$prefix  = $label . ': ';
	if ( str_starts_with( $concern, $prefix ) ) {
		$concern = substr( $concern, strlen( $prefix ) );
	}

	$editor_response = '' !== implode( '', $note['replies'] ) ? implode( "\n", $note['replies'] ) : '(no additional text)';

	$prompt = sprintf(
		"You are a rigorous fact checker. Today's date is %s; treat it as the present moment.\n\n%s" .
		"An editor has responded to a claim you flagged. Re-check ONLY this claim, taking their response into account.\n\n" .
		"CLAIM (from the article):\n%s\n\n" .
		"YOUR ORIGINAL CONCERN:\n%s\n\n" .
		"EDITOR'S RESPONSE:\n%s\n\n" .
		"If the response resolves the concern — it adds a source, corrects it, or explains why the claim is acceptable — reply with exactly: %s\n" .
		'Otherwise reply with one line stating what is still wrong or unsupported in light of their response. Do not simply repeat your original concern; engage with what they said.',
		$today,
		'' !== $source_context ? $source_context : '',
		StageAgent::wrap_untrusted( $note['block_text'], 'claim' ),
		$concern,
		$editor_response,
		$token
	);

	/*
	 * Re-checking one claim writes almost nothing — a pass token or a single line —
	 * so this ceiling is almost entirely reasoning budget. Reasoning on the
	 * comparable full-article check measured ~3,900 tokens and did not scale with
	 * how much text was being produced, so a ceiling under that is unusable no
	 * matter how short the answer is; 6,000 clears the measured figure by ~1.5x.
	 */
	$response = StageAgent::generate( $prompt, 6000 );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( StageAgent::is_verdict( $response, $token ) ) {
		return '';
	}

	$lines = StageAgent::parse_issue_lines( $response );

	return $lines ? $lines[0] : trim( $response );
}

/**
 * Build the fact-check prompt.
 *
 * When the post has named blocks, ask the model to map each problem to a block
 * number so notes can be anchored; otherwise fall back to a plain claim list.
 *
 * @param  string $title          Post title.
 * @param  array  $numbered       Output of StageAgent::number_blocks().
 * @param  string $content        Raw post content (used for block-less content).
 * @param  string $token          Per-run pass token the model must echo to pass.
 * @param  string $source_context Formatted ground-truth block, or '' when none.
 * @param  string $origin         Source origin ('ideation', 'web', or '').
 * @return string
 */
function build_prompt( string $title, array $numbered, string $content, string $token, string $source_context = '', string $origin = '' ): string {
	$today = current_time( 'F j, Y' );

	if ( '' !== $source_context ) {
		// Grounded: check the article against real source material. With the
		// ideation research the article was built on, "not supported by the
		// sources" is a legitimate finding; with web results (which are
		// incomplete) we only trust direct contradictions.
		$check = 'web' === $origin
			? "Check the article against the SOURCE MATERIAL above. Flag a statement only when it directly contradicts those results, or asserts a specific fact (name, number, date, quote, or event) that the results clearly refute. If the results do not cover a claim, do not flag it — they are incomplete. Also flag a statement that contradicts another statement in the same article.\n"
			: "Check the article against the SOURCE MATERIAL above. Flag any statement that (a) contradicts the source material, or (b) asserts a specific fact (name, number, date, quote, or event) that the source material does not support. Also flag a statement that contradicts another statement in the same article.\n";

		$guidance = sprintf(
			"You are a rigorous fact checker. Today's date is %s; treat it as the present moment.\n\n%s%s" .
			"Do not flag opinions, analysis, predictions, or hedged or subjective statements (for example \"critics argue\", \"may have altered\").\n\n",
			$today,
			$source_context,
			$check
		);
	} else {
		// Un-grounded: no source material, so anchor the model in the present and
		// aim it at verifiable errors rather than anything it merely cannot
		// personally confirm.
		$guidance = sprintf(
			"You are a rigorous fact checker. Today's date is %s; treat it as the present moment.\n\n" .
			"What is NOT an error:\n" .
			"- A claim is not false just because it postdates your training data or you cannot personally verify it. A real, recent event is not fictional, and absence of knowledge is not evidence of error. Never say an event \"has not happened\" when the article describes it as past or in progress and today's date allows it.\n" .
			"- Do not flag opinions, analysis, predictions, or hedged or subjective statements (for example \"critics argue\", \"seen by many\", \"may have altered\").\n\n" .
			"What IS an error — flag a statement only when it is verifiably wrong:\n" .
			"- It contradicts another statement in the same article.\n" .
			"- It is numerically or temporally impossible: a count, score, date, or total that does not add up or conflicts with how the article itself describes the event.\n" .
			"- It contradicts an enduring, well-established fact (history, geography, science, arithmetic) that does not depend on recent events.\n" .
			"- It misattributes a quote, statistic, event, or achievement to the wrong person, place, or time.\n\n",
			$today
		);
	}

	if ( ! empty( $numbered ) ) {
		$lines = array();
		foreach ( $numbered as $entry ) {
			$lines[] = sprintf( 'BLOCK %d: %s', $entry['number'], $entry['text'] );
		}

		return $guidance . sprintf(
			"Each numbered block below is one part of the article \"%s\".\n\n%s\n\n" .
			"Reply format:\n" .
			"- If no block contains a verifiable error, reply with exactly: %s\n" .
			"- Otherwise write one line per problem in the form: BLOCK <n>: <state what is wrong and the correct information>. Explain the error; do not simply repeat the claim. Use the same <n> for multiple problems in one block.\n" .
			'- Do not rewrite the article.',
			$title,
			StageAgent::wrap_untrusted( implode( "\n\n", $lines ), 'post body' ),
			$token
		);
	}

	return $guidance . sprintf(
		"Review this article for verifiable errors.\n\nARTICLE TITLE: %s\n\nARTICLE BODY:\n%s\n\n" .
		"Reply format:\n" .
		"- If you find no verifiable errors, reply with exactly: %s\n" .
		"- Otherwise list each problem on its own line, prefixed with '- ', stating what is wrong and the correct information. Explain the error; do not simply repeat the claim. Do not rewrite the article.",
		$title,
		StageAgent::wrap_untrusted( $content, 'post body' ),
		$token
	);
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
	return AiAvailability::for_selected_provider( array( __( 'Fact Check', 'workflow-agent-fact-check' ) ) );
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
			__( 'Post ID is required.', 'workflow-agent-fact-check' )
		);
	}

	$permission_error = \VIPWorkflow\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
	if ( $permission_error ) {
		return $permission_error;
	}

	return true;
}
