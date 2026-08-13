# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

See [AGENTS.md](./AGENTS.md) — it is the source of truth for agent configuration in this repo.

## The wave workflow

One architecture epic at a time, built as four packages, recorded, closed. Run it in this order.

1. **Pick the epic.** The `Architecture: Ecommerce — <module>` epics are sequenced by
   [`docs/MIGRATION_PLAN.md` §1](./docs/MIGRATION_PLAN.md) — tier order, then most-code-first
   within a tier. Read that rule before choosing; `gh issue list` returns GitHub's order, not
   the plan's.
2. **Claim it.** Assign the issue and move it to **In Progress** on project board 10 — see
   [`docs/agents/issue-tracker.md`](./docs/agents/issue-tracker.md#project-board-10). Do this
   before any building, so the board reflects work in flight rather than work finished.
3. **Survey the host from primary sources.** Read the migrations, models and services the
   module replaces. Name each fault from the code, not from the issue text — the issue
   describes the capability, not what is wrong here.
4. **Write the wave addendum**, at
   `~/.claude/projects/-home-tom-code-ecommerce-laravel/briefs/wave<N>-addendum.md`. It carries
   the boundary statement, the host's faults, the one fact that shapes the module, and the
   decisions agents must implement rather than rediscover. Briefs live outside `/tmp`, which is
   not durable here.
5. **Dispatch the domain agent**, pointing it at `module-build-brief.md`, the wave addendum and
   `presentation-brief.md`, with the instruction to STOP if any is missing.
6. **Verify green independently** against the GitHub API before believing the agent — see
   "Verifying a package" below.
7. **Dispatch the three presentation agents** (`-api`, `-filament`, `-livewire`) in parallel once
   the domain package is tagged. Verify each as it returns, then bulk-verify all four.
8. **Record the wave** in `docs/MIGRATION_PLAN.md` on a `docs/wave-<N>-shipped` branch, open a
   PR, merge it, pull `main`.
9. **Close the epic**: comment the shipped summary, `gh issue close --reason completed`, and move
   the board item to **Done**.

### Verifying a package

Never report or close on an agent's own account of its results. Per package:

```bash
R=module-ecommerce-<module>
gh run list --repo liberusoftware/$R --limit 12 --json workflowName,headBranch,conclusion \
  --jq '[.[]|select(.conclusion!=null)]|group_by(.workflowName)|map(.[0]|"\(.workflowName)@\(.headBranch)=\(.conclusion)")|join(" ")'
gh api repos/liberusoftware/$R/tags --jq '[.[].name]|join(" ")'
gh api repos/liberusoftware/$R/commits --jq '.[].commit.message' \
  | grep -iE 'claude\.ai|Claude-Session|session_0' && echo VIOLATION || echo "trailer clean"
```

Green means **Tests@main**, **Install@0.1.0** and **Compatibility@0.1.0** all `success`, and the
`0.1.0` tag exists.

## Standing constraints

- **No session identifiers, anywhere.** No `Claude-Session:` trailer, no `claude.ai` URL, no
  session id in a commit message, PR body, issue comment, tag message or file. Pass this to every
  dispatched agent as an absolute rule and grep for it after every package.
- **Modules live in their own GitHub repositories** under `liberusoftware`, never in a `modules/`
  directory in this host.
- **Dispatched agents never commit to this repository.** They clone their own module repo into
  `~/work/wave<N>/` — not `/tmp`, which is not durable here and which concurrent agents contend
  over.
- **Pre-production: migrations are edited in place.** No corrective migrations.
- **Composer cannot run locally.** PHP's libcurl is built against c-ares, which ignores the
  `options use-vc` that makes `curl`, `git` and `gh` work over TCP. There is no `vendor/` here and
  **GitHub Actions is the only test runner**. Require packages via
  `.github/workflows/composer-require.yml`.
- **Pint runs locally** via the release phar, with the fleet config — not the stock `laravel`
  preset. See `module-build-brief.md` §6.
