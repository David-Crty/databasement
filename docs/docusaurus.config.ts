import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

const version = process.env.DOCS_VERSION;
const isLocalBuild = process.env.DOCS_LOCAL === 'true';

const config: Config = {
    title: 'Databasement',
    tagline: 'Simple and powerful database backup management',
    favicon: 'img/favicon.ico',

    plugins: [
        require.resolve('docusaurus-lunr-search'),
        [
            'docusaurus-plugin-llms',
            {
                title: 'Databasement Documentation',
                description: 'Simple and powerful database backup management — self-hosting and user documentation for Databasement.',
                generateLLMsTxt: true,
                generateLLMsFullTxt: true,
                generateMarkdownFiles: true,
                preserveDirectoryStructure: false,
                excludeImports: true,
                removeDuplicateHeadings: true,
                ignoreFiles: [
                    'index.md',
                ],
                includeOrder: [
                    'self-hosting/intro.md',
                    'self-hosting/docker-compose.md',
                    'self-hosting/docker.md',
                    'self-hosting/kubernetes-helm.md',
                    'self-hosting/native-ubuntu.md',
                    'self-hosting/configuration/**',
                    'self-hosting/versioning.md',
                    'user-guide/intro.md',
                    'user-guide/database-servers.md',
                    'user-guide/volumes.md',
                    'user-guide/backups.md',
                    'user-guide/snapshots.md',
                    'user-guide/organizations.md',
                    'user-guide/permissions.md',
                    'user-guide/api.md',
                    'user-guide/mcp.md',
                    'contributing/**',
                ],
                includeUnmatchedLast: true,
                ...(version ? {version} : {}),
            },
        ],
    ],

    url: isLocalBuild ? 'http://localhost:3000' : 'https://david-crty.github.io',
    baseUrl: '/databasement/',

    organizationName: 'David-Crty',
    projectName: 'databasement',

    markdown: {
        mermaid: true,
        hooks: {
            onBrokenMarkdownLinks: 'throw',
        }
    },
    onBrokenLinks: 'throw',

    themes: ['@docusaurus/theme-mermaid'],

    i18n: {
        defaultLocale: 'en',
        locales: ['en'],
    },

    presets: [
        [
            'classic',
            {
                docs: {
                    sidebarPath: './sidebars.ts',
                    routeBasePath: '/',
                    editUrl: 'https://github.com/David-Crty/databasement/tree/main/docs/',
                },
                blog: false,
                theme: {
                    customCss: './src/css/custom.css',
                },
            } satisfies Preset.Options,
        ],
    ],

    themeConfig: {
        navbar: {
            title: 'Databasement',
            logo: {
                alt: 'Databasement Logo',
                src: 'img/logo.png',
            },
            items: [
                {
                    href: 'https://databasement-demo.crty.dev/',
                    label: 'Demo',
                    position: 'left',
                },
                {
                    type: 'doc',
                    docId: 'self-hosting/intro',
                    position: 'left',
                    label: 'Self-Hosting',
                },
                {
                    type: 'doc',
                    docId: 'user-guide/intro',
                    position: 'left',
                    label: 'User Guide',
                },
                ...(version ? [{
                    type: 'html' as const,
                    position: 'right' as const,
                    value: `<span class="badge badge--secondary">v${version}</span>`,
                }] : []),
                {
                    href: 'pathname:///llms.txt',
                    label: 'llms.txt',
                    position: 'right',
                    title: 'LLM-friendly documentation index (llmstxt.org)',
                },
                {
                    href: 'https://github.com/David-Crty/databasement',
                    label: 'GitHub',
                    position: 'right',
                },
            ],
        },
        footer: {
            style: 'dark',
            links: [
                {
                    title: 'Documentation',
                    items: [
                        {
                            label: 'Self-Hosting',
                            to: '/self-hosting/intro',
                        },
                        {
                            label: 'User Guide',
                            to: '/user-guide/intro',
                        },
                    ],
                },
                {
                    title: 'More',
                    items: [
                        {
                            label: 'GitHub',
                            href: 'https://github.com/David-Crty/databasement',
                        },
                    ],
                },
            ],
            copyright: `Copyright © ${new Date().getFullYear()} Databasement. Built with Docusaurus.`,
        },
        prism: {
            theme: prismThemes.github,
            darkTheme: prismThemes.dracula,
            additionalLanguages: ['bash', 'yaml', 'docker'],
        },
    } satisfies Preset.ThemeConfig,
};

export default config;
