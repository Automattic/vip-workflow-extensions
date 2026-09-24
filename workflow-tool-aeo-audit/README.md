# Workflow AEO Audit

A deterministic answer-engine optimization (AEO) readiness scanner for editors who need to find missing structured data and metadata, including before a page is publicly crawlable. It reports a transparent score and concrete fixes; it does not promise search visibility or AI citations.

## Install and run

1. Install and activate VIP Workflows, with WordPress 7.0+, PHP 8.2+ and PHP DOM available.
2. Copy this entire `workflow-tool-aeo-audit` directory into `wp-content/plugins/` and activate **Workflow AEO Audit**. There is no build step, API key or AI provider requirement.
3. In the Workflows Tools screen, enable **AEO audit** and its command-palette visibility. Set the minimum score (default 80). Enable **Ignore crawlability and score saved content (demo/drafts)** for unpublished or intentionally non-crawlable content; leave it off for a public-output audit.
4. Open a saved post or page, save any edits, open the editor command palette (⌘K on macOS or Ctrl+K), and choose **Run AEO audit**. Review the score and failed checks, make changes, save, and rerun.

Rank Math is optional. Without it, saved-content mode still scores content and metadata and can inspect schema.org JSON-LD stored in the post body; it clearly reports a missing schema source when none is available. Public-output mode inspects rendered JSON-LD from any provider. This plugin does not generate or insert structured data.

## Use as a publishing gate

The plugin registers three abilities:

| Ability | Purpose |
| --- | --- |
| `workflow-tool-aeo-audit/aeo-audit` | On-demand **AEO audit** report with score, green/red checklist and fixes. |
| `workflow-tool-aeo-audit/aeo-gate` | **AEO publishing gate**, eligible as a required workflow transition tool. |
| `workflow-tool-aeo-audit/aeo-settings` | Administrator-only settings adapter for the report and shared gate policy. |

1. In Workflows Tools, configure **AEO audit** with the desired minimum score and enable **Ignore crawlability and score saved content (demo/drafts)**. Initial-publication gates must use saved-content mode because the post is not public yet.
2. Enable **AEO publishing gate**. It uses the report's saved threshold and mode; there is no second scoring policy to keep in sync. Each ability has its own enabled switch, so hiding/disabling the on-demand report does not remove a required gate.
3. Edit the desired workflow sequence and add **AEO publishing gate** to **Required tools** on each transition that should be gated before publication. Do not select the detailed report as a transition tool. No sequence is changed automatically.
4. Have an editor save a draft and run **Run AEO audit**. Review failures, correct the source fields, save, and rerun. The report can pass with individual failed checks when the threshold is met and there are no blockers.
5. Attempt the configured transition. The gate reruns the same audit against the current saved content, so it does not trust a prior report or a caller-supplied score. An incomplete audit, blocker or score below the threshold blocks; a complete passing audit allows the transition without spurious warnings from passed checklist rows.

Failures use an explicit `error` issue with the summary and remediation text, so Workflows treats them as hard failures even when its default check mode is soft. Acknowledge-warnings cannot override this gate. Permission, unsupported-content and other execution errors propagate to Workflows and must also block required tools. Test this on your installed Workflows version before relying on it for publication. The extension gates configured Workflows transitions, not every possible WordPress publishing route; it does not install an independent global publishing hook.

For acceptance testing, use a test sequence and a draft below the threshold, confirm the transition is blocked with useful fixes, then correct the content and confirm it proceeds. Also verify a malformed schema still blocks at a low threshold, an unauthorized user cannot execute the audit, and ordinary on-demand checks never publish or change the post.

## Report and configuration

`workflow-tool-aeo-audit/aeo-audit` is a read-only validator with a 0–100 score, configurable threshold (default 80), and actionable findings. It works in the editor, command palette (**Run AEO audit**), and Secure MCP. Save changes before running; unsaved edits are not inspected. No AI service or package installation is needed.

### Drafts and intentionally non-crawlable sites

Enable **Ignore crawlability and score saved content (demo/drafts)**. The `ignore_crawl_restrictions` setting scores saved content and supported schema inputs for both drafts and published posts/pages. A draft, noindex setting, robots restriction, password, or inaccessible public URL cannot zero this score. It makes **no HTTP or preview request** and never changes crawler access, SEO metadata, content, or publication status.

The report explicitly labels the result **Content readiness**, sets `audit_mode: saved-content` and `public_output_verified: false`, and lists public checks as ignored. `audit_complete: true` means the saved-source assessment completed, not that a public crawl was verified. This mode supplies pre-publication readiness results for both the editor report and the publishing gate. Post-specific edit permission still applies, including for private/password-protected content. Unsupported post types, trash/auto-drafts, missing DOM support and inputs above 2 MiB return errors.

### Content-readiness rubric (2.0-content)

| Category | Points | Checks |
| --- | ---: | --- |
| Content | 40 | Title (10), 150+ words (10), H2/H3 headings (5), at least 80% of paragraphs at 120 words or fewer (5), inline images have alt attributes (5), nonempty/descriptive link labels (5) |
| Metadata | 20 | SEO description source or excerpt (10), valid featured image (5), saved permalink slug (5) |
| Structured-data readiness | 40 | Supported schema source (10); Article: headline, description, author, image, publisher and dates (5 each); WebPage: name, description and URL (10 each) |

These are transparent **demo heuristics**, not search-engine rules or an AI citation prediction. Word counting and generic link-label detection target English whitespace-separated text. Content is read without rendering shortcodes or dynamic blocks. The script/style/template/noscript text is excluded from metrics. No inline images or links means that ratio is not applicable and its points are removed from the denominator. Empty alt is accepted for decorative images; humans must verify whether an image is actually decorative and whether alternative text is useful. Links are not fetched and factual accuracy is not assessed.

The score is `round(100 × earned / applicable points)`. `breakdown` returns content/metadata/schema earned and possible points. `metrics` includes word/heading/paragraph/image/link counts and ratios for concise paragraphs, alt-attribute coverage and descriptive link labels. Incomplete fields lose their points; malformed saved schema blocks independently of the threshold. The summary and all applicable passed/failed checks are displayed, with remediation instructions on failures.

### What structured-data readiness verifies

The saved-source adapter inspects schema.org JSON-LD embedded in saved content and Rank Math's active schema module, per-post schema records, supported default type and underlying post/SEO inputs. It recognizes Article/BlogPosting/NewsArticle/Report/TechArticle/ScholarlyArticle for posts, and WebPage/AboutPage/ContactPage/CollectionPage/FAQPage/ItemPage/ProfilePage/QAPage for pages. References within saved graphs are resolved locally; no remote contexts are fetched.

Rank Math defaults use title/description templates, author display name, featured image, publisher identity and saved dates. A draft date is provisional. Explicit schema overrides are checked as supplied rather than filled with defaults that might hide blanks. Known tokens resolve from this post; unknown tokens remain failures. Other providers, custom output filters, dynamically generated schema and type-specific FAQ/Product/Event rules need a separate adapter or public-output inspection. The source score does **not** claim that these inputs have been emitted as correct markup. `schema_source` and `schema_evidence` identify what was inspected.

### Strict public-output mode

With `ignore_crawl_restrictions` off, the public audit is used. It requires a published, unprotected post/page and inspects anonymous public HTML plus the origin's robots.txt. Its rubric remains: crawl/index readiness 40, page metadata 20, rendered JSON-LD 40. Redirects, unsuccessful requests, unknown robots results, missing/malformed JSON-LD, missing primary schema and conflicting canonicals cannot pass merely by lowering the threshold. A missing canonical loses points. Drafts return incomplete rather than a content score in this mode.

Requests are same-origin using WordPress safe HTTP, without credentials/cookies or redirects, with a 3-second timeout each; HTML is capped at 2 MiB and robots.txt at 500 KiB. robots.txt is evaluated for Googlebot, Bingbot and OAI-SearchBot using agent groups, precedence, wildcards and longest-rule/Allow-tie matching. A 404/410 means absent; other unsuccessful responses are conservatively unknown. This does not test actual bot IP access, sitemaps, image downloads, Microdata/RDFa or client-rendered JavaScript. Public caches can lag saved edits. Use strict mode after publication; do not attach it to an initial-publication gate.

References: [Google structured-data guidance](https://developers.google.com/search/docs/appearance/structured-data/sd-policies), [Article properties](https://developers.google.com/search/docs/appearance/structured-data/article), [robots.txt](https://developers.google.com/crawling/docs/robots-txt/robots-txt-spec).

### Accessible report presentation

AEO uses Workflows' native `issues[].status: passed|failed` report contract to show successful checks as well as failures. Passed rows have a pale green background, dark green text and an aria-hidden ✅. Failed rows use pale red, dark red and an aria-hidden ❌. Native Passed/Failed text remains visible. Ignored/not-applicable rows have no pass/fail status and stay neutral. Consumers needing only failures should filter by `status: failed`; `issues` contains the complete checklist. Older Workflows transition handlers may treat every issue as a warning regardless of status/severity. The detailed report therefore sets `transition_eligible: false`, and a separate **AEO publishing gate** exposes a transition-safe verdict from the same audit: no issues on pass, a hard issue with fixes on failure. This supports older handlers without changing Workflows core. Installation does not modify any workflow sequence.

Text contrast is 10.51:1 for green rows, 8.00:1 for red, and 11.26:1 for neutral. Status borders exceed 3:1; forced-colors mode uses system colors. Styling is scoped to the AEO modal and leaves other tools and native dialog/focus behavior unchanged. A small DOM presentation adapter marks the native modal and adds decorative emoji alongside, rather than replacing, React-owned icons. Its title/row selectors must be rechecked when the managed Workflows renderer changes. Presentation was inspected in a local renderer-contract fixture at desktop and 390px widths; verify the actual hosted modal after an approved deployment.

### Configure with Secure MCP

Discover and inspect abilities first. Run `workflow-tool-aeo-audit/aeo-audit` with `{ "post_id": 123 }`. Manage only this tool using the admin-only `workflow-tool-aeo-audit/aeo-settings` ability:

```json
{
  "site_url": "https://example.org",
  "settings": {
    "enabled": true,
    "show_in_commands": true,
    "min_score": 80,
    "ignore_crawl_restrictions": true
  }
}
```

Omit `settings` to read. The URL must match the current site. The adapter does not switch sites or write arbitrary options; other tools and unspecified settings are preserved. Settings use this extension's own ability ID. Installing it does not import settings from another plugin; saved-content mode is off until enabled.

### Verification

From this extension directory, run:

```sh
php tests/gate.php
php tests/bootstrap.php
php tests/bootstrap.php --without-core
python3 tests/aeo-contrast.py
node --check assets/aeo-report.js
```

These require PHP 8.2+ with DOM, plus Python 3 and Node for the optional presentation checks. They install nothing and use synthetic WordPress/HTTP/provider boundaries. There are 110 audit checks, 11 gate checks, five standalone registration checks and one missing-dependency check; the bootstrap command also repeats the public audit suite. Production PHP and these tests pass WordPress-VIP-Go PHPCS. Narrow scanner exceptions exist only where standalone WordPress stubs must use PHP internals or CLI assertion messages.

The source implementation was exercised on a WordPress site before extraction. Its saved-content audit was rerun in September 2026 through Secure MCP and returned a complete 84/100 report for a draft on a non-crawlable site, with actionable missing-field findings. The standalone package was checked separately for registration, missing dependencies, audit behavior, gate verdicts and contrast. Four additional checks exercised the actual Workflows transition-checking method with WordPress/executor boundaries stubbed: passing audits proceed, failing scores block, warning acknowledgement cannot bypass failure, and corrected content passes. These test doubles do not claim a full WordPress activation or publishing-transition test of the renamed package. Verify your site's editor report after installation, particularly when updating VIP Workflows.

### Provenance and support

Developed outside VIP Workflows in September 2026 for editorial demonstrations that need meaningful readiness feedback before publication while intentionally keeping the site out of search indexes. Extracted as a self-contained example of the ability, report, settings and command-palette extension points. The original editorial bundle is not required. No site content, site-specific settings, credentials or customer identifiers are included.

Unsupported example code, not part of the VIP Workflows product or a support agreement. GPL-2.0-or-later, matching the repository licence.
