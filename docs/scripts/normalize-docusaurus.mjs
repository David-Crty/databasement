#!/usr/bin/env node
/**
 * Rewrites a Docusaurus docs tree into Starlight syntax, in place.
 *
 * It ran once over the working tree when the site moved to Starlight, and
 * scripts/build-versions.sh runs it on every docs snapshot checked out from an
 * older tag. It is idempotent: a tree already in Starlight syntax is unchanged.
 *
 * - the first `# H1` is dropped (Starlight renders the title) and becomes the
 *   `title` frontmatter when the page has none
 * - `sidebar_position` becomes `sidebar.order`, scaled so that a directory with
 *   a `_category_.json` position sorts where Docusaurus put it (Starlight sorts
 *   a directory by the lowest order among its pages)
 * - `slug: /` is dropped, the root index page uses the splash template
 * - `:::info` / `:::warning` become `:::note` / `:::caution`, and
 *   `:::type Title` becomes `:::type[Title]`
 * - pages importing `@theme/Tabs` become `.mdx` using Starlight's Tabs
 * - `../static/` asset paths become absolute `/` paths
 *
 * Usage: node scripts/normalize-docusaurus.mjs <content-dir>
 */
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(process.argv[2] ?? '');
if (!process.argv[2] || !fs.existsSync(root)) {
    console.error('Usage: node scripts/normalize-docusaurus.mjs <content-dir>');
    process.exit(1);
}

const ASIDE_ALIASES = {info: 'note', warning: 'caution'};

function walk(dir) {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        return entry.isDirectory() ? walk(full) : [full];
    });
}

function categoryPosition(dir) {
    const file = path.join(dir, '_category_.json');
    if (!fs.existsSync(file)) {
        return null;
    }
    return JSON.parse(fs.readFileSync(file, 'utf8')).position ?? null;
}

function splitFrontmatter(source) {
    const match = source.match(/^---\n([\s\S]*?)\n---\n?/);
    return match ? [match[1], source.slice(match[0].length)] : ['', source];
}

/** Applies `transform` to every line outside fenced code blocks. */
function mapProse(body, transform) {
    let fence = null;
    return body.split('\n').map((line) => {
        const marker = line.match(/^\s*(```+|~~~+)/);
        if (marker) {
            if (fence === null) {
                fence = marker[1];
            } else if (marker[1].startsWith(fence)) {
                fence = null;
            }
            return line;
        }
        return fence === null ? transform(line) : line;
    }).join('\n');
}

function normalizeFrontmatter(frontmatter, body, file) {
    let lines = frontmatter === '' ? [] : frontmatter.split('\n');
    const has = (key) => lines.some((line) => line.startsWith(`${key}:`));

    let title = null;
    body = mapProse(body, (line) => {
        const heading = title === null && line.match(/^# (.+?)\s*$/);
        if (heading) {
            title = heading[1];
            return '\u0000';
        }
        return line;
    }).replace(/\u0000\n*/, '');
    if (title !== null && !has('title')) {
        lines.unshift(`title: ${JSON.stringify(title)}`);
    }

    const position = lines.find((line) => line.startsWith('sidebar_position:'));
    if (position) {
        const own = Number(position.split(':')[1]);
        const dir = path.dirname(file);
        const category = dir === root ? null : categoryPosition(dir);
        const order = category === null ? own * 1000 : category * 1000 + own;
        lines = lines.filter((line) => line !== position);
        if (!has('sidebar')) {
            lines.push('sidebar:', `  order: ${order}`);
        }
    }

    lines = lines.filter((line) => !/^slug:\s*\/\s*$/.test(line));
    if (path.relative(root, file).replace(/\.mdx?$/, '') === 'index' && !has('template')) {
        lines.push('template: splash');
    }

    return [lines.join('\n'), body];
}

function normalizeBody(body) {
    return mapProse(body, (line) => line
        .replace(/^(\s*):::(\w+)(?:\s+(.+?))?\s*$/, (match, indent, type, title) => {
            const aside = ASIDE_ALIASES[type] ?? type;
            return `${indent}:::${aside}${title && !title.startsWith('[') ? `[${title}]` : title ?? ''}`;
        })
        .replace(/\]\((?:\.\.\/)+static\//g, '](/'));
}

function toMdx(body) {
    body = body.replace(/^import \w+ from '@theme\/\w+';\n/gm, '');
    body = `import { Tabs, TabItem } from '@astrojs/starlight/components';\n\n${body.trimStart()}`;
    body = body
        .replace(/<Tabs\s+groupId=/g, '<Tabs syncKey=')
        .replace(/<TabItem\s+value="[^"]*"\s+/g, '<TabItem ')
        .replace(/(<TabItem[^>]*?)\s+default(\s*>)/g, '$1$2');
    return mapProse(body, (line) => line.replace(/(?<!\\)\{#([\w-]+)\}/g, '\\{#$1\\}'));
}

let changed = 0;
for (const file of walk(root)) {
    if (path.basename(file) === '_category_.json') {
        continue;
    }
    if (!/\.mdx?$/.test(file)) {
        continue;
    }
    const source = fs.readFileSync(file, 'utf8');
    let [frontmatter, body] = splitFrontmatter(source);
    [frontmatter, body] = normalizeFrontmatter(frontmatter, body, file);
    body = normalizeBody(body);

    let target = file;
    if (/^import \w+ from '@theme\//m.test(body)) {
        body = toMdx(body);
        target = file.replace(/\.md$/, '.mdx');
    }

    const output = `---\n${frontmatter}\n---\n\n${body.trimStart()}`;
    if (output !== source || target !== file) {
        fs.writeFileSync(target, output);
        if (target !== file) {
            fs.rmSync(file);
        }
        changed++;
    }
}

for (const file of walk(root).filter((file) => path.basename(file) === '_category_.json')) {
    fs.rmSync(file);
}

console.log(`==> Normalized ${changed} page(s) in ${path.relative(process.cwd(), root) || '.'}`);
