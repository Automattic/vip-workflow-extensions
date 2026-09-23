<?php
/**
 * Plugin Name: Workflow TypeSafe Editorial Alignment
 * Description: Checks a post against the site's editorial guidelines using TypeSafe, one probability per rule, so the same content gets the same answer and a hard-mode gate blocks on a number rather than on generated text.
 * Version: 1.0.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * License: GPL-2.0-or-later
 * Requires Plugins: vip-workflows, typesafe-connector
 * Text Domain: workflow-typesafe-editorial-alignment
 *
 * Why this exists: a compliance gate asks a yes/no question per rule, and `workflow-tool-editorial-alignment` answers it by asking a text model for JSON. That works, but the gate is then only as steady as generated text: the same rule can reach different verdicts on the same copy, and the reply has to be parsed, sized against a token budget and defended against truncation. TypeSafe answers a yes/no question with a probability, so this extension asks one question per rule, the code decides what probability counts as a departure, and a hard-mode block rests on a number an editor can read and a threshold an administrator can change.
 *
 * It sits beside the original rather than replacing it, so the two engines can be run against the same guidelines and compared. The ability id differs, and both can be installed.
 *
 * It needs the TypeSafe Connector plugin from this repository, which holds the API key and the client that talks to TypeSafe. Nothing here stores or ships a key, and no AI provider needs to be configured in VIP Workflows.
 *
 * @package WorkflowTypeSafeEditorialAlignment
 */

declare( strict_types=1 );

namespace WorkflowTypeSafeEditorialAlignment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-editorial-alignment-checker.php';

// The checker is a plain ability, so it registers on the Abilities API hook.
add_action( 'wp_abilities_api_init', array( EditorialAlignmentChecker::class, 'register' ) );
