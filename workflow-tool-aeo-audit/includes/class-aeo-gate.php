<?php
/**
 * A transition-safe view of the same audit used by the editor report.
 *
 * @package WorkflowToolAeoAudit
 */
declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Gate issues contain only blocking verdicts, never successful report rows. */
final class AEO_Gate extends Post_Tool {
	public const ID = 'workflow-tool-aeo-audit/aeo-gate';

	/** Register separately so older transition consumers cannot misread the report. */
	public static function register(): void {
		vip_workflows_register_ability(
			self::ID,
			array(
				'label'               => __( 'AEO publishing gate', 'workflow-tool-aeo-audit' ),
				'description'         => __( 'Require a complete passing AEO audit before a workflow transition. Uses AEO audit settings and reruns saved content each time. Run AEO audit on demand for the full checklist.', 'workflow-tool-aeo-audit' ),
				'category'            => 'vip-workflows',
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'status', 'summary', 'score', 'threshold', 'issues' ),
					'properties' => array(
						'status'    => array( 'type' => 'string', 'enum' => array( 'pass', 'fail' ) ),
						'summary'   => array( 'type' => 'string' ),
						'score'     => array( 'type' => 'integer' ),
						'threshold' => array( 'type' => 'integer' ),
						'issues'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'permission_callback' => array( self::class, 'can_execute' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'type'                => 'validator',
					'result_type'         => 'report',
					'icon'                => 'shield',
					'show_in_rest'        => true,
					'show_in_commands'    => false,
					'supports'            => array( 'workflow' ),
					'transition_eligible' => true,
					'annotations'         => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	/** Recompute the audit; never trust an earlier report, input score or UI state. */
	public static function execute( array $input ): array|\WP_Error {
		$report = AEO_Audit::execute( $input );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$passed = $report['audit_complete'] && empty( $report['blockers'] ) && $report['score'] >= $report['threshold'] && 'pass' === $report['status'];
		$issues = array();
		if ( ! $passed ) {
			$fixes = array();
			foreach ( $report['issues'] as $issue ) {
				if ( 'failed' === ( $issue['status'] ?? '' ) ) {
					$fixes[] = $issue['rule'] . ': ' . $issue['message'];
				}
			}
			$issues[] = array(
				'check_key' => 'aeo_readiness',
				'rule'      => 'AEO readiness',
				'status'    => 'failed',
				'severity'  => 'error',
				'message'   => $report['summary'] . ' ' . implode( ' ', $fixes ) . ' Save corrections and run AEO audit for the full checklist, then retry the transition.',
			);
		}
		return array(
			'status'    => $passed ? 'pass' : 'fail',
			'summary'   => $report['summary'],
			'score'     => $report['score'],
			'threshold' => $report['threshold'],
			'issues'    => $issues,
		);
	}
}
