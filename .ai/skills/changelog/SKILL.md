---
name: changelog
description: Write the CHANGELOG.md entries for a release from git history. Reads the commits since the last tag, turns them into Keep a Changelog entries filed under their minor version, commits and pushes. Takes the version being released, `x.y.z`. Run by `make release`; run it by hand to review the entries before tagging.
argument-hint: "x.y.z"
disable-model-invocation: true
context: fork
agent: general-purpose
background: false
allowed-tools: Bash(git *), Bash(gh *), Bash(date *), Read, Edit, Write
---

# Changelog

You maintain `CHANGELOG.md` at the repository root: the commits since the last
tag go in as entries an operator understands, filed under the minor version of
the release being cut. Nothing else in the repository may change.

## Context

- Version being released: `$ARGUMENTS` (required, `x.y.z`)
- Branch: !`git branch --show-current`
- Last tag: !`git describe --tags --abbrev=0 --match 'v*'`
- Working tree (empty means clean): !`git status --porcelain`
- Today: !`date +%Y-%m-%d`
- Commits since the last tag (empty means there is nothing to release):

!`git log HEAD --not --tags --no-merges --format='%h %s%n%b%n---'`

If `$ARGUMENTS` is empty or is not an `x.y.z` version, stop and say
`Usage: /changelog x.y.z`. There is no other mode.

## File format

**One section per minor version, not per patch.** Every entry is prefixed with
the patch release that shipped it, in backticks. Entries are grouped by
section, newest patch first inside each:

```markdown
## [1.7] - 2026-09-05

### Added

- `1.7.9` Snapshots can carry a free-text comment ([#522](https://github.com/David-Crty/databasement/pull/522))
- `1.7.0` A backup configuration can target several storage volumes ([#478](https://github.com/David-Crty/databasement/pull/478))

### Fixed

- `1.7.10` A form that fails validation scrolls the first invalid field into view ([#598](https://github.com/David-Crty/databasement/pull/598))
```

The heading date is the date of the newest patch in that minor. There is no
`## [Unreleased]` section: the file only ever describes released versions.

Link references sit at the end of the file, one per minor spanning the previous
minor's newest tag to this minor's newest tag:

```markdown
[1.7]: https://github.com/David-Crty/databasement/compare/v1.6.12...v1.7.10
[1.6]: https://github.com/David-Crty/databasement/compare/v1.5.6...v1.6.12
```

The app renders this file as Markdown at `/changelog` and
`docs/scripts/sync-changelog.js` publishes it as a documentation page, so keep
the shape above: `## [minor] - date` headings, `### Section` groups,
`` - `x.y.z` entry `` bullets, and the link references (which turn each heading
into a link to the compare view).

## Editorial rules

- **Audience**: people who run Databasement. Say what changed for them, not what changed in the code. Lead with the visible effect.
- **One bullet per PR**. GitHub appends `(#NNN)` to the squash-merge subject; turn it into `([#NNN](https://github.com/David-Crty/databasement/pull/NNN))` at the end of the bullet. When several PRs ship one feature, merge them into one bullet and keep every link.
- **Drop** refactors, CI, tests, docs-only, tooling and dependency bumps unless an operator would notice. A dependency bump that fixes a CVE goes under Security with the CVE id.
- **Sections** in this order, empty ones omitted: Added, Changed, Deprecated, Removed, Fixed, Security. PR titles are conventional commits, so `feat:` starts in Added, `fix:` in Fixed, `fix(security):` in Security, `perf:`/`refactor:` in Changed, and a description starting with "remove"/"drop" or "deprecate" goes to Removed/Deprecated. The type is a hint, not a verdict: reclassify freely (a PR titled "Scope … to the current organization" is Security, "Allow choosing …" is Added).
- **Style**: one bullet per entry, sentence case, no trailing period. The lead sentence is the visible effect; at most one or two more sentences for its limits or any action the operator must take. Describe the result ("Snapshots can carry a comment", "The Redis password is masked in logged commands"). Keep the terms Backup, Restore, Snapshot as they are. No em dashes. Backticks for env vars, flags and file names.
- **Breaking changes** (`feat!:`, `fix!:`, a `BREAKING CHANGE:` footer) start with `**Breaking:**` after the patch prefix.
- The commit bodies above explain the why, the subjects only the what; read both. `gh pr view <number>` gives more context when a subject is opaque.
- Never rewrite entries of already-released patches. Never touch any file other than `CHANGELOG.md`. Never tag, never run `make release`.

## Steps

Stop with a clear message if any precondition fails:

- branch is `main`, the working tree is clean, and after `git fetch origin main` HEAD equals `origin/main`;
- `git rev-parse -q --verify refs/tags/vx.y.z` finds nothing;
- `x.y.z` is greater than the last tag.

Then:

1. Turn the commits since the last tag into polished entries, following the editorial rules.
2. Insert each entry into the `## [x.y]` section, prefixed with `` `x.y.z` ``, at the **top** of its `### Section` (newest patch first). Create the `### Section` in Keep a Changelog order if the minor has none yet.
   - If `## [x.y]` does not exist (this release opens a new minor), insert a whole new section directly below the file header.
   - If nothing since the last tag is worth an entry, add a single `` - `x.y.z` Maintenance release with no application changes `` under Changed.
3. Set the `## [x.y]` heading date to today.
4. Update the link references: `[x.y]: …/compare/v<newest tag of the previous minor>...vx.y.z`. A new minor adds its own line above the previous one; an existing minor only has its end of the range bumped to `vx.y.z`.
5. `git add CHANGELOG.md` and `git commit --no-verify -m "Record the vx.y.z changelog"`. The hook is skipped on purpose: the preconditions guarantee `main` is clean and already passed it, and its checks (Pint, PHPStan, tests) cover nothing in `CHANGELOG.md`. Check with `git show --stat HEAD` that the commit touches only that file. No attribution lines or trailers.
6. `git push origin main`.
7. Report the entries. If you were invoked by `make release`, it tags right after you exit; when run by hand, the next step is `make release VERSION=x.y.z`.
