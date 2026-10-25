import type {APIRoute} from 'astro';
import {getCollection} from 'astro:content';

/** Keeps the /sitemap.xml URL the Docusaurus site published (Starlight writes sitemap-index.xml). */
export const GET: APIRoute = async ({site}) => {
    const base = new URL(import.meta.env.BASE_URL, site);
    const pages = (await getCollection('docs')).map((entry) => new URL(entry.id === 'index' ? '' : `${entry.id}/`, base));
    const urls = [base, ...pages].map((url) => `  <url><loc>${url.href}</loc></url>`).join('\n');
    return new Response(
        `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`,
        {headers: {'Content-Type': 'application/xml'}},
    );
};
