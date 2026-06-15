---
sidebar_position: 8
---

# Permissions

Databasement uses a role-based access control system. Roles are assigned **per organization** — a user can have different roles in different organizations. See [Organizations](./organizations.md) for details on multi-org setup.

## User Roles

| Role            | Scope  | Description                                                                |
|-----------------|--------|----------------------------------------------------------------------------|
| **Super Admin** | Global | Full access to all organizations, user deletion, and global configuration  |
| **Admin**       | Org    | Full access within the org, including user management                      |
| **Member**      | Org    | Can manage database servers, volumes, and backups, but cannot manage users |
| **Viewer**      | Org    | Read-only access to view resources and monitor backup status               |

## Permissions by Resource

### Database Servers

| Action            |    Viewer    |    Member    | Admin |
|-------------------|:------------:|:------------:|:-----:|
| View list         |      ✅       |      ✅       |   ✅   |
| Create            |      ❌       |      ✅       |   ✅   |
| Edit              |      ❌       |      ✅       |   ✅   |
| Delete            |      ❌       |      ✅       |   ✅   |
| Run backup        |      ❌       |      ✅       |   ✅   |
| Restore to server |      ❌       |      ✅       |   ✅   |
| Open Adminer      | configurable | configurable |   ✅   |

Adminer access is enabled by default for Admins only. A Super Admin can change the threshold or disable the feature under **Configuration → Application**. See [Browsing Data with Adminer](./database-servers.md#browsing-data-with-adminer).

### Volumes

| Action    | Viewer | Member | Admin |
|-----------|:------:|:------:|:-----:|
| View list |   ✅    |   ✅    |   ✅   |
| Create    |   ❌    |   ✅    |   ✅   |
| Edit      |   ❌    |   ✅    |   ✅   |
| Delete    |   ❌    |   ✅    |   ✅   |

### Snapshots

| Action       | Viewer | Member | Admin |
|--------------|:------:|:------:|:-----:|
| View list    |   ✅    |   ✅    |   ✅   |
| View details |   ✅    |   ✅    |   ✅   |
| Download     |   ❌    |   ✅    |   ✅   |
| Delete       |   ❌    |   ✅    |   ✅   |

### Users

| Action                       | Viewer | Member | Admin |
|------------------------------|:------:|:------:|:-----:|
| View list                    |   ✅    |   ✅    |   ✅   |
| Invite new user              |   ❌    |   ❌    |   ✅   |
| Edit user role               |   ❌    |   ❌    |   ✅   |
| Delete user                  |   ❌    |   ❌    |   ✅*  |
| Remove from organization     |   ❌    |   ❌    |   ✅   |
| Copy invitation link         |   ❌    |   ❌    |   ✅   |

\* Org admins can only delete users who belong to their organization and no other. See restrictions below.

## Special Rules

### User Deletion

**Super admins** can delete any user, with these restrictions:

- Cannot delete yourself
- Cannot delete the last super admin

**Org admins** can delete a user only when all of the following are true:

- The target user is not a super admin
- The target user belongs to the admin's current organization
- The target user belongs to **only one organization** (the admin's org)

If the target user belongs to multiple organizations, admins can **remove them from the organization** instead of deleting them entirely.
