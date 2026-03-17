---
sidebar_position: 6
---

# Versioning

Databasement follows [semantic versioning](https://semver.org/). The Docker images, Helm chart, and application all share the same version number — version `1.0.1` means the same release everywhere.

Check the [GitHub Releases](https://github.com/David-Crty/databasement/releases) page for the full changelog and available versions.

## Docker Image Tags

Docker images are available on [Docker Hub](https://hub.docker.com/r/davidcrty/databasement). When a new version is released (e.g., `1.0.1`), the following tags are published:

- `davidcrty/databasement:1.0.1` — exact version (pinned)
- `davidcrty/databasement:1.0` — latest patch in the 1.0.x line
- `davidcrty/databasement:1` — latest release in the 1.x.x line
- `davidcrty/databasement:latest` — most recent release

Use an exact version tag for production deployments. Use `latest` or a major/minor tag if you want automatic updates with tools like [Renovate](https://docs.renovatebot.com/) or [Watchtower](https://containrrr.dev/watchtower/).

## Helm Chart

The Helm chart uses the same version as the application — installing chart version `1.0.1` deploys app version `1.0.1`.

### Install

```bash
helm repo add databasement https://david-crty.github.io/databasement
helm repo update
helm install databasement databasement/databasement --version 1.0.1
```

### Update

```bash
helm repo update
helm upgrade databasement databasement/databasement --version 1.0.1
```

### Use as a dependency

In your `Chart.yaml`:

```yaml
dependencies:
  - name: databasement
    version: "1.0.1"
    repository: "https://david-crty.github.io/databasement"
```

Then run `helm dependency update`.

For full Helm configuration options, see the [Kubernetes + Helm](./kubernetes-helm) guide.
