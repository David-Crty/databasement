---
sidebar_position: 3
---

# SSH Tunnel

Connect to databases that aren't directly reachable from Databasement: in private networks, behind a bastion/jump host, or on a remote Docker host whose database ports aren't published.

:::info How it works
Databasement runs `ssh -N -L <localPort>:<host>:<port>` before each backup or restore and closes it afterward. SSH credentials are encrypted at rest.
:::

:::note No database tools needed on the SSH host
The dump and restore tools (`mariadb-dump`, `pg_dump`, `mongodump`, and so on) run inside the Databasement container and are NOT NEEDED on the SSH host.
:::

## Configuration

Enable **SSH Tunnel** on the database server and point it at the SSH host:

| Field | Description |
|-------|-------------|
| SSH Host | SSH server hostname or IP (bastion, jump host, or remote Docker host) |
| SSH Port | SSH port (default: 22) |
| SSH Username | SSH user |
| Auth Type | `Password` or `Private Key` (with optional passphrase) |

### Reusing an SSH configuration

Once you've saved an SSH config, the form offers **Use existing** (pick a saved config) or **Set up a new SSH config**. Reusing one lets several database servers share the same host connection — update it once and every server that references it follows.

### Generating an SSH key

With **Auth Type** set to **Private Key**, the form can **Generate a new Ed25519 keypair** for you. Copy the displayed public key into `~/.ssh/authorized_keys` on the SSH server before saving — it's shown only once and the public key isn't stored.

## Backing up databases on a remote host

When databases run in Docker containers on a remote machine with ports only on an internal network, the tunnel reuses the **same SSH access you already use to manage that host** — no separate agent is needed, and the database stays private.

Publish the database port to the host's loopback, so it's reachable from the host but not from the network:

```yaml
services:
  db:
    image: postgres:16
    ports:
      - "127.0.0.1:5432:5432"
```

Set the database **Host** to `127.0.0.1` and **Port** to `5432`. Loopback is exactly where the tunnel terminates, so Databasement can reach the database while the network cannot.

### Optional: SSH server in the stack

If the host doesn't expose SSH — or you'd rather not publish the database port at all — add a small SSH server to the same Compose project. Only the SSH port is published; Databasement reaches the database by its service name over the project's default network.

```yaml
services:
  db:
    image: postgres:18

  sshd:
    image: lscr.io/linuxserver/openssh-server
    environment:
      USER_NAME: databasement
      PUBLIC_KEY: "ssh-ed25519 AAAA... databasement-tunnel"
      DOCKER_MODS: linuxserver/mods:openssh-server-ssh-tunnel
    ports:
      - "2222:2222"   # only SSH is published — not the database
```

Point the SSH Tunnel at the host on port `2222`, then set the database **Host** to `db` and **Port** to `5432` — the service name resolves on the project's default network.

For same-host containers sharing a Docker network (no SSH), see [Docker Networking](./database-servers.md#docker-networking) instead.

## Security

:::info Keep the database private
What matters most is that the database is not reachable from the public internet: either because its host is on a private network, or because you bound the port to loopback (`127.0.0.1:5432:5432`).
:::

Optionally, restrict the SSH key to forwarding only in `authorized_keys`:

```
restrict,port-forwarding ssh-ed25519 AAAA... databasement-tunnel
```

