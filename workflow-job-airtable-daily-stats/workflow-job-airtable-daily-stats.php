<?php
/**
 * Plugin Name: Workflow Airtable Daily Stats
 * Description: Syncs daily post statistics to Airtable. Demonstrates how to add custom jobs to VIP Workflow.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-job-airtable
 *
 * @package WorkflowJobAirtable
 */

declare( strict_types=1 );

namespace WorkflowJobAirtable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORKFLOW_JOB_AIRTABLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORKFLOW_JOB_AIRTABLE_URL', plugin_dir_url( __FILE__ ) );

// Load the job class.
require_once __DIR__ . '/includes/class-airtable-daily-stats-job.php';

/**
 * Register the Airtable stats job with the VIP Workflow job scheduler.
 *
 * Settings are configured on the Integrations → Jobs page.
 */
add_action(
	'vip_workflow_register_jobs',
	function ( \VIPWorkflow\Jobs\JobScheduler $scheduler ) {
		$scheduler->register_job( new AirtableDailyStatsJob() );
	}
);

/**
 * Enqueue admin scripts for the Jobs page.
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! str_contains( $hook, 'vip-workflow-jobs' ) ) {
			return;
		}

		$asset_file = WORKFLOW_JOB_AIRTABLE_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'workflow-job-airtable-admin',
			WORKFLOW_JOB_AIRTABLE_URL . 'build/admin.js',
			array_merge( $asset['dependencies'], [ 'vip-workflow-admin' ] ),
			$asset['version'],
			true
		);
	} 
);
