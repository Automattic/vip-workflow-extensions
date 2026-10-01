<?php
/**
 * End-to-end gate check against a real VIP Workflows install. Run on a disposable site only:
 *
 *     wp eval-file tests/e2e-transition.php --user=<administrator>
 *
 * Creates a test sequence, fixture posts and a temporary editor, drives real
 * transitions through StatusManager::transition() (the method behind
 * vip-workflows/transition-post) as that editor, and removes its fixtures
 * afterwards. Administrators bypass required tools by default, so the editor
 * is what makes the gate apply. This extension's own settings are restored.
 *
 * @package WorkflowToolAeoAudit
 */

// No strict_types: wp eval-file evaluates this after its own prologue.

use VIPWorkflows\Abilities\AbilitySettings;

$aeo_checks = 0;
$aeo_fail   = static function ( string $label, mixed $detail = null ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- CLI-only diagnostic of this script's own fixtures.
	throw new RuntimeException( $label . ( null === $detail ? '' : ' ' . print_r( $detail, true ) ) );
};
$aeo_check  = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$aeo_checks, $aeo_fail ): void {
	++$aeo_checks;
	if ( ! $ok ) {
		$aeo_fail( 'FAIL: ' . $label, $detail );
	}
	WP_CLI::log( 'ok - ' . $label );
};
$aeo_run    = static function ( string $name, array $input ): mixed {
	$ability = wp_get_ability( $name );
	return $ability ? $ability->execute( $input ) : new WP_Error( 'missing_ability', $name );
};

$aeo_settings = AbilitySettings::get_instance();
$aeo_saved    = array(
	'audit' => $aeo_settings->get( 'workflow-tool-aeo-audit/aeo-audit' ),
	'gate'  => $aeo_settings->get( 'workflow-tool-aeo-audit/aeo-gate' ),
);
$aeo_posts    = array();
$aeo_sequence = null;
$aeo_admin    = get_current_user_id();
$aeo_editor   = 0;

try {
	$aeo_settings->update(
		'workflow-tool-aeo-audit/aeo-audit',
		array(
			'enabled' => true,
			'options' => array(
				'audit_mode' => 'saved-content',
				'min_score'  => 80,
			),
		)
	);
	$aeo_settings->update( 'workflow-tool-aeo-audit/aeo-gate', array( 'enabled' => true ) );

	$aeo_sequence = $aeo_run(
		'vip-workflows/create-sequence',
		array(
			'name'       => 'AEO gate end-to-end test',
			'post_types' => array( 'post' ),
			'statuses'   => array(
				array(
					'key'         => 'aeo-e2e-draft',
					'label'       => 'Drafting',
					'is_initial'  => true,
					'status'      => 'draft',
					'transitions' => array(
						array(
							'to'             => 'aeo-e2e-published',
							'label'          => 'Publish',
							'required_tools' => array( 'workflow-tool-aeo-audit/aeo-gate' ),
						),
					),
				),
				array(
					'key'         => 'aeo-e2e-published',
					'label'       => 'Published',
					'status'      => 'publish',
					'is_terminal' => true,
				),
			),
		)
	);
	$aeo_check( is_array( $aeo_sequence ) && isset( $aeo_sequence['sequence_id'] ), 'Test sequence created with the gate as a required tool', $aeo_sequence );

	// The post-type mapping is read at load, before this sequence existed.
	add_filter( 'vip_workflows_sequences_for_post', static fn( $ids ) => array_merge( $ids, array( (int) $aeo_sequence['sequence_id'] ) ) );
	$aeo_manager  = new \VIPWorkflows\Workflow\StatusManager();
	$aeo_new_post = static function ( string $content, string $excerpt ) use ( &$aeo_posts, $aeo_manager, $aeo_sequence, $aeo_fail ): int {
		$id          = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'AEO gate end-to-end fixture',
				'post_name'    => 'aeo-gate-e2e-' . wp_generate_password( 6, false ),
				'post_excerpt' => $excerpt,
				'post_content' => $content,
			),
			true
		);
		$aeo_posts[] = $id;
		$assigned    = $aeo_manager->assign_sequence( $id, (int) $aeo_sequence['sequence_id'] );
		if ( true !== $assigned ) {
			$aeo_fail( 'Could not assign the test sequence', $assigned );
		}
		return $id;
	};
	$aeo_editor = wp_insert_user(
		array(
			'user_login' => 'aeo-e2e-editor-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'editor',
		)
	);
	$aeo_check( is_int( $aeo_editor ) && ! \VIPWorkflows\Admin\Settings::can_user_bypass_tool_checks( $aeo_editor ), 'Temporary editor does not bypass required tools', $aeo_editor );
	wp_set_current_user( $aeo_editor );
	$aeo_body     = '<h2>What changed</h2><p>' . str_repeat( 'Useful, specific detail for readers. ', 30 ) . '</p><p>' . str_repeat( 'Supporting evidence and context. ', 20 ) . '</p>';

	// 1. A thin draft is hard-blocked, and acknowledging warnings cannot bypass it.
	$aeo_weak = $aeo_new_post( '<p>Too short.</p>', '' );
	$aeo_url  = get_permalink( $aeo_weak );
	$aeo_check( str_contains( $aeo_url, '?p=' ), 'Draft permalink is the plain ?p=ID form on a pretty-permalink site', $aeo_url );
	foreach ( array( false, true ) as $aeo_ack ) {
		$aeo_result = $aeo_manager->transition( $aeo_weak, 'aeo-e2e-published', array( 'acknowledge_warnings' => $aeo_ack ) );
		$aeo_data   = is_wp_error( $aeo_result ) ? $aeo_result->get_error_data() : array();
		$aeo_check( is_wp_error( $aeo_result ) && 'tool_check_failed' === $aeo_result->get_error_code() && ! empty( $aeo_data['hard_failures'] ), 'Low score blocks the real transition (acknowledge_warnings=' . ( $aeo_ack ? 'true' : 'false' ) . ')', $aeo_result );
	}
	$aeo_check( 'draft' === get_post_status( $aeo_weak ), 'Blocked post stays a draft' );

	// 2. Reviewer scenario: JSON-LD using the final slug URL in a draft body, no schema provider.
	// The URL the editor shows in its permalink panel, which is what an author copies.
	require_once ABSPATH . 'wp-admin/includes/post.php';
	[ $aeo_template, $aeo_name ] = get_sample_permalink( $aeo_weak );
	$aeo_final                   = str_replace( array( '%pagename%', '%postname%' ), $aeo_name, $aeo_template );
	$aeo_ld   = '<!-- wp:html --><script type="application/ld+json">' . wp_json_encode(
		array(
			'@context'      => 'https://schema.org',
			'@type'         => 'BlogPosting',
			'url'           => $aeo_final,
			'headline'      => 'AEO gate end-to-end fixture',
			'description'   => 'A fixture with saved JSON-LD.',
			'author'        => array( 'name' => 'Fixture Author' ),
			'publisher'     => array( 'name' => 'Fixture Publisher' ),
			'image'         => 'https://example.org/fixture.jpg',
			'datePublished' => '2026-09-28',
			'dateModified'  => '2026-09-28',
		)
	) . '</script><!-- /wp:html -->';
	wp_update_post(
		array(
			'ID'           => $aeo_weak,
			'post_content' => $aeo_body . $aeo_ld,
			'post_excerpt' => 'A fixture with saved JSON-LD.',
		)
	);
	$aeo_report = $aeo_run( 'workflow-tool-aeo-audit/aeo-audit', array( 'post_id' => $aeo_weak ) );
	$aeo_rows   = array_column( $aeo_report['checks'], 'status', 'rule' );
	$aeo_check( 'saved-json-ld' === $aeo_report['schema_source'] && 'pass' === $aeo_rows['schema-source'] && 'pass' === $aeo_rows['schema-headline'], 'Draft JSON-LD with the slug URL is matched to the post', $aeo_report );

	// 3. Corrected content proceeds through the same transition.
	$aeo_result = $aeo_manager->transition( $aeo_weak, 'aeo-e2e-published' );
	$aeo_check( ! is_wp_error( $aeo_result ) && 'publish' === get_post_status( $aeo_weak ), 'Corrected post passes the gate and publishes', $aeo_result );

	// 4. No schema provider and no JSON-LD: 100 is reachable and the default threshold is met from post data.
	$aeo_plain  = $aeo_new_post( $aeo_body, 'A useful summary for readers.' );
	$aeo_report = $aeo_run( 'workflow-tool-aeo-audit/aeo-audit', array( 'post_id' => $aeo_plain ) );
	$aeo_check( 'wordpress-inputs' === $aeo_report['schema_source'] && $aeo_report['score'] >= 80, 'Provider-free draft scores from WordPress post data (' . $aeo_report['score'] . '/100; no featured image)', $aeo_report );
	$aeo_result = $aeo_manager->transition( $aeo_plain, 'aeo-e2e-published' );
	$aeo_check( ! is_wp_error( $aeo_result ) && 'publish' === get_post_status( $aeo_plain ), 'Provider-free post passes the gate at the default threshold', $aeo_result );

	// 5. An execution error (WP_Error) from the gate hard-blocks and cannot be acknowledged.
	$aeo_error = $aeo_new_post( $aeo_body, 'A useful summary for readers.' );
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: store oversized content without running content filters so the gate returns aeo_content_unavailable.
	$wpdb->update( $wpdb->posts, array( 'post_content' => str_repeat( 'x', 2097153 ) ), array( 'ID' => $aeo_error ) );
	clean_post_cache( $aeo_error );
	$aeo_result = $aeo_manager->transition( $aeo_error, 'aeo-e2e-published', array( 'acknowledge_warnings' => true ) );
	$aeo_data   = is_wp_error( $aeo_result ) ? $aeo_result->get_error_data() : array();
	$aeo_check( is_wp_error( $aeo_result ) && 'execution_error' === ( $aeo_data['hard_failures'][0]['key'] ?? '' ) && 'draft' === get_post_status( $aeo_error ), 'Gate WP_Error blocks the transition even with acknowledge_warnings', $aeo_result );

	WP_CLI::success( $aeo_checks . ' end-to-end checks passed on WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', VIP Workflows ' . ( defined( 'VIP_WORKFLOWS_VERSION' ) ? VIP_WORKFLOWS_VERSION : 'unknown' ) . '.' );
} finally {
	wp_set_current_user( $aeo_admin );
	if ( $aeo_editor > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $aeo_editor );
	}
	foreach ( $aeo_posts as $aeo_id ) {
		wp_delete_post( $aeo_id, true );
	}
	if ( is_array( $aeo_sequence ) && isset( $aeo_sequence['sequence_id'] ) && class_exists( '\\VIPWorkflows\\Sequences\\SequenceRepository' ) ) {
		( new \VIPWorkflows\Sequences\SequenceRepository() )->delete( (int) $aeo_sequence['sequence_id'] );
	}
	$aeo_settings->update( 'workflow-tool-aeo-audit/aeo-audit', $aeo_saved['audit'] );
	$aeo_settings->update( 'workflow-tool-aeo-audit/aeo-gate', $aeo_saved['gate'] );
}
