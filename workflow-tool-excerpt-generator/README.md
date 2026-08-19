# Workflow Excerpt Generator

AI-powered excerpt generation using the AI provider configured in VIP Workflow. Automatically generate post excerpts from content with configurable style and length.

## Features

🤖 **AI-Powered** - Uses the site's configured AI provider for intelligent summaries  
⚡ **One-Click Generation** - Generate excerpts instantly  
🎨 **Configurable Style** - Control tone, length, and format  
🎯 **Workflow Integration** - Available in transitions and command palette  
📝 **Editor Integration** - Generate from post editor  

## Installation

1. Requires **VIP Workflow** plugin
2. Activate this plugin
3. Configure an AI provider in **VIP Workflow → Settings**

## Configuration

**AI Settings:**

Configure the AI provider in **VIP Workflow → Settings** — this tool generates through whichever provider and model are selected there.

**Optional Customization:**

The generator uses sensible defaults but accepts customization via input parameters:
- **max_length**: Character limit (default: 155)
- **style**: `informative` (default) or `action`
- **prompt**: Custom prompt template with variables: `{title}`, `{content}`, `{max_length}`, `{style_instruction}`

## Usage

### Via Editor Command Palette

1. Open post editor
2. Run command: **"Generate Excerpt"**
3. Excerpt is generated and filled into excerpt field

### Via Workflow Transitions

The generator appears as an available ability during transitions:
- Automatically generates excerpt if one doesn't exist
- Can be configured to run on specific transitions

### Via REST API

```bash
POST /wp-json/wp-abilities-api/v1/abilities/workflow-tool-excerpt/excerpt-generator/execute

{
  "post_id": 123
}
```

### Programmatically

```php
$ability = new \WorkflowToolExcerpt\ExcerptGenerator();
$result = $ability->execute( array( 'post_id' => 123 ) );

if ( 'pass' === $result['status'] ) {
    $excerpt = $result['excerpt'];
}
```

## Output

The ability returns:

```json
{
  "status": "pass",
  "summary": "This is the AI-generated excerpt for your post.",
  "excerpt": "This is the AI-generated excerpt for your post.",
  "analysis": {
    "excerpt": "This is the AI-generated excerpt for your post.",
    "excerpt_length": 55,
    "source_word_count": 450,
    "max_length": 155,
    "style": "informative"
  }
}
```

**Status values:**
- `pass`: Excerpt generated successfully
- (Returns `WP_Error` on failure)

## Best Practices

### When to Generate

**Good use cases:**
- Long-form content without manual excerpts
- Bulk content import
- Quick drafting workflows
- RSS feed optimization

**When to write manually:**
- Marketing pages requiring specific messaging
- SEO-optimized landing pages
- Content with specific CTAs

### Customize for Your Voice

Pass custom parameters via the API:

```bash
# Professional, technical tone
POST /wp-json/wp-abilities-api/v1/abilities/workflow-tool-excerpt/excerpt-generator/execute
{
  "post_id": 123,
  "max_length": 200,
  "style": "informative"
}

# Marketing, action-oriented
POST /wp-json/wp-abilities-api/v1/abilities/workflow-tool-excerpt/excerpt-generator/execute
{
  "post_id": 123,
  "max_length": 155,
  "style": "action"
}

# Fully custom prompt
POST /wp-json/wp-abilities-api/v1/abilities/workflow-tool-excerpt/excerpt-generator/execute
{
  "post_id": 123,
  "prompt": "Write a compelling one-sentence excerpt that highlights the key benefit:\n\n{content}"
}
```

## Requirements

- WordPress VIP
- VIP Workflow plugin
- An AI provider configured in VIP Workflow

## Development

Demonstrates the Tool/Ability extension pattern. See `class-excerpt-generator.php` for implementation details. This plugin serves as a reference for building custom AI-powered workflow tools.
