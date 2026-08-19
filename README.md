# VIP Workflow Extensions

Example extensions for the **VIP Workflows** plugin from WordPress VIP.

## These are examples, and they are unsupported

Everything in this repository is example code. It was written to demonstrate what the extension points can do and it is published so that other people can read it and build their own.

Each extension works, and was tested before it was included.  These are **not** part of the VIP Workflows product, they are **not** supported by VIP. Including them will require ongoing maintiance on your part.

## You need the VIP Workflows plugin

None of these do anything on their own. Every extension here depends on the **VIP Workflows** plugin (`vip-workflow`) being installed and active.

VIP Workflows is not distributed from this repository.

Each extension declares the dependency with a `Requires Plugins: vip-workflow` header. Some extensions need more than core — a third-party API key, or another extension. Each one says so in its own header comment.

## Layout

One directory per extension, at the root, each a self-contained WordPress plugin:

```
vip-workflow-extensions/
├── README.md
├── LICENSE
└── workflow-discovery-foresight/
```

To use one, copy its directory into `wp-content/plugins/` and activate it. There is no build step and nothing to install.


## Contributing an extension

Build it wherever you normally build things, and submit it here once it works.

What a submission needs:

- Self-contained in its own directory, so it can be copied out on its own. No shared library at the root, no requiring files from a sibling extension.
- A plugin header declaring `Requires Plugins: vip-workflow`, plus anything else it depends on — a third-party subscription, an API key, a particular WordPress version.
- A header comment explaining what the extension is for and what problem prompted it. The reasoning is the part worth reading and the part that goes missing first.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). The licence text carries its own disclaimer of warranty, and it means it.
