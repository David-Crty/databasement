---
sidebar_position: 1
---

# Introduction

Welcome to the **User Guide** for **DBBackup**!

DBBackup is a web-based tool for managing database backups. This guide covers how to use the application to:

- Register and manage database servers
- Configure backup schedules
- Create and restore snapshots
- Manage storage volumes

## Getting Started

After installing DBBackup, access the web interface and create your first account.

### 1. Create Your Account

When you first access DBBackup, you'll be prompted to register. Create an account with:
- Your name
- Email address
- Password

Two-factor authentication can be enabled later in your account settings for additional security.

### 2. Add a Database Server

Navigate to **Database Servers** and click **Add Server**. You'll need:

- **Name**: A friendly name for this server (e.g., "Production MySQL")
- **Type**: MySQL, PostgreSQL, or MariaDB
- **Host**: The server hostname or IP address
- **Port**: The database port (default: 3306 for MySQL, 5432 for PostgreSQL)
- **Username**: Database user with backup privileges
- **Password**: The database password

Click **Test Connection** to verify the connection before saving.

### 3. Create a Storage Volume

Before creating backups, you need a storage volume. Navigate to **Volumes** and click **Add Volume**.

DBBackup supports:
- **Local storage**: Store backups on the server's filesystem
- **S3-compatible storage**: Amazon S3, MinIO, DigitalOcean Spaces, etc.

### 4. Create Your First Backup

Go to **Database Servers**, find your server, and click **Backup**. Select the database and storage volume, then start the backup.

Backups run asynchronously - you can navigate away and check progress later on the **Snapshots** page.

### 5. Restore a Snapshot

To restore a backup, go to **Snapshots**, find the snapshot you want to restore, and click **Restore**. You can restore to:

- The same server and database (rollback)
- A different database on the same server
- A completely different server (cross-server restore)

## Next Steps

- [Managing Database Servers](/user-guide/database-servers)
- [Configuring Backups](/user-guide/backups)
- [Working with Snapshots](/user-guide/snapshots)
- [Storage Volumes](/user-guide/volumes)
