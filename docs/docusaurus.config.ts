import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

const config: Config = {
    title: 'DBBackup',
    tagline: 'Simple and powerful database backup management',
    favicon: 'img/favicon.ico',

    plugins: [
        require.resolve('docusaurus-lunr-search'),
    ],

    url: 'https://davidcrty.github.io',
    baseUrl: '/dbbackup/',

    organizationName: 'davidcrty',
    projectName: 'dbbackup',

    onBrokenLinks: 'throw',
    onBrokenMarkdownLinks: 'warn',

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
                    editUrl: 'https://github.com/davidcrty/dbbackup/tree/main/docs/',
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
            title: 'DBBackup',
            items: [
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
                {
                    href: 'https://github.com/davidcrty/dbbackup',
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
                            href: 'https://github.com/davidcrty/dbbackup',
                        },
                    ],
                },
            ],
            copyright: `Copyright © ${new Date().getFullYear()} DBBackup. Built with Docusaurus.`,
        },
        prism: {
            theme: prismThemes.github,
            darkTheme: prismThemes.dracula,
            additionalLanguages: ['bash', 'yaml', 'docker'],
        },
    } satisfies Preset.ThemeConfig,
};

export default config;
