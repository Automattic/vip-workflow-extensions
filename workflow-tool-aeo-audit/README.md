# Workflow AEO Audit

A deterministic answer-engine optimization (AEO) readiness check for editors. It scores a post or page on content structure, metadata and structured data, lists what passed and what to fix, and can gate a VIP Workflows transition on the same score. It does not promise search visibility or AI citations, and it does not add structured data to your pages.

## Install and use

1. Have VIP Workflows active — either as a plugin or as a VIP platform integration (both work; see below). The extension needs WordPress 7.0+, PHP 8.2+ (the same as VIP Workflows) and the PHP DOM extension. No API key or AI service is involved.
2. Copy this directory into `wp-content/plugins/` and activate **Workflow AEO Audit**. Activation always succeeds; it does not declare a `Requires Plugins` dependency (see below).
3. In the Workflows Tools screen, enable **AEO audit** and its command-palette visibility, pick an **Audit mode** and set the **Minimum AEO readiness score** (default 80).
4. Save a post or page, open the command palette (⌘K or Ctrl+K) and choose **Run AEO audit**. Fix what failed, save, and rerun. Unsaved edits are not inspected.

### No "Requires Plugins" header

On hosted VIP sites checked so far, VIP Workflows runs as a **VIP platform integration**, not a plugin in `wp-content/plugins`. WordPress's `Requires Plugins` header can only validate against plugins it can see there, so declaring `Requires Plugins: vip-workflows` would block activation on exactly the sites this is built for, even though VIP Workflows is fully active and its abilities work. This extension checks for VIP Workflows at runtime instead: activation always succeeds, and if VIP Workflows isn't active, its tools simply don't register — you'll see a warning on the plugin's own row in **Plugins** and a dashboard notice (both admin-only), not a fatal error or a silent partial install.

## Audit modes

| Mode | Use it for | What it checks |
| --- | --- | --- |
| `saved-content` (default) | Drafts, pre-publication gates, sites that intentionally block crawlers | The saved post: content, metadata and structured-data inputs. Makes no HTTP request and ignores crawlability. |
| `public` | Published pages | The anonymous public page: HTTP response, robots meta, `X-Robots-Tag`, robots.txt for Googlebot, Bingbot and OAI-SearchBot, title, meta description, canonical and rendered JSON-LD. |

Neither mode changes content, SEO settings or crawler access.

## Saved-content scoring

| Category | Points | Checks |
| --- | ---: | --- |
| Content | 40 | Title (10), 150+ words (10), H2/H3 headings (5), 80% of paragraphs at 120 words or fewer (5), image alt attributes (5), descriptive link labels (5) |
| Metadata | 20 | SEO description or excerpt (10), featured image (5), permalink slug (5) |
| Structured data | 40 | Schema source (10); Article: headline, description, author, image, publisher, dates (5 each); WebPage: name, description, URL (10 each) |

The score is earned points over applicable points. A check with nothing to measure (no images, no links) is left out rather than failed. Malformed saved JSON-LD blocks regardless of score. These are transparent heuristics tuned for English text, not search-engine rules.

Where the structured-data inputs come from:

- **Rank Math** (optional), when its Schema module is active: the per-post schema you saved, or Rank Math's default Article/WebPage type with its title and description templates. Explicit blank overrides fail rather than falling back to defaults.
- **JSON-LD saved in the post body** (for example in a Custom HTML block): an Article or WebPage node tied to the post by `url`, `@id` or `mainEntityOfPage`. For drafts, the final permalink shown in the editor matches as well as the `?p=ID` preview URL.
- **Neither**: the same fields are read from WordPress post data (title, excerpt, author, featured image, site title and dates), and the 10-point source check is left out. A score of 100 is reachable without any SEO plugin, and missing inputs still lose points, so the default threshold of 80 stays meaningful. Public mode is what confirms that a theme or plugin actually renders the markup.

## Use as a publishing gate

The plugin registers three abilities: `workflow-tool-aeo-audit/aeo-audit` (the on-demand report), `workflow-tool-aeo-audit/aeo-gate` (transition-eligible gate) and `workflow-tool-aeo-audit/aeo-settings` (administrator-only settings over Secure MCP or REST).

1. Configure **AEO audit** with `saved-content` mode and your minimum score. The gate reads the same settings, so there is one policy.
2. Enable **AEO publishing gate** and add it to **Required tools** on the transitions you want gated. Nothing is added to a sequence automatically.
3. Editors run **Run AEO audit** to see the checklist, then attempt the transition. The gate reruns the audit on the saved post and ignores any score passed to it.

A failing gate returns one `error` issue with the fixes, so Workflows treats it as a hard failure that **Acknowledge warnings** cannot override. If the gate cannot run (permission, unsupported post type, oversized content), Workflows also blocks the transition.

By default VIP Workflows lets administrators bypass required tools (the `bypass_tool_check_roles` setting), so the gate applies to editors, authors and other non-bypass roles. The gate covers configured Workflows transitions, not every way WordPress can publish.

## Limitations

- Public mode uses WordPress safe HTTP, which refuses local and private-network hosts. On local or private staging sites use `saved-content` mode; public mode reports the page as unreachable there.
- The Rank Math adapter calls Rank Math internals (`Helper::replace_seo_fields`, `Helper::get_default_schema_type`, `Schema\DB::get_schemas`) that are not a documented public API. Recheck after Rank Math updates.
- Other SEO plugins' schema, filters and dynamically generated markup are only seen in public mode. Microdata, RDFa, client-rendered JavaScript and sitemaps are not evaluated.
- The report styling targets the class names of the VIP Workflows results modal. Recheck it after Workflows updates; the report still works unstyled.

## Tested with

- VIP Workflows 0.0.2, WordPress 7.0.6, PHP 8.2 in a local wp-env site: `tests/e2e-transition.php` drove real transitions as an editor. A low score was blocked with and without acknowledging warnings, a gate execution error was blocked, and corrected posts published.
- Rank Math 1.0.277.2 in the same local site, over REST: default Article schema, a saved per-post schema, a blank schema override and an unresolved description variable.
- The report modal in the local block editor.
- An earlier build of this audit (before the gate and these fixes) ran on a hosted VIP site on WordPress 7.0.6 with Rank Math 1.0.277.2. This package has not been deployed to a hosted site.

## Verification

From this directory, with PHP 8.2+ and DOM:

```sh
php tests/gate.php
php tests/bootstrap.php
php tests/bootstrap.php --without-core
python3 tests/aeo-contrast.py
```

These use WordPress stubs and need no site. For the end-to-end check, on a disposable site with VIP Workflows and this plugin active and Rank Math inactive, run `wp eval-file tests/e2e-transition.php --user=<administrator>`. It creates a test sequence, fixture posts and a temporary editor, and removes them afterwards.

## Provenance and support

Built outside VIP Workflows in September 2026 for editorial teams that want answer-engine readiness checked before publication, including on sites that intentionally block crawlers, and enforced as a transition gate. Needs nothing beyond VIP Workflows; Rank Math is optional.

Unsupported example code, not part of the VIP Workflows product or any support agreement. GPL-2.0-or-later.
