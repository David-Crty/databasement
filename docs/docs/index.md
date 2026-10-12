---
sidebar_position: 0
slug: /
title: Self-Hosted Database Backup Manager
description: Databasement is a self-hosted web app to schedule, store and restore backups of MySQL, PostgreSQL, MariaDB, SQL Server, MongoDB, SQLite, Firebird and Redis databases.
---

![Databasement Banner](/img/banner-v2.png)

# Databasement Documentation

Welcome to the **Databasement** documentation!

> **Try it out!** Explore the [live demo](https://databasement-demo.crty.dev/) to see Databasement in action.

Databasement is a web application for managing database server backups. It allows you to register database servers (MySQL, PostgreSQL, MariaDB, Microsoft SQL Server, MongoDB, SQLite, Firebird, Redis/Valkey), test connections, schedule automated backups, and restore snapshots to any registered server.

## Features

- **Multi-database support**: Manage MySQL, PostgreSQL, MariaDB, Microsoft SQL Server, MongoDB, SQLite, Firebird, and Redis/Valkey servers
- **SSH tunnel support**: Connect to databases in private networks through a bastion/jump server
- **Automated backups**: Schedule recurring backups with customizable retention
- **Storage volumes**: Store backups locally, on S3-compatible storage, Azure Blob Storage, Samba/SMB shares, or via SFTP/FTP
- **Cross-server restore**: Restore snapshots from one server to another
- **Failure notifications**: Get alerted via Email, Slack, Discord, Telegram, Pushover, Gotify, or Webhook when jobs fail
- **User management**: Multi-user support with two-factor authentication
- **Automation**: REST API and MCP server for scripting, CI/CD, and AI assistant integration
- **Simple deployment**: Single container with built-in web server, queue worker, and scheduler

## Quick Start

```bash
# Run the container
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -v ./databasement-data:/data \
  davidcrty/databasement:1
```

Open http://localhost:2226 and create your first admin account.

> **Note:** The container automatically handles volume permissions. You can use `PUID` and `PGID` environment variables to match your system's user/group IDs.

## Documentation Sections

### Self-Hosting

Deploy and configure Databasement on your own infrastructure:

- [Introduction](./self-hosting/intro.md): overview and requirements
- [Configuration](./self-hosting/configuration/index.md): environment variables for the [application](./self-hosting/configuration/application.md), [backups](./self-hosting/configuration/backup.md), [notifications](./self-hosting/configuration/notification.md) and [SSO](./self-hosting/configuration/sso.md)
- [Docker](./self-hosting/docker.md): run the single container
- [Docker Compose](./self-hosting/docker-compose.md): run it with its own database container
- [Kubernetes + Helm](./self-hosting/kubernetes-helm.md): deploy with the Helm chart
- [Versioning](./self-hosting/versioning.md): release versions and image tags
- [Native Ubuntu Installation](./self-hosting/native-ubuntu.md): install without Docker
- [Migrate to 1.10](./self-hosting/migrate-to-1-10.md): queue worker change for Docker Compose installs

### User Guide

Back up, restore and manage your databases:

- [Introduction](./user-guide/intro.md): first steps after installation
- [Database Servers](./user-guide/database-servers.md): register and test database connections
- [SSH Tunnel](./user-guide/ssh-tunnel.md): reach databases through a bastion host
- [Remote Agents](./user-guide/agents.md): back up private databases without opening inbound ports
- [Backups](./user-guide/backups.md): schedule backups and set retention
- [Snapshots](./user-guide/snapshots.md): browse, download and restore snapshots
- [Storage Volumes](./user-guide/volumes.md): choose where backups are stored
- [Organizations](./user-guide/organizations.md): separate teams and their resources
- [Permissions](./user-guide/permissions.md): roles and abilities
- [MCP Server](./user-guide/mcp.md): manage backups from an AI assistant
- [REST API](./user-guide/api.md): automate backups from scripts and CI/CD

### Contributing

- [Development Guide](./contributing/development.md): set up a local development environment

## Quick Links

- [GitHub Repository](https://github.com/David-Crty/databasement)
- [Report an Issue](https://github.com/David-Crty/databasement/issues)
