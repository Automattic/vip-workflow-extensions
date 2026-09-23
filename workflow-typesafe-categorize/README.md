# Workflow TypeSafe Categorizer

Categorizes a post from its content using [TypeSafe](https://docs.typesafe.ai/introduction). It ships two things that share one classifier:

- **Suggest Categories**, a tool you can run from the command palette (⌘K) or Workflows → Tools. It shows the categories TypeSafe would pick and how sure it is. It never changes the post.
- **Categorize**, an agent you can put on an AI-owned workflow stage. It assigns the categories when TypeSafe is confident, and otherwise assigns nothing, leaves a note, and fails the stage so the routing sends the post to a person.

## Why TypeSafe and not a text model

Choosing categories is a judgment with a closed set of answers. A text-generation model has to write its answer as prose and then something has to parse it back into a term, and it can invent a category the site does not have. TypeSafe returns a typed answer from the options you gave it, plus a probability for each, so this extension decides what is confident enough to write to a post and the model cannot invent a category. The confidence threshold is a setting an editor can read, not a sentence buried in a prompt.

## Requirements

- The **VIP Workflows** plugin (the `Requires Plugins` header enforces this on WordPress 6.5 and later).
- The **TypeSafe Connector** plugin from this repository (`Requires Plugins` names it). It stores the API key and holds the client that sends questions to TypeSafe. WordPress 7.0 or later.
- A TypeSafe API key from the [TypeSafe console](https://console.typesafe.ai/settings/keys). None is bundled, and none is stored by this extension.

Until the connector is active and a key is set, the tool and the agent show as unavailable and say which of the two is missing.

## How it decides

For each post it sends the title, the excerpt and the first 12,000 characters of body text, and asks TypeSafe one Choice question across every category on the site, with a "none of these" option. Categories are sent as `Parent › Child` with the term description, so a good description on a term is the cheapest way to improve accuracy.

- If the answer is "none", or TypeSafe's confidence is below **Minimum confidence**, nothing is assigned.
- Otherwise the top choice is the **main category**. The runners-up (at most five) then get an independent yes/no question each, because a Choice spreads probability across competing options and so under-reports an article that is genuinely about two things. A runner-up that clears **Additional category threshold** is added, up to **Maximum categories per post**.

The two passes are two requests. The second is skipped when the first is not confident, or when the maximum is 1.

## Settings

Set on the tool in Workflows → Tools. The agent reads the same values, so there is one place to configure.

| Setting | Default | What it does |
| --- | --- | --- |
| Taxonomy | `category` | The taxonomy to choose terms from. It must be enabled for the post type. |
| Minimum confidence (%) | 75 | How sure TypeSafe must be of the main category before the agent assigns anything. |
| Additional category threshold (%) | 80 | How likely an extra category must be before it is added beside the main one. |
| Maximum categories per post | 3 | Includes the main category. |

The defaults are conservative starting points, not measured values. Tune them on your own content: TypeSafe's guidance is to start conservative, test against real data, and adjust.

## What the agent does to a post

- **It only adds.** Categories an editor already chose are kept. The one exception is the default category (Uncategorized), which is removed when a real category is added, because it is a placeholder WordPress fills in when nothing else is set.
- **It leaves a note** on the first block saying what it assigned, or why it assigned nothing and what was closest. A re-run replaces its own note and never touches a human's.
- **It reads the result back** after assigning, and fails the stage if the categories did not land, rather than report a pass over nothing.
- **It will not overwrite a concurrent edit.** If the post changed while the agent was working, it stops with an error.

Stage routing is authored on the stage: `pass` when categories were assigned, `fail` when TypeSafe was not confident enough, and `error` when TypeSafe could not be reached or the key was rejected.

## Known limits

- **The tool suggests but cannot apply.** A tool result can be applied to a post field only through `meta.apply_field`, which writes one string with `editPost()`. Categories are a list of term IDs, so there is nothing for it to write. Assigning is the agent's job. This is a gap in the extension point, not a choice.
- **More than 254 categories is refused**, because that is more than one TypeSafe Choice question can hold. Point the taxonomy setting at a smaller one.
- **Post text is sent to TypeSafe**, a third party. The tool's permission check is scoped to the post being categorized, so someone who can edit some post but not this one cannot trigger it.
- **A crafted article can bias the answer.** TypeSafe returns only a choice and a probability, so it cannot be talked into writing arbitrary text, but article text is untrusted input to the judgment like any other. The confidence gate and the editorial note are what catch a bad call.
- **Text only.** Images in a post are not considered.
- **This extension was verified against a stand-in for the TypeSafe API**, not the live service. See the header of each file for what each part is for.

Unsupported example code, like everything in this repository. See the root README.
