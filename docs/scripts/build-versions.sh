#!/usr/bin/env bash
#
# Builds the published site: one docs version per 1.x minor, each from the
# latest patch tag of that minor. The newest minor is served at the site root
# (with the landing page and llms.txt), older ones under /<minor>/. Nothing is
# committed: every deploy rebuilds every version from its tag.
#
# Each version is a separate Astro build of the current project with the tag's
# content swapped in, done in a scratch copy under .build/ so the working tree
# is untouched (inside docs/, Node resolves docs/node_modules by walking up).
# Tags cut before the move to Starlight hold Docusaurus content under
# docs/docs/; scripts/normalize-docusaurus.mjs rewrites it on the way in.
#
# Requires the full tag list (CI must checkout with fetch-depth: 0).
#
# Usage: scripts/build-versions.sh [--latest-only]
set -euo pipefail

DOCS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$DOCS_DIR/.." && pwd)"
OUT="$DOCS_DIR/dist"
LATEST_ONLY=false
[ "${1:-}" = "--latest-only" ] && LATEST_ONLY=true

TAGS=$(git -C "$REPO_ROOT" tag -l 'v1.*' | grep -E '^v1\.[0-9]+\.[0-9]+$' || true)
if [ -z "$TAGS" ]; then
    echo "No v1.x.y tags found: nothing to build." >&2
    exit 1
fi
MINORS=$(echo "$TAGS" | sed -E 's/^v(1\.[0-9]+)\.[0-9]+$/\1/' | sort -uV -r)
LATEST=$(echo "$MINORS" | head -1)
VERSIONS=$(echo "$MINORS" | paste -sd, -)
$LATEST_ONLY && MINORS="$LATEST"

WORK="$DOCS_DIR/.build"
rm -rf "$WORK"
trap 'rm -rf "$WORK"' EXIT

SITE="$WORK/site"
mkdir -p "$SITE"
tar -C "$DOCS_DIR" --exclude=./node_modules --exclude=./dist --exclude=./.astro --exclude=./.build -cf - . | tar -C "$SITE" -xf -

rm -rf "$OUT"
for MINOR in $MINORS; do
    TAG=$(echo "$TAGS" | grep -E "^v${MINOR//./\\.}\.[0-9]+$" | sort -V | tail -1)
    echo "==> Building docs ${MINOR} from ${TAG}"

    CONTENT="$SITE/src/content/docs"
    rm -rf "$CONTENT"
    mkdir -p "$CONTENT"
    if git -C "$REPO_ROOT" cat-file -e "${TAG}:docs/src/content/docs" 2>/dev/null; then
        git -C "$REPO_ROOT" archive "$TAG" docs/src/content/docs | tar -x -C "$CONTENT" --strip-components=4
    else
        git -C "$REPO_ROOT" archive "$TAG" docs/docs | tar -x -C "$CONTENT" --strip-components=2
    fi

    # The changelog moved from the self-hosting section to the docs root;
    # tags cut before that link to it from within the section.
    if [ -d "$CONTENT/self-hosting" ]; then
        find "$CONTENT/self-hosting" -type f \( -name '*.md' -o -name '*.mdx' \) -exec sed -i \
            's@\](\./changelog\.md@](../changelog.md@g' {} +
    fi
    node "$DOCS_DIR/scripts/normalize-docusaurus.mjs" "$CONTENT"
    # The changelog page is generated rather than committed, so no tag
    # carries it: every version gets the latest one.
    node "$DOCS_DIR/scripts/sync-changelog.js" "$CONTENT"

    if [ "$MINOR" = "$LATEST" ]; then
        # The landing page owns the root of the latest version.
        rm -f "$CONTENT/index.md" "$CONTENT/index.mdx"
        DEST="$WORK/out/latest"
    else
        DEST="$WORK/out/$MINOR"
    fi

    (
        cd "$SITE"
        rm -rf .astro
        DOCS_MINOR="$MINOR" DOCS_VERSIONS="$VERSIONS" DOCS_OUT_DIR="$DEST" \
            DOCS_CACHE_DIR="$WORK/cache/$MINOR" npx astro build
    )
done

mkdir -p "$OUT"
cp -a "$WORK/out/latest/." "$OUT/"
for MINOR in $MINORS; do
    [ "$MINOR" = "$LATEST" ] && continue
    # Assets are served from the shared /_astro/ (build.assetsPrefix).
    cp -a "$WORK/out/$MINOR/_astro/." "$OUT/_astro/"
    rm -rf "$WORK/out/$MINOR/_astro"
    cp -a "$WORK/out/$MINOR" "$OUT/$MINOR"
done

echo "==> Built versions ${VERSIONS} into ${OUT#"$REPO_ROOT"/}"
