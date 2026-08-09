# Security policy

## Reporting a vulnerability

Report privately through GitHub, never in a public issue or a pull request:
[**Report a vulnerability**](https://github.com/liberusoftware/ecommerce-laravel/security/advisories/new).
Private vulnerability reporting is enabled on this repository, so the draft advisory is visible only
to you and the maintainers until it is published. No email address or PGP key is needed, and this
project publishes neither.

A useful report says what an attacker can do, not only what looks wrong. Include the affected file or
route, the version or commit you tested, the steps to reproduce, and what an attacker gains — reading
another tenant's data, escalating a role, forging a webhook, and so on. A proof of concept against
your own local install is worth more than a description.

## What to expect

This is a volunteer-maintained open-source project. There is no response-time commitment, no bug
bounty and no published support policy, and rather than invent one: reports are read, and you will be
told whether the finding is accepted, already known, or out of scope. If it is accepted you will be
credited in the advisory unless you ask not to be.

Please give maintainers a reasonable chance to ship a fix before disclosing publicly.

## Scope

In scope is anything in this repository that ships and runs: `app/`, `routes/`, `database/`,
`bootstrap/`, `config/`, the Blade and Livewire views under `resources/`, the Filament panels, the
`Dockerfile` and `.docker/`, and the GitHub Actions workflows.

Out of scope:

- Third-party dependencies. Report those to their own maintainers; `security.yml` already runs
  `composer audit` and `npm audit` on every push and weekly.
- Findings that only apply with `APP_DEBUG=true`, default `.env.example` credentials, or a
  deliberately misconfigured install. `.env.example` and `.env.testing` hold development values by
  design and are not secrets.
- Anything reachable only by a user who already has administrator rights in the Filament admin panel.
- Automated scanner output with no demonstrated impact.

## Known findings

This repository is pre-production and mid-migration, and it documents its own open security gaps
rather than implying it has none. [`docs/CONFORMANCE.md` §6](docs/CONFORMANCE.md#6-security-findings)
records the tenancy findings measured at commit `2d1024c`, and
[`docs/MIGRATION_PLAN.md`](docs/MIGRATION_PLAN.md) sequences the fixes. Please check both before
reporting — a finding already recorded there is known, and a report that it is still present is only
useful if it shows the impact is worse than recorded.

## Supported versions

No release under the `liberusoftware` vendor name has been published yet, so there is no supported
release line. Fixes land on `main`. The five versions still on Packagist under the former
`liberu-eccommerce` name are unsupported and should not be used — see
[ADR 0009](docs/adr/0009-vendor-rename-to-liberusoftware.md).
