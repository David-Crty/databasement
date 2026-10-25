#!/usr/bin/env node
/**
 * Guards the published URLs. Fails when the build in dist/:
 *
 * 1. drops a page or file the live site (the gh-pages branch) serves, so a
 *    link from the README, the Helm chart or the app never starts to 404
 * 2. drops a heading anchor a live page has (#supported-versions, #v1-7, ...)
 * 3. links to a page, file or anchor of its own that does not exist
 *
 * Run it after scripts/build-versions.sh, with the gh-pages branch fetched.
 *
 * Usage: node scripts/check-urls.mjs [--ref origin/gh-pages] [--dist dist]
 */
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const docsDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const option = (name, fallback) => {
    const at = args.indexOf(name);
    return at === -1 ? fallback : args[at + 1];
};
const ref = option('--ref', 'origin/gh-pages');
const dist = path.resolve(docsDir, option('--dist', 'dist'));
const siteBase = '/databasement/';

/** Paths the old site served that are not docs: the Helm repo, and files replaced on purpose. */
const IGNORED = [
    /^charts\//,
    /^index\.yaml$/,
    /^artifacthub-repo\.yml$/,
    /^\.gitkeep$/,
    /^assets\//,
    /(^|\/)search-index\.json$/,
    /^\d+\.\d+\/(llms(-full)?\.txt|.*\.md|sitemap\.xml)$/,
];

/** Paths whose files may exist in a version without one being served, e.g. /1.0/404.html. */
const servedAs = (file) => [file, file.replace(/index\.html$/, ''), `${file.replace(/\.html$/, '')}/index.html`];

function git(...gitArgs) {
    return execFileSync('git', gitArgs, {cwd: docsDir, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024});
}

function headingIds(html) {
    return new Set([...html.matchAll(/<h[1-6][^>]*\sid="([^"]+)"/g)].map((match) => decodeEntities(match[1])));
}

function allIds(html) {
    return new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((match) => decodeEntities(match[1])));
}

function decodeEntities(value) {
    return value.replace(/&amp;/g, '&').replace(/&#39;|&#x27;/g, "'").replace(/&quot;/g, '"');
}

function walk(dir, prefix = '') {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const relative = prefix + entry.name;
        return entry.isDirectory() ? walk(path.join(dir, entry.name), `${relative}/`) : [relative];
    });
}

/** Resolves a site path ("self-hosting/docker/") to the file GitHub Pages serves for it. */
function fileFor(sitePath) {
    const candidates = sitePath === '' || sitePath.endsWith('/')
        ? [`${sitePath}index.html`]
        : [sitePath, `${sitePath}/index.html`, `${sitePath}.html`];
    return candidates.find((candidate) => fs.existsSync(path.join(dist, candidate)) && fs.statSync(path.join(dist, candidate)).isFile());
}

if (!fs.existsSync(dist)) {
    console.error(`No build at ${dist}: run scripts/build-versions.sh first.`);
    process.exit(1);
}

const failures = [];
const htmlCache = new Map();
const readHtml = (file) => {
    if (!htmlCache.has(file)) {
        htmlCache.set(file, fs.readFileSync(path.join(dist, file), 'utf8'));
    }
    return htmlCache.get(file);
};

// 1 + 2: everything the live site serves must still be served, with its anchors.
const liveFiles = git('ls-tree', '-r', '--full-tree', '--name-only', ref).split('\n').filter(Boolean)
    .filter((file) => !IGNORED.some((pattern) => pattern.test(file)));
let checkedAnchors = 0;
for (const file of liveFiles) {
    const sitePath = file.replace(/index\.html$/, '');
    const served = servedAs(file).map((candidate) => fileFor(candidate.replace(/index\.html$/, ''))).find(Boolean);
    if (!served) {
        failures.push(`missing: ${siteBase}${sitePath} (served by ${ref})`);
        continue;
    }
    if (!file.endsWith('.html') || /(^|\/)404\.html$/.test(file)) {
        continue;
    }
    const newHtml = readHtml(served);
    if (/http-equiv="refresh"/i.test(newHtml)) {
        continue;
    }
    const liveIds = headingIds(git('show', `${ref}:${file}`));
    const newIds = allIds(newHtml);
    for (const id of liveIds) {
        checkedAnchors++;
        if (!newIds.has(id)) {
            failures.push(`missing anchor: ${siteBase}${sitePath}#${id}`);
        }
    }
}

// 3: every internal link of the new build must resolve, anchors included.
let checkedLinks = 0;
for (const file of walk(dist).filter((name) => name.endsWith('.html'))) {
    const html = readHtml(file);
    const pageUrl = new URL(`https://site${siteBase}${file.replace(/index\.html$/, '')}`);
    for (const [, raw] of html.matchAll(/<a\s[^>]*?href="([^"]+)"/g)) {
        const href = decodeEntities(raw);
        if (/^[a-z][a-z0-9+.-]*:/i.test(href) && !href.startsWith('https://david-crty.github.io/')) {
            continue;
        }
        const url = new URL(href.replace('https://david-crty.github.io/', 'https://site/'), pageUrl);
        if (url.host !== 'site' || !url.pathname.startsWith(siteBase)) {
            continue;
        }
        checkedLinks++;
        const target = fileFor(decodeURIComponent(url.pathname.slice(siteBase.length)));
        if (!target) {
            failures.push(`broken link: ${href} in ${file}`);
            continue;
        }
        const id = decodeURIComponent(url.hash.slice(1));
        if (id && id !== '_top' && target.endsWith('.html') && !allIds(readHtml(target)).has(id)) {
            failures.push(`broken anchor: ${href} in ${file}`);
        }
    }
}

console.log(`==> Checked ${liveFiles.length} live paths, ${checkedAnchors} live anchors and ${checkedLinks} internal links`);
if (failures.length > 0) {
    const unique = [...new Set(failures)];
    console.error(unique.join('\n'));
    console.error(`==> ${unique.length} URL problem(s)`);
    process.exit(1);
}
console.log('==> Every live URL and anchor is still served');
