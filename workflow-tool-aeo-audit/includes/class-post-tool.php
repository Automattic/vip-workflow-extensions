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
	/** Schema shared by both editor and transition invocations. */
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
		$id = $input['post_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return new \WP_Error( 'editorial_invalid_post', __( 'A positive integer post_id is required.', 'workflow-tool-aeo-audit' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'editorial_forbidden', __( 'You cannot edit this post.', 'workflow-tool-aeo-audit' ) );
		}
		if ( ! get_post( $id ) instanceof \WP_Post ) {
			return new \WP_Error( 'editorial_missing_post', __( 'The post could not be found.', 'workflow-tool-aeo-audit' ) );
		}
		return true;
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
