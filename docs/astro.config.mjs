// @ts-check
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {defineConfig} from 'astro/config';
import {satteri} from '@astrojs/markdown-satteri';
import starlight from '@astrojs/starlight';
import starlightSidebarTopics from 'starlight-sidebar-topics';
import starlightLlmsTxt from 'starlight-llms-txt';
import mermaid from 'astro-mermaid';
import {docsLinks} from './src/plugins/docs-links.mjs';

const projectDir = path.dirname(fileURLToPath(import.meta.url));
const contentDir = path.join(projectDir, 'src/content/docs');

const siteBase = '/databasement/';
const site = process.env.DOCS_LOCAL === 'true' ? 'http://localhost:4321' : 'https://david-crty.github.io';

// scripts/build-versions.sh builds the site once per 1.x minor. DOCS_MINOR is
// the minor of this build, DOCS_VERSIONS every published minor, newest first.
// The newest is served at the site root, older ones under /<minor>/. A plain
// `npm run build` / `npm run dev` sets neither and builds the latest.
const versions = (process.env.DOCS_VERSIONS ?? '').split(',').filter(Boolean);
const minor = process.env.DOCS_MINOR || versions[0] || '';
const isLatest = versions.length === 0 || minor === versions[0];
const base = isLatest ? siteBase : `${siteBase}${minor}/`;

/** Lets the Starlight overrides read the build's version context. */
function docsContext() {
    const context = {
        base,
        siteBase,
        isLatest,
        minor,
        versions,
        release: process.env.DOCS_VERSION ?? '',
        analyticsSnippet: process.env.ANALYTICS_SNIPPET ?? '',
    };
    return {
        name: 'docs-context',
        hooks: {
            'astro:config:setup': ({updateConfig, injectRoute}) => {
                updateConfig({
                    vite: {
                        plugins: [{
                            name: 'virtual-docs-context',
                            resolveId: (id) => (id === 'virtual:docs-context' ? '\0virtual:docs-context' : undefined),
                            load: (id) => (id === '\0virtual:docs-context' ? `export default ${JSON.stringify(context)};` : undefined),
                        }],
                    },
                });
                if (isLatest) {
                    injectRoute({pattern: '/', entrypoint: './src/landing/index.astro'});
                    injectRoute({pattern: '/sitemap.xml', entrypoint: './src/routes/sitemap.xml.ts'});
                    injectRoute({pattern: '/[...slug].md', entrypoint: './src/routes/page.md.ts'});
                }
                injectRoute({pattern: '/search', entrypoint: './src/routes/search.astro'});
            },
        },
    };
}

export default defineConfig({
    site,
    base,
    trailingSlash: 'ignore',
    // Old path → new path for a renamed or moved page, so published links keep
    // resolving (scripts/check-urls.mjs fails the deploy otherwise).
    redirects: {},
    outDir: process.env.DOCS_OUT_DIR || './dist',
    cacheDir: process.env.DOCS_CACHE_DIR || './node_modules/.astro',
    // Every version is built from the same project, so they share one
    // /databasement/_astro/ (content-hashed) instead of a copy each.
    build: {assetsPrefix: siteBase.replace(/\/$/, '')},
    markdown: {
        processor: satteri({
            features: {headingAttributes: true},
            mdastPlugins: [docsLinks({contentDir, base, siteBase, strict: isLatest})],
        }),
    },
    integrations: [
        docsContext(),
        mermaid({autoTheme: true}),
        starlight({
            title: 'Databasement',
            description: 'Self-hosted backup manager for MySQL, PostgreSQL, MariaDB, SQL Server, MongoDB, SQLite, Firebird and Redis databases.',
            logo: {src: './src/assets/logo.png', alt: 'Databasement'},
            favicon: '/img/favicon.svg',
            head: [
                {tag: 'link', attrs: {rel: 'icon', href: `${base}img/favicon.ico`, sizes: '32x32'}},
                {tag: 'link', attrs: {rel: 'apple-touch-icon', sizes: '180x180', href: `${base}img/apple-touch-icon.png`}},
            ],
            social: [{icon: 'github', label: 'GitHub', href: 'https://github.com/David-Crty/databasement'}],
            // Snapshots of released tags are rebuilt from scratch on every
            // deploy, so "Edit this page" always points at the source on main.
            editLink: {baseUrl: 'https://github.com/David-Crty/databasement/edit/main/docs/'},
            lastUpdated: false,
            customCss: ['./src/styles/tokens.css', './src/styles/docs.css'],
            routeMiddleware: './src/routeData.ts',
            expressiveCode: {shiki: {langAlias: {env: 'dotenv', cron: 'txt'}}},
            components: {
                Head: './src/components/starlight/Head.astro',
                Banner: './src/components/starlight/Banner.astro',
                SocialIcons: './src/components/starlight/SocialIcons.astro',
            },
            pagefind: {
                ranking: {
                    // The changelog is one very long page matching most terms:
                    // penalise length more than the default 0.75 so the page
                    // that documents a feature outranks the release notes.
                    pageLength: 0.9,
                    termFrequency: 0.8,
                    termSaturation: 1.2,
                },
            },
            plugins: [
                starlightSidebarTopics([
                    {
                        label: 'Self-Hosting',
                        link: '/self-hosting/intro/',
                        icon: 'laptop',
                        items: [{autogenerate: {directory: 'self-hosting'}}],
                    },
                    {
                        label: 'User Guide',
                        link: '/user-guide/intro/',
                        icon: 'open-book',
                        items: [{autogenerate: {directory: 'user-guide'}}],
                    },
                    {
                        label: 'Contributing',
                        link: '/contributing/development/',
                        icon: 'pencil',
                        items: [{autogenerate: {directory: 'contributing'}}],
                    },
                    {
                        label: 'Changelog',
                        link: '/changelog/',
                        icon: 'list-format',
                        items: ['changelog'],
                    },
                ]),
                ...(isLatest ? [starlightLlmsTxt({
                    projectName: 'Databasement',
                    description: 'Simple and powerful database backup management: self-hosting and user documentation for Databasement.',
                    promote: ['self-hosting/intro', 'self-hosting/**', 'changelog'],
                    demote: ['contributing/**'],
                    exclude: ['changelog', 'contributing/**'],
                    customSets: [
                        {label: 'Self-Hosting', paths: ['self-hosting/**'], description: 'installing, configuring and upgrading Databasement'},
                        {label: 'User Guide', paths: ['user-guide/**'], description: 'backing up, restoring and automating with Databasement'},
                    ],
                })] : []),
            ],
        }),
    ],
});
