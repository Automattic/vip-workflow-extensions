<?php
/**
 * Plugin Name: Workflow Excerpt Generator
 * Description: AI-powered excerpt generation using OpenAI. Demonstrates how to add custom tools/abilities to VIP Workflow.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflow
 * Text Domain: workflow-tool-excerpt
 *
 * @package WorkflowToolExcerpt
 */

declare( strict_types=1 );

namespace WorkflowToolExcerpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the excerpt generator class.
require_once __DIR__ . '/includes/class-excerpt-generator.php';

/**
 * Register the Excerpt Generator ability.
 *
 * The ability is registered with WordPress's Abilities API and will appear
 * in the VIP Workflow Tools tab on the Integrations page.
 */
add_action( 'wp_abilities_api_init', [ ExcerptGenerator::class, 'register' ] );
