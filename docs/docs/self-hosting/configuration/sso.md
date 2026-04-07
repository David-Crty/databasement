---
sidebar_position: 5
---

# SSO

Databasement supports OAuth authentication, allowing users to log in using external identity providers. This can be used alongside or instead of traditional password authentication.

## Supported Providers

- **Google** - Google Workspace and personal accounts
- **GitHub** - GitHub accounts
- **GitLab** - GitLab.com or self-hosted GitLab
- **Generic OIDC** - Any OpenID Connect provider (Keycloak, Authentik, Dex, Okta, etc.)

:::tip Need another provider?
Laravel Socialite supports [many more providers](https://socialiteproviders.com/) including Facebook, Microsoft, Apple, Slack, and 100+ others. Feel free to submit a PR to add support for additional providers.
:::

## Configuration

OAuth is configured via environment variables. Each provider can be enabled independently.

### Google

1. Create OAuth credentials in [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Set authorized redirect URI to: `https://your-domain.com/oauth/google/callback`
3. Configure environment variables:

```env
OAUTH_GOOGLE_ENABLED=true
OAUTH_GOOGLE_CLIENT_ID=your-client-id
OAUTH_GOOGLE_CLIENT_SECRET=your-client-secret
```

### GitHub

1. Create an OAuth App in [GitHub Developer Settings](https://github.com/settings/developers)
2. Set authorization callback URL to: `https://your-domain.com/oauth/github/callback`
3. Configure environment variables:

```env
OAUTH_GITHUB_ENABLED=true
OAUTH_GITHUB_CLIENT_ID=your-client-id
OAUTH_GITHUB_CLIENT_SECRET=your-client-secret
```

### GitLab

1. Create an OAuth application in GitLab (Admin Area > Applications or User Settings > Applications)
2. Set redirect URI to: `https://your-domain.com/oauth/gitlab/callback`
3. Configure environment variables:

```env
OAUTH_GITLAB_ENABLED=true
OAUTH_GITLAB_CLIENT_ID=your-application-id
OAUTH_GITLAB_CLIENT_SECRET=your-secret
OAUTH_GITLAB_HOST=https://gitlab.com  # Or your self-hosted GitLab URL
```

### Generic OIDC (Keycloak, Authentik, etc.)

For any OpenID Connect compatible provider:

1. Create a client/application in your identity provider
2. Set redirect URI to: `https://your-domain.com/oauth/oidc/callback`
3. Configure environment variables:

```env
OAUTH_OIDC_ENABLED=true
OAUTH_OIDC_CLIENT_ID=your-client-id
OAUTH_OIDC_CLIENT_SECRET=your-client-secret
OAUTH_OIDC_BASE_URL=https://your-idp.com/realms/your-realm  # The OIDC base URL
OAUTH_OIDC_LABEL=SSO  # Button label on login page
```

#### Keycloak Setup

1. In Keycloak Admin Console, go to **Clients** and click **Create client**
2. Configure the client:
   - **Client ID**: `databasement` (or your preferred name)
   - **Client authentication**: **On** (required for confidential clients)
   - **Authentication flow**: Check **Standard flow** (Authorization Code Flow)

3. In the **Settings** tab, configure the URLs (replace `databasement.example.com` with your domain):

   | Field                           | Value                                                  |
   | ------------------------------- | ------------------------------------------------------ |
   | Root URL                        | `https://databasement.example.com`                     |
   | Home URL                        | `https://databasement.example.com`                     |
   | Valid redirect URIs             | `https://databasement.example.com/oauth/oidc/callback` |
   | Valid post logout redirect URIs | `https://databasement.example.com`                     |
   | Web origins                     | `https://databasement.example.com`                     |

4. Go to the **Credentials** tab and copy the **Client secret**

5. Configure environment variables:

```env
OAUTH_OIDC_ENABLED=true
OAUTH_OIDC_CLIENT_ID=databasement
OAUTH_OIDC_CLIENT_SECRET=your-client-secret
OAUTH_OIDC_BASE_URL=https://keycloak.example.com/realms/your-realm
OAUTH_OIDC_LABEL=Keycloak
```

:::tip Finding the Issuer URL
The issuer URL follows the pattern `https://your-keycloak-server/realms/your-realm-name`. You can find it in **Realm Settings** > **General** > **Endpoints** > **OpenID Endpoint Configuration**.
:::

#### Authentik Example

```env
OAUTH_OIDC_ENABLED=true
OAUTH_OIDC_CLIENT_ID=databasement
OAUTH_OIDC_CLIENT_SECRET=your-secret
OAUTH_OIDC_BASE_URL=https://authentik.example.com/application/o/databasement/
OAUTH_OIDC_LABEL=Authentik
```

## User Creation Settings

### Auto-Create Users

When enabled (default), new users are automatically created when they log in via OAuth for the first time:

```env
OAUTH_AUTO_CREATE_USERS=true  # Default: true
```

Set to `false` to only allow existing users to log in via OAuth.

### Default Role

New users created via OAuth are assigned this role:

```env
OAUTH_DEFAULT_ROLE=member  # Options: viewer, member, admin
```

### Auto-Link by Email

When enabled (default), OAuth logins are automatically linked to existing users with matching email addresses:

```env
OAUTH_AUTO_LINK_BY_EMAIL=true  # Default: true
```

## OIDC Group-Based Role Mapping

When using a Generic OIDC provider (Keycloak, Authentik, Dex, etc.), you can map IdP groups to Databasement roles automatically. This lets you control who can access Databasement and what role they get — all from your identity provider.

### How It Works

1. Your IdP includes a `groups` claim in the OIDC token (e.g., `["devops", "databasement-admins"]`)
2. Databasement checks the user's groups against your configured mappings
3. The user gets the highest-priority matching role: **admin > member > viewer**
4. Roles are synced on every login, so changes in the IdP take effect immediately

### Configuration

First, make sure your IdP sends groups in the token. Most IdPs require requesting the `groups` scope:

```env
OAUTH_OIDC_SCOPES=groups
```

Then map IdP groups to Databasement roles. Use comma-separated values if multiple IdP groups should map to the same role:

```env
OAUTH_OIDC_ROLE_MAP_ADMIN=databasement-admins
OAUTH_OIDC_ROLE_MAP_MEMBER=databasement-members,devops-team
OAUTH_OIDC_ROLE_MAP_VIEWER=databasement-viewers,interns
```

When at least one `ROLE_MAP` is set, mapping is active. Users whose groups don't match any mapping get the `OAUTH_DEFAULT_ROLE`.

### Strict Mode

To deny access to users without a matching group (instead of falling back to the default role):

```env
OAUTH_OIDC_ROLE_STRICT=true
```

With strict mode, users who don't have any matching group are rejected at login — even returning users whose groups have been revoked in the IdP.

### Custom Claim Name

By default, Databasement reads the `groups` claim. If your IdP uses a different claim name (e.g., `roles` or `realm_access`):

```env
OAUTH_OIDC_ROLE_CLAIM=roles
```

### Full Example (Keycloak)

```env
OAUTH_OIDC_ENABLED=true
OAUTH_OIDC_CLIENT_ID=databasement
OAUTH_OIDC_CLIENT_SECRET=your-secret
OAUTH_OIDC_BASE_URL=https://keycloak.example.com/realms/your-realm
OAUTH_OIDC_LABEL=Keycloak
OAUTH_OIDC_SCOPES=groups

OAUTH_OIDC_ROLE_MAP_ADMIN=databasement-admins
OAUTH_OIDC_ROLE_MAP_MEMBER=databasement-members
OAUTH_OIDC_ROLE_MAP_VIEWER=databasement-viewers
OAUTH_OIDC_ROLE_STRICT=true
```

:::tip Keycloak Group Mapper
In Keycloak, go to your client > **Client scopes** > **databasement-dedicated** > **Mappers** > **Add mapper** > **Group Membership**. Set the token claim name to `groups` and disable "Full group path" to get flat group names.
:::

### Environment Variables Reference

| Variable | Default | Description |
| --- | --- | --- |
| `OAUTH_OIDC_SCOPES` | *(empty)* | Extra OIDC scopes to request (comma-separated, e.g., `groups`) |
| `OAUTH_OIDC_ROLE_CLAIM` | `groups` | JWT claim containing the user's groups |
| `OAUTH_OIDC_ROLE_MAP_ADMIN` | *(empty)* | IdP groups that map to the **admin** role (comma-separated) |
| `OAUTH_OIDC_ROLE_MAP_MEMBER` | *(empty)* | IdP groups that map to the **member** role (comma-separated) |
| `OAUTH_OIDC_ROLE_MAP_VIEWER` | *(empty)* | IdP groups that map to the **viewer** role (comma-separated) |
| `OAUTH_OIDC_ROLE_STRICT` | `false` | Deny login when no group matches (instead of using default role) |

For local development OAuth testing, see the [Development Guide](/contributing/development#oauth--sso-testing).
