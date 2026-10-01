<?php
/** Gate verdicts must agree with the editor report and fail closed. */
declare(strict_types=1);
require __DIR__ . '/aeo-content.php';
require __DIR__ . '/../includes/class-aeo-gate.php';
use WorkflowToolAeoAudit\AEO_Gate as Gate;
use VIPWorkflows\Abilities\AbilitySettings as Settings;
$checks = 0;
content_case(); $gate = Gate::execute(['post_id'=>1]);
check($gate['status']==='pass' && $gate['issues']===[] && $requests===[], 'Ready draft passes gate without HTTP or spurious warnings');
content_case(); $image=false; $gate=Gate::execute(['post_id'=>1]);
check($gate['status']==='pass' && $gate['score']===90 && $gate['issues']===[], 'Above-threshold audit passes despite individual findings');
Settings::$options['min_score']=95; $gate=Gate::execute(['post_id'=>1]);
check($gate['status']==='fail' && $gate['issues'][0]['severity']==='error', 'Shared threshold creates a hard failure');
check(str_contains($gate['issues'][0]['message'],'featured image'), 'Blocked transition includes remediation');
check($gate['score']===run()['score'] && $gate['status']===run()['status'], 'Gate and on-demand report agree');
$image=true; check(Gate::execute(['post_id'=>1])['issues']===[], 'Corrected content is reevaluated without stale cached verdict');
content_case(); $aeo_test_post->post_content.='<script type="application/ld+json">{bad}</script>'; Settings::$options['min_score']=0;
check(Gate::execute(['post_id'=>1])['issues'][0]['severity']==='error', 'Malformed schema blocks even at zero threshold');
content_case(); Settings::$options=['audit_mode'=>'public','min_score'=>0];
check(Gate::execute(['post_id'=>1])['status']==='fail', 'Incomplete public audit blocks even at zero threshold');
content_case(); $allowed=false; check(Gate::execute(['post_id'=>1]) instanceof WP_Error, 'Gate preserves edit permission');
content_case(); $aeo_test_post->post_type='attachment'; check(Gate::execute(['post_id'=>1]) instanceof WP_Error, 'Unsupported content fails closed');
content_case(); $image=false; Settings::$options['min_score']=95;
check(Gate::execute(['post_id'=>1,'score'=>100,'audit_mode'=>'public'])['status']==='fail', 'Caller cannot override score or stored settings');
echo 'PASS: ' . (int) $checks . " gate checks\n";
