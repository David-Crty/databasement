---
sidebar_position: 8
---

# REST API

Databasement provides a REST API for managing database servers, backups, snapshots, and storage volumes programmatically.

## Authentication

All API requests require a Bearer token. Create one from **Settings → API Tokens** (`/api-tokens`) in the Databasement UI.

Include the token in the `Authorization` header:

```
Authorization: Bearer YOUR_TOKEN
```

## API Documentation

Interactive API documentation is available at `/docs/api` on your Databasement instance. It is auto-generated from the codebase using [Scramble](https://scramble.dedoc.co/) and includes a "Try It" feature for testing endpoints directly.
