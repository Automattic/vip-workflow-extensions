<?php
/**
 * Editorial Alignment Checker Tool.
 *
 * @package WorkflowToolEditorialAlignment
 */

declare( strict_types=1 );

namespace WorkflowToolEditorialAlignment;

use VIPWorkflow\AI\AiInference;
use VIPWorkflow\Abilities\AiAvailability;
use VIPWorkflow\Abilities\Availability;
use VIPWorkflow\Integrations\LlmJsonGenerator;
use VIPWorkflow\Integrations\LlmTextGenerator;

/**
 * AI-powered editorial alignment validator using the AI provider configured in VIP Workflow.
 */
class EditorialAlignmentChecker {

	/**
	 * Validation prompt template.
	 *
	 * Available variables: {content}, {rule_name}, {rule_text}
	 */
	public const VALIDATION_PROMPT = 'You are an editorial compliance expert. Evaluate if the following content follows the editorial rule.

Rule Name: {rule_name}
Rule: {rule_text}

Content to evaluate:
{content}

Respond in JSON format:
{
  "compliant": true/false,
  "explanation": "Brief explanation of compliance or specific issues found",
  "examples": ["specific examples from content if non-compliant"]
}';

	/**
	 * Register the ability with WordPress.
	 */
	public static function register(): void {
		// Registered through the VIP Workflow wrapper, not core's
		// wp_register_ability(): only the wrapper sets ability_class, and only a
		// VIPWorkflow\Abilities\Ability consults availability_callback.
		if ( ! function_exists( 'vip_workflow_register_ability' ) ) {
			return;
		}

		vip_workflow_register_ability(
			'workflow-tool-editorial-alignment/editorial-alignment-checker',
			array(
				'label'               => __( 'Editorial Alignment Checker', 'workflow-tool-editorial-alignment' ),
				'description'         => __( 'Validate content against the Gutenberg/Core content guidelines.', 'workflow-tool-editorial-alignment' ),
				'category'            => 'vip-workflow',
				'input_schema'        => self::get_input_schema(),
				'output_schema'       => self::get_output_schema(),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_execute' ),
				'meta'                => array(
					'show_in_rest'          => true,
					'show_in_commands'      => true,
					'icon'                  => 'yes-alt',
					'type'                  => 'validator',
					'supports'              => array( 'workflow' ),
					'transition_eligible'   => true,
					'settings_schema'       => array(
						'validation_mode' => array(
							'type'        => 'string',
							'enum'        => array( 'soft', 'hard' ),
							'default'     => 'soft',
							'label'       => __( 'Validation mode', 'workflow-tool-editorial-alignment' ),
							'description' => __( 'Hard mode blocks transitions on rule failures.', 'workflow-tool-editorial-alignment' ),
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
	 * Whether AI text generation is configured for this tool.
	 *
	 * Asks about the admin-selected provider, matching `validate_rule()`, which
	 * generates through `AiInference`. The gate and the generation call therefore
	 * name the same provider by construction.
	 *
	 * @since 0.0.1
	 *
	 * @return bool|Availability True when generation is configured, otherwise the unmet requirements.
	 */
	public static function check_availability(): bool|Availability {
		return AiAvailability::for_selected_provider(
			array( __( 'Editorial Alignment Checker', 'workflow-tool-editorial-alignment' ) )
		);
	}

	/**
	 * Get input schema.
	 *
	 * @return array
	 */
	private static function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The post ID to validate.', 'workflow-tool-editorial-alignment' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => __( 'Raw content (alternative to post_id).', 'workflow-tool-editorial-alignment' ),
				),
			),
		);
	}

	/**
	 * Get output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => array( 'status', 'summary' ),
			'properties'           => array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'pass', 'warning', 'fail' ),
					'description' => __( 'Overall validation status.', 'workflow-tool-editorial-alignment' ),
				),
				'summary'  => array(
					'type'        => 'string',
					'description' => __( 'Summary of validation results.', 'workflow-tool-editorial-alignment' ),
				),
				'results'  => array(
					'type'        => 'array',
					'description' => __( 'Detailed results for each rule.', 'workflow-tool-editorial-alignment' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'rule_name'   => array(
								'type'        => 'string',
								'description' => __( 'Rule name.', 'workflow-tool-editorial-alignment' ),
							),
							'compliant'   => array(
								'type'        => 'boolean',
								'description' => __( 'Whether content is compliant.', 'workflow-tool-editorial-alignment' ),
							),
							'explanation' => array(
								'type'        => 'string',
								'description' => __( 'AI explanation of result.', 'workflow-tool-editorial-alignment' ),
							),
							'examples'    => array(
								'type'        => 'array',
								'description' => __( 'Specific examples from content.', 'workflow-tool-editorial-alignment' ),
								'items'       => array(
									'type' => 'string',
								),
							),
						),
					),
				),
				'analysis' => array(
					'type'        => 'object',
					'description' => __( 'Additional analysis data.', 'workflow-tool-editorial-alignment' ),
				),
			),
		);
	}

	/**
	 * Execute the editorial alignment checker.
	 *
	 * @param array|null $input Input parameters.
	 * @return array|\WP_Error Result data or error.
	 */
	public static function execute( ?array $input = null ) {
		$input   = $input ?? array();
		$content = '';

		// Get content from post or input.
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( $input['post_id'] );
			if ( $post ) {
				$content = $post->post_content;
			}
		} elseif ( ! empty( $input['content'] ) ) {
			$content = $input['content'];
		}

		if ( empty( $content ) ) {
			return new \WP_Error(
				'no_content',
				__( 'No content to validate.', 'workflow-tool-editorial-alignment' )
			);
		}

		$options = \VIPWorkflow\Abilities\AbilitySettings::get_instance()->get_options( 'workflow-tool-editorial-alignment/editorial-alignment-checker' );
		$mode    = $options['validation_mode'] ?? 'soft';
		$rules   = self::get_rules( (int) ( $input['post_id'] ?? 0 ) );

		if ( empty( $rules ) ) {
			return new \WP_Error(
				'no_rules',
				__( 'No Gutenberg/Core content guidelines are available.', 'workflow-tool-editorial-alignment' )
			);
		}

		// Strip blocks and HTML, normalize whitespace.
		$plain = wp_strip_all_tags( do_shortcode( $content ) );
		$plain = preg_replace( '/\s+/', ' ', $plain );
		$plain = trim( $plain );

		if ( empty( $plain ) ) {
			return new \WP_Error(
				'empty_content',
				__( 'Content contains no extractable text.', 'workflow-tool-editorial-alignment' )
			);
		}

		/*
		 * Resolve the model once for the whole run. This replaced an
		 * `AiClient::isConfigured()` call, which validated the key by listing models
		 * over the network on every execution; the ability's availability_callback
		 * now reports an unconfigured provider before the user ever runs the tool.
		 * What is left is the one thing the gate cannot promise: that the resolver
		 * returned a model.
		 */
		$model = AiInference::get_instance()->model();

		if ( null === $model ) {
			return new \WP_Error(
				'ai_not_configured',
				__( 'AI text generation is not configured on this site.', 'workflow-tool-editorial-alignment' )
			);
		}

		// Validate against each rule.
		$results      = array();
		$passed       = 0;
		$failed       = 0;
		$debug_info   = array(
			'prompts'   => array(),
			'responses' => array(),
		);

		foreach ( $rules as $rule ) {
			$rule_name = $rule['name'] ?? 'Unnamed Rule';
			$rule_text = $rule['rule'] ?? '';

			// Skip rules with empty text (don't count them).
			if ( empty( $rule_text ) ) {
				continue;
			}

			$validation = self::validate_rule( $model, $plain, $rule_name, $rule_text );

			if ( is_wp_error( $validation ) ) {
				$results[] = array(
					'rule_name'   => $rule_name,
					'compliant'   => false,
					'explanation' => $validation->get_error_message(),
					'examples'    => array(),
				);
				$failed++;
				
				// Add debug info.
				$debug_info['prompts'][ $rule_name ]   = 'Error: ' . $validation->get_error_message();
				$debug_info['responses'][ $rule_name ] = 'N/A';
			} else {
				// Store debug info if available (before adding to results).
				if ( ! empty( $validation['_debug'] ) ) {
					$debug_info['prompts'][ $rule_name ]   = $validation['_debug']['prompt'] ?? '';
					$debug_info['responses'][ $rule_name ] = $validation['_debug']['response'] ?? '';
					
					// Remove debug from result (internal only) BEFORE appending.
					unset( $validation['_debug'] );
				}
				
				$results[] = $validation;
				if ( $validation['compliant'] ) {
					$passed++;
				} else {
					$failed++;
				}
			}
		}

		// Calculate total validated rules (only non-empty ones).
		$total_rules = $passed + $failed;

		// Determine overall status.
		$all_passed = ( 0 === $failed );
		$status     = $all_passed ? 'pass' : ( 'hard' === $mode ? 'fail' : 'warning' );

		// Build summary and issues array for proper VIP Workflow display.
		$summary = sprintf(
			'%d of %d editorial rules passed.',
			$passed,
			$total_rules
		);

		if ( ! $all_passed && 'hard' === $mode ) {
			$summary .= ' ⚠️ Hard validation mode active - please fix failures before proceeding.';
		}

		// Build issues array - each rule gets an entry.
		$issues = array();
		foreach ( $results as $result ) {
			$icon        = $result['compliant'] ? '✅' : '❌';
			$status_text = $result['compliant'] ? 'PASSED' : 'FAILED';
			
			$message = sprintf(
				'%s %s: %s — %s',
				$icon,
				$result['rule_name'],
				$status_text,
				$result['explanation']
			);
			
			// Add examples if non-compliant.
			if ( ! $result['compliant'] && ! empty( $result['examples'] ) ) {
				$examples_text = implode(
					', ',
					array_map(
						function ( $ex ) {
							return '"' . $ex . '"';
						},
						$result['examples']
					) 
				);
				$message .= ' → Found: ' . $examples_text;
			}
			
			$issues[] = array(
				'message'  => $message,
				'severity' => $result['compliant'] ? 'info' : ( 'hard' === $mode ? 'error' : 'warning' ),
			);
		}

		return array(
			'status'   => $status,
			'summary'  => $summary,
			'issues'   => $issues,
			'results'  => $results,
			'analysis' => array(
				'total_rules'     => $total_rules,
				'passed'          => $passed,
				'failed'          => $failed,
				'validation_mode' => $mode,
				'content_length'  => strlen( $plain ),
				'debug'           => $debug_info,
			),
		);
	}

	/**
	 * Validate content against a single rule through the selected provider.
	 *
	 * @param mixed  $model     Resolved AI Client model from AiInference.
	 * @param string $content   Plain text content.
	 * @param string $rule_name Rule name.
	 * @param string $rule_text Rule description.
	 * @return array|\WP_Error Validation result or error (includes _debug key with prompt/response).
	 */
	private static function validate_rule( $model, string $content, string $rule_name, string $rule_text ) {
		// Truncate content to avoid token limits.
		$content = mb_substr( $content, 0, 8000 );

		// Build prompt.
		$prompt = str_replace(
			array( '{content}', '{rule_name}', '{rule_text}' ),
			array( $content, $rule_name, $rule_text ),
			self::VALIDATION_PROMPT
		);
		
		// Store prompt for debug.
		$debug_prompt = $prompt;

		try {
			/*
			 * No temperature is requested. This check used to ask for 0.3 so the same
			 * rule judged the same content consistently, but newer Claude models
			 * refuse any request carrying the option — they report `temperature` as
			 * deprecated and answer with HTTP 400 — so the check could not run at all
			 * on them. Consistency of judgement is now whatever the provider default
			 * gives: the same rule may reach different verdicts on the same content.
			 *
			 * The reply budget is sized from an observed truncation, not an estimate. At
			 * a ceiling of 300 the provider reported a `length` finish reason and the
			 * reply stopped after 593 characters, mid-way through the third entry of
			 * `examples` — the prompt asks for a prose `explanation` plus a list of
			 * quoted excerpts, and the excerpts grow with the content being judged. So
			 * a multi-sentence explanation alongside several quoted examples runs to
			 * something under 1,000 tokens.
			 *
			 * Worth keeping in view that this observation is also a counter-example to
			 * the reasoning cost being uniform: 593 characters of content came back
			 * under a 300-token ceiling, so that run cannot have spent more than a
			 * fraction of it reasoning, where the sampled agents spent ~3,900. Thinking
			 * is adaptive, and a single-rule judgement over truncated content is about
			 * as simple as a prompt here gets. That makes the reply model above sound
			 * and the old 1000 defensible on the evidence available at the time — but
			 * it is a bet on the prompt staying simple, and a longer rule over a longer
			 * document has no headroom for the model to think its way through. Hence
			 * the floor underneath it, which still leaves roughly twice the observed
			 * reply length on top.
			 *
			 * Generation and decoding both run through core's shared JSON path. This
			 * check used to unwrap code fences itself and hand the result to
			 * `json_decode()`, which meant that same cut-off reply came back as the bare
			 * line "Could not parse AI response", naming neither the length it stopped
			 * at nor the fact that it stopped. LlmJsonGenerator reads the provider's
			 * finish reason before parsing, so truncation is reported as truncation.
			 */
			// Use WordPress AI Client configured by VIP Workflow core.
			$result = LlmJsonGenerator::generate(
				\WordPress\AiClient\AiClient::prompt( $prompt )
					->usingSystemInstruction( 'You are an expert editorial compliance reviewer. Be specific and constructive in your feedback.' )
					->usingModel( $model )
					->usingMaxTokens( LlmTextGenerator::bounded_max_tokens( LlmTextGenerator::THINKING_FLOOR ) )
					->asJsonResponse(),
				'editorial alignment response'
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/*
			 * The debug `response` is the decoded payload re-encoded rather than the
			 * provider's raw text, because the generator returns what parsed. Nothing is
			 * lost on a failure: that branch never recorded the raw text either — it
			 * stored "N/A" — and what it carries now is the decoder's own message and the
			 * response length, inside the WP_Error.
			 */
			return array(
				'rule_name'   => $rule_name,
				'compliant'   => $result['compliant'] ?? false,
				'explanation' => $result['explanation'] ?? '',
				'examples'    => $result['examples'] ?? array(),
				'_debug'      => array(
					'prompt'   => $debug_prompt,
					'response' => (string) wp_json_encode( $result ),
				),
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Get the editorial rules configured for this site.
	 *
	 * @param int $post_id Optional post ID for post-aware guideline packets.
	 * @return array
	 */
	private static function get_rules( int $post_id = 0 ): array {
		if ( ! class_exists( '\VIPWorkflow\Integrations\GuidelineContextProvider' ) ) {
			return array();
		}

		return \VIPWorkflow\Integrations\GuidelineContextProvider::get_editorial_alignment_rules( $post_id );
	}

	/**
	 * Permission callback.
	 *
	 * Scoped to the post being checked rather than the global `edit_posts`
	 * capability: this ability reads the post body and returns portions of it
	 * in its output, so the gate has to name the object it is about to expose.
	 *
	 * @param  array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		if ( empty( $input['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'Post ID is required.', 'workflow-tool-editorial-alignment' )
			);
		}

		$permission_error = \VIPWorkflow\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
		if ( $permission_error ) {
			return $permission_error;
		}

		return true;
	}
}
