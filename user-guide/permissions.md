# Permissions

> Databasement uses role-based access control built on [Bouncer](https://github.com/JosephSilber/bouncer). A **role** grants a set of **abilities**. Roles and their abilities are **global** (shared across the whole application); only the **assignment** of a role to a user is **per organization**.

# Permissions

Databasement uses role-based access control built on [Bouncer](https://github.com/JosephSilber/bouncer). A **role** grants a set of **abilities**. Roles and their abilities are **global** (shared across the whole application); only the **assignment** of a role to a user is **per organization**.

## Roles are per organization

Each user has one role **per organization** they belong to, so the same person can be an **Admin** in one org and a **Viewer** in another. Roles are assigned from the **Users** screen of the organization you are currently working in (requires the `manage-users` ability). See [Organizations](./organizations.md) for multi-org setup.

Viewing resources (servers, volumes, snapshots, agents, schedules, restores) needs no ability — read access comes with membership. Everything else is gated by the abilities below.

## Super admins

The first/owner user is a **super admin**. Super admins:

- Bypass every ability check, in **every** organization.
- Manage the globally scoped settings that no ability covers: **authentication / SSO**, **role management** (Configuration → Roles), and **organizations**.
- Are the only ones who can grant **super admin** to another user.

Everyone can *view* those global screens read-only; only super admins can change them.

## Abilities

The catalogue is fixed and code-defined. Toggle any ability on any role under **Configuration → Roles** — changes apply immediately, everywhere.

| Ability | Grants |
|---------|--------|
| `run-backups` | Run backups on demand |
| `download-snapshots` | Download snapshot files |
| `delete-snapshots` | Delete snapshots and cancel pending backup jobs |
| `operate-restores` | Restore from snapshots and manage scheduled restores |
| `use-adminer` | Open the Adminer database browser |
| `manage-database-servers` | Create, edit and delete database server connections |
| `manage-volumes` | Create, edit and delete storage volumes |
| `manage-agents` | Create, edit, delete and regenerate tokens for remote agents |
| `manage-backup-settings` | Configure backup settings and schedules; run cleanup and verification |
| `manage-notifications` | Create, edit, delete and test notification channels |
| `manage-users` | Invite, edit and remove users in the organization |

:::note Abilities apply to the whole organization
An ability covers **all** resources of its type in the organization — for example, `manage-database-servers` grants access to *every* server in the org, not a specific one. Narrowing an ability to a single resource (such as letting someone manage just one database) is not possible today, but may land in a future release.
:::

:::note `manage-users` is the most powerful org ability
Its holder can grant any ability to anyone in the org. Give `manage-users` only to people you trust with the whole organization.
:::

## Default role abilities

These are the **seeded defaults** for the built-in roles. They are fully editable at runtime, and you can create custom roles or grant extra abilities to individual users — so real access may differ.

| Ability | Viewer | Operator | Member | Admin |
|---------|:------:|:--------:|:------:|:-----:|
| `run-backups` | ❌ | ✅ | ✅ | ✅ |
| `download-snapshots` | ❌ | ✅ | ✅ | ✅ |
| `delete-snapshots` | ❌ | ❌ | ✅ | ✅ |
| `operate-restores` | ❌ | ✅ | ✅ | ✅ |
| `use-adminer` | ❌ | ❌ | ✅ | ✅ |
| `manage-database-servers` | ❌ | ❌ | ✅ | ✅ |
| `manage-volumes` | ❌ | ❌ | ✅ | ✅ |
| `manage-agents` | ❌ | ❌ | ✅ | ✅ |
| `manage-backup-settings` | ❌ | ❌ | ❌ | ✅ |
| `manage-notifications` | ❌ | ❌ | ❌ | ✅ |
| `manage-users` | ❌ | ❌ | ❌ | ✅ |

In short: **Viewer** reads only; **Operator** also runs backups, restores and downloads; **Member** adds full resource and config management; **Admin** adds user management and backup/notification settings.

## User deletion

- **Super admins** can delete any user, except themselves and the last super admin.
- **Org admins** (`manage-users`) can delete a user only if that user is not a super admin and belongs to **only** their organization. If the user belongs to multiple orgs, remove them from the org instead.
