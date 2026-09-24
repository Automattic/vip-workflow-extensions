<?php
/** Saved-source readiness regressions. No HTTP, provider service or content writes. */
declare(strict_types=1);
namespace RankMath {
 class Helper {
  public static bool $active = true;
  public static string|false $schema_type = 'BlogPosting';
  public static string $description = '';
  public static bool $throw = false;
  public static function is_module_active(string $module): bool { return self::$active; }
  public static function replace_seo_fields(string $field, \WP_Post $aeo_test_post): string { if(self::$throw) { throw new \RuntimeException('secret'); } return '%seo_title%' === $field ? $aeo_test_post->post_title : (self::$description ?: $aeo_test_post->post_excerpt); }
  public static function get_settings(string $key): string { return 'Example Publisher'; }
  public static function get_default_schema_type(int $id, bool $valid): string|false { return self::$schema_type; }
 }
}
namespace RankMath\Schema {
 class DB {
  public static array $schemas = [];
  public static function get_schemas(int $id): array { return self::$schemas; }
 }
}
namespace {
 require __DIR__ . '/aeo.php';
 use VIPWorkflows\Abilities\AbilitySettings as Settings;
 use WorkflowToolAeoAudit\AEO_Content as Content;
 function strip_shortcodes(string $s): string { return preg_replace('/\[.*?\]/', '', $s); }
 function wp_strip_all_tags(string $s): string {
  // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- Standalone WordPress test double removes script/style contents before stripping tags.
  return strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $s));
 }
 function get_post_thumbnail_id(int $id): int { return $GLOBALS['image'] ? 10 : 0; }
 function wp_attachment_is_image(int $id): bool { return $id === 10; }
 function wp_get_attachment_image_url(int $id, string $size): string|false { return $id === 10 ? 'https://example.test/image.jpg' : false; }
 function get_userdata(int $id): object|false { return $GLOBALS['author_exists'] ? (object)['display_name'=>'Example Author'] : false; }
 function get_bloginfo(string $key): string { return 'Example Site'; }
 function content_case(): void {
  reset_case(); Settings::$write_ok = true;
  Settings::$options = ['ignore_crawl_restrictions'=>true];
  $GLOBALS['public'] = '0'; $GLOBALS['aeo_test_post']->post_status = 'draft';
  $GLOBALS['aeo_test_post']->post_content = '<h2>A useful section</h2><p>'.str_repeat('Evidence and examples ',35).'</p><p>'.str_repeat('More useful detail ',25).'</p><img src="image.jpg" alt="Example"><a href="https://example.test/source">Original source</a>';
  $GLOBALS['image'] = true; $GLOBALS['author_exists'] = true;
  \RankMath\Helper::$active = true; \RankMath\Helper::$schema_type = 'BlogPosting'; \RankMath\Helper::$description = ''; \RankMath\Helper::$throw = false; \RankMath\Schema\DB::$schemas = [];
 }
 $checks = 0;
 content_case(); $before = clone $aeo_test_post; $result = run();
 check($result['status'] === 'pass' && $result['score'] === 100, 'Draft with ready saved inputs gets a full score');
 check($requests === [] && get_object_vars($aeo_test_post) === get_object_vars($before), 'No fetch, preview or content mutation');
 check($result['audit_complete'] && !$result['public_output_verified'] && $result['audit_mode'] === 'saved-content', 'Saved audit complete does not claim verified public HTML');
 check($result['metrics']['short_paragraph_ratio'] === 1.0 && $result['metrics']['image_alt_ratio'] === 1.0, 'Content ratios surfaced');
 check(count(array_filter($result['issues'],fn($i)=>($i['status']??'')==='passed')) === 16, 'Passed checks are native report rows');
 check($result['issues'][count($result['issues'])-1]['severity'] === 'info' && !isset($result['issues'][count($result['issues'])-1]['status']), 'Ignored checks neutral instead of failed badge');
 foreach (['publish','private','pending','future'] as $post_status_case) { $aeo_test_post->post_status=$post_status_case; check(run()['score']===100 && !$requests, 'Status cannot penalize saved scoring: '.$post_status_case); }
 $aeo_test_post->post_password='protected'; check(run()['score']===100 && !$requests, 'Protected content is read locally with edit permissions');
 content_case(); $aeo_test_post->post_content = '<p>A short paragraph.</p>'; $image=false; $result=run();
 check($result['score']>0 && $result['score']<100 && $requests===[], 'Partial draft earns a meaningful score');
 check($result['breakdown']['content']['possible']===30 && $result['metrics']['image_alt_ratio']===null, 'Absent images and links are N/A and excluded');
 check(count(array_filter($result['issues'],fn($i)=>($i['status']??'')==='failed'))>=3, 'Failures rendered with failed status');
 content_case(); $aeo_test_post->post_title=''; $result=run(); check($result['score']<100 && !str_contains($result['issues'][0]['message'],'is populated'), 'Failure text never contradicts result');
 content_case(); $aeo_test_post->post_content=''; $aeo_test_post->post_excerpt=''; $image=false; check(run()['score']<80, 'Empty content cannot get a high readiness score');
 content_case(); $aeo_test_post->post_content='<p>'.str_repeat('long ',150).'</p><p>Short.</p><img src="x"><a href="x">click here</a>'; $r=run(); check($r['metrics']['short_paragraph_ratio']===0.5 && $r['metrics']['image_alt_ratio']===0.0 && $r['metrics']['descriptive_link_ratio']===0.0, 'Failed ratio measurements are correct');
 $content_metrics=Content::metrics('<script>'.str_repeat('junk ',200).'</script><style>junk</style><p>Real text</p>'); check($content_metrics['word_count']===2, 'Scripts and styles cannot inflate word count');
 content_case(); Settings::$options=['ignore_crawl_restrictions'=>false]; $aeo_test_post->post_status='draft'; check(run()['status']==='fail' && !$requests, 'Strict public mode still requires published output');
 content_case(); $allowed=false; check(run() instanceof WP_Error && !$requests, 'Edit permission remains required');
 content_case(); $aeo_test_post->post_type='attachment'; check(run() instanceof WP_Error, 'Unsupported types rejected');
 content_case(); $aeo_test_post->post_content=str_repeat('x',2097153); check(run() instanceof WP_Error, 'Saved input size bounded');
 content_case(); \RankMath\Helper::$active=false; $r=run(); check($r['schema_source']==='unavailable' && $r['score']===60, 'Unsupported provider does not fabricate schema credit');
 content_case(); \RankMath\Helper::$schema_type=false; check(run()['score']===60, 'Explicitly disabled default Article does not earn schema credit');
 content_case(); \RankMath\Helper::$throw=true; check(run()['status']==='fail' && !str_contains(wp_json_encode(run()),'secret'), 'Provider errors fail safely');
 content_case(); \RankMath\Helper::$description='%unknown%'; check(run()['score']===85, 'Unresolved description placeholders do not earn points');
 content_case(); $author_exists=false; check(run()['score']===95, 'Missing author loses source points');
 content_case(); $aeo_test_post->post_type='page'; check(run()['score']===100 && run()['profile']==='WebPage', 'Page default inputs use WebPage profile');
 content_case(); \RankMath\Schema\DB::$schemas=[['@type'=>'BlogPosting','headline'=>'%seo_title%','description'=>'%seo_description%','author'=>['@type'=>'Person','name'=>'%author%'],'publisher'=>['@type'=>'Organization','name'=>'%sitename%'],'image'=>'%post_thumbnail%','datePublished'=>'%date(Y-m-dTH:i:sP)%','dateModified'=>'%modified(Y-m-dTH:i:sP)%']];
 check(run()['score']===100, 'Supported saved schema variables resolve from this post');
 \RankMath\Schema\DB::$schemas[0]['image']=''; check(run()['score']===95, 'Explicit blank schema is not masked by a featured image');
 \RankMath\Schema\DB::$schemas[0]['headline']='%unsupported%'; check(run()['score']===90, 'Unsupported schema tokens fail rather than guess');
 content_case(); $aeo_test_post->post_content.='<script type="application/ld+json">{bad}</script>'; check(run()['status']==='fail' && in_array('invalid-saved-schema',run()['blockers'],true), 'Malformed saved JSON blocks despite configured provider');
 content_case(); \RankMath\Helper::$active=false; $aeo_test_post->post_content .= '<script type="application/ld+json">'.wp_json_encode(['@context'=>'https://schema.org','@type'=>'BlogPosting','url'=>'https://example.test/article/','headline'=>'Title','description'=>'Description','author'=>['name'=>'Author'],'publisher'=>['name'=>'Publisher'],'image'=>'https://example.test/image.jpg','datePublished'=>'2026-01-01','dateModified'=>'2026-01-02']).'</script>'; check(run()['score']===100, 'Saved JSON-LD supports provider-free inspection');
 echo "PASS: " . (int) $checks . " content-readiness checks\n";
}
