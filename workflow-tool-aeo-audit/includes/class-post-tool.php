<?php
/**
 * Shared post access and input handling.
 *
 * @package WorkflowToolAeoAudit
 */

declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Common contract for tools that operate on saved posts. */
abstract class Post_Tool {
	/** Schema shared by both editor and transition invocations. The editor also sends the tool's options, so extra properties are allowed and ignored. */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	/**
	 * Check access to the specific post, including direct callback invocations.
	 *
	 * @param array $input Ability input.
	 */
	public static function can_execute( array $input ): bool|\WP_Error {
		$id = self::post_id( $input );
		if ( null === $id ) {
			return new \WP_Error( 'aeo_invalid_post', __( 'A positive integer post_id is required.', 'workflow-tool-aeo-audit' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'aeo_forbidden', __( 'You cannot edit this post.', 'workflow-tool-aeo-audit' ) );
		}
		if ( ! get_post( $id ) instanceof \WP_Post ) {
			return new \WP_Error( 'aeo_missing_post', __( 'The post could not be found.', 'workflow-tool-aeo-audit' ) );
		}
		return true;
	}

	/**
	 * The requested post ID. Workflows transitions send an integer; a REST GET
	 * run sends the same schema-valid value as a digit string.
	 *
	 * @param array $input Ability input.
	 */
	protected static function post_id( array $input ): ?int {
		$id = $input['post_id'] ?? null;
		if ( is_string( $id ) && ctype_digit( $id ) ) {
			$id = (int) $id;
		}
		return is_int( $id ) && $id >= 1 ? $id : null;
	}

	/**
	 * Read stored options, falling back to explicit defaults.
	 *
	 * @param string $id Ability ID.
	 * @param array  $defaults Default options.
	 */
	protected static function options( string $id, array $defaults ): array {
		return array_replace( $defaults, \VIPWorkflows\Abilities\AbilitySettings::get_instance()->get_options( $id ) );
	}

	/**
	 * Record one weighted check and its report row. Both audit modes use this, so
	 * every row has a readable label, evidence on pass and a fix on failure.
	 *
	 * @param array     $report   Report being built.
	 * @param string    $category Breakdown category.
	 * @param string    $rule     Stable machine slug.
	 * @param string    $label    Editor-facing row title.
	 * @param bool|null $pass     Result; null means not applicable and unscored.
	 * @param int       $weight   Points available.
	 * @param string    $evidence Shown when the check passes.
	 * @param string    $fix      Shown when the check fails.
	 * @param bool      $blocking Whether a failure blocks regardless of score.
	 * @param string    $note     Shown instead of the generic text when not applicable.
	 */
	protected static function check( array &$report, string $category, string $rule, string $label, ?bool $pass, int $weight, string $evidence, string $fix, bool $blocking = false, string $note = '' ): void {
		$points                                        = true === $pass ? $weight : 0;
		$possible                                      = null === $pass ? 0 : $weight;
		$report['checks'][]                            = array(
			'rule'     => $rule,
			'label'    => $label,
			'status'   => null === $pass ? 'not-applicable' : ( $pass ? 'pass' : 'fail' ),
			'points'   => $points,
			'possible' => $possible,
			'blocking' => $blocking,
			'category' => $category,
		);
		$report['breakdown'][ $category ]            ??= array(
			'earned'   => 0,
			'possible' => 0,
		);
		$report['breakdown'][ $category ]['earned']   += $points;
		$report['breakdown'][ $category ]['possible'] += $possible;
		$issue = array(
			'rule'     => $label,
			'severity' => false === $pass ? ( $blocking ? 'error' : 'warning' ) : 'info',
			'message'  => null === $pass ? ( '' !== $note ? $note : $label . ': not applicable; excluded from scoring.' ) : ( $pass ? $evidence : $fix ),
		);
		if ( null !== $pass ) {
			$issue['status'] = $pass ? 'passed' : 'failed';
		}
		$report['issues'][] = $issue;
		if ( false === $pass && $blocking ) {
			$report['blockers'][] = $rule;
		}
	}

	/**
	 * Plain text without rendering shortcodes or dynamic blocks.
	 *
	 * @param string $content Stored content.
	 */
	public static function plain_text( string $content ): string {
		$content = strip_shortcodes( $content );
		// Preserve word boundaries between block-level elements.
		$content = preg_replace( '/<\/(?:p|div|h[1-6]|li|blockquote)>|<br\s*\/?>/i', ' ', $content );
		$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $content ) ?? '' );
	}
}
