<?php
/** AEO behavior tests with anonymous HTTP and WordPress boundaries stubbed. */
declare(strict_types=1);
namespace VIPWorkflows\Abilities {
 class AbilitySettings {
  public static array $options = [];
  public static array $extra = ['unrelated-tool'=>['enabled'=>false]];
  public static bool $write_ok = true;
  public function get(string $id): array { return array_merge(['enabled'=>true,'options'=>self::$options], self::$extra[$id] ?? []); }
  public function update(string $id, array $values): bool { if (!self::$write_ok) { return false; } self::$options = array_merge(self::$options, $values['options'] ?? []); unset($values['options']); self::$extra[$id] = array_merge(self::$extra[$id] ?? [], $values); return true; }
  public static function get_instance(): self { return new self(); }
  public function get_options(string $id): array { return self::$options; }
  public function is_enabled(string $id): bool { return true; }
  public function is_hard_check(string $id, string $key): bool { return false; }
 }
}
namespace {
 use WorkflowToolAeoAudit\AEO_Audit as Audit;
 use WorkflowToolAeoAudit\AEO_Document as Document;
 use VIPWorkflows\Abilities\AbilitySettings as Settings;
 class WP_Error { public function __construct(public string $code, public string $message, public mixed $data = null) {} }
 class WP_Post { public int $ID = 1; public string $post_type = 'post'; public string $post_status = 'publish'; public string $post_password = ''; public string $post_title = 'Example article'; public string $post_content = ''; public string $post_excerpt = 'Example excerpt'; public string $post_name = 'article'; public int $post_author = 1; public string $post_date = '2026-01-02 10:30:00'; public string $post_modified = '2026-01-03 10:30:00'; }
 class WP_Http {
  public static function make_absolute_url(string $value, string $base): string {
   if (str_starts_with($value, '#')) { return $base . $value; }
   if (str_starts_with($value, '/')) { return 'https://example.test' . $value; }
   return $value;
  }
 }
 function wp_json_encode(mixed $v, int $flags = 0): string {
  // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress test double must delegate to PHP, not recursively call itself.
  return json_encode($v, $flags | JSON_THROW_ON_ERROR);
 }
 function __(string $s, string $domain = ''): string { return $s; }
 function current_user_can(string $cap, int $id = 0): bool { return $GLOBALS['allowed']; }
 function get_post(int $id): ?WP_Post { return $id === 1 ? $GLOBALS['aeo_test_post'] : null; }
 function get_permalink(int $id): string { return $GLOBALS['url']; }
 function get_sample_permalink(WP_Post $post): array { return ['https://example.test/%postname%/', $post->post_name]; }
 function get_option(string $key): mixed { return $GLOBALS['public']; }
 function home_url(string $path = '/'): string { return 'https://example.test/'; }
 function wp_parse_url(string $url, int $component = -1): mixed {
  // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone WordPress stub delegates to PHP without recursively calling itself.
  return parse_url($url, $component);
 }
 function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
 function wp_remote_retrieve_response_code(array $r): int { return $r['code']; }
 function wp_remote_retrieve_body(array $r): string { return $r['body']; }
 function wp_remote_retrieve_header(array $r, string $name): mixed { return $r['headers'][$name] ?? ''; }
 function wp_safe_remote_get(string $url, array $options): array|WP_Error {
  $GLOBALS['requests'][] = [$url, $options];
  return str_ends_with($url, '/robots.txt') ? $GLOBALS['robots_response'] : $GLOBALS['page_response'];
 }
 require __DIR__ . '/../includes/class-post-tool.php';
 require __DIR__ . '/../includes/class-aeo-document.php';
 require __DIR__ . '/../includes/class-aeo-content.php';
 require __DIR__ . '/../includes/class-aeo-audit.php';
 require __DIR__ . '/../includes/class-aeo-settings.php';
 $checks = 0;
 function check(bool $ok, string $label): void {
  ++$GLOBALS['checks'];
  if (!$ok) {
   // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only assertion label is a literal supplied by these tests, never HTML output.
   throw new \RuntimeException($label);
  }
 }
 function response(string $body, int $code = 200): array { return ['code'=>$code, 'body'=>$body, 'headers'=>['content-type'=>'text/html; charset=UTF-8']]; }
 function html(array $schema, string $meta = ''): string {
  return '<html><head><title>Example article</title><meta name="description" content="A description"><link rel="canonical" href="https://example.test/article/">' . $meta . '<script type="application/ld+json">' . wp_json_encode($schema) . '</script></head><body>Article</body></html>';
 }
 function run(): array|WP_Error { return Audit::execute(['post_id'=>1]); }
 function reset_case(): void {
  $GLOBALS['aeo_test_post'] = new WP_Post(); $GLOBALS['allowed'] = true; $GLOBALS['public'] = '1';
  $GLOBALS['url'] = 'https://example.test/article/'; $GLOBALS['requests'] = []; Settings::$options = ['audit_mode'=>'public'];
  $GLOBALS['schema'] = ['@context'=>'https://schema.org', '@graph'=>[
   ['@type'=>'BlogPosting', '@id'=>'https://example.test/article/#article', 'headline'=>'Example article', 'author'=>['@id'=>'#author'], 'publisher'=>['@id'=>'#publisher'], 'image'=>['@id'=>'#image'], 'datePublished'=>'2026-01-02T10:30:00Z', 'dateModified'=>'2026-01-03'],
   ['@type'=>'Person', '@id'=>'#author', 'name'=>'Example Author'],
   ['@type'=>'Organization', '@id'=>'#publisher', 'name'=>'Example Publisher'],
   ['@type'=>'ImageObject', '@id'=>'#image', 'url'=>'https://example.test/image.jpg'],
  ]];
  $GLOBALS['page_response'] = response(html($GLOBALS['schema']));
  $GLOBALS['robots_response'] = response("User-agent: *\nDisallow: /private/\n");
 }
 reset_case(); $result = run();
 check($result['score'] === 100 && $result['status'] === 'pass' && $result['audit_complete'], 'Complete article graph passes');
 check(count($requests) === 2 && $requests[0][1]['redirection'] === 0 && $requests[0][1]['cookies'] === [] && $requests[0][1]['limit_response_size'] === 2097153, 'Bounded anonymous nonredirecting requests');
 $public = '0'; check(run()['status'] === 'fail', 'Site privacy blocks despite 95 score');
 Settings::$options['min_score'] = 0; check(run()['status'] === 'fail', 'Threshold cannot override blockers');
 reset_case(); $schema['@graph'][0]['headline'] = ''; $page_response = response(html($schema));
 Settings::$options['min_score'] = 96; check(run()['score'] === 95 && run()['status'] === 'fail', 'Configurable threshold enforced');
 Settings::$options['min_score'] = 95; check(run()['status'] === 'pass', 'Threshold equality passes');
 reset_case(); $aeo_test_post->post_type = 'page'; $schema = ['@context'=>'https://schema.org', '@type'=>['WebPage','AboutPage'], '@id'=>'https://example.test/article/#webpage', 'name'=>'About us', 'description'=>'Our company', 'url'=>'https://example.test/article/']; $page_response = response(html($schema));
 check(run()['score'] === 100 && run()['profile'] === 'WebPage', 'Pages use their own profile');
 $schema['description'] = ''; $page_response = response(html($schema)); check(run()['score'] === 90, 'Page profile field weights');
 reset_case(); $schema['@graph'][0]['@id'] = 'https://example.test/unrelated/#article'; $page_response = response(html($schema)); check(in_array('primary-schema', run()['blockers'], true), 'Unrelated article cannot satisfy primary schema');
 reset_case(); $schema['@context'] = 'https://evil.example/context'; $page_response = response(html($schema)); check(in_array('primary-schema', run()['blockers'], true), 'Unknown contexts are not guessed');
 reset_case(); $page_response['body'] .= '<script type="application/ld+json">{broken}</script>'; check(in_array('json-ld', run()['blockers'], true), 'Malformed second script fails despite valid first script');
 reset_case(); $schema['@graph'][0]['headline'] = 'Rock &amp; Roll'; $parsed = Document::parse(html($schema)); check($parsed['nodes'][0]['headline'] === 'Rock &amp; Roll', 'JSON script entity preservation');
 foreach (['noindex','none','nosnippet','max-snippet:0','max-snippet: 0','unavailable_after: 01 Jan 2020 00:00:00 GMT'] as $directive) {
  reset_case(); $page_response = response(html($schema, '<meta name="googlebot" content="'.$directive.'">')); check(in_array('index-and-snippets', run()['blockers'], true), 'Restrictive meta: '.$directive);
 }
 foreach (['noindex', 'googlebot: noindex, nofollow', ['bingbot: noindex', 'index']] as $header) {
  reset_case(); $page_response['headers']['x-robots-tag'] = $header; check(in_array('index-and-snippets', run()['blockers'], true), 'Header restrictions');
 }
 reset_case(); $page_response['headers']['x-robots-tag'] = 'unrelatedbot: noindex'; check(run()['status'] === 'pass', 'Unrelated scoped header ignored');
 foreach ([301,302,401,403,404,429,500] as $code) { reset_case(); $page_response['code'] = $code; check(!run()['audit_complete'] && run()['status'] === 'fail', 'HTTP failure/redirect: '.$code); }
 reset_case(); $page_response = new WP_Error('timeout','internal secret'); check(!str_contains(wp_json_encode(run()), 'internal secret'), 'Network errors do not leak internals');
 reset_case(); $page_response['body'] = str_repeat('x',2097153); check(!run()['audit_complete'], 'HTML size limit');
 reset_case(); $page_response['headers']['content-type'] = 'application/json'; check(!run()['audit_complete'], 'Non-HTML response');
 foreach ([404,410] as $code) { reset_case(); $robots_response = response('', $code); check(run()['status'] === 'pass', 'Missing robots file allowed'); }
 foreach ([301,401,403,429,500] as $code) { reset_case(); $robots_response = response('', $code); check(!run()['audit_complete'], 'Unknown robots response fails closed'); }
 reset_case(); $robots_response = response('<html>Login</html>'); check(!run()['audit_complete'], 'HTML robots response not a pass');
 reset_case(); $robots_response = response(str_repeat('x',512001)); check(!run()['audit_complete'], 'Robots size limit');
 reset_case(); $aeo_test_post->post_status = 'draft'; check(!run()['audit_complete'] && count($requests) === 0, 'Draft never fetches a public preview');
 reset_case(); $aeo_test_post->post_password = 'secret'; check(!run()['audit_complete'] && count($requests) === 0, 'Password protected content never fetched');
 reset_case(); $url = 'https://outside.example/article/'; check(!run()['audit_complete'] && count($requests) === 0, 'Off-origin permalink rejected');
 reset_case(); $allowed = false; check(run() instanceof WP_Error && count($requests) === 0, 'Unauthorized requests never fetch');
 $rules = [
  ["User-agent: *\nDisallow: /", false],
  ["User-agent: *\nDisallow:\n", true],
  ["User-agent: *\nDisallow: /\nAllow: /article/", true],
  ["User-agent: *\nAllow: /article/\nDisallow: /article/", true],
  ["User-agent: *\nDisallow: /\nUser-agent: Googlebot\nAllow: /", true],
  ["User-agent: Googlebot\nDisallow: /article/\nUser-agent: Googlebot\nAllow: /article/", true],
  ["User-agent: Googlebot\nUser-agent: bingbot\nDisallow: /article/", false],
  ["User-agent: *\nDisallow: /*cle/$", false],
  ["User-agent: *\nDisallow: /Article/", true],
  ["User-agent: *\nDisallow: /%61rticle/", false],
 ];
 foreach ($rules as [$text,$expected]) { check(Document::robots_allowed($text,'https://example.test/article/','Googlebot') === $expected, 'REP: '.$text); }
 foreach ([null,'',[],['@id'=>'#missing']] as $value) { check(!Document::field_valid('author',$value,[]), 'Missing author does not pass'); }
 check(!Document::field_valid('datePublished','2026-02-30',[]), 'Invalid date rejected');
 check(!Document::field_valid('image','javascript:alert(1)',[]), 'Image must be HTTP URL');
 check(!Document::field_valid('headline','%seo_title%',[]), 'Template placeholder rejected');
 check(!Document::same_page('', 'https://example.test/article/'), 'Blank canonical rejected');
 reset_case(); $page_response['body'] = str_replace('<link rel="canonical" href="https://example.test/article/">', '', $page_response['body']); check(run()['score'] === 90 && run()['status'] === 'pass', 'Missing canonical loses points without hard block');
 reset_case(); $page_response['body'] = str_replace('rel="canonical" href="https://example.test/article/"', 'rel="canonical" href="https://example.test/other/"', $page_response['body']); check(in_array('canonical', run()['blockers'], true), 'Conflicting canonical blocks');
 reset_case(); $duplicate = $schema['@graph'][0]; $duplicate['image'] = ['@type'=>'ImageObject','url'=>'']; $schema['@graph'][] = $duplicate; $page_response = response(html($schema)); check(run()['score'] === 95, 'Incomplete duplicate primary schema is detected');
 reset_case(); $settings_class = \WorkflowToolAeoAudit\AEO_Settings::class;
 $result = $settings_class::execute(['site_url'=>'https://example.test','settings'=>['audit_mode'=>'saved-content','min_score'=>90,'enabled'=>true,'show_in_commands'=>true]]);
 check($result['updated'] && $result['settings']['options']['min_score'] === 90 && 'saved-content' === $result['settings']['options']['audit_mode'], 'Admin can configure only AEO settings');
 check(Settings::$extra['unrelated-tool'] === ['enabled'=>false], 'Other tool settings preserved');
 check(!$settings_class::execute(['site_url'=>'https://example.test','settings'=>['min_score'=>90]])['updated'], 'Idempotent settings update');
 check(!$settings_class::execute(['site_url'=>'https://example.test'])['updated'], 'Settings read does not write');
 check($settings_class::execute(['site_url'=>'https://other.test']) instanceof WP_Error, 'Settings site guard');
 foreach ([['min_score'=>101],['min_score'=>'80'],['enabled'=>'yes'],['arbitrary_option'=>true],['audit_mode'=>'demo'],['ignore_crawl_restrictions'=>true]] as $bad) { check($settings_class::execute(['site_url'=>'https://example.test','settings'=>$bad]) instanceof WP_Error, 'Bad settings rejected'); }
 Settings::$write_ok = false; check($settings_class::execute(['site_url'=>'https://example.test','settings'=>['min_score'=>50]]) instanceof WP_Error, 'Settings write failure reported');
 $allowed = false; check($settings_class::execute(['site_url'=>'https://example.test']) instanceof WP_Error, 'Settings require administrator');
 foreach (['1', 1] as $ok_id) { reset_case(); check(!(Audit::execute(['post_id'=>$ok_id]) instanceof WP_Error), 'REST digit-string and integer post_id accepted'); }
 foreach (['1.5', ' 1', '0', 0, -1, '1e1', null, [1]] as $bad_id) { reset_case(); check(Audit::execute(['post_id'=>$bad_id]) instanceof WP_Error && [] === $requests, 'Malformed post_id fails closed'); }
 reset_case(); $result = run(); $rows = array_column(array_filter($result['issues'], fn($i) => isset($i['status'])), 'rule');
 check(in_array('robots.txt access', $rows, true) && !in_array('robots-txt', $rows, true) && !in_array('This public-output check passed.', array_column($result['issues'], 'message'), true), 'Public rows use readable labels and evidence');
 check(40 === $result['breakdown']['crawl']['possible'] && 20 === $result['breakdown']['metadata']['possible'] && 40 === $result['breakdown']['schema']['possible'], 'Public breakdown matches the documented rubric');
 foreach (['Google', 'G', 'Googlebot-News'] as $token) { check(Document::robots_allowed("User-agent: {$token}\nDisallow: /", 'https://example.test/article/', 'Googlebot'), 'REP product token is exact, not a prefix: '.$token); }
 check(!Document::robots_allowed("User-agent: GOOGLEBOT\nDisallow: /", 'https://example.test/article/', 'Googlebot'), 'REP product token is case-insensitive');
 echo "PASS: " . (int) $checks . " AEO checks\n";
}
