import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const SCHEME = /^[a-z][a-z0-9+.-]*:/i;
const DOC_EXTENSION = /\.mdx?$/;
const ASSET_EXTENSION = /\.[a-z0-9]+$/i;

/**
 * Sätteri mdast plugin resolving the Docusaurus link conventions the docs are
 * written in, so pages keep linking to files rather than URLs:
 *
 * - `./backups.md#x`, `../user-guide/volumes` and `versioning` resolve against
 *   the page's own file, then become `<base><slug>/#x`
 * - `/user-guide/backups` and `/img/logo.png` get the version's base prepended,
 *   so a link never escapes the version being read
 * - `pathname:///llms.txt` points at the site root, outside any version
 *
 * A link to a page that does not exist fails the build when `strict` is set
 * (the current docs), and only warns otherwise (snapshots of released tags).
 *
 * @param {{contentDir: string, base: string, siteBase: string, strict: boolean, logger?: {warn: (message: string) => void}}} options
 */
export function docsLinks({contentDir, base, siteBase, strict, logger = console}) {
    const root = path.resolve(contentDir);

    function resolveDoc(docPath, source) {
        const stem = docPath.replace(/\/+$/, '').replace(DOC_EXTENSION, '');
        const candidates = [`${stem}.md`, `${stem}.mdx`, `${stem}/index.md`, `${stem}/index.mdx`];
        const found = candidates.find((candidate) => fs.existsSync(path.join(root, candidate)));
        if (!found) {
            const message = `Broken link to "${docPath}" in ${path.relative(root, source)}`;
            if (strict) {
                throw new Error(message);
            }
            logger.warn(message);
            return null;
        }
        const slug = found.replace(DOC_EXTENSION, '').replace(/(^|\/)index$/, '');
        return `${base}${slug === '' ? '' : `${slug}/`}`;
    }

    function rewrite(url, source) {
        if (url.startsWith('pathname://')) {
            return siteBase + url.slice('pathname://'.length).replace(/^\/+/, '');
        }
        if (url === '' || url.startsWith('#') || url.startsWith('//') || SCHEME.test(url)) {
            return url;
        }

        const hashAt = url.search(/[?#]/);
        const pathname = hashAt === -1 ? url : url.slice(0, hashAt);
        const suffix = hashAt === -1 ? '' : url.slice(hashAt);

        if (pathname.startsWith('/')) {
            if (pathname.startsWith(base) || pathname.startsWith(siteBase)) {
                return url;
            }
            const target = pathname.slice(1);
            if (ASSET_EXTENSION.test(target) && !DOC_EXTENSION.test(target)) {
                return base + target + suffix;
            }
            const resolved = resolveDoc(target, source);
            return resolved === null ? url : resolved + suffix;
        }

        if (ASSET_EXTENSION.test(pathname) && !DOC_EXTENSION.test(pathname)) {
            return url;
        }
        const from = path.posix.dirname(path.relative(root, source).split(path.sep).join('/'));
        const target = path.posix.normalize(path.posix.join(from, pathname));
        if (target.startsWith('..')) {
            return url;
        }
        const resolved = resolveDoc(target === '.' ? '' : target, source);
        return resolved === null ? url : resolved + suffix;
    }

    return ({fileURL}) => {
        if (!fileURL) {
            return null;
        }
        const source = fileURLToPath(fileURL);
        if (!source.startsWith(root + path.sep)) {
            return null;
        }
        const visit = (node, ctx) => {
            const url = rewrite(node.url, source);
            if (url !== node.url) {
                ctx.setProperty(node, 'url', url);
            }
        };
        return {name: 'docs-links', link: visit, image: visit, definition: visit};
    };
}
