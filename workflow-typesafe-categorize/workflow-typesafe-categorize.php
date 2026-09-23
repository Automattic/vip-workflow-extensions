<?php
/**
 * Plugin Name: Workflow TypeSafe Categorizer
 * Description: Categorizes a post from its content using TypeSafe, as a command-palette tool that suggests categories and as an AI-stage agent that assigns them when TypeSafe is confident.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * License: GPL-2.0-or-later
 * Requires Plugins: vip-workflows, typesafe-connector
 * Text Domain: workflow-typesafe-categorize
 *
 * Why this exists: choosing categories is a judgment about text with a fixed set of possible answers, which is the job TypeSafe's models are built for. Asking a text-generation model to do it means parsing prose back into term IDs and hoping it did not invent a category. TypeSafe returns a typed answer and a probability for each option instead, so the code decides what is confident enough to write to a post, and the model cannot invent a category that does not exist.
 *
 * It needs the TypeSafe Connector plugin from this repository, which holds the API key and the client that talks to TypeSafe. Nothing here stores or ships a key.
 *
 * @package WorkflowTypeSafeCategorize
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeCategorize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-categorizer.php';
require_once __DIR__ . '/includes/class-categorize-tool.php';
require_once __DIR__ . '/includes/class-categorize-agent.php';

// The tool is a plain ability, so it registers on the Abilities API hook.
add_action( 'wp_abilities_api_init', array( CategorizeTool::class, 'register' ) );

// The agent is a stage ability plus a card on the Agents tab.
add_action( 'vip_workflows_register_abilities', array( CategorizeAgent::class, 'register' ) );
add_action( 'vip_workflows_register_assistant_meta', array( CategorizeAgent::class, 'register_agent_meta' ) );
