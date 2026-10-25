declare module 'virtual:docs-context' {
    const context: {
        base: string;
        siteBase: string;
        isLatest: boolean;
        minor: string;
        versions: string[];
        release: string;
        analyticsSnippet: string;
    };
    export default context;
}
