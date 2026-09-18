# Workflow Editorial Alignment Checker

AI-powered editorial compliance validation for WordPress content. Validates posts against Gutenberg/Core content guidelines.

## Features

✅ **Gutenberg Guidelines** - Reads the canonical `wp_knowledge` guideline rows
🤖 **AI-Powered Validation** - Uses the site's configured AI provider to evaluate content compliance  
📊 **Detailed Feedback** - Get specific explanations and examples for failures  
🔒 **Hard/Soft Modes** - Block transitions or allow with warnings  
🎯 **Workflow Integration** - Available in transitions and command palette  

## Installation

1. Requires **VIP Workflows** plugin
2. Activate this plugin
3. Configure an AI provider in **Workflows → Settings**
4. Configure content guidelines in Gutenberg/Core Guidelines

## Configuration

### Guidelines Source

Configure guidelines in the Gutenberg/Core Guidelines UI (Settings → Guidelines). The checker reads the published `wp_knowledge` guideline rows and validates content against the configured site, copy, image, additional, and block-level guidelines.

**Validation Mode:**
- **Soft** (default): Show warnings but allow transitions
- **Hard**: Block transitions if validation fails

## Usage

### In Workflow Transitions

The checker will appear as an available ability when transitioning between states.

### Via Command Palette

1. Open post editor
2. Run command: **"Editorial Alignment Checker"**
3. View detailed results

### Via REST API

```php
POST /wp-json/wp-abilities-api/v1/abilities/workflow-tool-editorial-alignment/editorial-alignment-checker/execute

{
  "post_id": 123
}
```

## Output

The checker returns:

- **Status**: `pass`, `warning`, or `fail`
- **Summary**: Overall result text
- **Results**: Array of per-rule validations with:
  - `rule_name`: Name of the rule
  - `compliant`: true/false
  - `explanation`: AI explanation of result
  - `examples`: Specific content examples (if non-compliant)

### Example Response

```json
{
  "status": "warning",
  "summary": "0 of 1 editorial rules passed.",
  "results": [
    {
      "rule_name": "Content Guidelines",
      "compliant": false,
      "explanation": "Content uses prohibited superlatives.",
      "examples": [
        "We're the #1 solution in the market",
        "The best choice for your business"
      ]
    }
  ],
  "analysis": {
    "total_rules": 1,
    "passed": 0,
    "failed": 1,
    "validation_mode": "soft"
  }
}
```

## Best Practices

### Writing Effective Guidelines

**Be Specific:**
```
❌ "Use good tone"
✅ "Warm, confident, plain English. No jargon. Short paragraphs."
```

**Provide Examples:**
```
❌ "Follow brand guidelines"
✅ "Never claim 'best' or '#1'. Prefer 'trusted' and 'reliable'."
```

**Focus on Measurable Criteria:**
```
❌ "Make it engaging"
✅ "Use H2s for sections, bullets for lists, one CTA at end."
```

### Validation Modes

**Soft Mode** (recommended for most workflows):
- Shows warnings but doesn't block
- Good for editorial suggestions
- Allows editorial discretion

**Hard Mode** (for strict compliance):
- Blocks transitions on failures
- Good for brand compliance
- Ensures all rules pass

## Requirements

- WordPress VIP
- VIP Workflows plugin
- Gutenberg/Core Guidelines
- An AI provider configured in VIP Workflows

## Development

Based on WordPress Abilities API. See `workflow-tool-excerpt-generator` for similar implementation patterns.
