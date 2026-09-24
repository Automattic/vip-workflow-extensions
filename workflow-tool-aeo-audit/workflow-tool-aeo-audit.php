<?php
/**
 * Plugin Name: Workflow AEO Audit
 * Description: AEO readiness reports and a publishing gate for VIP Workflows.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: vip-workflows
 * License: GPL-2.0-or-later
 * Text Domain: workflow-tool-aeo-audit
 *
 * Helps editors find missing structured data and metadata before publication,
 * including on intentionally non-crawlable sites. It also checks published HTML.
 * No API key, AI service or build step is required. PHP DOM is required;
 * Rank Math is an optional source adapter, never an installation dependency.
 *
 * @package WorkflowToolAeoAudit
 */

declare( strict_types=1 );

namespace WorkflowToolAeoAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-post-tool.php';
require_once __DIR__ . '/includes/class-aeo-document.php';
require_once __DIR__ . '/includes/class-aeo-content.php';
require_once __DIR__ . '/includes/class-aeo-audit.php';
require_once __DIR__ . '/includes/class-aeo-settings.php';
require_once __DIR__ . '/includes/class-aeo-gate.php';

// Runtime detection also supports a core supplied as a VIP platform integration.
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_tools' );

/** Register the detailed report, transition gate and administrator settings ability. */
function register_tools(): void {
	if ( ! function_exists( 'vip_workflows_register_ability' ) || ! class_exists( '\\VIPWorkflows\\Abilities\\AbilitySettings' ) ) {
		return;
	}
	foreach ( array( AEO_Audit::class, AEO_Gate::class, AEO_Settings::class ) as $tool ) {
		$tool::register();
	}
}

add_action(
	'admin_notices',
	static function (): void {
		if ( current_user_can( 'manage_options' ) && ! function_exists( 'vip_workflows_register_ability' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Workflow AEO Audit requires an active VIP Workflows integration. Its tools will register when that integration is available.', 'workflow-tool-aeo-audit' ) . '</p></div>';
		}
	}
);

/** Scoped presentation for this plugin's AEO results inside the Workflows modal. */
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		if ( ! function_exists( 'vip_workflows_register_ability' ) ) {
			return;
		}
		wp_enqueue_style( 'workflow-aeo-report', plugins_url( 'assets/aeo-report.css', __FILE__ ), array(), '1.0.0' );
		wp_enqueue_script( 'workflow-aeo-report', plugins_url( 'assets/aeo-report.js', __FILE__ ), array(), '1.0.0', true );
		wp_add_inline_script( 'workflow-aeo-report', 'window.workflowAeoReport = ' . wp_json_encode( array( 'label' => __( 'AEO audit', 'workflow-tool-aeo-audit' ) ) ) . ';', 'before' );
	}
);
