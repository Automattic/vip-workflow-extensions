<?php
/**
 * Plugin Name: Workflow Editorial Alignment Checker
 * Description: AI-powered editorial alignment validation. Check content against custom editorial rules (tone, brand voice, formatting, etc.).
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * Requires Plugins: vip-workflows
 * Text Domain: workflow-tool-editorial-alignment
 *
 * @package WorkflowToolEditorialAlignment
 */

declare( strict_types=1 );

namespace WorkflowToolEditorialAlignment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the editorial alignment checker class.
require_once __DIR__ . '/includes/class-editorial-alignment-checker.php';

/**
 * Register the Editorial Alignment Checker ability.
 *
 * The ability is registered with WordPress's Abilities API and will appear
 * on the VIP Workflows Tools screen.
 */
add_action( 'wp_abilities_api_init', array( EditorialAlignmentChecker::class, 'register' ) );
