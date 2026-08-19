<?php
/**
 * Excerpt Generator Tool.
 *
 * @package WorkflowToolExcerpt
 */

declare( strict_types=1 );

namespace WorkflowToolExcerpt;

use VIPWorkflow\AI\AiInference;
use VIPWorkflow\Abilities\AiAvailability;
use VIPWorkflow\Abilities\Availability;
use VIPWorkflow\Integrations\LlmTextGenerator;

/**
 * AI-powered excerpt generator using the AI provider configured in VIP Workflow.
 */
class ExcerptGenerator {

	/**
	 * Default prompt template.
	 *
	 * Available variables: {title}, {content}, {max_length}, {style_instruction}
	 */
	public const DEFAULT_PROMPT = 'Write a meta description excerpt for the following article.

Requirements:
- Maximum {max_length} characters
- {style_instruction}
- Do not use quotes around the excerpt
- Do not include the title in the excerpt

Title: {title}

Content:
{content}

Excerpt:';

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
			'workflow-tool-excerpt/excerpt-generator',
			[
				'label'               => __( 'Excerpt Generator', 'workflow-tool-excerpt' ),
				'description'         => __( 'Generate a concise excerpt from post content using AI.', 'workflow-tool-excerpt' ),
				'category'            => 'vip-workflow',
				'input_schema'        => self::get_input_schema(),
				'output_schema'       => self::get_output_schema(),
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => [ self::class, 'can_execute' ],
				'meta'                => [
					'show_in_rest'          => true,
					'show_in_commands'      => true,
					'icon'                  => 'editor-paragraph',
					'type'                  => 'helper',
					/* One generated excerpt replacing a field. See skills/create-tool/SKILL.md. */
					'result_type'           => 'value',
					'supports'              => [ 'workflow' ],
					'transition_eligible'   => false,
					'apply_field'           => 'excerpt',
					'settings_schema'       => [
						'max_length' => [
							'type'        => 'integer',
							'default'     => 155,
							'label'       => __( 'Maximum excerpt length', 'workflow-tool-excerpt' ),
							'description' => __( 'Maximum characters for generated excerpts.', 'workflow-tool-excerpt' ),
							'minimum'     => 50,
							'maximum'     => 500,
						],
						'style'      => [
							'type'        => 'string',
							'enum'        => [ 'informative', 'action' ],
							'default'     => 'informative',
							'label'       => __( 'Excerpt style', 'workflow-tool-excerpt' ),
						],
						'prompt'     => [
							'type'        => 'string',
							'default'     => self::DEFAULT_PROMPT,
							'label'       => __( 'Prompt template', 'workflow-tool-excerpt' ),
							'description' => __( 'Variables: {title}, {content}, {max_length}, {style_instruction}', 'workflow-tool-excerpt' ),
						],
					],
					'annotations'           => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'availability_callback' => [ self::class, 'check_availability' ],
				],
			]
		);
	}

	/**
	 * Whether AI text generation is configured for this tool.
	 *
	 * Asks about the admin-selected provider, matching `generate_excerpt()`, which
	 * generates through `AiInference`. The gate and the generation call therefore
	 * name the same provider by construction.
	 *
	 * @since 0.0.1
	 *
	 * @return bool|Availability True when generation is configured, otherwise the unmet requirements.
	 */
	public static function check_availability(): bool|Availability {
		return AiAvailability::for_selected_provider(
			array( __( 'Excerpt Generator', 'workflow-tool-excerpt' ) )
		);
	}

	/**
	 * Get input schema.
	 *
	 * @return array
	 */
	private static function get_input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'    => [
					'type'        => 'integer',
					'description' => __( 'The post ID to generate excerpt for.', 'workflow-tool-excerpt' ),
				],
				'content'    => [
					'type'        => 'string',
					'description' => __( 'Raw content (alternative to post_id).', 'workflow-tool-excerpt' ),
				],
				'title'      => [
					'type'        => 'string',
					'description' => __( 'Post title (if using content).', 'workflow-tool-excerpt' ),
				],
				'max_length' => [
					'type'        => 'integer',
					'description' => __( 'Maximum excerpt length in characters.', 'workflow-tool-excerpt' ),
				],
				'style'      => [
					'type'        => 'string',
					'enum'        => [ 'informative', 'action' ],
					'description' => __( 'Excerpt style: informative or action-oriented.', 'workflow-tool-excerpt' ),
				],
				'prompt'     => [
					'type'        => 'string',
					'description' => __( 'Prompt template. Variables: {title}, {content}, {max_length}, {style_instruction}', 'workflow-tool-excerpt' ),
				],
			],
		];
	}

	/**
	 * Get output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => [ 'status', 'summary', 'excerpt' ],
			'properties'           => [
				'status'   => [
					'type'        => 'string',
					'enum'        => [ 'pass', 'warning', 'fail' ],
					'description' => __( 'Generation status.', 'workflow-tool-excerpt' ),
				],
				'summary'  => [
					'type'        => 'string',
					'description' => __( 'The generated excerpt.', 'workflow-tool-excerpt' ),
				],
				'excerpt'  => [
					'type'        => 'string',
					'description' => __( 'The generated excerpt (alias for summary).', 'workflow-tool-excerpt' ),
				],
				'analysis' => [
					'type'        => 'object',
					'description' => __( 'Generation details.', 'workflow-tool-excerpt' ),
				],
			],
		];
	}

	/**
	 * Execute the excerpt generator.
	 *
	 * @param array|null $input Input parameters.
	 * @return array|\WP_Error Result data or error.
	 */
	public static function execute( ?array $input = null ) {
		$input   = $input ?? [];
		$content = '';
		$title   = '';

		// Get content from post or input.
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( $input['post_id'] );
			if ( $post ) {
				$content = $post->post_content;
				$title   = $post->post_title;
			}
		} elseif ( ! empty( $input['content'] ) ) {
			$content = $input['content'];
			$title   = $input['title'] ?? '';
		}

		if ( empty( $content ) ) {
			return new \WP_Error(
				'no_content',
				__( 'No content to generate excerpt from.', 'workflow-tool-excerpt' )
			);
		}

		$settings   = \VIPWorkflow\Abilities\AbilitySettings::get_instance()->get_options( 'workflow-tool-excerpt/excerpt-generator' );
		$max_length = $settings['max_length'] ?? 155;
		$style      = $settings['style'] ?? 'informative';
		$prompt     = $settings['prompt'] ?? self::DEFAULT_PROMPT;

		// Strip blocks and HTML, normalize whitespace.
		$plain = wp_strip_all_tags( do_shortcode( $content ) );
		$plain = preg_replace( '/\s+/', ' ', $plain );
		$plain = trim( $plain );

		if ( empty( $plain ) ) {
			return new \WP_Error(
				'empty_content',
				__( 'Content contains no extractable text.', 'workflow-tool-excerpt' )
			);
		}

		$word_count = str_word_count( $plain );

		/*
		 * Resolve the model up front. This replaced an `AiClient::isConfigured()`
		 * call, which validated the key by listing models over the network on every
		 * single execution; the ability's availability_callback now reports an
		 * unconfigured provider before the user ever runs the tool. What is left is
		 * the one thing the gate cannot promise: that the resolver returned a model.
		 */
		$model = AiInference::get_instance()->model();

		if ( null === $model ) {
			return new \WP_Error(
				'ai_not_configured',
				__( 'AI text generation is not configured on this site.', 'workflow-tool-excerpt' )
			);
		}

		$excerpt = self::generate_excerpt( $model, $plain, $title, $max_length, $style, $prompt );
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		return [
			'status'   => 'pass',
			'summary'  => $excerpt,
			'excerpt'  => $excerpt,
			'analysis' => [
				'excerpt'           => $excerpt,
				'excerpt_length'    => strlen( $excerpt ),
				'source_word_count' => $word_count,
				'max_length'        => $max_length,
				'style'             => $style,
			],
		];
	}

	/**
	 * Generate the excerpt through the admin-selected provider and model.
	 *
	 * @param mixed  $model           Resolved AI Client model from AiInference.
	 * @param string $content         Plain text content.
	 * @param string $title           Post title.
	 * @param int    $max_length      Max excerpt length.
	 * @param string $style           Style: informative or action.
	 * @param string $prompt_template Prompt template with variables.
	 * @return string|\WP_Error Generated excerpt or error.
	 */
	private static function generate_excerpt( $model, string $content, string $title, int $max_length, string $style, string $prompt_template = '' ): string|\WP_Error {
		// Truncate content to avoid token limits (roughly 4 chars per token).
		$content = mb_substr( $content, 0, 8000 );

		$style_instruction = 'informative' === $style
			? 'Write a clear, factual summary.'
			: 'Write an engaging, action-oriented summary that encourages readers to click.';

		// Use provided template or default.
		if ( empty( $prompt_template ) ) {
			$prompt_template = self::DEFAULT_PROMPT;
		}

		// Replace variables in prompt template.
		$prompt = str_replace(
			array( '{title}', '{content}', '{max_length}', '{style_instruction}' ),
			array( $title, $content, (string) $max_length, $style_instruction ),
			$prompt_template
		);

		try {
			/*
			 * No temperature is requested. The value sent here (0.7) was an
			 * unremarkable creative default, but newer Claude models reject the
			 * option outright — they report `temperature` as deprecated and refuse
			 * the whole request with HTTP 400, so on those models this tool could
			 * not generate an excerpt at all. Leaving it unset uses the provider's
			 * own default and keeps the tool working everywhere.
			 *
			 * The ceiling is the floor and nothing more. The reply is a meta description
			 * and nothing else — the prompt caps it at `{max_length}` characters and the
			 * code below trims anything longer — so it is a few dozen tokens however this
			 * is configured, and the budget is thinking cost almost in its entirety. That
			 * is what made the previous 100 the most under-sized ceiling in the repo:
			 * roughly a fortieth of what the model spends reasoning before it writes the
			 * line, so there was never an excerpt to trim.
			 */
			// Use WordPress AI Client configured by VIP Workflow core.
			$excerpt = \WordPress\AiClient\AiClient::prompt( $prompt )
				->usingSystemInstruction( 'You are an expert copywriter who writes concise, engaging meta descriptions.' )
				->usingModel( $model )
				->usingMaxTokens( LlmTextGenerator::bounded_max_tokens( LlmTextGenerator::THINKING_FLOOR ) )
				->generateText();

			// Remove quotes if present.
			$excerpt = trim( $excerpt, '"\'' );

			// Ensure max length.
			if ( strlen( $excerpt ) > $max_length ) {
				$excerpt = mb_substr( $excerpt, 0, $max_length - 1 ) . '…';
			}

			return $excerpt;

		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Permission callback.
	 *
	 * Scoped to the post being summarized rather than the global `edit_posts`
	 * capability. This ability reads the post body and sends it to the
	 * configured AI provider, so a caller who may edit *a* post but not *this*
	 * one would otherwise cross both the object and the vendor boundary.
	 *
	 * @param  array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		if ( empty( $input['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'Post ID is required.', 'workflow-tool-excerpt-generator' )
			);
		}

		$permission_error = \VIPWorkflow\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
		if ( $permission_error ) {
			return $permission_error;
		}

		return true;
	}
}
