<?php
/**
 * Plugin Name: Workflow Ntfy Channel
 * Plugin URI: https://github.com/your-org/workflow-ntfy
 * Description: Adds ntfy.sh push notification support to VIP Workflow. Supports multiple topics for different notification routing.
 * Version: 2.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * License: GPL-2.0+
 * Requires Plugins: vip-workflow
 *
 * @package WorkflowNtfy
 */

declare( strict_types=1 );

namespace WorkflowNtfy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
const VERSION = '2.0.0';

/**
 * Plugin directory path.
 */
define( 'WORKFLOW_NTFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORKFLOW_NTFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register ntfy channels with VIP Workflow.
 *
 * Creates multiple channel instances - one per configured destination.
 */
add_action(
	'vip_workflow_register_notification_channels',
	function ( $dispatcher ) {
		require_once WORKFLOW_NTFY_DIR . 'includes/class-ntfy-channel.php';

		// Register all configured ntfy destinations as separate channels.
		$channels = NtfyChannel::create_channel_instances();
		foreach ( $channels as $channel ) {
			$dispatcher->register_channel( $channel );
		}
	} 
);

/**
 * Register REST API routes for managing ntfy destinations.
 */
add_action(
	'rest_api_init',
	function () {
		require_once WORKFLOW_NTFY_DIR . 'includes/class-ntfy-channel.php';

		register_rest_route(
			'workflow-ntfy/v1',
			'/destinations',
			[
				[
					'methods'             => 'GET',
					'callback'            => function () {
						return new \WP_REST_Response(
							[
								'destinations' => NtfyChannel::get_destinations(),
							] 
						);
					},
					'permission_callback' => fn() => current_user_can( 'manage_options' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => function ( $request ) {
						$destinations = $request->get_json_params();

						if ( ! is_array( $destinations ) ) {
							return new \WP_Error( 'invalid_data', 'Destinations must be an array', [ 'status' => 400 ] );
						}

						NtfyChannel::save_destinations( $destinations );

						return new \WP_REST_Response(
							[
								'success'      => true,
								'destinations' => NtfyChannel::get_destinations(),
							] 
						);
					},
					'permission_callback' => fn() => current_user_can( 'manage_options' ),
				],
			] 
		);
	} 
);

/**
 * Enqueue admin scripts for the Notifications page (Channels tab).
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! str_contains( $hook, 'vip-workflow-notifications' ) ) {
			return;
		}

		$asset_file = WORKFLOW_NTFY_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'workflow-channel-ntfy-admin',
			WORKFLOW_NTFY_URL . 'build/admin.js',
			array_merge( $asset['dependencies'], [ 'vip-workflow-admin' ] ),
			$asset['version'],
			true
		);

		// Enqueue styles if they exist.
		$css_file = WORKFLOW_NTFY_DIR . 'build/style-admin.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'workflow-channel-ntfy-admin',
				WORKFLOW_NTFY_URL . 'build/style-admin.css',
				[ 'vip-workflow-admin' ],
				$asset['version']
			);
		}

		// Pass ntfy destinations to the frontend.
		require_once WORKFLOW_NTFY_DIR . 'includes/class-ntfy-channel.php';
		wp_localize_script(
			'workflow-channel-ntfy-admin',
			'workflowNtfy',
			[
				'destinations' => NtfyChannel::get_destinations(),
				'defaultServer' => 'https://ntfy.sh',
			] 
		);
	} 
);
