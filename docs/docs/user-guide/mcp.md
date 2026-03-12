---
sidebar_position: 7
---

# MCP Server

Databasement includes a built-in [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server that lets AI assistants manage your database backups through natural language.

## What is MCP?

MCP is an open protocol that allows AI clients (Claude Code, Cursor, VS Code Copilot, etc.) to discover and call tools exposed by your application. Instead of clicking through the UI, you can say things like:

- "List my database servers"
- "Back up the production MySQL server"
- "Restore the latest snapshot to staging"

The MCP server wraps the same services that power the web UI and REST API, so behavior is consistent across all interfaces.

## Architecture

```
AI Client (Claude Code, Cursor, etc.)
        │
        ▼
  MCP Server (Databasement)
        │
        ├── List Servers      → Eloquent queries
        ├── List Snapshots    → Eloquent queries
        ├── Trigger Backup    → TriggerBackupAction → Queue
        ├── Trigger Restore   → BackupJobFactory → Queue
        └── Get Job Status    → Eloquent queries
```

Backup and restore operations are **asynchronous** — they dispatch jobs to the queue just like the web UI does. Use the "get job status" tool to poll for completion.

## Available Tools

| Tool | Description | Destructive? |
|------|-------------|:---:|
| **list-database-servers** | List all registered servers with connection details and backup config. Optionally filter by database type. | No |
| **list-snapshots** | List backup snapshots, optionally filtered by server. Returns most recent first. | No |
| **trigger-backup** | Trigger an on-demand backup for a server. Returns snapshot IDs for status tracking. | No |
| **trigger-restore** | Restore a snapshot to a target server. Drops and recreates the target database. | **Yes** |
| **get-job-status** | Check the status of a backup or restore job (pending, running, completed, failed). | No |

## Remote Configuration (Sanctum)

The MCP server is available over HTTP at `/mcp`, protected by Sanctum authentication.

1. Create a Sanctum API token for your user (via the API or Tinker).
2. Configure your MCP client with the following JSON:

```json
{
  "mcpServers": {
    "databasement": {
      "url": "https://your-databasement-instance.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_SANCTUM_TOKEN"
      }
    }
  }
}
```

## Local Configuration (stdio)

If your AI client runs on the same machine as Databasement (e.g., during development), you can use the local stdio transport:

```json
{
  "mcpServers": {
    "databasement": {
      "type": "stdio",
      "command": "docker",
      "args": [
        "compose", "exec", "--user", "application", "-T", "app",
        "php", "artisan", "mcp:start", "databasement"
      ]
    }
  }
}
```

## Testing Your Connection

Use the built-in MCP Inspector to verify your server is working:

```bash
# Test the web server
docker compose exec --user application -T app php artisan mcp:inspector mcp

# Test the local server
docker compose exec --user application -T app php artisan mcp:inspector databasement
```

The inspector will list all available tools and let you invoke them interactively.
