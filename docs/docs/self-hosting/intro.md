---
sidebar_position: 1
---

# Introduction

Welcome to the **Self-Hosting** section of the **Databasement documentation**!

Databasement is a web application for managing database server backups. It allows you to register database servers (MySQL, PostgreSQL, MariaDB, MongoDB, SQLite, Redis/Valkey), test connections, schedule automated backups, and restore snapshots to any registered server.

## Getting Started

We provide guides to deploy Databasement using:

- [**Docker**](docker) - Single container deployment (recommended for most users)
- [**Docker Compose**](docker-compose) - Multi-container setup with external database
- [**Kubernetes + Helm**](kubernetes-helm) - For Kubernetes clusters
- [**Native Ubuntu**](native-ubuntu) - Traditional installation without Docker

See [Versioning](versioning) for available Docker image tags and Helm chart versions.

## Requirements

Databasement runs in a single container that includes:
- FrankenPHP web server
- Queue worker for async backup/restore jobs
- Scheduler for automated backups

The only external requirement is a database for the application itself:
- **SQLite** (simplest, built into the container)
- **MySQL/MariaDB** or **PostgreSQL** (recommended for production)

### Supported Application Database Versions

| Engine     | Minimum Version | Recommended |
|------------|-----------------|-------------|
| SQLite     | 3.26+           | Latest      |
| MySQL      | 5.7+            | 8.x         |
| MariaDB    | 10.3+           | 11.x        |
| PostgreSQL | 10.0+           | 16+         |

These are the databases Databasement uses to store its own configuration, users, and backup metadata -- not the databases you back up (see [Supported Versions](../user-guide/database-servers#supported-versions) for that).

## Quick Start

The fastest way to try Databasement:

```bash
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -v databasement-data:/app/storage \
  davidcrty/databasement:1
```

Then open http://localhost:2226 in your browser.

:::note
This quick start uses SQLite for the application database. For production deployments, see the [Docker guide](docker) for recommended configurations.
:::

## Support

If you encounter issues with self-hosting, please open an issue on [GitHub](https://github.com/David-Crty/databasement/issues).
