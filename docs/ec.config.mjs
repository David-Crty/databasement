// Expressive Code options live here rather than in astro.config.mjs: a custom
// theme object does not survive Starlight's config serialization.
import {defineEcConfig} from '@astrojs/starlight/expressive-code';
import codeTheme from './src/code-theme.mjs';

export default defineEcConfig({
    themes: [codeTheme],
    useStarlightUiThemeColors: false,
    styleOverrides: {
        borderRadius: 'var(--radius-md)',
        borderColor: '#262c36',
        codeFontFamily: 'var(--font-mono)',
        uiFontFamily: 'var(--font-mono)',
        codeFontSize: '14px',
    },
    shiki: {langAlias: {env: 'dotenv', cron: 'txt'}},
});
