<?php
/**
 * Plugin Name: Workflow AEO Audit
 * Description: AEO readiness reports and a publishing gate for VIP Workflows.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: workflow-tool-aeo-audit
 *
 * Helps editors find missing structured data and metadata before publication,
 * including on intentionally non-crawlable sites. It also checks published HTML.
 * No API key, AI service or build step is required. PHP DOM is required;
 * Rank Math is an optional source adapter, never an installation dependency.
 *
 * No "Requires Plugins" header: on every hosted VIP site checked so far, VIP
 * Workflows is delivered as a VIP platform integration rather than a plugin
 * folder, so WordPress's dependency check can never resolve it and would
 * block activation outright. register_tools() below does the equivalent
 * check at runtime instead and no-ops (with an admin notice and a Plugins-row
 * notice) if VIP Workflows isn't actually active.
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

/**
 * Same signal, in the one place an admin is already looking right after
 * activating it: the Plugins list row. The static "Requires Plugins" header
 * only covers VIP Workflows shipped as a plugin folder; a site where it is
 * provided as a VIP platform integration drops that header (see the
 * showme-site deployment), so this runtime-driven row is the one notice
 * that reaches an admin on every delivery shape.
 */
add_action(
	'after_plugin_row_' . plugin_basename( __FILE__ ),
	static function ( string $plugin_file, array $plugin_data, string $status ): void {
		if ( function_exists( 'vip_workflows_register_ability' ) ) {
			return;
		}
		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%s</p></div></td></tr>',
			esc_html__( 'Workflow AEO Audit is active, but no VIP Workflows integration was found. Its report, gate and settings tools will not appear until one is active.', 'workflow-tool-aeo-audit' )
		);
	},
	10,
	3
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
