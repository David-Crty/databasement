import type {APIRoute, GetStaticPaths} from 'astro';
import {getCollection, type CollectionEntry} from 'astro:content';

/**
 * Serves every page's Markdown source at `<page>.md` (e.g. /user-guide/api.md),
 * the per-page files the Docusaurus llms plugin used to publish.
 */
export const getStaticPaths = (async () => (await getCollection('docs'))
    .filter((entry) => entry.id !== 'index')
    .map((entry) => ({params: {slug: entry.id}, props: {entry}}))) satisfies GetStaticPaths;

export const GET: APIRoute<{entry: CollectionEntry<'docs'>}> = ({props: {entry}}) => {
    const body = (entry.body ?? '').replace(/^import .*\n+/gm, '');
    const description = entry.data.description ? `\n> ${entry.data.description}\n` : '';
    return new Response(`# ${entry.data.title}\n${description}\n${body.trimStart()}`, {
        headers: {'Content-Type': 'text/markdown; charset=utf-8'},
    });
};
