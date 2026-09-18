# VIP Workflow Extensions

Example extensions for the **VIP Workflows** plugin from WordPress VIP.

## These are examples, and they are unsupported

Everything in this repository is example code. It was written to demonstrate what the extension points can do and it is published so that other people can read it and build their own.

Each extension works, and was tested before it was included.  These are **not** part of the VIP Workflows product, they are **not** supported by VIP. Including them will require ongoing maintiance on your part.

## You need the VIP Workflows plugin

None of these do anything on their own. Every extension here depends on the **VIP Workflows** plugin (`vip-workflows`) being installed and active.

VIP Workflows is not distributed from this repository.

Each extension declares the dependency with a `Requires Plugins: vip-workflows` header. Some extensions need more than core — a third-party API key, or another extension. Each one says so in its own header comment.

## Layout

One directory per extension, at the root, each a self-contained WordPress plugin:

```
vip-workflow-extensions/
├── README.md
├── LICENSE
├── workflow-discovery-foresight/
└── workflow-tool-excerpt-generator/
```

To use one, copy its directory into `wp-content/plugins/` and activate it. There is no build step and nothing to install.

## What is here

| Extension | What it does | Also needs |
| --- | --- | --- |
| `workflow-agent-fact-check` | Stage-capable agent that flags unsupported or dubious factual claims in a post, writing editorial notes on the blocks where they appear. | An AI provider configured in VIP Workflows |
| `workflow-agent-guideline-review` | Stage-capable agent that reviews a post against the site's own content guidelines and leaves a note on each block that departs from them. It never edits the copy. | An AI provider configured in VIP Workflows, plus content guidelines set up |
| `workflow-agent-reformat-to-template` | Stage-capable agent that reformats a post's body to follow a configurable structural template. | An AI provider configured in VIP Workflows |
| `workflow-assistant-hackernews` | Research assistant that searches Hacker News for tech discussions and articles during ideation. | Nothing — Hacker News's public search API needs no key |
| `workflow-assistant-poems` | Research assistant that writes five short poems inspired by the ideation search terms, for creative inspiration. | Nothing — falls back to static poems if no AI provider is configured |
| `workflow-channel-ntfy` (Workflow Ntfy Channel) | Notification channel that pushes VIP Workflows notifications to phones and desktops via ntfy.sh, with support for multiple topics. | Nothing — ntfy.sh is free; a self-hosted server is optional |
| `workflow-discovery-currents` | Story discovery provider for Currents API news — breaking and recent coverage across categories, regions and languages. | Your own Currents API key |
| `workflow-discovery-foresight` | Story discovery from Foresight News — events, diary dates, and scheduled announcements — with a research assistant that composes ideation prompts from them. | Your own Foresight News subscription |
| `workflow-discovery-stream` | Merges every other registered discovery provider into one feed, ranked by how comparable past coverage performed. | At least one other discovery provider registered |
| `workflow-job-airtable-daily-stats` (Workflow Airtable Daily Stats) | Scheduled job that syncs daily post counts, by blueprint and status, to an Airtable base. | Your own Airtable API key, base, and table |
| `workflow-tool-editorial-alignment` (Workflow Editorial Alignment Checker) | Validates content against the site's Gutenberg/Core content guidelines, in soft (warn) or hard (block) mode. | An AI provider configured in VIP Workflows, plus content guidelines set up |
| `workflow-tool-excerpt-generator` (Workflow Excerpt Generator) | Generates a post excerpt from its content, with configurable length, tone, and prompt. | An AI provider configured in VIP Workflows |
| `workflow-tool-minimum-pins` (Workflow Minimum Pins) | Phase transition tool requiring a minimum number of pinned research sources before an ideation project can leave that phase. | Nothing |

Discovery providers and AI-powered agents and tools need a subscription, API key, or AI provider of your own — there is no bundled account and no sample data. Without one they authenticate, fail, and return nothing (or, for tools, report that AI generation is not configured).

## Contributing an extension

Build it wherever you normally build things, and submit it here once it works.

What a submission needs:

- Self-contained in its own directory, so it can be copied out on its own. No shared library at the root, no requiring files from a sibling extension.
- A plugin header declaring `Requires Plugins: vip-workflows`, plus anything else it depends on — a third-party subscription, an API key, a particular WordPress version.
- A header comment explaining what the extension is for and what problem prompted it. The reasoning is the part worth reading and the part that goes missing first.
- A row in the table above, so the next person can find it.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). The licence text carries its own disclaimer of warranty, and it means it.
