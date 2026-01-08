---
sidebar_position: 7
---

# OAuth / SSO Authentication

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
   |---------------------------------|--------------------------------------------------------|
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

For local development OAuth testing, see the [Development Guide](/docs/contributing/development#oauth--sso-testing).
