<?php
/**
 * Plugin Name: Workflow Agent: Guideline Review
 * Description: Stage-capable agent that reviews a post against the newsroom's own content guidelines and leaves a note on each block that departs from them. Never edits the copy.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-agent-guideline-review
 *
 * WHAT IT DOES, AND WHAT IT DELIBERATELY DOES NOT
 * ----------------------------------------------
 * It reads the guidelines the newsroom has written, reads the post a block at a
 * time, and leaves a note on each block that departs from them. It does not
 * rewrite anything. No word of the prose changes, no correction is applied, and
 * nothing is accepted on an editor's behalf.
 *
 * That restraint is the point. A proofreader that edits text is a different
 * product, and there are good ones. What a desk cannot buy off the shelf is a
 * reviewer that works from *their* standards — house style, legal lines,
 * sensitivity rules, the things an organisation argued about and wrote down —
 * and shows its reasoning next to the paragraph it is reasoning about, so an
 * editor can disagree with it.
 *
 * The guidelines come from the site's own guideline rows, which the newsroom
 * authors and can read. A reviewer whose rules cannot be inspected is one an
 * editor has to either obey or ignore, and they will pick ignore.
 *
 * WHY NOTES RATHER THAN A VERDICT
 * -------------------------------
 * A guideline breach is a judgement, and judgement belongs to the desk. Findings
 * land as block notes an editor can resolve or dismiss, in the same place they
 * already read every other note. The result also carries `issues`, so a sequence
 * that wants to hold a transition on this can — but that is the desk's decision
 * to make in the sequence, not this agent's to impose.
 *
 * NOTHING PASSES UNREVIEWED
 * -------------------------
 * Every way this agent can end without having read the copy is an error rather
 * than a pass: no guidelines configured, a post longer than one run can cover, a
 * reply it cannot parse. A pass is a statement that the guidelines were applied,
 * and on a gated transition it is what lets the post move — so it is only ever
 * returned when they actually were.
 *
 * A NOTE ON CONSISTENCY
 * ---------------------
 * Two runs over the same copy do not always return the same findings. The
 * substantive ones recur; the marginal ones come and go. Stating the role as the
 * opening instruction reduces the drift; it does not remove it — measured over
 * three runs on one article this still produced three different sets.
 *
 * That is a property of asking a model to apply a measurable rule, not of the
 * prompt. Most of these guidelines are countable — an intro of about twenty
 * words, a caption of twelve to thirteen, the first image after the second
 * paragraph — and a model estimates a count rather than taking one, which is why
 * the same intro is 26 words on one run and 27 on the next.
 *
 * The fix is to move the model to the other end of the process: have it read the
 * prose once and emit deterministic rules from it — a threshold, a field, a
 * position — which are then applied in code on every review. The newsroom still
 * authors prose, so the rules stay theirs and stay legible; the counting stops
 * being an inference. The model keeps only the judgements that genuinely need
 * one, like whether a sentence sneers.
 *
 * Until that exists, a desk should read this as a reviewer that raises good
 * points rather than one that returns a fixed checklist.
 *
 * @package WorkflowAgentGuidelineReview
 */

declare( strict_types=1 );

namespace WorkflowAgentGuidelineReview;

use VIPWorkflows\Abilities\Agents\StageAgent;
use VIPWorkflows\Abilities\AiAvailability;
use VIPWorkflows\Abilities\Availability;
use VIPWorkflows\Integrations\GuidelineContextProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comment-meta key tagging notes authored by this agent.
 *
 * Its own marker, so a re-run replaces this agent's notes and leaves every other
 * agent's alone.
 */
const NOTE_MARKER = '_vip_guideline_review_agent';

/**
 * Most blocks sent to the model in one request.
 *
 * A long feature in a single prompt pushes the findings off the end of the
 * reply. Forty is what one pass reads without crowding them out.
 */
const MAX_BLOCKS = 40;

/**
 * Most requests one review may make.
 *
 * The ceiling on a single run's cost. A post above `MAX_BLOCKS * MAX_PASSES`
 * blocks is refused rather than partly reviewed — see `execute()`.
 */
const MAX_PASSES = 5;

/**
 * Token ceiling for one pass: the reasoning floor plus room for the findings.
 *
 * Thinking models bill their reasoning against the same ceiling as their reply
 * and are not told what it is, so a budget sized against the answer alone is
 * spent entirely on reasoning and comes back with no content at all. Findings
 * are one line each and a long feature rarely produces more than a dozen; the
 * 6,000 below is the measured reasoning floor, and the rest is the reply.
 */
const MAX_TOKENS = 7500;

add_action( 'vip_workflows_register_abilities', __NAMESPACE__ . '\register' );
add_action( 'vip_workflows_register_assistant_meta', __NAMESPACE__ . '\register_agent_meta' );

/**
 * Register the guideline-review stage agent ability.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'vip_workflows_register_ability' ) || ! class_exists( StageAgent::class ) ) {
		return;
	}

	vip_workflows_register_ability(
		'workflow-agent-guideline-review/guideline-review',
		array(
			'label'               => __( 'Guideline Review', 'workflow-agent-guideline-review' ),
			'description'         => __( 'Reviews the post against your content guidelines and leaves a note on each block that departs from them. Never edits the copy.', 'workflow-agent-guideline-review' ),
			'category'            => 'vip-workflows',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Post to review.', 'workflow-agent-guideline-review' ),
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
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Guideline departures left as notes.', 'workflow-agent-guideline-review' ),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\execute',
			'permission_callback' => __NAMESPACE__ . '\can_execute',
			'meta'                => array(
				'show_in_rest'          => true,
				'show_in_commands'      => true,
				'icon'                  => 'text-page',
				'type'                  => 'agent',
				'availability_callback' => __NAMESPACE__ . '\check_availability',
				'display_order'         => 50,
				'supports'              => array( 'workflow', 'stage' ),
				'stage_eligible'        => true,

				/*
				 * A sequence may gate a transition on this. That is safe only
				 * because a pass cannot be talked into existence: the clean
				 * verdict is a per-run token the article never sees, and every
				 * path that ends without a completed review returns an error.
				 */
				'transition_eligible'   => true,
				'thinking_message'      => __( 'Reading the guidelines and reviewing the copy…', 'workflow-agent-guideline-review' ),
				'annotations'           => array(

					/*
					 * Not readonly: it writes notes, and anchors them by adding a
					 * noteId to the block. Not destructive: notes are additive and
					 * resolvable, and the prose is never touched.
					 */
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
		'workflow-agent-guideline-review',
		array(
			'label'        => __( 'Guideline Review', 'workflow-agent-guideline-review' ),
			'description'  => __( 'Reviews the post against your content guidelines and leaves a note on each block that departs from them. Never edits the copy.', 'workflow-agent-guideline-review' ),
			'icon'         => 'text-page',
			'ability_ids'  => array( 'workflow-agent-guideline-review/guideline-review' ),
			'capabilities' => array( 'stage' ),
		)
	);
}

/**
 * Whether AI text generation is configured for this agent.
 *
 * Asks about the admin-selected provider, because `StageAgent::generate()`
 * resolves its model through it. Without this the agent presents as working on
 * an unconfigured site and fails only once a post reaches the stage.
 *
 * @return bool|Availability True when generation is configured, otherwise the unmet requirements.
 */
function check_availability(): bool|Availability {
	return AiAvailability::for_selected_provider( array( __( 'Guideline Review', 'workflow-agent-guideline-review' ) ) );
}

/**
 * Permission callback.
 *
 * Scoped to the post the agent will act on. The read path enforces this too,
 * but the callback is what core's abilities endpoint, WP-CLI and MCP consult.
 *
 * @param  array $input Ability input.
 * @return bool|\WP_Error
 */
function can_execute( array $input ): bool|\WP_Error {
	if ( empty( $input['post_id'] ) ) {
		return new \WP_Error(
			'missing_post_id',
			__( 'Post ID is required.', 'workflow-agent-guideline-review' )
		);
	}

	$permission_error = \VIPWorkflows\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
	if ( $permission_error ) {
		return $permission_error;
	}

	return true;
}

/**
 * Review the post against the guidelines and leave notes.
 *
 * @param  array|null $input Ability input; requires post_id.
 * @return array|\WP_Error Result contract or error.
 */
function execute( ?array $input = null ) {
	$input   = $input ?? array();
	$post_id = (int) ( $input['post_id'] ?? 0 );

	if ( ! $post_id ) {
		return new \WP_Error( 'missing_post_id', __( 'A post_id is required.', 'workflow-agent-guideline-review' ) );
	}

	$post = StageAgent::read_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$rules = guideline_rules( $post_id );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}

	$label    = __( 'Guideline Review', 'workflow-agent-guideline-review' );
	$blocks   = parse_blocks( (string) $post['content'] );
	$numbered = with_block_names( StageAgent::number_blocks( $blocks ), $blocks );

	/*
	 * Mint one unguessable pass token per run. The model is told to reply with
	 * this token to report a clean article; copy that says "reply with an empty
	 * findings list" cannot forge a token it never saw. The article itself is
	 * fenced as untrusted data on top of that.
	 */
	$token = StageAgent::verdict_token();

	/*
	 * Classic and free-form content parses into blocks with no block name, which
	 * numbering skips. Reviewing nothing and calling it a pass would tell an
	 * editor their guidelines had been applied to copy no model ever read, so
	 * that content is reviewed whole instead; its findings land as one
	 * post-level note, which is all an unnamed block can carry.
	 */
	if ( array() === $numbered ) {
		return review_freeform( $post_id, $post, $rules, $token, $label );
	}

	/*
	 * Every block is reviewed, in as many passes as it takes. Reviewing the
	 * first N and passing hides every departure below the fold — the reader of
	 * a pass has no way to tell which half was read. A post too long for the
	 * pass ceiling is refused rather than partly reviewed.
	 */
	$total = count( $numbered );
	if ( $total > MAX_BLOCKS * MAX_PASSES ) {
		return new \WP_Error(
			'guideline_review_post_too_long',
			sprintf(
				/* translators: 1: number of blocks in the post, 2: number of blocks the agent can review in one run. */
				__( 'This post has %1$d blocks and this review covers at most %2$d in one run. Reviewing part of a post and reporting a pass would be misleading, so nothing was reviewed. Split the post, or raise the pass limit in the agent.', 'workflow-agent-guideline-review' ),
				$total,
				MAX_BLOCKS * MAX_PASSES
			),
			array( 'status' => 400 )
		);
	}

	$structure = structure_list( $numbered );
	$issue_map = array();

	foreach ( array_chunk( $numbered, MAX_BLOCKS ) as $batch ) {
		$response = StageAgent::generate(
			build_prompt( $rules, $structure, $batch, $token, $total ),
			MAX_TOKENS
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( StageAgent::is_verdict( $response, $token ) ) {
			continue;
		}

		$found = map_findings( $response, $numbered );

		/*
		 * Not the pass token, and nothing parseable in it: the reply is
		 * unrecognised, not clean. Reporting a pass here is the one outcome that
		 * cannot be recovered from downstream — it reads as "your guidelines were
		 * applied" and, on a gated transition, moves the post.
		 */
		if ( array() === $found ) {
			return new \WP_Error(
				'guideline_review_unparseable',
				__( 'The guideline review returned a response that could not be read as findings or as a clean result. Nothing was written and the post was not reviewed.', 'workflow-agent-guideline-review' ),
				array( 'status' => 502 )
			);
		}

		foreach ( $found as $number => $issues ) {
			$issue_map[ $number ] = array_merge( $issue_map[ $number ] ?? array(), $issues );
		}
	}

	$issues = array();
	foreach ( $issue_map as $block_issues ) {
		foreach ( $block_issues as $issue ) {
			$issues[] = $issue;
		}
	}

	$count        = count( $issues );
	$clean        = __( 'No guideline departures found.', 'workflow-agent-guideline-review' );
	$summary_note = 0 === $count ? $clean : null;

	$written = StageAgent::write_block_notes( $post_id, $issue_map, $summary_note, $post['modified'], NOTE_MARKER, $label );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	if ( 0 === $count ) {
		return StageAgent::result( 'pass', $clean );
	}

	return StageAgent::result(
		'fail',
		sprintf(
			/* translators: %d: number of guideline departures found. */
			_n(
				'%d guideline departure left as a note for review.',
				'%d guideline departures left as notes for review.',
				$count,
				'workflow-agent-guideline-review'
			),
			$count
		),
		array( 'issues' => $issues )
	);
}

/**
 * Review classic or free-form content, which has no blocks to anchor to.
 *
 * The body goes to the model in one piece and the findings come back as a list
 * rather than as block references. They are written as a single post-level note,
 * because an unnamed block cannot carry a note anchor.
 *
 * @param  int    $post_id Post being reviewed.
 * @param  array  $post    Output of StageAgent::read_post().
 * @param  array  $rules   Guideline rules.
 * @param  string $token   Per-run pass token the model must echo to pass.
 * @param  string $label   Human label prefixed to each note body.
 * @return array|\WP_Error
 */
function review_freeform( int $post_id, array $post, array $rules, string $token, string $label ) {
	$text = trim( (string) wp_strip_all_tags( (string) $post['content'] ) );

	if ( '' === $text ) {
		return StageAgent::result( 'pass', __( 'There is no content to review.', 'workflow-agent-guideline-review' ) );
	}

	$response = StageAgent::generate( build_freeform_prompt( $rules, $text, $token ), MAX_TOKENS );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$clean = __( 'No guideline departures found.', 'workflow-agent-guideline-review' );

	if ( StageAgent::is_verdict( $response, $token ) ) {
		$written = StageAgent::write_block_notes( $post_id, array(), $clean, $post['modified'], NOTE_MARKER, $label );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return StageAgent::result( 'pass', $clean );
	}

	$issues = StageAgent::parse_issue_lines( $response );

	if ( array() === $issues ) {
		return new \WP_Error(
			'guideline_review_unparseable',
			__( 'The guideline review returned a response that could not be read as findings or as a clean result. Nothing was written and the post was not reviewed.', 'workflow-agent-guideline-review' ),
			array( 'status' => 502 )
		);
	}

	$written = StageAgent::write_block_notes( $post_id, array( 1 => $issues ), null, $post['modified'], NOTE_MARKER, $label );
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return StageAgent::result(
		'fail',
		sprintf(
			/* translators: %d: number of guideline departures found. */
			_n(
				'%d guideline departure left as a note for review.',
				'%d guideline departures left as notes for review.',
				count( $issues ),
				'workflow-agent-guideline-review'
			),
			count( $issues )
		),
		array( 'issues' => $issues )
	);
}

/**
 * The newsroom's guidelines, or an error explaining why there are none.
 *
 * No guidelines is not a pass. Reporting "nothing to flag" when the reviewer had
 * nothing to review against reads as approval, and on a gated transition it
 * would wave the post through. An error says what is actually wrong, which is a
 * configuration problem someone can fix.
 *
 * @param  int $post_id Post being reviewed; guidelines may be scoped to it.
 * @return array|\WP_Error Rule definitions, or an error when none are available.
 */
function guideline_rules( int $post_id ) {
	if ( ! class_exists( GuidelineContextProvider::class ) ) {
		return new \WP_Error(
			'guideline_review_no_provider',
			__( 'Content guidelines are not available in this version of VIP Workflows, so there is nothing to review against.', 'workflow-agent-guideline-review' ),
			array( 'status' => 400 )
		);
	}

	$rules = GuidelineContextProvider::get_editorial_alignment_rules( $post_id );

	if ( ! is_array( $rules ) || array() === $rules ) {
		return new \WP_Error(
			'guideline_review_no_guidelines',
			__( 'No content guidelines are available to review against. Add guidelines before running this review.', 'workflow-agent-guideline-review' ),
			array( 'status' => 400 )
		);
	}

	return $rules;
}

/**
 * The prompt for one pass over a batch of blocks.
 *
 * Blocks are numbered so the model can point at one, which is what makes a
 * finding anchorable. It is told to name the guideline it is applying, because a
 * note saying only "this breaks house style" is one an editor cannot act on or
 * argue with.
 *
 * The article is fenced as untrusted data and the reply format is stated after
 * the fence, so a directive planted in the copy is read as part of the copy.
 *
 * @param  array  $rules     Guideline rules.
 * @param  string $structure Running order of every block in the post.
 * @param  array  $batch     Numbered blocks in this pass.
 * @param  string $token     Per-run pass token the model must echo to pass.
 * @param  int    $total     Number of blocks in the whole post.
 * @return string
 */
function build_prompt( array $rules, string $structure, array $batch, string $token, int $total ): string {
	$body = '';

	foreach ( $batch as $block ) {
		$text  = trim( (string) $block['text'] );
		$body .= sprintf(
			"BLOCK %d (%s):\n%s%s\n\n",
			(int) $block['number'],
			(string) ( $block['name'] ?? 'unknown' ),
			(string) ( $block['details'] ?? '' ),
			'' === $text ? '' : '  text: ' . $text
		);
	}

	$scope = count( $batch ) < $total
		? sprintf(
			"This pass covers blocks %d to %d of the %d in the article. The running order below lists all of them, so you can judge where a block sits; report findings only on the blocks shown in full.\n\n",
			(int) $batch[0]['number'],
			(int) $batch[ count( $batch ) - 1 ]['number'],
			$total
		)
		: '';

	$guidelines = guidelines_text( $rules );
	$article    = StageAgent::wrap_untrusted( $body, 'article' );

	$guidance   = not_a_finding();

	return role() . <<<PROMPT
You are reviewing an article against a newsroom's content guidelines.

Each block is labelled with its block type, e.g. BLOCK 3 (core/image). Guidelines
headed "Block: <type>" apply only to blocks of that type. Guidelines about where
blocks sit relative to each other are about the order and types you see below.

{$scope}=== THE GUIDELINES ===
{$guidelines}
=== THE ARTICLE, BY BLOCK ===
{$article}

=== THE STRUCTURE, IN ORDER ===
{$structure}
Each block lists its own attributes — a caption, alt text, a credit. `(none)`
means that attribute is genuinely absent and may be a departure. An attribute
shown with a value is present: do not report it as missing.

{$guidance}
Use the structure list for guidelines about where blocks sit — where the first
image belongs, where subheads start and how often they recur, what an article may
open on. Anchor such a finding to the block that is in the wrong place.

Reply format:
- If the article follows the guidelines throughout, reply with exactly: {$token}
- Otherwise write one line per departure, in the form: BLOCK <n>: <guideline in a few words> — <what is wrong, in one sentence an editor can act on>. Quote the offending phrase where there is one. Use the same <n> for several departures in one block.
- Do not rewrite the article, and do not reply with anything else.
PROMPT;
}

/**
 * The prompt for classic content, which has no block numbers to point at.
 *
 * @param  array  $rules Guideline rules.
 * @param  string $text  The article body as plain text.
 * @param  string $token Per-run pass token the model must echo to pass.
 * @return string
 */
function build_freeform_prompt( array $rules, string $text, string $token ): string {
	$guidelines = guidelines_text( $rules );
	$article    = StageAgent::wrap_untrusted( $text, 'article' );

	$guidance   = not_a_finding();

	return role() . <<<PROMPT
You are reviewing an article against a newsroom's content guidelines. This
article is not made of blocks, so guidelines written for a particular block type
or for where blocks sit relative to each other do not apply to it.

=== THE GUIDELINES ===
{$guidelines}
=== THE ARTICLE ===
{$article}

{$guidance}
Reply format:
- If the article follows the guidelines throughout, reply with exactly: {$token}
- Otherwise write one line per departure, each starting with '- ', naming the guideline in a few words and what is wrong in one sentence an editor can act on. Quote the offending phrase where there is one.
- Do not rewrite the article, and do not reply with anything else.
PROMPT;
}

/**
 * The standing instruction, stated as the role rather than as an item in a list
 * the model has to remember at the end of a long packet.
 *
 * This is the one rule it kept breaking.
 *
 * @return string
 */
function role(): string {
	return 'You are a copy desk reviewer applying a fixed set of house guidelines. '
		. 'You report only departures that require an edit. You never report that something is correct, '
		. 'and you never report the absence of an optional feature — many of these guidelines describe '
		. 'what the newsroom usually does, expressed as proportions, and a tendency is not a requirement. '
		. "You are deterministic: the same article and the same guidelines always produce the same findings.\n\n";
}

/**
 * What does not count as a finding, spelled out.
 *
 * Shared by both prompts so the block and classic paths hold the same line on
 * what is worth an editor's attention.
 *
 * @return string
 */
function not_a_finding(): string {
	return <<<GUIDANCE
Every finding must be something an editor would change. Before reporting one, ask
what the fix is. If there is no fix, it is not a finding.

This is not a finding, and must never be reported:
  "The headline does not include a quoted phrase, which is common in this style."
Nothing is wrong there. A guideline saying a feature is common, typical or used in
some proportion of articles describes the corpus; it never requires that feature.

That rules out three things in particular:

1. Never report that the article follows a guideline. Compliance is not a finding.
2. Never report the absence of an optional feature. Many guidelines describe what
   the newsroom usually does — "86% carry a caption", "about 1 in 5 use subheads".
   A proportion describes the corpus; it is not a requirement to be met. Not doing
   an optional thing is never a departure.
3. Never report anything the guidelines do not cover — not spelling, not grammar,
   not preferences you hold but cannot point to above.


GUIDANCE;
}

/**
 * Format the guideline rows for the prompt.
 *
 * @param  array $rules Guideline rules.
 * @return string
 */
function guidelines_text( array $rules ): string {
	$guidelines = '';

	foreach ( $rules as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$guidelines .= sprintf(
			"## %s\n%s\n\n",
			(string) ( $rule['name'] ?? __( 'Guideline', 'workflow-agent-guideline-review' ) ),
			(string) ( $rule['rule'] ?? '' )
		);
	}

	return $guidelines;
}

/**
 * A plain running order of the whole post.
 *
 * Placement rules are checked against a list rather than against the model's own
 * count of a long document. "The first image sits after the second paragraph" is
 * unanswerable without one, which is why placement went unreported. It covers
 * every block, not just the ones in the current pass, so position still means
 * something when a long post is reviewed in several passes.
 *
 * @param  array $numbered Every named block in the post.
 * @return string
 */
function structure_list( array $numbered ): string {
	$structure = '';
	$counts    = array();

	foreach ( $numbered as $block ) {
		$name            = (string) ( $block['name'] ?? 'unknown' );
		$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
		$structure      .= sprintf(
			"%d. %s (%s #%d)\n",
			(int) $block['number'],
			$name,
			$name,
			$counts[ $name ]
		);
	}

	return $structure;
}

/**
 * Attach each numbered block's type and attributes to it.
 *
 * Half the packet is written per block type — headings, images and paragraphs
 * each have their own rules — and none of it can be applied to a block whose
 * type the model cannot see. Without this the model guessed, and applied the
 * headline rule to a subheading.
 *
 * @param  array $numbered Output of StageAgent::number_blocks().
 * @param  array $blocks   The parsed blocks it was built from.
 * @return array
 */
function with_block_names( array $numbered, array $blocks ): array {
	foreach ( $numbered as $i => $entry ) {
		$index = (int) ( $entry['index'] ?? -1 );
		$block = $blocks[ $index ] ?? array();

		$numbered[ $i ]['name']    = (string) ( $block['blockName'] ?? 'unknown' );
		$numbered[ $i ]['details'] = block_details( $block );
	}

	return $numbered;
}

/**
 * The attributes of a block that guidelines actually talk about.
 *
 * Rendered text is not the whole block. An image's caption, credit and alt text
 * live in its attributes and its markup, and a reviewer sent only the text sees
 * an empty block — so it reported all three as missing on an image that had all
 * three. Absent is reported as `(none)` rather than omitted, so the difference
 * between "no alt text" and "not shown to you" is visible.
 *
 * @param  array $block Parsed block.
 * @return string Lines describing the block, or '' when it has none.
 */
function block_details( array $block ): string {
	$details = array();
	$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

	if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
		$caption = image_caption( $block );

		$details['caption'] = '' === $caption ? '(none)' : $caption;
		$details['alt']     = image_alt( block_html( $block ) );

		/*
		 * The credit is not its own field — the guidelines describe it as
		 * `Credit: Source` appended to the caption, so that is where to look.
		 */
		$details['credit'] = ( '' !== $caption && false !== stripos( $caption, 'credit:' ) )
			? trim( substr( $caption, (int) stripos( $caption, 'credit:' ) ) )
			: '(none)';
	}

	foreach ( $attrs as $key => $value ) {
		if ( in_array( $key, array( 'metadata', 'caption', 'id', 'className' ), true ) ) {
			continue;
		}

		if ( is_scalar( $value ) && '' !== (string) $value ) {
			$details[ $key ] = (string) $value;
		}
	}

	$out = '';

	foreach ( $details as $key => $value ) {
		$out .= sprintf( "  %s: %s\n", $key, mb_substr( (string) $value, 0, 200 ) );
	}

	return $out;
}

/**
 * An image block's caption, from wherever that block keeps it.
 *
 * A caption typed into the editor is stored in the block's markup, inside
 * `<figcaption>`; only some images carry one in `attrs.caption`. Reading the
 * attribute alone therefore reports "(none)" on the ordinary case — an image
 * with a caption, told it has none, against a guideline that requires one.
 *
 * @param  array $block Parsed block.
 * @return string The caption as plain text, or '' when there is none.
 */
function image_caption( array $block ): string {
	$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$caption = trim( wp_strip_all_tags( (string) ( $attrs['caption'] ?? '' ) ) );

	if ( '' !== $caption ) {
		return $caption;
	}

	if ( preg_match( '#<figcaption\b[^>]*>(.*?)</figcaption>#is', block_html( $block ), $matches ) ) {
		return trim( wp_strip_all_tags( $matches[1] ) );
	}

	return '';
}

/**
 * A block's own markup.
 *
 * @param  array $block Parsed block.
 * @return string
 */
function block_html( array $block ): string {
	$html = (string) ( $block['innerHTML'] ?? '' );

	if ( '' === trim( $html ) && ! empty( $block['innerContent'] ) ) {
		$html = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
	}

	return $html;
}

/**
 * The alt text on the first image in a block's markup.
 *
 * @param  string $html Block inner HTML.
 * @return string The alt text, or '(none)'.
 */
function image_alt( string $html ): string {
	if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
		return '(unknown)';
	}

	$tags = new \WP_HTML_Tag_Processor( $html );

	while ( $tags->next_tag( 'IMG' ) ) {
		$alt = trim( (string) $tags->get_attribute( 'alt' ) );

		return '' === $alt ? '(none)' : $alt;
	}

	return '(none)';
}

/**
 * Turn a model reply into a block-number => issues map.
 *
 * A finding naming a block that is not in the post is dropped rather than
 * relocated: a note anchored to the wrong paragraph is worse than a missing one,
 * because an editor cannot tell it is misplaced. A reply whose findings all name
 * blocks that do not exist therefore maps to nothing, and the caller treats that
 * as an unreadable reply rather than as a clean article.
 *
 * @param  string $response Raw model reply.
 * @param  array  $numbered Every named block in the post.
 * @return array<int, string[]>
 */
function map_findings( string $response, array $numbered ): array {
	$valid = array();
	foreach ( $numbered as $entry ) {
		$valid[ (int) $entry['number'] ] = true;
	}

	return array_intersect_key( StageAgent::parse_block_issues( $response ), $valid );
}
