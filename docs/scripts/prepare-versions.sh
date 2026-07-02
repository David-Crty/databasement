#!/usr/bin/env bash
#
# Builds Docusaurus versioned_docs snapshots from git tags — one docs version
# per 1.x minor, using the latest patch tag of each minor. Snapshots are
# generated at build time and never committed (see docs/.gitignore).
#
# Requires the full tag list (CI must checkout with fetch-depth: 0).
set -euo pipefail

DOCS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$DOCS_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Preserve the working-tree docs (the ref being built) and restore them when
# the script exits, even on failure — the loop below overwrites them per tag.
BACKUP=$(mktemp -d)
cp -a docs/docs "$BACKUP/docs"
cp -a docs/sidebars.ts "$BACKUP/sidebars.ts"
restore_working_tree() {
    rm -rf docs/docs docs/sidebars.ts
    cp -a "$BACKUP/docs" docs/docs
    cp -a "$BACKUP/sidebars.ts" docs/sidebars.ts
    git restore --staged docs/docs docs/sidebars.ts 2>/dev/null || true
    rm -rf "$BACKUP"
}
trap restore_working_tree EXIT

# Start from a clean slate so the script is idempotent
rm -rf "$DOCS_DIR/versioned_docs" "$DOCS_DIR/versioned_sidebars" "$DOCS_DIR/versions.json"

MINORS=$(git tag -l 'v1.*' | grep -E '^v1\.[0-9]+\.[0-9]+$' | sed -E 's/^v(1\.[0-9]+)\.[0-9]+$/\1/' | sort -uV)

if [ -z "$MINORS" ]; then
    echo "No v1.x.y tags found — nothing to version."
    exit 0
fi

# Cut versions oldest-first so versions.json ends up newest-first
for MINOR in $MINORS; do
    TAG=$(git tag -l "v${MINOR}.*" | grep -E '^v1\.[0-9]+\.[0-9]+$' | sort -V | tail -1)
    echo "==> Versioning docs ${MINOR} from tag ${TAG}"
    rm -rf docs/docs docs/sidebars.ts
    git checkout "$TAG" -- docs/docs docs/sidebars.ts
    # Older tags reference static assets as ../static/…, which no longer
    # resolves once the files are nested under versioned_docs/. Rewrite to
    # absolute static paths (served from docs/static of the current build).
    find docs/docs -type f \( -name '*.md' -o -name '*.mdx' \) -exec sed -i 's#\.\./static/#/#g' {} +
    (cd "$DOCS_DIR" && npx docusaurus docs:version "$MINOR")
done

echo "==> Generated versions: $(cat "$DOCS_DIR/versions.json")"
