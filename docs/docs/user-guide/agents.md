---
sidebar_position: 4
---

# Remote Agents

A remote **agent** backs up and restores databases that Databasement cannot reach directly — without opening any inbound port to them.

Instead of Databasement connecting **in** to your database (as an [SSH tunnel](./ssh-tunnel.md) does), you run a small agent next to the database that connects **out** to Databasement. The database stays completely private; only outbound HTTPS is ever needed.

:::info Egress, not ingress
The SSH tunnel needs **inbound** access (Databasement reaches into the network). An agent needs only **outbound** HTTPS. That makes it the right fit for hardened, firewalled, or multi-tenant environments where opening inbound ports is forbidden.
:::

## How it works

The agent is the same Databasement image running in agent mode (`agent:run`). It polls the server over HTTPS, claims any job assigned to it, runs the dump (or the restore) on its own network, moves the snapshot straight between the database and your storage volume, and reports back. It never receives an inbound connection and never touches the server's database.

```mermaid
flowchart TB
  subgraph net["🔒 Your private network — no inbound ports"]
    direction LR
    DB[("Database")]
    Agent["Agent (agent:run)"]
    Vol["Volume (S3 / SFTP)"]
    Agent ==>|dump| DB
    Agent ==>|upload| Vol
  end

  Agent -. "outbound HTTPS only (poll → claim → report)" .-> Server["Databasement server"]

  classDef agent fill:#dbeafe,stroke:#3b82f6,stroke-width:1.5px,color:#1e3a8a;
  classDef data fill:#ede9fe,stroke:#8b5cf6,stroke-width:1.5px,color:#4c1d95;
  classDef server fill:#dcfce7,stroke:#22c55e,stroke-width:1.5px,color:#14532d;

  class Agent agent;
  class DB,Vol data;
  class Server server;

  style net fill:#f8fafc,stroke:#cbd5e1,stroke-width:1px,color:#475569;
```

1. **Poll** — the agent sends a heartbeat and asks the server for work.
2. **Claim** — the server hands back a job describing the database, the schedule, and the destination volume.
3. **Run** — the agent dumps the database (it reaches it on the local network) and uploads the snapshot to the volume.
4. **Report** — the agent acknowledges the result (filename, size, checksum, logs) so the snapshot shows up in the UI like any other.

## When to use an agent

- The database lives in a network where **no inbound port** can be opened (compliance, firewall, customer-managed VPC).
- You back up databases in **many isolated networks** and want one Databasement server orchestrating them all over HTTPS.
- An [SSH tunnel](./ssh-tunnel.md) isn't possible because there's no SSH host to reach.

If you *can* reach the database directly or over SSH, prefer that — it's simpler. The agent's unique value is the outbound-only connectivity.

## Setup

1. **Create an agent** — go to **Agents → Add Agent**, then copy the token shown once on creation.
2. **Run the agent** next to your database, pointing it at your server:

   ```bash
   docker run -d --restart unless-stopped \
     --name databasement-agent \
     -e DATABASEMENT_URL='https://databasement.example.com' \
     -e DATABASEMENT_AGENT_TOKEN='<paste-token>' \
     davidcrty/databasement:1
   ```

   When `DATABASEMENT_URL` is set, the container runs in agent mode — it only executes `agent:run` and needs no database configuration of its own.

3. **Assign the agent** to a database server by setting its **Agent** field. From then on, that server's backups, and any restore that targets it, run through the agent.

The **Agents** page shows each agent's connection status, so you can confirm it's polling.

## Constraints

- **No local volume** — the agent uploads from its own network, so it must use a reachable destination (S3-compatible or SFTP/FTP), not the server's local storage.
- **Restores read from a reachable volume** — a restore onto an agent-backed server downloads the snapshot on the agent's network, so the snapshot must have a copy on a volume the agent can reach. Copies on the server's local storage are not offered as a source.
- **Restores are never retried** — a restore drops and recreates the target database, so a restore interrupted by a lost agent is reported as failed rather than run again. It is given the full backup job timeout to finish, and fails if no agent claims it within that time.

## Restoring through an agent

Restoring onto an agent-backed server works like any other restore: pick the server as the target from the UI, the API, the MCP server, or a [scheduled restore](./snapshots.md#scheduled-restores). Instead of running on Databasement's queue, the restore is handed to the server's agent, which downloads the snapshot, restores it, and streams its logs back to the restore's job.

This makes agent-backed servers usable for recurring production-to-staging refreshes, where staging sits in a network Databasement cannot reach.

:::caution Update your agents
Agents only pick up restore jobs once they run a version that supports them. An older agent keeps running backups but leaves restores pending until they time out, so update the agent image before restoring onto its servers.
:::
