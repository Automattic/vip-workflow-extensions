<?php
/** Standalone plugin registration and dependency-guard regression checks. */
declare( strict_types=1 );

if ( ! in_array( '--without-core', $argv, true ) ) {
 require __DIR__ . '/aeo.php';
 function vip_workflows_register_ability( string $id, array $args ): void { $GLOBALS['aeo_registered'][ $id ] = $args; }
 function wp_register_ability( string $id, array $args ): void { $GLOBALS['aeo_registered'][ $id ] = $args; }
}
function add_action( string $name, mixed $callback ): void { $GLOBALS['aeo_hooks'][ $name ][] = $callback; }
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../workflow-tool-aeo-audit.php';
foreach ( $GLOBALS['aeo_hooks']['wp_abilities_api_init'] as $callback ) { $callback(); }
if ( in_array( '--without-core', $argv, true ) ) {
 if ( ! empty( $GLOBALS['aeo_registered'] ) ) { throw new RuntimeException( 'Must not register without Workflows.' ); }
 echo "PASS: missing dependency guard\n";
 exit;
}
$aeo_id = 'workflow-tool-aeo-audit/aeo-audit';
check( count( $GLOBALS['aeo_registered'] ) === 3, 'Only AEO report, gate and settings register' );
check( isset( $GLOBALS['aeo_registered'][ $aeo_id ], $GLOBALS['aeo_registered']['workflow-tool-aeo-audit/aeo-settings'] ), 'Standalone ability IDs' );
check( false === $GLOBALS['aeo_registered'][ $aeo_id ]['meta']['transition_eligible'], 'Detailed report stays separate from transition verdicts' );
check( $GLOBALS['aeo_registered'][ $aeo_id ]['meta']['show_in_commands'], 'Report available in command palette' );
check( $GLOBALS['aeo_registered']['workflow-tool-aeo-audit/aeo-gate']['meta']['transition_eligible'], 'Gate is transition eligible' );
echo "PASS: 5 standalone registration checks\n";
