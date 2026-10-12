---
name: release-issues
description: After a release is tagged, comment on every GitHub issue closed by the PRs it shipped, pinging the people who reported it, so they know the fix is out and what to do to get it. Takes the released version, `x.y.z`. Run by `make release` after the tag is pushed; run it by hand to retry.
argument-hint: "x.y.z"
disable-model-invocation: true
context: fork
agent: general-purpose
background: false
allowed-tools: Bash(git *), Bash(gh *), Read
---

# Release issues

A release just went out. Tell the people who reported each issue it resolves,
on the issue itself, that the fix is available and what they need to do. Read
and comment only: never edit, close, reopen or label an issue, and never touch
the repository.

## Context

- Version released: `$ARGUMENTS` (required, `x.y.z`)
- Repository: !`gh repo view --json nameWithOwner --jq .nameWithOwner`
- Maintainer (never ping): !`gh api user --jq .login`

If `$ARGUMENTS` is empty or is not an `x.y.z` version, stop and say
`Usage: /release-issues x.y.z`. If `git rev-parse -q --verify refs/tags/vx.y.z`
finds nothing, stop: the release is not tagged yet.

## Steps

1. **Range.** `git fetch --tags --quiet`, then the previous release is
   `git describe --tags --abbrev=0 --match 'v*' vx.y.z^`. The release's commits
   are `git log <previous>..vx.y.z --no-merges --format='%h %s%n%b%n---'`.

2. **Linked issues.** Collect, deduplicated:
   - for every `(#NNN)` PR suffix in a subject, the issues that PR closes:
     `gh pr view NNN --json title,body,closingIssuesReferences`;
   - issues a commit body closes itself (`Fixes #N`, `Closes #N`,
     `Resolves #N`, any tense or case).

   An issue only *mentioned* (`see #N`, `(#N)` in prose) is not linked: skip
   it. Skip every PR titled `fix(security):` and the issues it closes: security
   fixes are disclosed through their advisory, not here. If nothing is linked,
   report that and stop.

3. **Per issue**, read it with
   `gh issue view N --json number,title,state,author,body,comments`, then:
   - **Skip it** if a comment already links `releases/tag/vx.y.z` (this
     release was already announced there, so a rerun posts nothing twice).
   - **Who to ping**: the author, plus every commenter who says they hit the
     same problem or asked for the same thing. Never the maintainer, never a
     bot (`[bot]` suffix, `coderabbitai`, `github-actions`). If nobody is left,
     skip the issue.
   - **What shipped**: read the closing PR's body (it explains the why and the
     trade-offs) and the `vx.y.z` entries in `CHANGELOG.md`. Compare against
     what the reporter actually asked for or described, and say plainly if part
     of it was solved differently or not at all.

4. **Write the comment** in the reporter's terms, not the code's:
   - First line: the pings, then `fixed in` / `shipped in`
     `[vx.y.z](https://github.com/<repo>/releases/tag/vx.y.z) (#PR)`, using
     "fixed" for a bug and "shipped" for a feature.
   - Two to four bullets on what changed *for them*: the visible behaviour,
     and any limit (what still works the old way, which engines are covered).
   - **What they must do**, only when there is something: drop a flag that is
     now redundant, change a setting, run a command. Map it to their setup
     from the issue (their engine, their dump flags, Docker vs. Kubernetes vs.
     native install). Backups, restores and discovery run on the remote agent
     when one is configured, so when the change is in that code path, or the
     reporter uses an agent, say the agent must be updated to `x.y.z` as well.
   - When another PR in the same release also helps their case (the root cause
     they described, a symptom they mentioned), link it in one sentence.
   - Close with one short line of thanks, specific to the report when it
     earned it (a reproduction, a proposed design that was followed).
   - Style: concise, no headings, no em dashes (use commas, parentheses or
     separate sentences), backticks for flags, env vars and commands, keep
     the terms Backup, Restore, Snapshot. Never claim something was tested or
     behaves in a way the PR does not state.

5. **Post** each through a quoted heredoc, so apostrophes and backticks pass
   through untouched:

   ```bash
   gh issue comment N --body-file - <<'EOF'
   <comment>
   EOF
   ```

6. **Report** one line per issue: the comment URL, or why it was skipped.
