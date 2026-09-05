#!/usr/bin/env node
/**
 * Copies the repository's CHANGELOG.md into the docs tree as a Docusaurus
 * page. Run before `npm run build` / `npm run start`, and once per snapshot by
 * scripts/prepare-versions.sh (versioned builds drop the current version, and
 * the generated page is never committed, so each snapshot needs its own copy).
 *
 * Usage: node scripts/sync-changelog.js [--max-minor 1.5]
 *   --max-minor  omit versions newer than this minor, for a versioned snapshot
 */
const fs = require('node:fs');
const path = require('node:path');

const maxMinorIndex = process.argv.indexOf('--max-minor');
const maxMinor = maxMinorIndex === -1 ? null : process.argv[maxMinorIndex + 1];

const source = path.join(__dirname, '..', '..', 'CHANGELOG.md');
const target = path.join(__dirname, '..', 'docs', 'self-hosting', 'changelog.md');

if (!fs.existsSync(source)) {
    console.warn(`==> No CHANGELOG.md at ${source}, skipping the changelog page.`);
    process.exit(0);
}

const asNumbers = (minor) => minor.split('.').map(Number);
const isNewerThanMax = (minor) => {
    if (maxMinor === null) {
        return false;
    }

    const [major, patch] = asNumbers(minor);
    const [maxMajor, maxPatch] = asNumbers(maxMinor);

    return major > maxMajor || (major === maxMajor && patch > maxPatch);
};

let markdown = fs.readFileSync(source, 'utf8');

// Docs are built from tags, where the Unreleased section is empty. Drop it
// (and its link reference) unless a manual build catches entries in it.
markdown = markdown
    .replace(/^## \[Unreleased\]\n+(?=## \[)/m, '')
    .replace(/^\[Unreleased\]: .*\n/m, '');

// A snapshot of an older minor must not advertise the releases that came after it.
if (maxMinor !== null) {
    markdown = markdown
        .split(/^(?=## \[)/m)
        .filter((block) => {
            const heading = block.match(/^## \[(\d+\.\d+)\]/);

            return heading === null || !isNewerThanMax(heading[1]);
        })
        .join('')
        .replace(/^\[(\d+\.\d+)\]: .*\n/gm, (line, minor) => (isNewerThanMax(minor) ? '' : line));
}

// Stable anchors: "## [1.7] - 2026-09-05" gets the id "v1-7", so other pages
// can link to changelog.md#v1-7 instead of a slug built from the date.
markdown = markdown.replace(
    /^## \[(\d+\.\d+)\](.*)$/gm,
    (line, version, rest) => `## [${version}]${rest} {#v${version.replace(/\./g, '-')}}`,
);

const frontMatter = '---\nsidebar_position: 8\n---\n\n';

fs.mkdirSync(path.dirname(target), {recursive: true});
fs.writeFileSync(target, frontMatter + markdown.trimEnd() + '\n');

const minors = (markdown.match(/^## \[/gm) || []).length;
console.log(`==> Wrote docs/self-hosting/changelog.md (${minors} versions${maxMinor ? `, up to ${maxMinor}` : ''})`);
