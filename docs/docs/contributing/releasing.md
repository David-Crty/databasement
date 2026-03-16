---
sidebar_position: 2
---

# Releasing

This guide explains how releases work and how Docker images and Helm charts are versioned.

## Creating a Release

The entire release process is automated. To create a new release, push a semver git tag:

```bash
git tag v0.2.0
git push origin v0.2.0
```

This triggers three parallel workflows:

1. **Docker images** — Built and pushed to Docker Hub with semver tags
2. **Helm chart** — Packaged and published to the Helm repository on GitHub Pages
3. **GitHub Release** — Created automatically with generated release notes

## Docker Image Tagging

Both the base image (`davidcrty/databasement-php`) and the app image (`davidcrty/databasement`) follow the same tagging strategy:

| Trigger | Tags produced | Example |
|---------|--------------|---------|
| Push tag `v0.2.0` | `:0.2.0`, `:0.2`, `:0` | `davidcrty/databasement:0.2.0` |
| Push to `main` | `:latest` | `davidcrty/databasement:latest` |
| Push to any other branch | `:<branch-slug>` | `davidcrty/databasement:feature-foo` |

### Semver tags

When a version tag is pushed, Docker images are tagged with the full version, the major.minor, and the major number. For example, pushing `v1.2.3` produces:

- `davidcrty/databasement:1.2.3` — exact version (pinned)
- `davidcrty/databasement:1.2` — latest patch in the 1.2.x line
- `davidcrty/databasement:1` — latest release in the 1.x.x line

This allows users and tools like [Renovate](https://docs.renovatebot.com/) to track updates at their preferred level of stability.

## Helm Chart Versioning

The Helm chart's `version`, `appVersion`, and default `image.tag` in `values.yaml` are all set to `0.0.0-dev` in source. During a release, they are automatically patched to match the git tag version.

For example, pushing `v0.2.0` produces a Helm chart with:

```yaml
# Chart.yaml
version: 0.2.0
appVersion: "0.2.0"

# values.yaml
image:
  tag: "0.2.0"
```

The packaged chart is published to the Helm repository at:

```
https://david-crty.github.io/databasement/charts
```

## GitHub Release

A GitHub Release is created automatically for every version tag, with release notes generated from the commits and pull requests since the previous tag.
