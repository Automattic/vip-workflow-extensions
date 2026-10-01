<?php
/** Saved-content audit for drafts and intentionally non-crawlable sites.
 * @package WorkflowToolAeoAudit
 */
declare( strict_types=1 );
namespace WorkflowToolAeoAudit;

/** Scores content and schema inputs without rendering or fetching a preview. */
final class AEO_Content extends Post_Tool {
	/** Build a complete source-readiness report, never a public crawlability claim. */
	public static function audit( \WP_Post $post, array $report ): array|\WP_Error {
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'aeo_content_unsupported', 'Save a post or page before running content readiness.' );
		}
		if ( strlen( $post->post_content ) > 2097152 || ! class_exists( '\DOMDocument' ) ) {
			return new \WP_Error( 'aeo_content_unavailable', 'Content readiness requires PHP DOM and saved content no larger than 2 MiB.' );
		}
		$report['audit_mode']             = 'saved-content';
		$report['public_output_verified'] = false;
		$report['rubric_version']         = '2.0-content';
		$report['ignored_checks']         = array( 'public-post', 'site-visibility', 'public-http', 'index-and-snippets', 'robots-txt', 'canonical' );
		$report['breakdown']              = array();
		$metrics                          = self::metrics( $post->post_content );
		$report['metrics']                = $metrics;
		$title                            = self::plain_text( $post->post_title );
		$excerpt                          = self::plain_text( $post->post_excerpt );
		$has_title                        = self::populated( $title );
		self::check( $report, 'content', 'content-title', 'Content title', $has_title, 10, 'A saved title is populated.', 'Add a descriptive title and replace TODO/TK placeholders.' );
		self::check( $report, 'content', 'content-length', 'Content length', $metrics['word_count'] >= 150, 10, $metrics['word_count'] . ' words; the rubric targets at least 150.', 'Expand the saved body into useful coverage for the reader. The rubric targets 150 words; length alone is not quality.' );
		self::check( $report, 'content', 'content-headings', 'Content structure', $metrics['heading_count'] > 0, 5, $metrics['heading_count'] . ' nonempty H2/H3 headings.', 'Add descriptive H2/H3 headings to make the content easier to scan.' );
		self::check( $report, 'content', 'paragraph-ratio', 'Concise paragraphs', null === $metrics['short_paragraph_ratio'] ? false : $metrics['short_paragraph_ratio'] >= 0.8, 5, self::ratio_label( $metrics['short_paragraph_ratio'] ) . ' of paragraphs contain 120 words or fewer.', 'Current concise-paragraph ratio: ' . self::ratio_label( $metrics['short_paragraph_ratio'] ) . '. Break up long paragraphs. The target is at least 80% at 120 words or fewer; add paragraph blocks if none are present.' );
		self::check( $report, 'content', 'image-alt-ratio', 'Image alternatives', null === $metrics['image_alt_ratio'] ? null : $metrics['image_alt_ratio'] >= 1, 5, self::ratio_label( $metrics['image_alt_ratio'] ) . ' of saved inline images have an alt attribute. Empty alt is allowed for decorative images; review its meaning manually.', 'Current alt-attribute coverage: ' . self::ratio_label( $metrics['image_alt_ratio'] ) . '. Give each inline image an alt attribute: describe informative images; use empty alt only for decorative images.', false, 'Image alternatives: no inline images, so this check is excluded from scoring.' );
		self::check( $report, 'content', 'link-label-ratio', 'Descriptive link labels', null === $metrics['descriptive_link_ratio'] ? null : $metrics['descriptive_link_ratio'] >= 1, 5, self::ratio_label( $metrics['descriptive_link_ratio'] ) . ' of links have nonempty labels beyond click here/read more.', 'Current descriptive-link ratio: ' . self::ratio_label( $metrics['descriptive_link_ratio'] ) . '. Use descriptive link text or a meaningful image alternative instead of empty or generic link labels.', false, 'Descriptive link labels: no links, so this check is excluded from scoring.' );

		$source                    = self::schema_source( $post, $title, $excerpt );
		$report['schema_source']   = $source['source'];
		$report['schema_evidence'] = $source['evidence'];
		self::check( $report, 'metadata', 'description-source', 'Description source', self::populated( $source['description'] ), 10, 'An SEO description source or manual excerpt is populated.', 'Add an SEO meta description or a manual excerpt and replace unresolved template variables.' );
		self::check( $report, 'metadata', 'featured-image', 'Featured image', wp_attachment_is_image( get_post_thumbnail_id( $post->ID ) ), 5, 'A valid featured-image attachment is selected.', 'Select a featured image for the saved post/page.' );
		self::check( $report, 'metadata', 'slug', 'Permalink slug', self::populated( $post->post_name ), 5, 'A saved permalink slug is populated.', 'Set a descriptive permalink slug and save. Draft preview URLs are not penalized.' );
		$nodes = $source['nodes'];
		// With no provider there is nothing to score as a source; the field checks below still score the inputs.
		self::check( $report, 'schema', 'schema-source', 'Structured-data source', 'wordpress-inputs' === $source['source'] ? null : ! empty( $nodes ), 10, $source['evidence'], $source['evidence'] . ' Tie saved JSON-LD to this post by url, @id or mainEntityOfPage, or configure Article/WebPage schema in Rank Math.', false, 'Structured-data source: ' . $source['evidence'] );
		$fields = 'post' === $post->post_type ? array( 'headline', 'description', 'author', 'image', 'publisher', 'dates' ) : array( 'name', 'description', 'url' );
		foreach ( $fields as $field ) {
			$valid = ! empty( $nodes );
			foreach ( $nodes as $node ) {
				if ( 'dates' === $field ) {
					$valid = $valid && AEO_Document::field_valid( 'datePublished', $node['datePublished'] ?? null, $source['all_nodes'] ) && AEO_Document::field_valid( 'dateModified', $node['dateModified'] ?? null, $source['all_nodes'] );
				} else {
					$valid = $valid && AEO_Document::field_valid( $field, $node[ $field ] ?? null, $source['all_nodes'] );
				}
			}
			self::check(
				$report,
				'schema',
				'schema-' . $field,
				'Schema: ' . $field,
				$valid,
				'post' === $post->post_type ? 5 : 10,
				'dates' === $field ? 'Saved date inputs are populated; publication dates for drafts are provisional.' : 'A valid ' . $field . ' is populated in the ' . $source['label'] . '.',
				'Populate ' . $field . ' in the ' . $source['label'] . '; resolve local references and replace blank or unresolved variables. ' . self::field_hint( $field )
			);
		}
		if ( $source['invalid'] ) {
			$report['blockers'][] = 'invalid-saved-schema';
			$report['issues'][]   = array(
				'rule'     => 'Saved JSON-LD',
				'status'   => 'failed',
				'severity' => 'error',
				'message'  => 'Fix malformed saved JSON-LD or invalid schema records. A valid second schema must not hide a broken one.',
			);
		}
		$earned                   = array_sum( array_column( $report['checks'], 'points' ) );
		$possible                 = array_sum( array_column( $report['checks'], 'possible' ) );
		$report['score']          = $possible > 0 ? (int) round( 100 * $earned / $possible ) : 0;
		$report['audit_complete'] = true;
		$report['status']         = empty( $report['blockers'] ) && $report['score'] >= $report['threshold'] ? 'pass' : 'fail';
		$report['summary']        = sprintf(
			'Content readiness: %d/100; threshold %d. %s Crawlability is assumed for scoring. Saved content and schema inputs checked; public HTML is not verified. Content %d/%d, metadata %d/%d, structured data %d/%d. Save edits and rerun. This rubric is not a prediction of AI citations.',
			$report['score'],
			$report['threshold'],
			'pass' === $report['status'] ? 'Pass.' : 'Needs attention.',
			$report['breakdown']['content']['earned'],
			$report['breakdown']['content']['possible'],
			$report['breakdown']['metadata']['earned'],
			$report['breakdown']['metadata']['possible'],
			$report['breakdown']['schema']['earned'],
			$report['breakdown']['schema']['possible']
		);
		$report['issues'][] = array(
			'rule'     => 'Crawlability',
			'severity' => 'info',
			'message'  => 'Ignored by configuration: publication status, public HTTP access, robots.txt, indexing restrictions and rendered canonical. These do not reduce this score. No live crawl or preview request was made.',
		);
		return $report;
	}

	/** Basic saved-block metrics; never execute shortcodes or dynamic blocks. */
	public static function metrics( string $html ): array {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . ( '' === $html ? ' ' : $html ) . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		// Hidden scripts/styles must never improve content metrics.
		$xpath = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//script|//style|//template|//noscript' ) as $hidden ) {
			$hidden->parentNode->removeChild( $hidden );
		}
		$words    = self::word_count( self::plain_text( $dom->saveHTML() ) );
		$headings = 0;
		foreach ( $xpath->query( '//h2|//h3' ) as $heading ) {
			$headings += self::populated( trim( $heading->textContent ) ) ? 1 : 0;
		}
		$paragraphs = $short = 0;
		foreach ( $dom->getElementsByTagName( 'p' ) as $paragraph ) {
			$count = self::word_count( trim( $paragraph->textContent ) );
			if ( $count > 0 ) {
				++$paragraphs;
				$short += $count <= 120 ? 1 : 0;
			}
		}
		$images = $with_alt = $links = $descriptive = 0;
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			++$images;
			$with_alt += $img->hasAttribute( 'alt' ) ? 1 : 0;
		}
		foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
			if ( '' === trim( $link->getAttribute( 'href' ) ) ) {
				continue;
			}
			++$links;
			$label = trim( $link->getAttribute( 'aria-label' ) ?: $link->textContent );
			foreach ( $link->getElementsByTagName( 'img' ) as $img ) {
				$label .= ' ' . $img->getAttribute( 'alt' );
			}
			$descriptive += '' !== trim( $label ) && ! preg_match( '/^(click here|here|read more|learn more)$/i', trim( $label ) ) ? 1 : 0;
		}
		return array(
			'word_count'             => $words,
			'heading_count'          => $headings,
			'paragraph_count'        => $paragraphs,
			'short_paragraph_ratio'  => $paragraphs ? round( $short / $paragraphs, 4 ) : null,
			'image_count'            => $images,
			'image_alt_ratio'        => $images ? round( $with_alt / $images, 4 ) : null,
			'link_count'             => $links,
			'descriptive_link_ratio' => $links ? round( $descriptive / $links, 4 ) : null,
		);
	}

	/** Whitespace-delimited word count; intended for this English-language rubric. */
	private static function word_count( string $text ): int {
		return '' === trim( $text ) ? 0 : count( preg_split( '/\s+/u', trim( $text ) ) ?: array() );
	}

	private static function ratio_label( ?float $ratio ): string {
		return null === $ratio ? 'Not applicable' : (string) round( 100 * $ratio ) . '%';
	}

	/** Reject placeholders rather than rewarding populated but unresolved templates. */
	private static function populated( string $value ): bool {
		return '' !== trim( $value ) && ! preg_match( '/%[^%\s]+%|\{\{|\b(?:TODO|TK)\b/', $value );
	}

	private static function field_hint( string $field ): string {
		return match ( $field ) {
			'image' => 'Choose a featured image or set a valid schema image URL.',
			'author' => 'Assign an author with a display name, or fill the schema author name.',
			'publisher' => 'Set the site title in Settings > General, or the organization name in your SEO plugin (Rank Math: Titles & Meta > Local SEO).',
			'description' => 'Fill the SEO description or manual excerpt used by the schema.',
			'dates' => 'Review the saved post date and modification date, or the schema date fields.',
			default => 'Review the post title, slug and any schema editor your SEO plugin provides.',
		};
	}

	/**
	 * URLs that identify this post. A draft's get_permalink() is the plain ?p=ID
	 * form, while authors write JSON-LD with the final slug URL, so both count.
	 */
	private static function page_urls( \WP_Post $post ): array {
		$urls = array();
		if ( 'publish' !== $post->post_status ) {
			if ( ! function_exists( 'get_sample_permalink' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			$sample = get_sample_permalink( $post );
			$urls[] = str_replace( array( '%pagename%', '%postname%' ), (string) ( $sample[1] ?? '' ), (string) ( $sample[0] ?? '' ) );
		}
		$urls[] = (string) get_permalink( $post->ID );
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** Build evidence from saved JSON-LD, Rank Math's saved configuration or WordPress's own post data. */
	private static function schema_source( \WP_Post $post, string $title, string $excerpt ): array {
		$document = AEO_Document::parse( $post->post_content ?: '<p></p>' );
		$article  = 'post' === $post->post_type;
		$urls     = self::page_urls( $post );
		$author   = get_userdata( (int) $post->post_author );
		// The inputs every schema provider draws on; also the fallback when no provider is present.
		$base   = array(
			'headline'      => $title,
			'name'          => $title,
			'description'   => $excerpt,
			'url'           => $urls[0] ?? '',
			'author'        => array(
				'@type' => 'Person',
				'name'  => $author ? $author->display_name : '',
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => (string) get_bloginfo( 'name' ),
			),
			'image'         => wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), 'full' ) ?: '',
			'datePublished' => self::saved_date( $post->post_date ),
			'dateModified'  => self::saved_date( $post->post_modified ),
		);
		$source = array(
			'source'      => 'saved-json-ld',
			'label'       => 'saved JSON-LD',
			'description' => $excerpt,
			'nodes'       => AEO_Document::primaries( $document['nodes'], $urls, $article ),
			'all_nodes'   => $document['nodes'],
			'invalid'     => $document['json_errors'] > 0,
			'evidence'    => 'Saved JSON-LD tied to this post is inspected; its public rendering is unverified.',
		);
		$helper = '\\RankMath\\Helper';
		if ( ! class_exists( $helper ) || ! class_exists( '\\RankMath\\Schema\\DB' ) || ! $helper::is_module_active( 'rich-snippet' ) ) {
			if ( ! empty( $source['nodes'] ) ) {
				return $source;
			}
			if ( AEO_Document::profile_nodes( $document['nodes'], $article ) ) {
				$source['evidence'] = 'Saved JSON-LD has an ' . ( $article ? 'Article' : 'WebPage' ) . ' node, but its url, @id or mainEntityOfPage does not match this post (' . implode( ' or ', $urls ) . ').';
				return $source;
			}
			$source['source']   = 'wordpress-inputs';
			$source['label']    = 'WordPress post data';
			$source['nodes']    = array( array_merge( $base, array( '@type' => $article ? 'Article' : 'WebPage' ) ) );
			$source['evidence'] = 'No schema provider was detected, so the fields a provider would use are checked from WordPress post data: title, excerpt, author, featured image, site title and dates. This plugin does not add schema markup; use public-output mode after publication to confirm it is rendered.';
			return $source;
		}
		try {
			// Without a configured template Rank Math resolves %seo_title% to whitespace.
			$seo_title             = trim( (string) $helper::replace_seo_fields( '%seo_title%', $post ) );
			$description           = trim( (string) $helper::replace_seo_fields( '%seo_description%', $post ) );
			$source['description'] = '' !== trim( $description ) ? $description : $excerpt;
			$base                  = array_replace(
				$base,
				array(
					'headline'    => $seo_title ?: $title,
					'name'        => $seo_title ?: $title,
					'description' => $source['description'],
					'publisher'   => array(
						'@type' => 'Organization',
						'name'  => (string) ( $helper::get_settings( 'titles.knowledgegraph_name' ) ?: get_bloginfo( 'name' ) ),
					),
				)
			);
			$schemas               = \RankMath\Schema\DB::get_schemas( $post->ID );
			if ( ! is_array( $schemas ) ) {
				throw new \UnexpectedValueException();
			}
			$provider_nodes = array();
			foreach ( $schemas as $schema ) {
				if ( ! is_array( $schema ) ) {
					$source['invalid'] = true;
					continue;
				}
				// Explicit fields never inherit defaults that could conceal blank overrides.
				$schema               = self::resolve_template( $schema, $base, $post );
				$schema['@context'] ??= 'https://schema.org';
				$parsed               = AEO_Document::parse( '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_HEX_TAG ) . '</script>' );
				$source['invalid']    = $source['invalid'] || ! empty( $parsed['json_errors'] );
				$provider_nodes       = array_merge( $provider_nodes, $parsed['nodes'] );
			}
			// Stored post schema is attached to this post; an @id is not required in the editor.
			foreach ( $provider_nodes as $node ) {
				if ( self::profile_type( $node['@type'] ?? null, $post->post_type ) ) {
					$source['nodes'][] = $node;
				}
			}
			if ( empty( $schemas ) ) {
				$type = 'page' === $post->post_type ? 'WebPage' : $helper::get_default_schema_type( $post->ID, true );
				if ( self::profile_type( $type, $post->post_type ) ) {
					$source['nodes'][] = array_merge( $base, array( '@type' => $type ) );
				}
			}
			$source['all_nodes'] = array_merge( $source['all_nodes'], $provider_nodes );
			$source['source']    = 'rank-math-saved-inputs';
			$source['label']     = 'saved schema or Rank Math inputs';
			$source['evidence']  = 'Rank Math schema module is active. Saved schema fields or supported default schema inputs are checked; no rendered graph is claimed.';
		} catch ( \Throwable $error ) {
			$source['source']   = 'unavailable';
			$source['evidence'] = 'The Rank Math source adapter could not inspect this configuration. Review schema settings and rerun.';
			$source['invalid']  = true;
		}
		return $source;
	}

	private static function profile_type( mixed $type, string $post_type ): bool {
		$accepted = 'post' === $post_type ? array( 'Article', 'BlogPosting', 'NewsArticle', 'Report', 'TechArticle', 'ScholarlyArticle' ) : array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'ItemPage', 'ProfilePage', 'QAPage' );
		return (bool) array_intersect( $accepted, array_filter( (array) $type, 'is_string' ) );
	}

	private static function saved_date( string $value ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? substr( $value, 0, 10 ) : '';
	}

	/** Resolve known source tokens only; unknown tokens remain visible failures. */
	private static function resolve_template( mixed $value, array $base, \WP_Post $post, int $depth = 0 ): mixed {
		if ( $depth > 32 ) {
			throw new \UnexpectedValueException();
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::resolve_template( $item, $base, $post, $depth + 1 );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		return strtr(
			$value,
			array(
				'%seo_title%'              => $base['headline'],
				'%title%'                  => $post->post_title,
				'%seo_description%'        => $base['description'],
				'%excerpt%'                => self::plain_text( $post->post_excerpt ),
				'%url%'                    => $base['url'],
				'%author%'                 => $base['author']['name'],
				'%post_thumbnail%'         => $base['image'],
				'%sitename%'               => get_bloginfo( 'name' ),
				'%date(Y-m-dTH:i:sP)%'     => $base['datePublished'],
				'%modified(Y-m-dTH:i:sP)%' => $base['dateModified'],
			)
		);
	}
}
