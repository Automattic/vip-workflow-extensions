<?php
/**
 * Plugin Name: Workflow Minimum Pins
 * Description: Phase transition tool that requires a minimum number of pinned sources before leaving ideation.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com/
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-tool-minimum-pins
 *
 * @package WorkflowToolMinimumPins
 */

declare( strict_types=1 );

namespace WorkflowToolMinimumPins;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-minimum-pins-checker.php';

add_action( 'wp_abilities_api_init', [ MinimumPinsChecker::class, 'register' ] );
