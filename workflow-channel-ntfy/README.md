# Workflow Ntfy Channel

Push notification support for VIP Workflows using ntfy.sh. Send workflow notifications to mobile devices, desktop apps, or any ntfy-compatible client.

## Features

📱 **Push Notifications** - Real-time notifications via ntfy.sh  
🎯 **Multiple Topics** - Configure different topics for routing  
🌐 **Custom Servers** - Use ntfy.sh or self-hosted instances  
🔔 **Workflow Integration** - Available for all workflow notifications  

## Installation

1. Requires **VIP Workflows** plugin
2. Activate this plugin
3. Configure destinations in **Workflows → Notifications → Channels**

## Configuration

Navigate to **Workflows → Notifications → Channels** to add ntfy destinations:

**Each destination requires:**
- **Name**: Descriptive label (e.g., "Mobile Alerts", "Team Channel")
- **Server URL**: ntfy server (default: `https://ntfy.sh`)
- **Topic**: The ntfy topic name

**Example Configuration:**

```
Name: Editor Notifications
Server: https://ntfy.sh
Topic: my-site-editors

Name: Admin Alerts
Server: https://ntfy.sh
Topic: my-site-admins
```

## Usage

### Subscribing to Notifications

1. Install [ntfy mobile app](https://ntfy.sh/docs/subscribe/phone/) or [desktop app](https://ntfy.sh/docs/subscribe/desktop/)
2. Subscribe to your configured topic(s)
3. Notifications will appear when workflow events occur

### In Workflow Notifications

Ntfy channels will appear automatically in the notification channels list when:
- Setting up notification rules
- Configuring transitions
- Sending manual notifications

## REST API

### Get Destinations

```bash
GET /wp-json/workflow-ntfy/v1/destinations
```

### Save Destinations

```bash
POST /wp-json/workflow-ntfy/v1/destinations

[
  {
    "name": "Editor Alerts",
    "server": "https://ntfy.sh",
    "topic": "my-topic"
  }
]
```

## Requirements

- WordPress VIP
- VIP Workflows plugin
- ntfy.sh account or self-hosted ntfy server

## Development

Demonstrates the Notification Channel extension pattern. Each configured destination registers as a separate channel instance.
