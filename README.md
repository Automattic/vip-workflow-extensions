# VIP Workflow Extensions

Example extensions for the **VIP Workflows** plugin from WordPress VIP.

## These are examples, and they are unsupported

Everything in this repository is example code. It was written to demonstrate what the extension points can do, usually for a specific customer conversation, and it is published so that other people can read it and build their own.

Each extension works, and was tested, at the point it landed here. That is the bar for getting in — this is not a scratchpad, and nothing arrives half-finished. But *working* and *supported* are different things.

These are **not** part of the VIP Workflows product, they are **not** covered by any VIP support agreement, and they come with **no warranty of any kind**. Nobody is on call for them. They may fall behind the plugin, they may break when it changes, and they may be removed. If you put any of this near production, you own it from that point on — read it, test it against your own setup, and expect to maintain it yourself.

## You need the VIP Workflows plugin

None of these do anything on their own. Every extension here depends on the **VIP Workflows** plugin (`vip-workflow`) being installed and active — that is where the abilities, stages, blueprints, and discovery framework they hook into actually live.

VIP Workflows is not distributed from this repository. **Get in touch with WordPress VIP** to enable it.

Each extension declares the dependency with a `Requires Plugins: vip-workflow` header, so WordPress 6.5 and later will refuse to activate it without the plugin present. On older versions there is no such guard: the extension will activate quietly and then do nothing visible, which looks like a broken extension rather than a missing dependency.

Some extensions need more than core — a third-party API key, or another extension. Each one says so in its own header comment.

## Layout

One directory per extension, at the root, each a self-contained WordPress plugin:

```
vip-workflow-extensions/
├── README.md
├── LICENSE
└── workflow-discovery-foresight/
```

To use one, copy its directory into `wp-content/plugins/` and activate it. There is no build step and nothing to install.

## What is here

| Extension | What it does | Also needs |
| --- | --- | --- |
| `workflow-discovery-foresight` | Story discovery from Foresight News — events, diary dates, and scheduled announcements — with a research assistant that composes ideation prompts from them. | Your own Foresight News subscription |

`workflow-discovery-foresight` was built as a demo for customers who subscribe to Foresight News, and it talks to the Foresight API on your behalf. **It will not work without your own subscription and API credentials** — there is no bundled account, no trial mode, and no sample data to fall back on. Without credentials it authenticates, fails, and returns nothing.

More will land here over time.

## Credentials

No credentials are committed to this repository, and none should be. Extensions read their keys and secrets from WordPress options or from constants defined in `wp-config.php`, and fail loudly when they are not configured.

If you add an extension here, keep it that way. Do not commit a key, even a test one, and do not leave one in a code comment or a fixture.

## Contributing an extension

Build it wherever you normally build things, and submit it here once it works. This repository is a shelf, not a workshop — extensions arrive finished, having already run somewhere real. Please do not open a pull request for something you are still figuring out.

What a submission needs:

- Self-contained in its own directory, so it can be copied out on its own. No shared library at the root, no requiring files from a sibling extension.
- A plugin header declaring `Requires Plugins: vip-workflow`, plus anything else it depends on — a third-party subscription, an API key, a particular WordPress version.
- A header comment explaining what the extension is for and what problem prompted it. The reasoning is the part worth reading and the part that goes missing first.
- No credentials. Not a real one, not a test one, not in a comment or a fixture.
- A row in the table above, so the next person can find it.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). The licence text carries its own disclaimer of warranty, and it means it.
