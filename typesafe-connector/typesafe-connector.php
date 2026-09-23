<?php
/**
 * Plugin Name: TypeSafe Connector
 * Description: Registers TypeSafe as a connector in Settings → Connectors, so its API key is stored, masked and overridable the same way WordPress stores its AI provider keys.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Author: WordPress VIP
 * Author URI: https://wpvip.com
 * License: GPL-2.0-or-later
 * Text Domain: typesafe-connector
 *
 * Why this exists: TypeSafe is not an AI provider in WordPress's sense (it returns typed judgments, not generated text), so Core does not register it, and plugins that call it would each invent their own settings screen for the key. Registering one connector gives every plugin the same place to look, and gives site owners the standard precedence: environment variable, then constant, then the value saved in Settings → Connectors.
 *
 * It is also the one place that talks to TypeSafe. \TypeSafeConnector\Client resolves the key and sends questions, so an extension that needs TypeSafe lists `typesafe-connector` in its `Requires Plugins` header and calls the client, rather than carrying a copy of it. This repository's rule is that anything an extension needs besides VIP Workflows lives here too, and this is what makes that true for TypeSafe.
 *
 * This plugin deliberately does not require VIP Workflows. It knows nothing about workflows, and says nothing about what the answers are used for.
 *
 * @package TypeSafeConnector
 */

declare( strict_types=1 );

namespace TypeSafeConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-client.php';

const CONNECTOR_ID = 'typesafe';

/**
 * Environment variable and constant that supply the key ahead of the saved setting.
 * TypeSafe's own SDKs read the same variable name, so a key already exported for them just works.
 */
const KEY_NAME = 'TYPESAFE_API_KEY';

add_action( 'wp_connectors_init', __NAMESPACE__ . '\register_connector' );

/**
 * Register the TypeSafe connector.
 *
 * `setting_name` is left out on purpose. Core generates it from the type and id
 * (`connectors_service_typesafe_api_key`), registers the setting, and masks the
 * value in REST responses. Consumers should not hardcode it: read it back from
 * wp_get_connector( 'typesafe' )['authentication']['setting_name'].
 *
 * @param object $registry The WP_Connector_Registry instance.
 * @return void
 */
function register_connector( $registry ): void {
	// Core's registry only exists on WordPress 7.0+. Do nothing on older versions rather than fatal.
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
		return;
	}

	if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( CONNECTOR_ID ) ) {
		return;
	}

	$registry->register(
		CONNECTOR_ID,
		array(
			'name'           => __( 'TypeSafe', 'typesafe-connector' ),
			'description'    => __( 'Fast, typed judgments about text: categories, yes/no questions and scores that code can act on directly.', 'typesafe-connector' ),
			'type'           => 'service',
			'authentication' => array(
				'method'          => 'api_key',
				'credentials_url' => 'https://console.typesafe.ai/settings/keys',
				'constant_name'   => KEY_NAME,
				'env_var_name'    => KEY_NAME,
			),
		)
	);
}
