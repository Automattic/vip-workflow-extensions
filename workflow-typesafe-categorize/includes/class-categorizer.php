<?php
/**
 * The categorization logic shared by the tool and the agent.
 *
 * @package WorkflowTypeSafeCategorize
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeCategorize;

use VIPWorkflows\Abilities\AbilitySettings;

/**
 * Turns a post into a set of category decisions.
 *
 * The split is deliberate. TypeSafe supplies the judgment: which category the article is about,
 * and how sure it is. This class supplies the policy: how sure is sure enough, how many categories
 * a post may carry, and what happens to the ones that miss. Policy lives in code, as settings an
 * editor can read, so changing a threshold never means re-prompting anything.
 */
final class Categorizer {

	/**
	 * Ability id of the tool. The agent reads its settings from here too, so there is one place to configure.
	 */
	public const TOOL_ID = 'workflow-typesafe-categorize/categorize';

	public const DEFAULTS = array(
		'taxonomy'            => 'category',
		'min_confidence'      => 75,
		'secondary_threshold' => 80,
		'max_categories'      => 3,
	);

	/**
	 * The Choice question's escape hatch, so an article that fits nothing is not forced into the nearest category.
	 */
	private const NONE = 'none';

	/**
	 * Choice questions accept up to 255 options; one is reserved for NONE.
	 */
	private const MAX_CANDIDATES = 254;

	/**
	 * How many runners-up get an independent yes/no check after the first pass.
	 */
	private const SHORTLIST = 5;

	/**
	 * Below this probability a runner-up is noise, not a candidate.
	 */
	private const SHORTLIST_FLOOR = 0.02;

	/**
	 * Characters of body text sent. Categorizing needs the gist, not the whole article.
	 */
	private const MAX_BODY_CHARS = 12000;

	/**
	 * Read the tool's saved settings, filled with defaults and clamped to sane ranges.
	 *
	 * @return array{taxonomy: string, min_confidence: int, secondary_threshold: int, max_categories: int}
	 */
	public static function settings(): array {
		$saved = array();
		if ( class_exists( AbilitySettings::class ) ) {
			$saved = AbilitySettings::get_instance()->get_options( self::TOOL_ID );
		}
		$saved = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );

		$taxonomy = sanitize_key( (string) $saved['taxonomy'] );

		return array(
			'taxonomy'            => '' !== $taxonomy ? $taxonomy : self::DEFAULTS['taxonomy'],
			'min_confidence'      => max( 0, min( 100, (int) $saved['min_confidence'] ) ),
			'secondary_threshold' => max( 0, min( 100, (int) $saved['secondary_threshold'] ) ),
			'max_categories'      => max( 1, min( 5, (int) $saved['max_categories'] ) ),
		);
	}

	/**
	 * Classify a post.
	 *
	 * @param  int        $post_id  Post ID.
	 * @param  array|null $settings Settings override; defaults to the saved ones.
	 * @return array|\WP_Error See decide() for the shape.
	 */
	public static function classify( int $post_id, ?array $settings = null ) {
		$settings = $settings ?? self::settings();
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'no_post', __( 'Post not found.', 'workflow-typesafe-categorize' ) );
		}

		if ( ! is_object_in_taxonomy( $post->post_type, $settings['taxonomy'] ) ) {
			return new \WP_Error(
				'taxonomy_not_supported',
				sprintf(
					/* translators: 1: taxonomy slug, 2: post type slug. */
					__( 'The "%1$s" taxonomy is not enabled for the "%2$s" post type. Change the taxonomy in the tool settings.', 'workflow-typesafe-categorize' ),
					$settings['taxonomy'],
					$post->post_type
				)
			);
		}

		$state = self::state( $post );
		if ( '' === $state['body'] && '' === $state['title'] ) {
			return new \WP_Error( 'no_content', __( 'The post has no text to categorize.', 'workflow-typesafe-categorize' ) );
		}

		$candidates = self::candidates( $settings['taxonomy'] );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		// First pass: which single category is the article primarily about, and how clearly.
		$first = TypeSafeClient::ask( $state, array( 'primary' => self::primary_question( $candidates ) ) );
		if ( is_wp_error( $first ) ) {
			return $first;
		}

		$primary = self::read_choice( $first['primary'] ?? null, $candidates );
		if ( is_wp_error( $primary ) ) {
			return $primary;
		}

		// Second pass, only when it can change the outcome: an independent yes/no for the runners-up,
		// because a Choice spreads probability across competing options and so under-reports an
		// article that is genuinely about two things.
		$secondary = array();
		if ( self::primary_accepted( $primary, $settings ) && $settings['max_categories'] > 1 ) {
			$shortlist = self::shortlist( $primary, $candidates );
			if ( $shortlist ) {
				$second = TypeSafeClient::ask( $state, self::secondary_questions( $shortlist ) );
				if ( is_wp_error( $second ) ) {
					return $second;
				}
				$secondary = self::read_noul_answers( $second, $shortlist );
			}
		}

		return self::decide( $candidates, $primary, $secondary, $settings ) + array( 'taxonomy' => $settings['taxonomy'] );
	}

	/**
	 * Apply the policy to TypeSafe's answers. Pure: no WordPress, no network, so it can be tested with plain arrays.
	 *
	 * @param  array $candidates Candidate key => array{term_id: int, label: string, description: string}.
	 * @param  array $primary    array{choice: string, confidence: float, probabilities: array<string, float>}.
	 * @param  array $secondary  Candidate key => probability of "yes", for the runners-up that were asked about.
	 * @param  array $settings   Result of settings().
	 * @return array{
	 *     confident: bool,
	 *     assign: array<int, array{term_id: int, label: string, role: string, score: float}>,
	 *     considered: array<int, array{term_id: int, label: string, role: string, score: float}>,
	 *     reason: string
	 * }
	 */
	public static function decide( array $candidates, array $primary, array $secondary, array $settings ): array {
		if ( ! self::primary_accepted( $primary, $settings ) ) {
			return array(
				'confident'  => false,
				'assign'     => array(),
				'considered' => self::top_by_probability( $candidates, $primary['probabilities'], 3 ),
				'reason'     => self::NONE === $primary['choice']
					? __( 'None of the site\'s categories fit this article.', 'workflow-typesafe-categorize' )
					: sprintf(
						/* translators: 1: confidence percent, 2: required percent. */
						__( 'TypeSafe was %1$d%% confident in the leading category; %2$d%% is required.', 'workflow-typesafe-categorize' ),
						(int) round( $primary['confidence'] * 100 ),
						$settings['min_confidence']
					),
			);
		}

		$chosen = $candidates[ $primary['choice'] ];
		$assign = array(
			array(
				'term_id' => $chosen['term_id'],
				'label'   => $chosen['label'],
				'role'    => 'primary',
				// The probability of the pick, not the distribution's confidence, so a row reads "84% this category".
				'score'   => (float) ( $primary['probabilities'][ $primary['choice'] ] ?? $primary['confidence'] ),
			),
		);

		arsort( $secondary );
		$threshold = $settings['secondary_threshold'] / 100;
		foreach ( $secondary as $key => $yes ) {
			if ( count( $assign ) >= $settings['max_categories'] ) {
				break;
			}
			if ( $yes >= $threshold && isset( $candidates[ $key ] ) ) {
				$assign[] = array(
					'term_id' => $candidates[ $key ]['term_id'],
					'label'   => $candidates[ $key ]['label'],
					'role'    => 'secondary',
					'score'   => (float) $yes,
				);
			}
		}

		return array(
			'confident'  => true,
			'assign'     => $assign,
			'considered' => array(),
			'reason'     => '',
		);
	}

	/**
	 * Add categories to a post without ever removing one an editor chose.
	 *
	 * The only term removed is the taxonomy's default (Uncategorized), because it is a placeholder WordPress
	 * fills in when nothing else is set, and leaving it beside real categories is noise.
	 *
	 * @param  int    $post_id  Post ID.
	 * @param  string $taxonomy Taxonomy slug.
	 * @param  int[]  $term_ids Terms to add.
	 * @return true|\WP_Error
	 */
	public static function assign( int $post_id, string $taxonomy, array $term_ids ) {
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object || ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
			return new \WP_Error(
				'cannot_assign_terms',
				__( 'The current user is not allowed to assign terms in this taxonomy.', 'workflow-typesafe-categorize' )
			);
		}

		$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$default_term = 'category' === $taxonomy ? (int) get_option( 'default_category' ) : 0;
		$kept         = array_values( array_diff( array_map( 'intval', $existing ), array( $default_term ) ) );
		$final        = array_values( array_unique( array_merge( $kept, array_map( 'intval', $term_ids ) ) ) );

		$result = wp_set_object_terms( $post_id, $final, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Read it back. Term assignment can be filtered or refused without an error, and a stage that
		// reports a pass over categories that never landed is worse than one that fails.
		$stored = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $stored ) || array_diff( array_map( 'intval', $term_ids ), array_map( 'intval', $stored ) ) ) {
			return new \WP_Error(
				'terms_not_saved',
				__( 'The categories were not saved to the post. Something on this site refused the change.', 'workflow-typesafe-categorize' )
			);
		}

		return true;
	}

	/**
	 * The state TypeSafe judges: named fields, because the title and the body carry different signal.
	 *
	 * @param  \WP_Post $post The post.
	 * @return array{title: string, excerpt: string, body: string}
	 */
	public static function state( \WP_Post $post ): array {
		$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$body = trim( (string) preg_replace( '/\s+/', ' ', $body ) );

		return array(
			'title'   => trim( wp_strip_all_tags( (string) $post->post_title ) ),
			'excerpt' => trim( wp_strip_all_tags( (string) $post->post_excerpt ) ),
			'body'    => mb_substr( $body, 0, self::MAX_BODY_CHARS ),
		);
	}

	/**
	 * The terms an article may be filed under, keyed by the id TypeSafe echoes back.
	 *
	 * Keys are `t{term_id}`, never names, so the answer is a value from a closed set and cannot be a
	 * misspelling or an invention.
	 *
	 * @param  string $taxonomy Taxonomy slug.
	 * @return array<string, array{term_id: int, label: string, description: string}>|\WP_Error
	 */
	public static function candidates( string $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$default_term = 'category' === $taxonomy ? (int) get_option( 'default_category' ) : 0;
		$by_id        = array();
		foreach ( $terms as $term ) {
			$by_id[ $term->term_id ] = $term;
		}

		$candidates = array();
		foreach ( $by_id as $term_id => $term ) {
			// Never offer the placeholder as an answer.
			if ( $term_id === $default_term ) {
				continue;
			}

			$path = array( $term->name );
			$seen = array( $term_id );
			$node = $term;
			while ( $node->parent && isset( $by_id[ $node->parent ] ) && ! in_array( $node->parent, $seen, true ) ) {
				$node   = $by_id[ $node->parent ];
				$seen[] = $node->term_id;
				array_unshift( $path, $node->name );
			}

			$candidates[ 't' . $term_id ] = array(
				'term_id'     => (int) $term_id,
				'label'       => html_entity_decode( implode( ' › ', $path ), ENT_QUOTES ),
				'description' => trim( wp_strip_all_tags( (string) $term->description ) ),
			);
		}

		if ( ! $candidates ) {
			return new \WP_Error(
				'no_categories',
				__( 'This site has no categories to choose from. Create some first.', 'workflow-typesafe-categorize' )
			);
		}

		if ( count( $candidates ) > self::MAX_CANDIDATES ) {
			return new \WP_Error(
				'too_many_categories',
				sprintf(
					/* translators: %d: the maximum number of categories. */
					__( 'This site has more than %d categories, which is more than one TypeSafe question can choose between. Point the tool at a smaller taxonomy.', 'workflow-typesafe-categorize' ),
					self::MAX_CANDIDATES
				)
			);
		}

		return $candidates;
	}

	/**
	 * The first-pass question: one Choice across every category, plus "none".
	 *
	 * @param  array $candidates From candidates().
	 * @return array
	 */
	private static function primary_question( array $candidates ): array {
		$criteria = array();
		foreach ( $candidates as $key => $candidate ) {
			$criteria[ $key ] = '' !== $candidate['description']
				? $candidate['label'] . ': ' . $candidate['description']
				: $candidate['label'];
		}
		$criteria[ self::NONE ] = 'None of the other categories describe what this article is mainly about.';

		return array(
			'type'         => 'choice',
			'instructions' => 'Which category is this article mainly about? Judge by the subject the article spends most of its words on, not by a passing mention. Categories are shown as Parent › Child; choose the most specific one that fits.',
			'criteria'     => $criteria,
		);
	}

	/**
	 * The second-pass questions: one independent yes/no per runner-up.
	 *
	 * @param  array $shortlist Candidate key => candidate.
	 * @return array
	 */
	private static function secondary_questions( array $shortlist ): array {
		$questions = array();
		foreach ( $shortlist as $key => $candidate ) {
			$detail = '' !== $candidate['description'] ? ' (' . $candidate['description'] . ')' : '';

			$questions[ $key ] = array(
				'type'         => 'noul',
				'instructions' => sprintf( 'Does this article give substantial attention to the topic "%s"%s, beyond mentioning it in passing?', $candidate['label'], $detail ),
				'criteria'     => array(
					'true'  => 'A real part of the article is about this topic.',
					'false' => 'The topic is only mentioned in passing, or not at all.',
				),
			);
		}

		return $questions;
	}

	/**
	 * Validate a Choice answer against the options that were offered.
	 *
	 * @param  mixed $answer     Raw answer from TypeSafe.
	 * @param  array $candidates Offered candidates.
	 * @return array{choice: string, confidence: float, probabilities: array<string, float>}|\WP_Error
	 */
	private static function read_choice( $answer, array $candidates ) {
		$choice = is_array( $answer ) ? ( $answer['choice'] ?? null ) : null;

		if ( ! is_string( $choice ) || ( self::NONE !== $choice && ! isset( $candidates[ $choice ] ) ) ) {
			return new \WP_Error(
				'typesafe_bad_response',
				__( 'TypeSafe returned a category this extension did not offer.', 'workflow-typesafe-categorize' )
			);
		}

		return array(
			'choice'        => $choice,
			'confidence'    => (float) ( $answer['confidence'] ?? 0 ),
			'probabilities' => array_map( 'floatval', is_array( $answer['probabilities'] ?? null ) ? $answer['probabilities'] : array() ),
		);
	}

	/**
	 * Pull the "yes" probability out of each Noul answer, ignoring any that are malformed.
	 *
	 * @param  array $answers   Answers keyed by question id.
	 * @param  array $shortlist The candidates that were asked about.
	 * @return array<string, float>
	 */
	private static function read_noul_answers( array $answers, array $shortlist ): array {
		$out = array();
		foreach ( array_keys( $shortlist ) as $key ) {
			if ( isset( $answers[ $key ]['noul'] ) && is_numeric( $answers[ $key ]['noul'] ) ) {
				$out[ $key ] = (float) $answers[ $key ]['noul'];
			}
		}

		return $out;
	}

	/**
	 * Whether the first pass is strong enough to write anything.
	 *
	 * @param  array $primary  Result of read_choice().
	 * @param  array $settings Result of settings().
	 * @return bool
	 */
	private static function primary_accepted( array $primary, array $settings ): bool {
		return self::NONE !== $primary['choice'] && $primary['confidence'] >= $settings['min_confidence'] / 100;
	}

	/**
	 * The runners-up worth a second look: the leading probabilities, minus the winner and the noise.
	 *
	 * @param  array $primary    Result of read_choice().
	 * @param  array $candidates Offered candidates.
	 * @return array Candidate key => candidate.
	 */
	private static function shortlist( array $primary, array $candidates ): array {
		$probabilities = $primary['probabilities'];
		unset( $probabilities[ $primary['choice'] ], $probabilities[ self::NONE ] );
		arsort( $probabilities );

		$shortlist = array();
		foreach ( $probabilities as $key => $probability ) {
			if ( count( $shortlist ) >= self::SHORTLIST || $probability < self::SHORTLIST_FLOOR ) {
				break;
			}
			if ( isset( $candidates[ $key ] ) ) {
				$shortlist[ $key ] = $candidates[ $key ];
			}
		}

		return $shortlist;
	}

	/**
	 * The leading candidates, for reporting what was close when nothing was confident.
	 *
	 * @param  array $candidates    Offered candidates.
	 * @param  array $probabilities Candidate key => probability.
	 * @param  int   $limit         How many to return.
	 * @return array<int, array{term_id: int, label: string, role: string, score: float}>
	 */
	private static function top_by_probability( array $candidates, array $probabilities, int $limit ): array {
		unset( $probabilities[ self::NONE ] );
		arsort( $probabilities );

		$rows = array();
		foreach ( $probabilities as $key => $probability ) {
			if ( count( $rows ) >= $limit ) {
				break;
			}
			if ( isset( $candidates[ $key ] ) ) {
				$rows[] = array(
					'term_id' => $candidates[ $key ]['term_id'],
					'label'   => $candidates[ $key ]['label'],
					'role'    => 'considered',
					'score'   => (float) $probability,
				);
			}
		}

		return $rows;
	}
}
