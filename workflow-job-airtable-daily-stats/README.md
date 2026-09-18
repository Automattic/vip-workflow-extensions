# Workflow Airtable Daily Stats

> **Not compatible with current VIP Workflows.** This extension registers with the Jobs framework (the `vip_workflow_register_jobs` action, the Job Scheduler and the Integrations → Jobs screen), which current versions of VIP Workflows no longer include. Nothing here runs against them. It is kept, unchanged, as a worked example of the old Job extension pattern, and its code and instructions below still use the old naming.

Sync daily post statistics to Airtable. Automatically tracks post counts by blueprint and status.

## Features

📊 **Daily Statistics** - Post counts by blueprint and status  
🔄 **Automatic Sync** - Scheduled job updates Airtable daily  
🎯 **Runs at Midnight** - Syncs once per day automatically  
📝 **Historical Data** - Track trends over time  

## Installation

1. Requires **VIP Workflow** plugin
2. Activate this plugin
3. Configure Airtable credentials in **VIP Workflow → Integrations → Jobs**

## Configuration

Navigate to **VIP Workflow → Integrations → Jobs** and configure:

**Airtable Settings:**
- **Enabled**: Turn the job on/off
- **API Key**: Your Airtable API key
- **Base ID**: The Airtable base to sync to
- **Table ID**: The table ID or name

The job runs daily at midnight (00:00:00) automatically.

## Airtable Table Structure

The job expects a table with these fields:

| Field | Type | Description |
|-------|------|-------------|
| `Date` | Date | The date of the statistics |
| `Blueprint` | Single line text | Blueprint name |
| `Status` | Single line text | Status label |
| `Count` | Number | Number of posts |

The job will create records automatically with the current date and post counts.

## Usage

Once configured, the job runs automatically daily at midnight. Each run:

1. Queries post counts by blueprint and status
2. Creates records in Airtable with current date
3. Returns success or error results

### Manual Execution

Run manually from **VIP Workflow → Integrations → Jobs** by clicking "Run Now" on the job.

## Output Example

Each day creates records like:

```
Date: 2026-01-14
Blueprint: Editorial Workflow
Status: Draft
Count: 23
---
Date: 2026-01-14
Blueprint: Editorial Workflow
Status: Published
Count: 156
---
Date: 2026-01-14
Blueprint: Video Production
Status: In Production
Count: 8
```

## Requirements

- WordPress VIP
- VIP Workflow plugin
- Airtable account with API access

## Development

Demonstrates the Job extension pattern. Jobs are registered with the VIP Workflow Job Scheduler and managed via the Integrations → Jobs interface.
