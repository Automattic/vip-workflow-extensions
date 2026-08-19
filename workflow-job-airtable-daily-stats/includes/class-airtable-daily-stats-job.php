<?php
/**
 * Airtable Daily Stats Job.
 *
 * @package WorkflowJobAirtable
 */

declare( strict_types=1 );

namespace WorkflowJobAirtable;

use VIPWorkflow\Jobs\Job;
use VIPWorkflow\Blueprints\BlueprintRepository;

/**
 * Syncs daily post statistics to Airtable.
 */
class AirtableDailyStatsJob extends Job {

	/**
	 * Get the unique job ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'airtable_daily_stats';
	}

	/**
	 * Get the job name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Airtable Daily Stats', 'workflow-job-airtable' );
	}

	/**
	 * Get the job description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Syncs post counts per status to Airtable for reporting.', 'workflow-job-airtable' );
	}

	/**
	 * Get the run interval.
	 *
	 * @return int Interval in seconds.
	 */
	public function get_interval(): int {
		return DAY_IN_SECONDS;
	}

	/**
	 * Get the scheduled time.
	 *
	 * @return string Run at midnight.
	 */
	public function get_scheduled_time(): ?string {
		return '00:00:00';
	}

	/**
	 * Get the job icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'chart-bar';
	}

	/**
	 * Check if this job has configurable settings.
	 *
	 * @return bool
	 */
	public function has_settings(): bool {
		return true;
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		return [
			'enabled'  => ! empty( $input['enabled'] ),
			'api_key'  => sanitize_text_field( $input['api_key'] ?? '' ),
			'base_id'  => sanitize_text_field( $input['base_id'] ?? '' ),
			'table_id' => sanitize_text_field( $input['table_id'] ?? '' ),
		];
	}

	/**
	 * Run the sync job.
	 *
	 * @return array Results summary.
	 */
	public function run(): array {
		$settings = $this->get_settings();

		$results = [
			'enabled'      => false,
			'records_sent' => 0,
			'errors'       => [],
		];

		// Check if sync is enabled.
		if ( empty( $settings['enabled'] ) ) {
			$results['errors'][] = 'Sync is disabled in settings.';
			return $results;
		}

		$results['enabled'] = true;

		// Validate settings.
		if ( empty( $settings['api_key'] ) || empty( $settings['base_id'] ) || empty( $settings['table_id'] ) ) {
			$results['errors'][] = 'Missing Airtable configuration.';
			return $results;
		}

		// Gather statistics.
		$stats = $this->gather_stats();

		// Send to Airtable.
		foreach ( $stats as $stat ) {
			$result = $this->send_to_airtable( $stat, $settings );

			if ( is_wp_error( $result ) ) {
				$results['errors'][] = $result->get_error_message();
			} else {
				$results['records_sent']++;
			}
		}

		return $results;
	}

	/**
	 * Gather post statistics by blueprint and status.
	 *
	 * @return array Statistics data.
	 */
	private function gather_stats(): array {
		$repository = new BlueprintRepository();
		$blueprints = $repository->get_active();
		$stats      = [];
		$today      = wp_date( 'Y-m-d' );

		foreach ( $blueprints as $blueprint ) {
			// Workflow stage is decoupled from post_status (VIPPROD-643); count via
			// the StageQuery seam, which owns the (now stage-meta) aggregation.
			$counts = \VIPWorkflow\Workflow\StageQuery::counts_by_stage( $blueprint );

			foreach ( $blueprint->get_statuses() as $status ) {
				$stats[] = [
					'date'      => $today,
					'blueprint' => $blueprint->name,
					'status'    => $status['label'],
					'count'     => $counts[ $status['key'] ] ?? 0,
				];
			}
		}

		return $stats;
	}

	/**
	 * Send a stat record to Airtable.
	 *
	 * @param array $stat     Stat data.
	 * @param array $settings Airtable settings.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private function send_to_airtable( array $stat, array $settings ) {
		$url = sprintf(
			'https://api.airtable.com/v0/%s/%s',
			$settings['base_id'],
			$settings['table_id']
		);

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'fields' => [
							'Date'      => $stat['date'],
							'Blueprint' => $stat['blueprint'],
							'Status'    => $stat['status'],
							'Count'     => $stat['count'],
						],
					] 
				),
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- cron-driven batch write to Airtable, off the request path.
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new \WP_Error(
				'airtable_error',
				sprintf( 'Airtable API error (%d): %s', $code, $body )
			);
		}

		return true;
	}
}
