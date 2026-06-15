---
sidebar_position: 3
---

# SSH Tunnel

Connect to databases that aren't directly reachable from Databasement: in private networks, behind a bastion/jump host, or on a remote Docker host whose database ports aren't published.

:::info How it works
Databasement runs `ssh -N -L <localPort>:<host>:<port>` before each backup or restore and closes it afterward, where `<host>` and `<port>` are the **Host** and **Port** set on the database server (resolved from the SSH server's side). SSH credentials are encrypted at rest.
:::

:::note No database tools needed on the SSH host
The tunnel only forwards TCP. The dump and restore tools (`mariadb-dump`, `pg_dump`, `mongodump`, and so on) run inside the Databasement container and connect *through* the tunnel — the SSH host needs only `sshd` and network access to the database, not any database client installed.
:::

## Configuration

Enable **SSH Tunnel** on the database server and point it at the SSH host:

| Field | Description |
|-------|-------------|
| SSH Host | SSH server hostname or IP (bastion, jump host, or remote Docker host) |
| SSH Port | SSH port (default: 22) |
| SSH Username | SSH user |
| Auth Type | `Password` or `Private Key` (with optional passphrase) |

Databasement connects with `StrictHostKeyChecking=accept-new` (the host key is trusted on first connection). Keys and passphrases are never exposed in process arguments.

## Backing up databases on a remote host

When databases run in Docker containers on a remote machine with ports only on an internal network, the tunnel reuses the **same SSH access you already use to manage that host** — no separate agent or "SSH container" is needed, and the database stays private.

Publish the database port to the host's loopback, so it's reachable from the host but not from the network:

```yaml
services:
  db:
    image: postgres:16
    ports:
      - "127.0.0.1:5432:5432"
```

Set the database **Host** to `127.0.0.1` and **Port** to `5432`. Loopback is exactly where the tunnel terminates, so Databasement can reach the database while the network cannot.

For same-host containers sharing a Docker network (no SSH), see [Docker Networking](./database-servers.md#docker-networking) instead.

## Security

:::info Hardening the SSH tunnel
Disable password auth (`PasswordAuthentication no`) and use a dedicated, least-privilege key. Restrict it in `authorized_keys` to forwarding only:

```
restrict,permitopen="127.0.0.1:5432",no-pty,no-X11-forwarding ssh-ed25519 AAAA... databasement-tunnel
```
:::
