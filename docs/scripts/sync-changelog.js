#!/usr/bin/env node
/**
 * Copies the repository's CHANGELOG.md into the docs tree as a Starlight
 * page. Run before `npm run build` / `npm run dev`, and once per snapshot by
 * scripts/build-versions.sh (the generated page is never committed, so no tag
 * carries it and each snapshot needs its own copy).
 *
 * Every version gets the same, latest changelog: it is a single history of the
 * project, not a per-version document.
 *
 * Usage: node scripts/sync-changelog.js [content-dir]
 */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const source = path.join(__dirname, '..', '..', 'CHANGELOG.md');
const contentDir = process.argv[2] ?? path.join(__dirname, '..', 'src', 'content', 'docs');
const target = path.join(contentDir, 'changelog.md');

if (!fs.existsSync(source)) {
    console.warn(`==> No CHANGELOG.md at ${source}, skipping the changelog page.`);
    process.exit(0);
}

// Stable anchors: "## [1.7] - 2026-09-05" gets the id "v1-7", so other pages
// can link to changelog.md#v1-7 instead of a slug built from the date.
const markdown = fs.readFileSync(source, 'utf8').replace(/^# .*\n+/, '').replace(
    /^## \[(\d+\.\d+)\](.*)$/gm,
    (line, version, rest) => `## [${version}]${rest} {#v${version.replace(/\./g, '-')}}`,
);

const frontMatter = '---\ntitle: Changelog\ntableOfContents:\n  maxHeadingLevel: 2\n---\n\n';

fs.mkdirSync(path.dirname(target), {recursive: true});
fs.writeFileSync(target, frontMatter + markdown.trimEnd() + '\n');

const minors = (markdown.match(/^## \[/gm) || []).length;
console.log(`==> Wrote ${path.relative(process.cwd(), target)} (${minors} versions)`);
