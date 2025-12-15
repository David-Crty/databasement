---
sidebar_position: 1
---

# Introduction

Welcome to the **Self-Hosting** section of the **Databasement documentation**!

Databasement is a web application for managing database server backups. It allows you to register database servers (MySQL, PostgreSQL, MariaDB), test connections, schedule automated backups, and restore snapshots to any registered server.

## Features

- **Multi-database support**: Manage MySQL, PostgreSQL, and MariaDB servers
- **Automated backups**: Schedule recurring backups with customizable retention
- **Storage volumes**: Store backups locally or on S3-compatible storage
- **Cross-server restore**: Restore snapshots from one server to another
- **User management**: Multi-user support with two-factor authentication
- **Simple deployment**: Single container with built-in web server, queue worker, and scheduler

## Getting Started

We provide guides to deploy Databasement using:

- [**Docker**](/self-hosting/guides/docker) - Single container deployment (recommended for most users)
- [**Docker Compose**](/self-hosting/guides/docker-compose) - Multi-container setup with external database
- [**Kubernetes + Helm**](/self-hosting/guides/kubernetes-helm) - For Kubernetes clusters

## Requirements

Databasement runs in a single container that includes:
- FrankenPHP web server
- Queue worker for async backup/restore jobs
- Scheduler for automated backups

The only external requirement is a database for the application itself:
- **SQLite** (simplest, built into the container)
- **MySQL/MariaDB** or **PostgreSQL** (recommended for production)

## Quick Start

The fastest way to try Databasement:

```bash
docker run -d \
  --name databasement \
  -p 8000:8000 \
  -v databasement-data:/app/storage \
  davidcrty/databasement:latest
```

Then open http://localhost:8000 in your browser.

:::note
This quick start uses SQLite for the application database. For production deployments, see the [Docker guide](/self-hosting/guides/docker) for recommended configurations.
:::

## Support

If you encounter issues with self-hosting, please open an issue on [GitHub](https://github.com/David-Crty/databasement/issues).
