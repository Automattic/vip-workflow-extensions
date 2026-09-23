<?php
/**
 * Editorial Alignment Checker, with TypeSafe as the engine.
 *
 * @package WorkflowTypeSafeEditorialAlignment
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeEditorialAlignment;

use TypeSafeConnector\Client;
use VIPWorkflows\Abilities\AbilitySettings;
use VIPWorkflows\Abilities\Availability;
use VIPWorkflows\Abilities\RequirementFactory;
use VIPWorkflows\Abilities\RequirementGroup;

/**
 * Asks TypeSafe, for each guideline rule, how likely it is that the post breaks it.
 *
 * The output shape is the same as `workflow-tool-editorial-alignment`, so a sequence that gates on one can
 * gate on the other. What differs is where the verdict comes from: each rule gets a probability, and the
 * policy that turns a probability into pass, warning or fail is in decide(), which touches nothing but its
 * arguments.
 */
final class EditorialAlignmentChecker {

	public const TOOL_ID = 'workflow-typesafe-editorial-alignment/editorial-alignment-checker';

	public const DEFAULTS = array(
		'validation_mode'     => 'soft',
		'violation_threshold' => 50,
		'granularity'         => 'each',
	);

	/**
	 * The longest body, in characters, that one check will read.
	 *
	 * Longer than this is an error, not a partial check. A gate that passes copy it never finished reading is
	 * worse than one that says it could not run, and rules about the ending (a closing call to action, a
	 * disclosure line) cannot be judged from the start alone. A conservative starting point, not a measured limit.
	 */
	private const MAX_BODY_CHARS = 20000;

	/**
	 * How many rules go in one request. Independent questions over the same state run in parallel on TypeSafe's
	 * side, so batching costs nothing in speed; it only keeps any single request a sensible size.
	 */
	private const RULES_PER_REQUEST = 20;

	/**
	 * The most individual rules one check will ask about.
	 *
	 * Refused rather than trimmed for the same reason as an over-long post: dropping rules would pass a post
	 * against rules nobody asked about. Guideline rows are capped at 5,000 characters each, so this is well above
	 * what one careful desk writes.
	 */
	private const MAX_RULES = 100;

	/**
	 * A guideline line shorter than this is a label or a stray, not a rule.
	 */
	private const MIN_RULE_CHARS = 12;

	/**
	 * Register the ability. Shown in Integrations → Tools, usable on a transition and from the command palette.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Registered through the VIP Workflows wrapper, not core's wp_register_ability(): only the wrapper sets
		// ability_class, and only a VIPWorkflows\Abilities\Ability consults availability_callback.
		if ( ! function_exists( 'vip_workflows_register_ability' ) ) {
			return;
		}

		vip_workflows_register_ability(
			self::TOOL_ID,
			array(
				'label'               => __( 'Editorial Alignment Checker (TypeSafe)', 'workflow-typesafe-editorial-alignment' ),
				'description'         => __( 'Checks the post against the site\'s content guidelines with TypeSafe, and reports how likely it is that each rule is broken.', 'workflow-typesafe-editorial-alignment' ),
				'category'            => 'vip-workflows',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID to validate.', 'workflow-typesafe-editorial-alignment' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'Raw content (alternative to post_id).', 'workflow-typesafe-editorial-alignment' ),
						),
						'granularity' => array(
							'type'        => 'string',
							'enum'        => array( 'each', 'whole' ),
							'description' => __( 'Overrides the saved setting for this run: "each" asks about every guideline separately, "whole" asks once about the guideline text as a whole.', 'workflow-typesafe-editorial-alignment' ),
						),
					),
				),
				'output_schema'       => self::output_schema(),
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
						'validation_mode'     => array(
							'type'        => 'string',
							'enum'        => array( 'soft', 'hard' ),
							'default'     => self::DEFAULTS['validation_mode'],
							'label'       => __( 'Validation mode', 'workflow-typesafe-editorial-alignment' ),
							'description' => __( 'Hard mode blocks transitions when a rule is flagged.', 'workflow-typesafe-editorial-alignment' ),
						),
						'granularity'         => array(
							'type'        => 'string',
							'enum'        => array( 'each', 'whole' ),
							'default'     => self::DEFAULTS['granularity'],
							'label'       => __( 'Guidelines are judged', 'workflow-typesafe-editorial-alignment' ),
							'description' => __( '"each" splits the site\'s guidelines into individual rules and asks about every one, so a flag names the rule. "whole" asks once about all the guideline text together, which is one blended answer.', 'workflow-typesafe-editorial-alignment' ),
						),
						'violation_threshold' => array(
							'type'        => 'integer',
							'default'     => self::DEFAULTS['violation_threshold'],
							'label'       => __( 'Flag a rule at (%)', 'workflow-typesafe-editorial-alignment' ),
							'description' => __( 'A rule is flagged when TypeSafe puts the chance the post breaks it at or above this. Lower it to catch more, at the cost of more false alarms.', 'workflow-typesafe-editorial-alignment' ),
							'minimum'     => 1,
							'maximum'     => 100,
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
	 * Reads the environment only; it makes no network request, since availability is read on every Tools-page load.
	 *
	 * @return bool|Availability True when configured, otherwise the unmet requirements.
	 */
	public static function check_availability(): bool|Availability {
		$sources = array( __( 'Editorial Alignment Checker (TypeSafe)', 'workflow-typesafe-editorial-alignment' ) );

		if ( ! class_exists( Client::class ) || ! Client::has_connector() ) {
			return Availability::unmet(
				RequirementGroup::all(
					RequirementFactory::dependency(
						'dependency:typesafe-connector',
						__( 'The TypeSafe connector is not registered. Install and activate the TypeSafe Connector plugin (WordPress 7.0 or later).', 'workflow-typesafe-editorial-alignment' ),
						__( 'TypeSafe is not set up on this site. Ask an administrator to install the TypeSafe connector.', 'workflow-typesafe-editorial-alignment' ),
						$sources
					)
				)
			);
		}

		if ( '' === Client::api_key() ) {
			return Availability::unmet(
				RequirementGroup::all(
					RequirementFactory::dependency(
						'dependency:typesafe-key',
						__( 'TypeSafe has no API key. Add one in Settings → Connectors, or set TYPESAFE_API_KEY.', 'workflow-typesafe-editorial-alignment' ),
						__( 'TypeSafe is not connected. Ask an administrator to add the API key.', 'workflow-typesafe-editorial-alignment' ),
						$sources
					)
				)
			);
		}

		return true;
	}

	/**
	 * Read the tool's saved settings, filled with defaults and clamped to sane ranges.
	 *
	 * @return array{validation_mode: string, violation_threshold: int, granularity: string}
	 */
	public static function settings(): array {
		$saved = array();
		if ( class_exists( AbilitySettings::class ) ) {
			$saved = AbilitySettings::get_instance()->get_options( self::TOOL_ID );
		}
		$saved = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );

		return array(
			'validation_mode'     => 'hard' === $saved['validation_mode'] ? 'hard' : 'soft',
			'violation_threshold' => max( 1, min( 100, (int) $saved['violation_threshold'] ) ),
			'granularity'         => 'whole' === $saved['granularity'] ? 'whole' : 'each',
		);
	}

	/**
	 * Run the check.
	 *
	 * @param  array|null $input Ability input.
	 * @return array|\WP_Error Result data or error.
	 */
	public static function execute( ?array $input = null ) {
		$input   = $input ?? array();
		$title   = '';
		$excerpt = '';
		$content = '';

		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( (int) $input['post_id'] );
			if ( $post ) {
				$title   = (string) $post->post_title;
				$excerpt = (string) $post->post_excerpt;
				$content = (string) $post->post_content;
			}
		} elseif ( ! empty( $input['content'] ) ) {
			$content = (string) $input['content'];
		}

		if ( '' === $content ) {
			return new \WP_Error( 'no_content', __( 'No content to validate.', 'workflow-typesafe-editorial-alignment' ) );
		}

		$settings    = self::settings();
		$granularity = in_array( $input['granularity'] ?? null, array( 'each', 'whole' ), true ) ? (string) $input['granularity'] : $settings['granularity'];

		$rules = self::rules( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! $rules ) {
			return new \WP_Error( 'no_rules', __( 'No Gutenberg/Core content guidelines are available.', 'workflow-typesafe-editorial-alignment' ) );
		}

		if ( 'each' === $granularity ) {
			$rules = self::atomize( $rules );
		}

		if ( count( $rules ) > self::MAX_RULES ) {
			return new \WP_Error(
				'too_many_rules',
				sprintf(
					/* translators: 1: rules found, 2: the limit. */
					__( 'The guidelines break down into %1$d rules, more than the %2$d one check will ask about. Trim them, or set the tool to judge the guidelines as a whole.', 'workflow-typesafe-editorial-alignment' ),
					count( $rules ),
					self::MAX_RULES
				)
			);
		}

		$keyed = array();
		foreach ( array_values( $rules ) as $index => $rule ) {
			$keyed[ 'r' . $index ] = $rule;
		}
		$rules = $keyed;

		$body = self::plain_text( $content );
		if ( '' === $body ) {
			return new \WP_Error( 'empty_content', __( 'Content contains no extractable text.', 'workflow-typesafe-editorial-alignment' ) );
		}

		if ( mb_strlen( $body ) > self::MAX_BODY_CHARS ) {
			return new \WP_Error(
				'content_too_long',
				sprintf(
					/* translators: %s: the character limit. */
					__( 'This post is longer than the %s characters one check can read. A check that stopped early could pass copy it never saw, so it did not run.', 'workflow-typesafe-editorial-alignment' ),
					number_format_i18n( self::MAX_BODY_CHARS )
				)
			);
		}

		$state = array(
			'title'   => trim( wp_strip_all_tags( $title ) ),
			'excerpt' => trim( wp_strip_all_tags( $excerpt ) ),
			'body'    => $body,
		);

		$questions = array();
		foreach ( $rules as $id => $rule ) {
			$questions[ $id ] = self::question( $rule['name'], $rule['rule'] );
		}

		$probabilities = array();
		foreach ( array_chunk( $questions, self::RULES_PER_REQUEST, true ) as $batch ) {
			$answers = Client::ask( $state, $batch );

			// One failed request means TypeSafe could not be used at all (no key, rejected key, unreachable),
			// which is the same for every rule. Say so once rather than as one error per rule.
			if ( is_wp_error( $answers ) ) {
				return $answers;
			}

			foreach ( array_keys( $batch ) as $id ) {
				$probabilities[ $id ] = self::read_noul( $answers[ $id ] ?? null );
			}
		}

		$decision = self::decide( $rules, $probabilities, $settings );

		return array(
			'status'   => $decision['status'],
			'summary'  => $decision['summary'],
			'issues'   => $decision['issues'],
			'results'  => $decision['results'],
			'analysis' => array(
				'engine'              => 'typesafe',
				'total_rules'         => $decision['passed'] + $decision['failed'],
				'passed'              => $decision['passed'],
				'failed'              => $decision['failed'],
				'validation_mode'     => $settings['validation_mode'],
				'violation_threshold' => $settings['violation_threshold'],
				'granularity'         => $granularity,
				'content_length'      => mb_strlen( $body ),
			),
		);
	}

	/**
	 * Apply the policy to TypeSafe's answers. Pure: no WordPress, no network, so it can be tested with plain arrays.
	 *
	 * A rule with no usable answer is flagged, not passed: a gate should not wave a rule through because the
	 * answer to it went missing.
	 *
	 * @param  array $rules         Question id => array{name: string, rule: string}.
	 * @param  array $probabilities Question id => probability the post breaks the rule, or null when unusable.
	 * @param  array $settings      Result of settings().
	 * @return array{status: string, summary: string, issues: array, results: array, passed: int, failed: int}
	 */
	public static function decide( array $rules, array $probabilities, array $settings ): array {
		$threshold = $settings['violation_threshold'] / 100;
		$hard      = 'hard' === $settings['validation_mode'];
		$percent   = (int) $settings['violation_threshold'];

		$results = array();
		$issues  = array();
		$passed  = 0;
		$failed  = 0;

		foreach ( $rules as $id => $rule ) {
			$probability = $probabilities[ $id ] ?? null;

			if ( null === $probability ) {
				$compliant   = false;
				$explanation = __( 'TypeSafe returned no usable answer for this rule, so it is flagged rather than passed.', 'workflow-typesafe-editorial-alignment' );
			} else {
				$compliant   = $probability < $threshold;
				$chance      = (int) round( $probability * 100 );
				$explanation = $compliant
					? sprintf(
						/* translators: 1: chance percent, 2: flag-at percent. */
						__( 'TypeSafe put the chance this breaks the rule at %1$d%%, below the %2$d%% that flags it.', 'workflow-typesafe-editorial-alignment' ),
						$chance,
						$percent
					)
					: sprintf(
						/* translators: 1: chance percent, 2: flag-at percent. */
						__( 'TypeSafe put the chance this breaks the rule at %1$d%%, at or above the %2$d%% that flags it. It judges the post as a whole and does not point at a passage.', 'workflow-typesafe-editorial-alignment' ),
						$chance,
						$percent
					);
			}

			$results[] = array(
				'rule_name'             => $rule['name'],
				'compliant'             => $compliant,
				'explanation'           => $explanation,
				'examples'              => array(),
				'violation_probability' => $probability,
			);

			$issues[] = array(
				'message'  => sprintf( '%s %s: %s — %s', $compliant ? '✅' : '❌', $rule['name'], $compliant ? 'PASSED' : 'FAILED', $explanation ),
				'severity' => $compliant ? 'info' : ( $hard ? 'error' : 'warning' ),
			);

			if ( $compliant ) {
				++$passed;
			} else {
				++$failed;
			}
		}

		$summary = sprintf(
			/* translators: 1: rules passed, 2: rules checked. */
			__( '%1$d of %2$d editorial rules passed.', 'workflow-typesafe-editorial-alignment' ),
			$passed,
			$passed + $failed
		);

		if ( $failed > 0 && $hard ) {
			$summary .= ' ' . __( 'Hard validation mode is on: fix the flagged rules before proceeding.', 'workflow-typesafe-editorial-alignment' );
		}

		return array(
			'status'  => 0 === $failed ? 'pass' : ( $hard ? 'fail' : 'warning' ),
			'summary' => $summary,
			'issues'  => $issues,
			'results' => $results,
			'passed'  => $passed,
			'failed'  => $failed,
		);
	}

	/**
	 * The question for one rule: how likely is it that the post breaks it.
	 *
	 * Phrased so a high value means a departure. A departure is something concrete to look for; "complies" asks the
	 * model to prove a negative. The criteria say what each side means, including that a rule which does not apply
	 * to the post is not broken by it.
	 *
	 * @param  string $name Rule name.
	 * @param  string $rule Rule text.
	 * @return array
	 */
	public static function question( string $name, string $rule ): array {
		return array(
			'type'         => 'noul',
			'instructions' => sprintf(
				"Does this article break the editorial rule below, in at least one place?\n\nRule name: %s\nRule: %s",
				$name,
				$rule
			),
			'criteria'     => array(
				'true'  => 'The article does something the rule forbids, or leaves out something the rule requires.',
				'false' => 'The article follows the rule, or the rule does not apply to it.',
			),
		);
	}

	/**
	 * Pull the probability out of a Noul answer, or null when it is missing or out of range.
	 *
	 * @param  mixed $answer Raw answer from TypeSafe.
	 * @return float|null
	 */
	public static function read_noul( $answer ): ?float {
		$value = is_array( $answer ) ? ( $answer['noul'] ?? null ) : null;

		if ( ! is_numeric( $value ) || $value < 0 || $value > 1 ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Post markup as text, keeping the structure editorial rules talk about.
	 *
	 * Paragraph breaks, headings and list items survive as blank lines, `##` and `-`, because rules such as "short
	 * paragraphs" or "use subheads for sections" cannot be judged from one run of words.
	 *
	 * @param  string $markup Post content.
	 * @return string
	 */
	public static function plain_text( string $markup ): string {
		$text = strip_shortcodes( $markup );
		$text = (string) preg_replace_callback(
			'#<h([1-6])\b[^>]*>#i',
			static fn( array $m ): string => "\n\n" . str_repeat( '#', (int) $m[1] ) . ' ',
			$text
		);
		$text = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = (string) preg_replace( '#</(p|h[1-6]|ul|ol|blockquote|figcaption|figure)>#i', "\n\n", $text );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/ ?\n ?/', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * The editorial rules configured for this site.
	 *
	 * With Gutenberg Guidelines this is a single rule named "Content guidelines" whose text is every guideline
	 * section joined together. atomize() is what turns that into rules a question can be asked about.
	 *
	 * @param  int $post_id Optional post ID for post-aware guideline packets.
	 * @return array<int, array{name: string, rule: string}>
	 */
	private static function rules( int $post_id ): array {
		if ( ! class_exists( '\VIPWorkflows\Integrations\GuidelineContextProvider' ) ) {
			return array();
		}

		$rules = array();
		foreach ( \VIPWorkflows\Integrations\GuidelineContextProvider::get_editorial_alignment_rules( $post_id ) as $rule ) {
			$text = trim( (string) ( $rule['rule'] ?? '' ) );

			// A rule with no text has nothing to check against. Skipped and not counted, as the original does.
			if ( '' === $text ) {
				continue;
			}

			$rules[] = array(
				'name' => (string) ( $rule['name'] ?? __( 'Unnamed Rule', 'workflow-typesafe-editorial-alignment' ) ),
				'rule' => $text,
			);
		}

		return $rules;
	}

	/**
	 * Break guideline text into the individual rules it holds. Pure, so it can be tested with plain strings.
	 *
	 * Why: VIP Workflows hands the checker one rule, and its text is the whole guideline packet, a markdown
	 * document with a `## Section` per guideline scope. One question about all of it can only ask "does the post
	 * break any of this?", which blends every rule into one number and cannot say which. TypeSafe answers narrow
	 * questions well, so each rule gets its own.
	 *
	 * A rule is a list item (with anything nested under it) or a paragraph. Headings are boundaries, and the
	 * section name travels with each rule so it reads on its own. Rules that are not a packet, such as ones a
	 * site supplies through the `vip_workflows_editorial_alignment_rules` filter, are left exactly as they are.
	 *
	 * @param  array<int, array{name: string, rule: string}> $rules Rules as configured.
	 * @return array<int, array{name: string, rule: string}>
	 */
	public static function atomize( array $rules ): array {
		$out = array();

		foreach ( $rules as $rule ) {
			$units = self::units( $rule['rule'] );

			if ( ! $units ) {
				$out[] = $rule;
				continue;
			}

			foreach ( $units as $unit ) {
				$out[] = array(
					'name' => $unit['section'] . ': ' . mb_strimwidth( $unit['text'], 0, 60, '…' ),
					'rule' => sprintf( '(From the "%s" guidelines) %s', $unit['section'], $unit['text'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * The rules inside one guideline packet, or none when the text is not a packet.
	 *
	 * @param  string $text Guideline text.
	 * @return array<int, array{section: string, text: string}>
	 */
	private static function units( string $text ): array {
		if ( ! preg_match( '/^##\s/m', $text ) ) {
			return array();
		}

		$units   = array();
		$section = '';
		$current = null;
		$flush   = static function () use ( &$units, &$current ): void {
			if ( null !== $current && mb_strlen( $current['text'] ) >= self::MIN_RULE_CHARS ) {
				$units[] = $current;
			}
			$current = null;
		};

		foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
			$line = (string) $line;

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$flush();
				if ( 2 === strlen( $m[1] ) ) {
					$section = trim( $m[2] );
				}
				continue;
			}

			if ( '' === trim( $line ) ) {
				$flush();
				continue;
			}

			if ( preg_match( '/^(\s*)(?:[-*+]|\d+[.)])\s+(.*)$/', $line, $m ) ) {
				// A top-level item starts a rule; an indented one belongs to the rule above it.
				if ( '' === $m[1] || null === $current ) {
					$flush();
					$current = array(
						'section' => $section,
						'text'    => trim( $m[2] ),
					);
				} else {
					$current['text'] .= ' ' . trim( $m[2] );
				}
				continue;
			}

			if ( null === $current ) {
				$current = array(
					'section' => $section,
					'text'    => trim( $line ),
				);
			} else {
				$current['text'] .= ' ' . trim( $line );
			}
		}

		$flush();

		return $units;
	}

	/**
	 * Output schema. Matches the original checker, plus the probability on each rule.
	 *
	 * @return array
	 */
	private static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => array( 'status', 'summary' ),
			'properties'           => array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'pass', 'warning', 'fail' ),
					'description' => __( 'Overall validation status.', 'workflow-typesafe-editorial-alignment' ),
				),
				'summary'  => array(
					'type'        => 'string',
					'description' => __( 'Summary of validation results.', 'workflow-typesafe-editorial-alignment' ),
				),
				'results'  => array(
					'type'        => 'array',
					'description' => __( 'Detailed results for each rule.', 'workflow-typesafe-editorial-alignment' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'rule_name'             => array( 'type' => 'string' ),
							'compliant'             => array( 'type' => 'boolean' ),
							'explanation'           => array( 'type' => 'string' ),
							'examples'              => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'violation_probability' => array(
								'type'        => array( 'number', 'null' ),
								'description' => __( 'TypeSafe\'s probability, from 0 to 1, that the post breaks this rule.', 'workflow-typesafe-editorial-alignment' ),
							),
						),
					),
				),
				'analysis' => array(
					'type'        => 'object',
					'description' => __( 'Additional analysis data.', 'workflow-typesafe-editorial-alignment' ),
				),
			),
		);
	}

	/**
	 * Permission callback, scoped to the post being checked.
	 *
	 * The check sends the post body to a third party, so the gate has to name the object it is about to expose.
	 *
	 * @param  array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		if ( empty( $input['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'Post ID is required.', 'workflow-typesafe-editorial-alignment' ) );
		}

		$permission_error = \VIPWorkflows\Abilities\Tools\require_post_edit_permission( (int) $input['post_id'] );
		if ( $permission_error ) {
			return $permission_error;
		}

		return true;
	}
}
