# Workflow TypeSafe Editorial Alignment

Checks a post against the site's content guidelines using [TypeSafe](https://docs.typesafe.ai/introduction). For every guideline rule it asks TypeSafe one question, *does this article break this rule?*, and gets back a probability. The result has the same shape as [`workflow-tool-editorial-alignment`](../workflow-tool-editorial-alignment), so a transition that gates on one can gate on the other.

It sits beside the original rather than replacing it. The ability id is different and both can be installed, so the two engines can be run against the same guidelines and compared.

## Why TypeSafe and not a text model

A compliance gate is a yes/no question per rule. The original asks a text model to answer it as JSON, which makes the gate only as steady as generated text: the same rule can reach different verdicts on the same copy, the reply has to be parsed, and it has to be sized against a token budget and defended against truncation.

TypeSafe answers a yes/no question with a probability. So the code decides what probability counts as a departure, the same copy gets the same answer, and a hard-mode block rests on a number an editor can read and a threshold an administrator can change.

## Requirements

- The **VIP Workflows** plugin, and the **TypeSafe Connector** plugin from this repository. Both are named in the `Requires Plugins` header. WordPress 7.0 or later, for the connector.
- A TypeSafe API key from the [TypeSafe console](https://console.typesafe.ai/settings/keys), set in Settings → Connectors or as `TYPESAFE_API_KEY`. None is bundled, and none is stored here.
- Content guidelines set up on the site. With none, every run is an error.

**No AI provider needs to be configured in VIP Workflows.** This extension does not use one.

Until the connector is active and a key is set, the tool shows as unavailable and says which of the two is missing.

## How it decides

**Guidelines are split into individual rules first.** With Gutenberg Guidelines, VIP Workflows gives the checker one rule, "Content guidelines", whose text is every guideline section joined together as markdown. One question about all of it could only ask "does the post break any of this?", which blends every rule into one number and cannot say which. So this extension breaks the packet apart: each list item (with anything nested under it) and each paragraph becomes its own rule, named after its section, for example `Copy: Do not call anything the best or number one…`. A flag then names the rule that broke. Rules supplied some other way, through the `vip_workflows_editorial_alignment_rules` filter, are left as they are.

The state sent to TypeSafe is the post's title, excerpt and body. The body keeps its paragraph breaks, headings (as `##`) and list items (as `-`), because rules like "short paragraphs" or "use subheads" can't be judged from one run of words. Each rule becomes one yes/no question, phrased so a high value means a departure, and all of them go in one request (in batches of 20 for a site with more rules).

A rule is **flagged** when TypeSafe puts the chance the post breaks it at or above **Flag a rule at (%)**, which defaults to 50. Then, as in the original:

| Rules flagged | Soft mode | Hard mode |
| --- | --- | --- |
| None | `pass` | `pass` |
| One or more | `warning` | `fail` |

**A rule with no usable answer is flagged, not passed.** A gate should not wave a rule through because the answer to it went missing.

**More rules means more chances for a false alarm.** A post is flagged if any one rule is, so a site with sixty rules will see more stray flags than one with five, at the same threshold. Raise the threshold, or trim guidelines that are context rather than rules, if that shows up.

## Settings

Set on the tool in Workflows → Tools.

| Setting | Default | What it does |
| --- | --- | --- |
| Validation mode | Soft | Hard mode blocks the transition when a rule is flagged. |
| Flag a rule at (%) | 50 | Lower it to catch more, at the cost of more false alarms. |
| Guidelines are judged | each | `each` splits the guidelines into individual rules, as above. `whole` asks one question about all the guideline text together, which is how the original checker reads it, and is there so the two can be compared like for like. |

The default is a starting point, not a measured value. Tune it on your own posts and guidelines: run the checker on copy you know breaks a rule and on copy you know is clean, and look at the probabilities in the `results`.

## What you get, and what you lose against the original

Each entry in `results` carries `violation_probability` (0 to 1) alongside `rule_name` and `compliant`.

**It says whether a rule is broken, not where or why.** TypeSafe returns a probability and cannot write text, so `explanation` is a plain statement of the number and `examples` is always empty. The original quotes the offending passage. If your editors depend on that, use the original for the explanation and this for the gate, or run both.

Other differences worth knowing:

- **Long guidelines are refused too.** More than 100 individual rules and the check returns an error rather than ask about some of them.
- **Long posts are refused.** Over 20,000 characters the check returns an error and does not run. One that stopped early could pass copy it never read, and rules about the ending can't be judged from the start. The limit is a conservative starting point, not a measured one.
- **Shortcodes are stripped, not run.** The original runs them before checking, which executes whatever they do.

## Known limits

- **Post text is sent to TypeSafe**, a third party. The tool's permission check is scoped to the post being checked, so someone who can edit some post but not this one cannot trigger it.
- **A crafted article can bias the answer.** TypeSafe returns only a probability, so it cannot be talked into writing anything, but article text is untrusted input to the judgment like any other.
- **Text only.** Images in a post are not considered, so a rule about image captions or alt text is judged from the surrounding text alone.
- **This extension was verified against a stand-in for the TypeSafe API**, not the live service: the policy, the request shape, batching, and each failure path. Whether a given rule is answered well is something to check on your own guidelines.

Unsupported example code, like everything in this repository. See the root README.
