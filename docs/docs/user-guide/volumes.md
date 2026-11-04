---
sidebar_position: 7
---

# Storage Volumes

Storage volumes are the destinations where your backup files are stored. Databasement supports multiple storage backends to fit your infrastructure needs.

## Volume Types

### Local Storage

Local volumes store backups on the filesystem where Databasement is running. This is the simplest option for single-server setups.

| Field | Description |
|-------|-------------|
| **Path** | Absolute path to the backup directory |

:::info
Ensure the Databasement container has write access to the specified path. You may need to mount a volume when running Docker:
```bash
docker run -v /path/on/host:/backups davidcrty/databasement
```
:::

### S3 Storage

S3 volumes store backups in AWS S3 or any S3-compatible object storage (MinIO, DigitalOcean Spaces, Backblaze B2, etc.). All credentials and settings are configured per-volume.

| Field | Description |
|-------|-------------|
| **Bucket** | S3 bucket name |
| **Prefix** | Optional path prefix within the bucket (e.g., `backups/production/`) |
| **Region** | AWS region where the bucket is located (e.g., `us-east-1`) |
| **Access Key ID** | AWS access key (optional — see below) |
| **Secret Access Key** | AWS secret key (optional — see below) |

**Advanced settings** (expand in the form):

| Field | Description |
|-------|-------------|
| **Custom Endpoint** | For S3-compatible storage (MinIO, DigitalOcean Spaces, etc.) |
| **Public Endpoint** | Public URL for presigned download links when the internal endpoint differs |
| **Use Path-Style Endpoint** | Required for most S3-compatible providers (MinIO, etc.) |
| **IAM Role ARN** | Assume this IAM role via STS before accessing S3 |
| **Role Session Name** | Identifier for the assumed role session (default: `databasement`) |
| **STS Endpoint** | Custom STS endpoint for role assumption |

:::tip
Credentials are optional. When left blank, the AWS SDK uses its default credential chain: environment variables, EC2/ECS instance roles, and EKS IRSA. This allows deployments on AWS infrastructure to work without explicit keys.
:::

:::tip
The secret access key is encrypted at rest in the database using Laravel's encryption. It is never stored in plain text.
:::

### SFTP Storage

SFTP volumes store backups on a remote server via SSH File Transfer Protocol. This is ideal for storing backups on a dedicated backup server or NAS.

| Field | Description |
|-------|-------------|
| **Host** | SFTP server hostname or IP address |
| **Port** | SSH port (default: 22) |
| **Username** | SSH username |
| **Password** | SSH password |
| **Root Directory** | Base path on the remote server (e.g., `/backups`) |
| **Connection Timeout** | Timeout in seconds (default: 10) |

:::note
Only password authentication is currently supported. SSH key authentication is planned for a future release.
:::

:::tip
The password is encrypted at rest in the database using Laravel's encryption. It is never stored in plain text.
:::

### FTP Storage

FTP volumes store backups on a remote FTP server. Both standard FTP and FTPS (FTP over SSL/TLS) are supported.

| Field | Description |
|-------|-------------|
| **Host** | FTP server hostname or IP address |
| **Port** | FTP port (default: 21) |
| **Username** | FTP username |
| **Password** | FTP password |
| **Root Directory** | Base path on the remote server (e.g., `/backups`) |
| **Enable SSL** | Use FTPS (FTP over SSL/TLS) for encrypted transfers |
| **Passive Mode** | Use passive mode for data connections (recommended for most setups) |
| **Connection Timeout** | Timeout in seconds (default: 90) |

:::warning
Standard FTP transmits credentials and data in plain text. Enable SSL for secure transfers, or prefer SFTP for better security.
:::

:::tip
The password is encrypted at rest in the database using Laravel's encryption. It is never stored in plain text.
:::

### Google Drive

Google Drive volumes store backups in your personal Google Drive. This requires setting up OAuth 2.0 credentials in the Google Cloud Console.

| Field | Description |
|-------|-------------|
| **Client ID** | OAuth 2.0 Client ID from Google Cloud Console |
| **Client Secret** | OAuth 2.0 Client Secret |
| **Refresh Token** | OAuth refresh token for offline access |
| **Folder ID** | Optional — the ID of a Google Drive folder to store backups in. Leave blank to use the root of the drive |

:::tip
The client secret and refresh token are encrypted at rest in the database using Laravel's encryption. They are never stored in plain text.
:::

:::note
Only personal Google Drive (My Drive) folders are supported. Shared drives are not supported in this release.
:::

#### Obtaining Credentials

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) and create a new project (or select an existing one).

2. Navigate to **APIs & Services → Library**, search for **Google Drive API**, and enable it.

3. Go to **APIs & Services → OAuth consent screen** and configure the consent screen. Set the user type to **External** (or Internal if using Google Workspace).

4. Go to **APIs & Services → Credentials → Create Credentials → OAuth client ID**. Select **Web application** as the application type.

5. Under **Authorized redirect URIs**, add:
   ```
   https://developers.google.com/oauthplayground
   ```

6. Copy the **Client ID** and **Client Secret**.

#### Generating a Refresh Token

1. Open the [Google OAuth Playground](https://developers.google.com/oauthplayground/).

2. Click the ⚙️ gear icon (settings) in the top right and check **Use your own OAuth credentials**. Enter the Client ID and Client Secret from the previous step.

3. In the left panel under **Step 1**, find **Google Drive API v3** and select the scope `https://www.googleapis.com/auth/drive.file`.

4. Click **Authorize APIs** and sign in with your Google account. If you see a security warning, click **Advanced → Go to (app name)** to proceed.

5. In **Step 2**, click **Exchange authorization code for tokens**.

6. Copy the **Refresh Token** from the response.

#### Finding a Folder ID

To store backups in a specific folder, open the folder in Google Drive. The folder ID is the last part of the URL:

```
https://drive.google.com/drive/folders/1aBcDeFgHiJkLmNoPqRsTuVwXyZ
                                        └── this is the folder ID
```

## Connection Testing

Before saving a volume, use the **Test Connection** button to verify:
- The storage location is accessible
- Write permissions are configured correctly
- Credentials are valid (for S3/SFTP/FTP/Google Drive)

The test creates a small temporary file, reads it back to verify integrity, then deletes it.

## Volume Immutability

Once a volume has backup snapshots associated with it, the storage configuration becomes read-only. This protects backup integrity by ensuring snapshots always point to their original storage location.

You can still rename the volume, but the storage type and configuration cannot be changed. To use different storage settings, create a new volume.
