#!/usr/bin/env node
/**
 * Copies the repository's CHANGELOG.md into the docs tree as a Docusaurus
 * page. Run before `npm run build` / `npm run start`, and once per snapshot by
 * scripts/prepare-versions.sh (versioned builds drop the current version, and
 * the generated page is never committed, so each snapshot needs its own copy).
 *
 * Every version gets the same, latest changelog: it is a single history of the
 * project, not a per-version document.
 *
 * Usage: node scripts/sync-changelog.js
 */
const fs = require('node:fs');
const path = require('node:path');

const source = path.join(__dirname, '..', '..', 'CHANGELOG.md');
const target = path.join(__dirname, '..', 'docs', 'changelog.md');

if (!fs.existsSync(source)) {
    console.warn(`==> No CHANGELOG.md at ${source}, skipping the changelog page.`);
    process.exit(0);
}

// Stable anchors: "## [1.7] - 2026-09-05" gets the id "v1-7", so other pages
// can link to changelog.md#v1-7 instead of a slug built from the date.
const markdown = fs.readFileSync(source, 'utf8').replace(
    /^## \[(\d+\.\d+)\](.*)$/gm,
    (line, version, rest) => `## [${version}]${rest} {#v${version.replace(/\./g, '-')}}`,
);

const frontMatter = '---\nsidebar_position: 1\n---\n\n';

fs.mkdirSync(path.dirname(target), {recursive: true});
fs.writeFileSync(target, frontMatter + markdown.trimEnd() + '\n');

const minors = (markdown.match(/^## \[/gm) || []).length;
console.log(`==> Wrote docs/changelog.md (${minors} versions)`);
