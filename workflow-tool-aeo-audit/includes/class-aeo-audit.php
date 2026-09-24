<?php
/** Public-page AEO readiness validator.
 * @package WorkflowToolAeoAudit
 */
declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Read-only, deterministic audit of a saved post's public permalink. */
final class AEO_Audit extends Post_Tool {
	public const ID            = 'workflow-tool-aeo-audit/aeo-audit';
	private const HTML_LIMIT   = 2097152;
	private const ROBOTS_LIMIT = 512000;
	private const AGENTS       = array( 'Googlebot', 'bingbot', 'OAI-SearchBot' );

	/** Register with the existing Workflows report and settings interfaces. */
	public static function register(): void {
		vip_workflows_register_ability(
			self::ID,
			array(
				'label'               => __( 'AEO audit', 'workflow-tool-aeo-audit' ),
				'description'         => __( 'Scores saved content and schema inputs when crawlability is ignored, including drafts. Otherwise audits public HTML, metadata and JSON-LD. Save edits before running.', 'workflow-tool-aeo-audit' ),
				'category'            => 'vip-workflows',
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'status', 'summary', 'score', 'threshold', 'audit_complete', 'checks', 'issues', 'blockers' ),
					'properties' => array(
						'status'                 => array(
							'type' => 'string',
							'enum' => array( 'pass', 'fail' ),
						),
						'summary'                => array( 'type' => 'string' ),
						'score'                  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'threshold'              => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'audit_complete'         => array( 'type' => 'boolean' ),
						'url'                    => array( 'type' => 'string' ),
						'checked_at'             => array( 'type' => 'string' ),
						'profile'                => array( 'type' => 'string' ),
						'rubric_version'         => array( 'type' => 'string' ),
						'audit_mode'             => array( 'type' => 'string' ),
						'public_output_verified' => array( 'type' => 'boolean' ),
						'metrics'                => array( 'type' => 'object' ),
						'breakdown'              => array( 'type' => 'object' ),
						'schema_source'          => array( 'type' => 'string' ),
						'schema_evidence'        => array( 'type' => 'string' ),
						'demo_mode'              => array( 'type' => 'boolean' ),
						'ignored_checks'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'blockers'               => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'checks'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'issues'                 => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'required'   => array( 'rule', 'message', 'severity' ),
								'properties' => array(
									'rule'     => array( 'type' => 'string' ),
									'status'   => array(
										'type' => 'string',
										'enum' => array( 'passed', 'failed' ),
									),
									'message'  => array( 'type' => 'string' ),
									'severity' => array(
										'type' => 'string',
										'enum' => array( 'error', 'warning', 'info' ),
									),
								),
							),
						),
					),
				),
				'permission_callback' => array( self::class, 'can_execute' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'type'                => 'validator',
					'result_type'         => 'report',
					'icon'                => 'search',
					'show_in_rest'        => true,
					'mcp'                 => array( 'public' => true ),
					'show_in_commands'    => true,
					'supports'            => array( 'workflow' ),
					'transition_eligible' => false,
					'settings_schema'     => array(
						'ignore_crawl_restrictions' => array(
							'type'        => 'boolean',
							'default'     => false,
							'label'       => __( 'Ignore crawlability and score saved content (demo/drafts)', 'workflow-tool-aeo-audit' ),
							'description' => __( 'Score saved content, metadata and supported schema inputs without any public HTTP or preview request. Includes drafts. Public output remains unverified; actual crawler settings never change.', 'workflow-tool-aeo-audit' ),
						),
						'min_score'                 => array(
							'type'    => 'integer',
							'default' => 80,
							'minimum' => 0,
							'maximum' => 100,
							'label'   => __( 'Minimum AEO readiness score', 'workflow-tool-aeo-audit' ),

						),
					),
					'annotations'         => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/** Execute only against this site's saved permalink; never accept an arbitrary URL. */
	public static function execute( array $input ): array|\WP_Error {
		$permission = self::can_execute( $input );
		if ( true !== $permission ) {
			return $permission;
		}
		$post      = get_post( $input['post_id'] );
		$options   = self::options(
			self::ID,
			array(
				'min_score'                 => 80,
				'ignore_crawl_restrictions' => false,
			)
		);
		$threshold = is_numeric( $options['min_score'] ) ? max( 0, min( 100, (int) $options['min_score'] ) ) : 80;
		$url       = (string) get_permalink( $post->ID );
		$report    = array(
			'status'                 => 'fail',
			'summary'                => '',
			'score'                  => 0,
			'threshold'              => $threshold,
			'audit_complete'         => false,
			'url'                    => $url,
			'checked_at'             => gmdate( 'c' ),
			'profile'                => 'post' === $post->post_type ? 'Article' : 'WebPage',
			'demo_mode'              => true === $options['ignore_crawl_restrictions'],
			'ignored_checks'         => array(),
			'rubric_version'         => '1.0',
			'audit_mode'             => 'public-html',
			'public_output_verified' => false,
			'checks'                 => array(),
			'issues'                 => array(),
			'blockers'               => array(),
		);
		if ( $report['demo_mode'] ) {
			return AEO_Content::audit( $post, $report );
		}
		$public = 'publish' === $post->post_status && '' === $post->post_password && in_array( $post->post_type, array( 'post', 'page' ), true );
		self::check( $report, 'public-post', $public, 5, true, 'Use a published, password-free post or page. Drafts and previews cannot verify public crawlability.' );
		self::check( $report, 'site-visibility', '1' === (string) get_option( 'blog_public' ), 5, true, 'WordPress discourages search indexing. Review Settings > Reading if this site is intended to be indexed.' );
		if ( ! $public ) {
			return self::finish( $report );
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return new \WP_Error( 'aeo_dom_unavailable', 'The PHP DOM extension is required for the AEO audit.' );
		}
		$response = self::fetch( $url, self::HTML_LIMIT );
		$http_ok  = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
		$body     = $http_ok ? wp_remote_retrieve_body( $response ) : '';
		$type     = $http_ok ? strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) : '';
		$http_ok  = $http_ok && str_contains( $type, 'text/html' ) && '' !== trim( $body ) && strlen( $body ) <= self::HTML_LIMIT;
		self::check( $report, 'public-http', $http_ok, 10, true, 'The anonymous permalink must return HTTP 200 HTML within 3 seconds and 2 MiB. Check redirects, login/edge restrictions, network failures and response size.' );
		if ( ! $http_ok ) {
			return self::finish( $report );
		}
		$document                         = AEO_Document::parse( $body );
		$report['public_output_verified'] = true;
		$directives                       = $document['robots'];
		// Include generic and relevant bot-specific X-Robots-Tag values. Unknown agents are ignored.
		foreach ( (array) wp_remote_retrieve_header( $response, 'x-robots-tag' ) as $header ) {
			$scope = '';
			foreach ( explode( ',', strtolower( (string) $header ) ) as $directive ) {
				$directive = trim( $directive );
				if ( preg_match( '/^([a-z][a-z0-9_-]*):\s*(.*)$/', $directive, $match ) && ! in_array( $match[1], array( 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' ), true ) ) {
					$scope     = $match[1];
					$directive = $match[2];
				}
				if ( '' === $scope || in_array( $scope, array_map( 'strtolower', self::AGENTS ), true ) ) {
					$directives[] = $directive;
				}
			}
		}
		$restricted = false;
		foreach ( $directives as $directive ) {
			if ( preg_match( '/(?:^|[\s,;])(?:noindex|none|nosnippet)(?:$|[\s,;])|max-snippet\s*:\s*0(?:$|[\s,;])/i', $directive ) || stripos( $directive, 'unavailable_after' ) !== false ) {
				$restricted = true;
			}
		}
		self::check( $report, 'index-and-snippets', ! $restricted, 10, true, 'Remove unintended noindex/none, nosnippet, max-snippet:0 or unavailable_after restrictions from robots meta or X-Robots-Tag. Expiry directives require manual review.' );
		$parts       = wp_parse_url( $url );
		$robots_url  = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/robots.txt';
		$robots      = self::fetch( $robots_url, self::ROBOTS_LIMIT );
		$robots_code = is_wp_error( $robots ) ? 0 : wp_remote_retrieve_response_code( $robots );
		$robots_body = is_wp_error( $robots ) ? '' : wp_remote_retrieve_body( $robots );
		// Missing robots.txt is allowed; other failures are deliberately conservative.
		$robots_known   = in_array( $robots_code, array( 404, 410 ), true ) || ( 200 === $robots_code && strlen( $robots_body ) <= self::ROBOTS_LIMIT && ! preg_match( '/<(?:!doctype|html|body)\b/i', $robots_body ) );
		$blocked_agents = array();
		if ( $robots_known && 200 === $robots_code ) {
			foreach ( self::AGENTS as $agent ) {
				if ( ! AEO_Document::robots_allowed( $robots_body, $url, $agent ) ) {
					$blocked_agents[] = $agent;
				}
			}
		}
		self::check(
			$report,
			'robots-txt',
			$robots_known && empty( $blocked_agents ),
			10,
			true,
			$robots_known ? 'robots.txt blocks this URL for: ' . implode( ', ', $blocked_agents ) . '. Review the relevant Allow/Disallow rules.' : 'Could not verify robots.txt (redirect, HTTP error, oversized response or unexpected HTML). Resolve it and rerun; unknown is not a pass.'
		);
		self::check( $report, 'html-title', '' !== $document['title'], 5, false, 'Populate the rendered HTML title through the theme or SEO plugin.' );
		self::check( $report, 'meta-description', '' !== $document['description'], 5, false, 'Populate the rendered meta description through the SEO plugin.' );
		$canonical_ok = 1 === count( $document['canonicals'] ) && AEO_Document::same_page( $document['canonicals'][0], $url );
		self::check( $report, 'canonical', $canonical_ok, 10, ! empty( $document['canonicals'] ), 'Render exactly one canonical link pointing to this permalink. Review conflicting SEO plugins or a canonical pointing elsewhere.' );
		$json_ok = $document['json_count'] > 0 && 0 === $document['json_errors'];
		self::check( $report, 'json-ld', $json_ok, 5, true, 'Add valid JSON-LD and fix every malformed or empty JSON-LD script. This audit does not evaluate Microdata or RDFa.' );
		$article = 'Article' === $report['profile'];
		$primary = AEO_Document::primaries( $document['nodes'], $url, $article );
		self::check( $report, 'primary-schema', ! empty( $primary ), 5, true, 'Provide a schema.org ' . $report['profile'] . ' node tied to this permalink by url, @id or mainEntityOfPage. Related articles do not satisfy this check.' );
		$fields = $article ? array( 'headline', 'author', 'datePublished', 'dateModified', 'image', 'publisher' ) : array( 'name', 'description', 'url' );
		foreach ( $fields as $field ) {
			$valid = ! empty( $primary );
			foreach ( $primary as $node ) {
				$node_valid = AEO_Document::field_valid( $field, $node[ $field ] ?? null, $document['nodes'] );
				if ( 'url' === $field ) {
					$node_valid = $node_valid && AEO_Document::same_page( $node['url'], $url );
				}
				$valid = $valid && $node_valid;
			}
			self::check( $report, 'schema-' . $field, $valid, $article ? 5 : 10, false, 'Populate a valid ' . $field . ' on every primary ' . $report['profile'] . ' node (including output from multiple plugins). Resolve local @id references and replace blank values or template placeholders.' );
		}
		$report['audit_complete'] = $robots_known;
		return self::finish( $report );
	}

	/** Same-origin, anonymous bounded requests. Redirects never escape this origin. */
	private static function fetch( string $url, int $limit ): array|\WP_Error {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) || isset( $target['user'] ) || isset( $target['pass'] ) ||
			! in_array( $target['scheme'] ?? '', array( 'http', 'https' ), true ) ||
			strtolower( $target['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) ||
			( $target['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) ||
			( $target['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return new \WP_Error( 'aeo_unsafe_url', 'The audit requires a permalink on the current site origin.' );
		}
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => $limit + 1,
				'cookies'             => array(),
				'user-agent'          => 'WPVIP-AEO-Audit/1.0',
				'headers'             => array( 'Accept' => 'text/html, text/plain;q=0.9' ),
			)
		);
	}

	/** Add an inspectable weighted check and, on failure, an actionable finding. */
	private static function check( array &$report, string $rule, bool $pass, int $weight, bool $blocking, string $message ): void {
		$report['checks'][] = array(
			'rule'     => $rule,
			'status'   => $pass ? 'pass' : 'fail',
			'points'   => $pass ? $weight : 0,
			'possible' => $weight,
			'blocking' => $blocking,
		);
		if ( $pass ) {
			$report['score']   += $weight;
			$report['issues'][] = array(
				'rule'     => $rule,
				'status'   => 'passed',
				'severity' => 'info',
				'message'  => 'This public-output check passed.',
			);
			return;
		}
		$report['issues'][] = array(
			'rule'     => $rule,
			'message'  => $message,
			'severity' => $blocking ? 'error' : 'warning',
			'status'   => 'failed',
		);
		if ( $blocking ) {
			$report['blockers'][] = $rule;
		}
	}

	/** A score cannot override a blocker or incomplete network inspection. */
	private static function finish( array $report ): array {
		$report['status']  = $report['audit_complete'] && empty( $report['blockers'] ) && $report['score'] >= $report['threshold'] ? 'pass' : 'fail';
		$report['summary'] = sprintf(
			'AEO readiness: %d/100; threshold %d. %s %s Public HTML rubric v1.0; not a guarantee of indexing, rich results or AI citation.',
			$report['score'],
			$report['threshold'],
			$report['audit_complete'] ? 'Public audit completed.' : 'Audit incomplete; unchecked items earn no points.',
			'pass' === $report['status'] ? 'Pass.' : 'Fail: resolve blockers and findings, then rerun.'
		);
		return $report;
	}
}
