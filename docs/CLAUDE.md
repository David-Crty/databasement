# Documentation site (Astro + Starlight)

GitHub Pages site at `https://david-crty.github.io/databasement/`, deployed by `.github/workflows/docs.yml` on every `v*` tag push (never from `main` merges). The root is a marketing landing page (`src/landing/index.astro`); the docs are Starlight pages built from plain Markdown in `src/content/docs/`.

## Commands

```bash
npm run dev                         # Dev server: working-tree docs, latest version only
npm run build                       # Single-version build into dist/: fails on a broken page link
npm run build:versions              # The published site: every 1.x minor from its tag, into dist/
npm run check:urls                  # After build:versions: no live URL or anchor disappears, no broken link
```

Run `npm run build` after editing docs. To test the published site, run `build:versions` then `check:urls` (needs `git fetch origin gh-pages`).

## Versioning (important)

Every 1.x minor is a **separate Astro build** of the current project, with the content of that minor's latest patch tag swapped in (`scripts/build-versions.sh`, in a scratch copy under `.build/`). The newest minor is served at the root with the landing page and llms.txt; older ones under `/<minor>/`, each with its own Pagefind index, so search only returns the version being read. Nothing versioned is committed.

`astro.config.mjs` reads `DOCS_MINOR` / `DOCS_VERSIONS` (set by the script) to pick the `base`; with neither set it builds the latest. Versions share one `/_astro/` folder (`build.assetsPrefix`), merged by the script.

Tags cut before the move to Starlight hold Docusaurus content under `docs/docs/`. `scripts/normalize-docusaurus.mjs` rewrites each snapshot on the way in (H1 to `title`, `sidebar_position` to `sidebar.order`, `:::info`/`:::warning` to `:::note`/`:::caution`, Tabs to `.mdx`). To change how an already-published version renders, fix it there or in the script; the working tree is the *next* version and goes live with the next tag.

## URLs never break

`scripts/check-urls.mjs` runs in CI before every deploy: every path and heading anchor the live `gh-pages` branch serves must still exist in the new build (the README, the Helm chart and the app link to pages and anchors such as `#supported-versions`). To rename or move a page, add the old path to `redirects` in `astro.config.mjs` so it keeps resolving; do not remove the check.

## Changelog page (generated)

`src/content/docs/changelog.md` is **generated** by `scripts/sync-changelog.js` from the repository's root `CHANGELOG.md`; it is gitignored, so **never edit it and never commit it** (edit the root `CHANGELOG.md`, which the `/changelog` skill maintains). `npm run build` / `npm run dev` write it via the `prebuild` / `predev` hooks, and `build-versions.sh` writes the same, latest file into every version. The script adds `{#v1-7}` heading ids, so other pages can link to `../changelog.md#v1-7`.

## Search and llms.txt

- Pagefind (Starlight's built-in search), tuned in `astro.config.mjs` (`pagefind.ranking`) so the long changelog does not outrank the page documenting a feature.
- `starlight-llms-txt` writes `llms.txt`, `llms-full.txt`, `llms-small.txt` and one set per section (latest version only). `src/routes/page.md.ts` serves each page's Markdown at `<page>.md`.

## Links & assets

- Internal links: **relative file paths** — `./backups.md`, `../self-hosting/configuration/backup.md#anchor`. `src/plugins/docs-links.mjs` resolves them against the page's file, fails the build on a missing page, and turns them into URLs within the version being read. Never write `/databasement/...` URLs by hand.
- Images/static files: absolute `/img/...` (served from `public/`); the plugin adds the version's base.
- Links to site-root files outside any version (llms.txt): `pathname:///llms.txt`.
- Custom heading ids: `## Webhook {#webhook}` (Sätteri heading attributes). In `.mdx`, escape the braces: `\{#webhook\}`.

## Structure & style

- Four sidebar topics (`starlight-sidebar-topics`, configured in `astro.config.mjs`): `self-hosting/`, `user-guide/`, `contributing/`, and the changelog. Each autogenerates from its directory; group labels are the directory name capitalized (`src/routeData.ts`).
- Frontmatter: `title` (required, rendered as the page H1, so the body has no `# H1`) and `sidebar.order`. Orders are in thousands (`3000`), with a subdirectory's pages at `<its position>001`, `002`, ...: Starlight places a directory by the lowest order among its pages.
- Task-oriented `## sections`; short paragraphs.
- UI element names in **bold** (`**Configuration > Organizations**`), env vars and values in `` `code` ``, env/shell examples in fenced ```bash blocks with the language tag.
- Asides (`:::note`, `:::tip`, `:::caution`, `:::danger`, with an optional `[Title]`) sparingly, one per section at most; `:::caution` only for data-loss/lockout risks.
- Tabs: rename the page to `.mdx` and import `Tabs`, `TabItem` from `@astrojs/starlight/components`.
- Keep the technical terms **Backup**, **Restore**, **Snapshot** as-is (project-wide convention).

## Look and feel

The look comes from the Databasement design system (claude.ai artifact `1ygXaNtssC2gtbuWS6UPcP`): dark ops-console surfaces, one cyan accent, Instrument Sans + JetBrains Mono, code that stays dark in both themes. Keep it light-touch: Starlight's own layout and components, restyled with CSS.

- `src/styles/tokens.css`: the design tokens and `@font-face` (fonts in `src/fonts/`), shared by the landing page and the docs. Dark is the base, `[data-theme="light"]` the override; Starlight's theme picker and the landing toggle share the `starlight-theme` localStorage key.
- `src/styles/docs.css`: maps the tokens onto Starlight's `--sl-*` variables and restyles header, sidebar, prose, asides and pager. Starlight's styles are in cascade layers, so plain unlayered rules win.
- `ec.config.mjs` + `src/code-theme.mjs`: the code block theme (a custom theme object must live in `ec.config.mjs`, not `astro.config.mjs`, or the page and its stylesheet get different hashes).
- `src/landing/index.astro`: the marketing page, standalone (no Starlight), with its styles inline. Its ids `features`, `quick-start`, `documentation-sections`, `self-hosting`, `user-guide`, `contributing` and `quick-links` are published anchors.
- Icons are Heroicons v2 outline SVGs in `src/icons/` (copy more from the `heroicons` package, `24/outline/`), inlined with `src/components/Icon.astro`. The logo mark is `src/assets/mark.svg` (from the app's `logo-icon.blade.php`), engine logos are in `src/assets/engines/` (devicon).
- Starlight component overrides are in `src/components/starlight/` (analytics `<head>`, announcement banner, header links with the version picker). The site-wide announcement is `src/announcement.mjs`, rendered by `src/components/Announcement.astro` on the landing and every docs page; a `danger` (security) notice cannot be dismissed.

## When adding a page

1. Create the `.md` under the right section with `title` and `sidebar.order`.
2. Link it from related pages (relative `.md` links) and run `npm run build`.

## Deployment notes

- `gh-pages` also hosts the Helm repo: `charts/`, `index.yaml`, `artifacthub-repo.yml` must survive deploys (handled via `clean-exclude` in the workflow — keep it). `public/.nojekyll` must stay too, or GitHub Pages hides `_astro/`.
- The workflow runs from the tagged commit, so workflow/script changes only take effect for tags cut after they land on `main`.
