---
sidebar_position: 7
---

# Organizations

Databasement supports multi-organization setups, allowing you to isolate resources (database servers, volumes, agents, snapshots) between teams or projects.

## Concepts

- Every resource belongs to exactly one organization
- Users can belong to multiple organizations with different roles in each
- A **default organization** is created automatically on first install
- Super admins can create and manage additional organizations

## User Roles

Roles are **per-organization**. A user can be an admin in one org and a viewer in another.

| Role       | Scope                                                        |
|------------|--------------------------------------------------------------|
| **Super Admin** | Global. Full access to all organizations and settings.  |
| **Admin**  | Per-org. Can manage users and all resources within the org.  |
| **Member** | Per-org. Can manage resources but not users.                 |
| **Viewer** | Per-org. Read-only access.                                   |

Super admin is a flag on the user account, not an org role. It grants access everywhere regardless of membership.

## Switching Organizations

Use the organization switcher in the sidebar to change your active context. All pages (dashboard, servers, volumes, snapshots) reflect the selected organization. The switcher is visible when you belong to more than one organization or are a super admin.

On switch, you are redirected to the dashboard to avoid stale resource URLs. Your selection is persisted in a cookie and restored on next login.

## Managing Organizations

Super admins can manage organizations from **Configuration > Organizations**:

- Create new organizations
- Rename existing organizations
- Delete empty organizations (all servers, volumes, and agents must be removed first)

The default organization cannot be renamed or deleted. Organization names must be unique.

## User Management

The **Users** page shows members of the currently selected organization.

Admins (org or super) can add users from the **Users > Add User** page:

- **Invite new user** — Creates a new account with an invitation link. The user joins the current organization with the selected role.
- **Add existing user** — Adds a user who already has an account (in another org) to the current organization. Select the user and assign a role.

**Delete** permanently removes the user account. **Remove from org** detaches the user from the current organization but keeps their account for other orgs.

| Scenario | Org Admin | Super Admin |
|---|---|---|
| User in **one** org | Delete | Delete |
| User in **multiple** orgs | Remove from org | Delete or Remove from org |

- Admins cannot delete or remove themselves
- Org admins cannot act on super admin users
- The last super admin cannot be deleted

## API Usage

API requests target an organization using the `?org_id=` query parameter (by organization ID):

```
GET /api/v1/database-servers?org_id=01JA2B3C4D5E6F7G8H9J0KMNPQ
```

Alternatively, pass the `X-Organization-Id` header with the same value. If neither is provided, the default organization is used.

You can find the organization ID in **Configuration > Organizations**.

## Data Isolation

Each organization has its own:

- Database servers and SSH configs
- Storage volumes
- Snapshots and backup jobs
- Agents

Shared across all organizations:

- User accounts and API tokens
- Global configuration (application, authentication, backup, notification settings)
- Backup schedules

A backup's database server and volume must belong to the same organization. Cross-org restore is not allowed — the source snapshot and target server must be in the same org.

## Agents

Agents belong to a single organization. They only execute jobs for servers within their assigned organization.

## OAuth / OIDC

Auto-created OAuth users join the default organization by default. Set the `OAUTH_DEFAULT_ORGANIZATION_ID` environment variable to assign them to a different org instead.

## CLI Commands

Artisan commands (`backup:run`, `cleanup:snapshots`, `verify:files`) operate globally across all organizations. They bypass org scoping by design.

## Fresh Install

On first install:

1. A **Default** organization is created automatically
2. The first registered user becomes a super admin, attached to the default org as admin
3. Additional organizations can be created from Configuration
