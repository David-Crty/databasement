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

## Available Tools

| Tool | Description | Destructive? |
|------|-------------|:---:|
| **list-database-servers** | List all registered servers with connection details and backup config. Optionally filter by database type. | No |
| **list-snapshots** | List backup snapshots, optionally filtered by server. Returns most recent first. | No |
| **trigger-backup** | Trigger an on-demand backup for a server. Returns snapshot IDs and job IDs for status tracking. | No |
| **trigger-restore** | Restore a snapshot to a target server. Drops and recreates the target database. | **Yes** |
| **get-job-status** | Check the status of a backup or restore job (pending, running, completed, failed). | No |

## Setup

The MCP server is available at `/mcp`, protected by [Sanctum](https://laravel.com/docs/sanctum) token authentication.

### 1. Create an API Token

Go to **Settings → API Tokens** (`/api-tokens`) in the Databasement UI and create a new token.

### 2. Configure Your AI Client

Add the following to your MCP client configuration (e.g., `~/.claude/settings.json` or `.mcp.json` for Claude Code, `mcp.json` for Cursor):

```json
{
  "mcpServers": {
    "databasement": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "https://your-databasement-instance.com/mcp",
        "--header",
        "Authorization: Bearer YOUR_SANCTUM_TOKEN"
      ]
    }
  }
}
```

Replace the URL with your Databasement instance address and the token with the one generated in step 1.

Tools will appear natively in your AI client (e.g., as `mcp__databasement__trigger-backup-tool` in Claude Code).

:::note
[mcp-remote](https://www.npmjs.com/package/mcp-remote) requires Node.js to be installed. It bridges the HTTP transport to stdio, which is supported by all MCP clients.
:::
