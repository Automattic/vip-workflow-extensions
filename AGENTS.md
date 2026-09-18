# Working in this repository

Guidance for coding agents and for anyone reading before they change something. Start here.

## What this repository is

A shelf of finished example extensions for the **VIP Workflows** plugin. Each one is a self-contained WordPress plugin that worked, and was tested, before it landed.

People come here to do one of three things:

1. **Install an extension as-is**, because it happens to match what they need.
2. **Copy one and adapt it**, which is the common case — an extension here is a worked example of how to hook into VIP Workflows, and the shape is usually more valuable than the specifics.
3. **Read one** to understand what the extension points can do before building something of their own.

## What this repository is not

**It is not a development environment, and it is not a testbed.** Nothing should be built here to find out whether it works. Extensions are developed wherever their author normally develops — a site repository, a sandbox, a client project — and arrive here once they are finished and proven.

That distinction sets the bar for changes. If a change cannot be described as "this works and I have run it," it does not belong in this repository yet.

It is also not the VIP Workflows plugin. Questions about how an ability, stage, blueprint, or discovery provider actually behaves are answered by the plugin, not by reading an example here. An extension can only show you how it *used* the extension points, and it may be showing you an old way.

## Support expectations

None, and this should stay explicit in the README. Everything here is unsupported example code with no warranty, not part of the product and not covered by any support agreement. That is compatible with a high quality bar — *working* and *supported* are different promises, and only the first one is made here.

## Rules for anything that lands here

- **No credentials, ever.** Not a real one, not a test one, not in a comment, not in a fixture, not in a commit that is later reverted. Extensions read keys from WordPress options or `wp-config.php` constants and fail loudly when they are not configured. Scan before committing.
- **No customer names**, and nothing identifying who an extension was originally built for. Describe the use case instead: what the extension does and who it is useful to.
- **Every extension declares `Requires Plugins: vip-workflows`.** WordPress 6.5 and later enforces it. Older versions activate the extension silently and it then does nothing visible, which reads as a broken extension rather than a missing dependency — so the header matters even though it is not universally honoured.
- **Keep each extension self-contained.** One directory, no shared library at the root, no cross-extension `require`. Someone should be able to copy a single directory out and have it work.
- **Say what a thing is for in its header comment**, and what problem prompted it. The reasoning is the part worth reading and the first part to go missing.
- **No build step.** These are plain PHP plugins that can be copied into `wp-content/plugins/` and activated. An extension needing a build pipeline needs a different home.

## Layout

One directory per extension, at the root, alongside the README and licence. Adding an extension means adding a directory and a row in the README table. Nothing else in the repository needs to know it exists.

## Provenance

| Extension | Origin |
| --- | --- |
| `workflow-discovery-foresight` | Built inside VIP Workflows, August 2026, as a demo for customers who subscribe to Foresight News. Still present in the plugin; this is a copy rather than a move. Needs a Foresight News subscription of its own to do anything — there is no bundled account and no sample data. |
| `workflow-agent-guideline-review` | Built inside VIP Workflows, August 2026, for desks that keep written house guidelines and want the copy checked against those rather than against a generic style engine. Proposed for the product and kept out of it deliberately — it is a worked example of the stage-agent extension point, not something every site needs. |

When an extension is added, record where it came from, in terms of the use case rather than the customer.

## Licence

GPL-2.0-or-later, matching WordPress. Anything contributed here is contributed under it.

## Writing conventions

Say **"workflow", not "pipeline"**. Use month names rather than M1/M2-style codes. Use **they/them** for anyone whose pronouns have not been stated. **Do not hard-wrap prose** — one continuous line per paragraph and list item, with structural breaks only, between paragraphs and list items and around tables, headings, code blocks, and blockquotes.
