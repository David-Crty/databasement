/**
 * Expressive Code theme from the design system's code-* and syntax-* tokens.
 * One dark theme serves both site themes: a command always looks like a terminal.
 */
const color = {
    bg: '#0d1117',
    bar: '#161b22',
    line: '#262c36',
    ink: '#e6edf3',
    muted: '#8b949e',
    keyword: '#c4b5fd',
    string: '#86efac',
    number: '#fcd34d',
    variable: '#67e8f9',
};

export default {
    name: 'databasement',
    type: 'dark',
    colors: {
        'editor.background': color.bg,
        'editor.foreground': color.ink,
        'editorGroupHeader.tabsBackground': color.bar,
        'editorGroupHeader.tabsBorder': color.line,
        'tab.activeBackground': color.bar,
        'tab.activeForeground': color.ink,
        'tab.activeBorderTop': color.variable,
        'tab.inactiveForeground': color.muted,
        'titleBar.activeBackground': color.bar,
        'titleBar.activeForeground': color.muted,
        'titleBar.border': color.line,
        'editor.selectionBackground': '#22d3ee33',
        'editor.lineHighlightBackground': '#22d3ee17',
        'editor.lineHighlightBorder': color.variable,
    },
    tokenColors: [
        {scope: ['comment', 'punctuation.definition.comment'], settings: {foreground: color.muted, fontStyle: 'italic'}},
        {
            scope: ['keyword', 'storage', 'entity.name.tag', 'entity.name.function', 'support.function', 'entity.name.command', 'support.type.property-name', 'entity.other.attribute-name'],
            settings: {foreground: color.keyword},
        },
        {scope: ['string', 'markup.inline.raw'], settings: {foreground: color.string}},
        {scope: ['constant.numeric', 'constant.language', 'constant.other.option', 'variable.parameter.option'], settings: {foreground: color.number}},
        {scope: ['variable', 'support.variable', 'variable.other', 'entity.name.type', 'support.type'], settings: {foreground: color.variable}},
        {scope: ['punctuation', 'meta.brace', 'keyword.operator'], settings: {foreground: color.ink}},
    ],
};
