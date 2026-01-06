---
sidebar_position: 5
---

# Storage Volumes

Storage volumes are the destinations where your backup files are stored. Databasement supports local filesystem storage and S3-compatible object storage.

## Volume Types

### Local Volume

:::info
Ensure the Databasement container has write access to the specified path. You may need to mount a volume when running Docker.
:::

### S3 Volume

:::info
Checks the [S3 Storage doc in Configuration](../self-hosting/configuration.md#s3-storage) to learn how to configure S3 connectivity.
:::
