# Contributing

What this repository asks of a change, and why each gate sits where it does. The governing documents
are `standards/CONTRIBUTING.md` and `standards/DOCUMENTATION.md` in
[`liberusoftware/documentation`](https://github.com/liberusoftware/documentation); this file records
how they land here, and the places where knowing the local history saves you a wasted afternoon.

---

## Before you start

This repository is mid-migration and its `docs/` directory mixes living plans, a frozen snapshot,
decision records and dated research. [`docs/index.md`](docs/index.md) says what each document is and
whether it is current — read it before trusting any of them. The two that will matter most are
[`docs/MIGRATION_PLAN.md`](docs/MIGRATION_PLAN.md), which sequences the structural work, and
[`docs/MODULE_DEVELOPMENT.md`](docs/MODULE_DEVELOPMENT.md), which describes the shape the codebase is
heading towards.

Branch from `main`, keep the change to one outcome, and search for the existing source of truth
before adding a second one. For a change large enough to argue about, open an issue first — the plan
is sequenced, and a change that lands out of order costs more to reconcile than it saves.

---

## The gates

There are three: formatting, static analysis, tests. `composer check` runs them in that order and is
what CI amounts to.

```bash
composer lint      # Pint, --test (check only)
composer lint:fix  # Pint, applying
composer analyse   # PHPStan
composer test      # php artisan test
composer check     # lint, analyse, test
```

### Formatting — Pint

`pint.json` is the Laravel preset and nothing else; there are no overrides to learn. The Lint
workflow checks **only the PHP files your branch touches**, compared against `main`.

That scoping is deliberate and it has a consequence you will meet. Running Pint across the tree would
rewrite most of ~400 files in one commit, bury every real change under formatting noise and make
`git blame` useless on a codebase whose history is the main tool for working out why something is the
way it is. So the gate is a ratchet: files get formatted as they get edited. **A red Pint job is
about your diff, never about the file.** Touching a long-unformatted file means adopting its
formatting in the same commit — that is the point, not an accident.

### Static analysis — PHPStan

`phpstan.neon`, whole tree, no baseline, run in the Lint workflow.

**Read the comment block at the top of `phpstan.neon` before you touch the `level`.** The level is 0
and that is a measured decision, not an oversight: `larastan/larastan` is not installed, so PHPStan
has no idea what Eloquent and the facades are, and on this tree level 2 already produces 909 errors
of which 802 are two identifiers — `property.notFound` and `staticMethod.notFound` — that are
Eloquent working exactly as designed. Baselining ~2000 of those to claim level 8 would bury the real
findings among the magic. The file names the path up (install larastan, add its extension, re-measure,
raise to the highest rung that is honest), and raising the level without doing that will be sent back.

The 26 findings level 0 produced on day one were, almost to a line, real, which is why the count is
now zero. Keep it there.

### Tests

`composer test` runs both suites — 226 test files under `tests/Unit` and `tests/Feature`. Coverage is
uploaded to Codecov by the Tests workflow and gated by `codecov.yml`: 70% on the patch, and overall
project coverage may not regress by more than 0.5%.

Two tests are worth knowing about before they fail on you:

- `tests/Unit/ArchitectureTest.php` reads the shipped code with `token_get_all` and enforces static
  rules on it — most notably that `env()` is only called from `config/`, because under `config:cache`
  every other call site silently resolves to null in production. Two classes shipped that way.
- `tests/Feature/ComposerLockIsCurrentTest.php` fails when `composer.json` and `composer.lock`
  disagree, so a dependency change must commit both.

---

## The five workflows

| Workflow | File | Covers |
| --- | --- | --- |
| Install | `install.yml` | A clean install against MySQL: `composer install`, key generation, `migrate`, `db:seed`, `npm ci`, asset build |
| Lint | `lint.yml` | Pint on branch-changed PHP files; PHPStan on the whole tree |
| Tests | `tests.yml` | `php artisan test` with coverage, uploaded to Codecov |
| Docker | `main.yml` | Builds the image, boots it, smoke-tests it, then pushes — the smoke test exists because two production faults got past a build-only gate |
| Security | `security.yml` | `composer audit --locked` and `npm audit --omit=dev`, on push, on pull request, and weekly |

All five must be green. Lint also accepts `workflow_dispatch`, because this repository drops
`pull_request` synchronize events often enough that a pushed fix can sit with no run at all; if your
branch shows no Lint run, dispatch one rather than pushing an empty commit.

---

## Architecture decisions

One rule decides whether your change needs an ADR, and it is narrower than "significant": a deviation
from the Liberu documentation, or a deliberate loss of behaviour. The rule and the numbering
convention are in [`docs/adr/README.md`](docs/adr/README.md) — read its "Adding one" section rather
than a restatement of it here. A deviation that ships without an ADR is a bug.

---

## Two documents with opposite rules

[`docs/MIGRATION_PLAN.md`](docs/MIGRATION_PLAN.md) is **living**. It is edited as waves land and as
the execution epics discover things the plan got wrong. If your change completes, invalidates or
reorders anything the plan describes, update the plan in the same pull request.

[`docs/CONFORMANCE.md`](docs/CONFORMANCE.md) is a **dated snapshot that is never revised.** Every
number in it counts the tree at commit `2d1024c`. It is superseded finding by finding, not corrected,
and the gap between it and the plan *is* the progress record — so a well-meant edit that brings the
snapshot up to date destroys exactly the information both documents exist to carry.

This is not hypothetical. A rename was once recorded by rewriting a row of the snapshot in place, and
had to be reverted and re-landed in the plan instead (`17dc639`). If you find something in
`CONFORMANCE.md` that is no longer true, that is the document working correctly. Write the correction
into `MIGRATION_PLAN.md`.

The same rule covers `docs/research/` for the same reason: those four documents are dated evidence,
not maintained pages.

---

## There is no changelog, and that is deliberate

`liberusoftware/ecommerce-laravel` has published no release. The five versions on Packagist
(`v1.0.0`–`v1.0.3`, `v13.0.0`) belong to the old `liberu-eccommerce` vendor name, carry a `v` prefix
the release triggers do not fire on, and therefore skipped the release gates — see
[ADR 0005](docs/adr/0005-bare-version-tags.md) and [ADR 0009](docs/adr/0009-vendor-rename-to-liberusoftware.md).
Writing them into a `CHANGELOG.md` would grant them a legitimacy those ADRs specifically deny.

Until there is a gated release to describe, the record of what changed is `git log`: every pull
request is squash-merged with a descriptive subject and its number. Do not add a changelog entry to
your pull request; write the commit subject as though it were one, because it is. When the first
release under this vendor name is tagged, `CHANGELOG.md` starts there and describes releases, which
is what the documentation standard asks it to own.

---

## Documentation

A behaviour change is not finished until the document that owns the behaviour is current in the same
pull request. Define a rule once, in its owning document, and link to it from anywhere else that needs
it. Use sentence-case headings, **must** for requirements and **should** for recommendations, and
relative links between files in this repository.

If you add a document to `docs/`, add it to [`docs/index.md`](docs/index.md) with an honest status.
"Status unaudited" is information; a confident wrong label is not.

Do not claim support, coverage, security or deployment capability without evidence that a reader can
follow to something that runs.

---

## Pull requests

The template at [`.github/pull_request_template.md`](.github/pull_request_template.md) is short and
asks for the two things this repository has repeatedly needed stated. Beyond it: identify any public
contract you changed, and include tests or say why tests do not apply. Respond to review with fresh
commits rather than a force-push, so a reviewer can read what changed since they last looked.

Vulnerabilities do not go in a pull request or a public issue. Follow [`SECURITY.md`](SECURITY.md).
