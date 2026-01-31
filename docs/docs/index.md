---
sidebar_position: 0
slug: /
---

![Databasement Banner](../static/img/banner-v1.png)

# Databasement Documentation

Welcome to the **Databasement** documentation!

> **Try it out!** Explore the [live demo](https://databasement-demo.crty.dev/) to see Databasement in action.

Databasement is a web application for managing database server backups. It allows you to register database servers (MySQL, PostgreSQL, MariaDB), test connections, schedule automated backups, and restore snapshots to any registered server.

## Features

- **Multi-database support**: Manage MySQL, PostgreSQL, and MariaDB servers
- **Automated backups**: Schedule recurring backups with customizable retention
- **Storage volumes**: Store backups locally, on S3-compatible storage, or via SFTP/FTP
- **Cross-server restore**: Restore snapshots from one server to another
- **User management**: Multi-user support with two-factor authentication
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
  davidcrty/databasement:latest
```

Open http://localhost:2226 and create your first admin account.

> **Note:** The container automatically handles volume permissions. You can use `PUID` and `PGID` environment variables to match your system's user/group IDs.

## Documentation Sections

### Self-Hosting

Learn how to deploy Databasement on your own infrastructure:

- [Introduction](self-hosting/intro) - Overview and requirements
- [Configuration](self-hosting/configuration) - Environment variables reference
- [Docker Guide](self-hosting/docker) - Deploy with Docker
- [Docker Compose Guide](self-hosting/docker-compose) - Deploy with Docker Compose
- [Kubernetes + Helm](self-hosting/kubernetes-helm) - Deploy on Kubernetes
- [Native Ubuntu](self-hosting/native-ubuntu) - Traditional installation without Docker

### User Guide

Learn how to use Databasement:

- [Getting Started](user-guide/intro) - First steps after installation
- [Database Servers](user-guide/database-servers) - Managing database connections
- [Backups](user-guide/backups) - Creating and managing backups
- [Snapshots](user-guide/snapshots) - Working with backup snapshots
- [Storage Volumes](user-guide/volumes) - Configuring backup storage

### Contributing

Want to contribute to Databasement?

- [Development Guide](contributing/development) - Set up a local development environment

## Quick Links

- [GitHub Repository](https://github.com/David-Crty/databasement)
- [Report an Issue](https://github.com/David-Crty/databasement/issues)
