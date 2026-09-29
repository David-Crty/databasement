---
name: worktree-development
description: >
  Use whenever the cwd is a git worktree of this repo (under `.claude/worktrees/`),
  before running any make, docker compose, artisan, Pest, PHPStan or Pint command
  or committing there. A bare `docker compose` in a worktree addresses a second,
  empty Compose project ("service app is not running"), and a fresh worktree has no
  vendor/, node_modules/ or `_ide_helper_models.php`. Also for setting up, branching,
  or reviewing/reworking a PR in a worktree. Not for the main checkout.
---

# Working in a worktree

The stack runs as one Compose project started from the **main checkout**, which
bind-mounts the whole repo at `/app`. A worktree under
`.claude/worktrees/<name>/` is therefore already visible inside the container
at `/app/.claude/worktrees/<name>/`. Nothing needs to be mounted, rebuilt or
restarted.

Worktrees must live inside the repo. The Makefile maps the worktree to
`/app/<path relative to the repo>`, so one created elsewhere
(`git worktree add ../x`) points at a path the container does not have.

## Always go through make

`make test`, `make phpstan`, `make lint-fix` and the rest work unchanged from a
worktree: the `COMPOSE_ROOT` block at the top of the Makefile finds the Compose
project through the shared git dir and runs in the worktree's container path.

Never run a bare `docker compose` there. `docker-compose.yml` is tracked, so the
worktree has its own copy, and Compose silently addresses a **different
project** named after the worktree directory, with no containers. Hence
`service "app" is not running` about a container that is running fine. Starting
that second project is no fix either: its host ports are already taken.

If the Makefile has no `COMPOSE_ROOT` block, the branch predates worktree
support. Merge `origin/main` first.

`make test` and the pre-commit hook can take a few minutes. Give the Bash call a
long timeout (up to 600000 ms) or run it in the background.

## Setting up a fresh worktree

1. `make install`. `vendor/` is gitignored and absent, so Pest, PHPStan and
   Pint have nothing to run. It takes a few minutes.
2. `make ide-helper`. `_ide_helper_models.php` is gitignored and listed in
   PHPStan's `scanFiles`, so a standalone `make phpstan` dies with `Scanned file
   .../_ide_helper_models.php does not exist` until it exists.

Do **not** symlink `vendor` to the main checkout's. Composer's autoloader
resolves `__DIR__` through the symlink to `/app/vendor`, so `app/` classes load
from the **main branch's** copies and the tests run against code you did not
write. The symptom is a missing enum case or method that is plainly there in
the file you just edited.

`public/build/` is absent. Asset builds and anything reading the Vite manifest
must run from the main checkout. Tests are unaffected (the suite calls
`withoutVite()`), but a Tailwind class you introduce is not in the built CSS
yet, which matters when eyeballing a page.

`make update-translation` works from a worktree: it reads the API key from the
main checkout's `.env.local`. Run it whenever you add a `__()` string, or
`make check-translation` fails.

## Branching

A worktree may start from whatever HEAD the main checkout had. For a PR, base it
explicitly:

```bash
git fetch origin
git checkout --no-track -B <branch> origin/main
```

`--no-track` keeps the branch from tracking `origin/main`; the first
`git push -u origin <branch>` sets its own upstream.

## Committing

The Husky pre-commit hook shells out to the Makefile (Pint, ide-helper,
PHPStan, the full suite), so it passes from a worktree once vendor is
installed. If it fails, fix the cause; never commit with `--no-verify`.

## Reviewing someone else's PR here

```bash
git fetch origin refs/pull/<n>/head:refs/remotes/origin/pr/<n>
git reset --hard origin/pr/<n>
git merge --no-edit origin/main
```

`origin` is the upstream repo, so a pull request from a fork is reachable this
way but not pushable. Pushing back to a contributor's fork branch needs their
remote and is an outward-facing action: confirm with the user before doing it.

The stash stack is shared with the main checkout and every other worktree.
Prefer a throwaway WIP commit over `git stash`.

## Worktree-isolated sessions

When the session itself runs with worktree isolation, Claude Code refuses shell
commands whose target it cannot prove stays inside the worktree: chained
commands (`cd … && …`), `$(...)`, heredocs and `sh -c '...'` are rejected as
"too complex to verify". In that mode:

- One plain command per Bash call; for git, `git -C <worktree> <subcommand>`.
- Edit files with Write/Edit, not `sed -i` or a heredoc.
- Commit and PR bodies go in a scratchpad file: `git -C <worktree> commit -F
  <file>`, `gh pr create --body-file <file>`.
- Anything that needs a pipeline goes in a script file in the scratchpad.
