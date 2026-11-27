# AGENTS.md

Guidance for coding agents working in this repository. Humans can read it too; the
longer versions live in [README.md](README.md), [DEVELOPMENT.md](DEVELOPMENT.md) and
[CONTRIBUTING.md](CONTRIBUTING.md). Styling conventions for the frontend are in
[CLAUDE.md](CLAUDE.md).

## What this app is

Pipelinq is a CRM for Nextcloud: client and organisation management, lead pipelines
on kanban boards, request intake before a request becomes a formal case, and a log of
every interaction. It is a thin client on
[OpenRegister](https://github.com/ConductionNL/openregister), which stores its records
as OpenRegister objects.

## Setup

```bash
composer install     # also points git at .githooks (post-install-cmd)
npm install          # also points git at .githooks (prepare)
```

Run it locally with Docker in one of two ways, both described in the README:

- Evaluation stack from this repo: `docker compose -f docker/docker-compose.yml up -d --build`,
  then open http://localhost:8080/apps/pipelinq/ (user `admin`, password `admin`).
  `docker compose -f docker/docker-compose.yml down -v` removes it.
- Shared development environment: the app is volume-mounted into the
  OpenRegister dev stack, see [DEVELOPMENT.md](DEVELOPMENT.md).

## Build

```bash
npm run build        # production frontend build (webpack)
npm run dev          # development build
npm run watch        # development build, rebuilds on change
```

## Test

```bash
composer test:unit       # PHPUnit, tests/Unit (phpunit.xml)
npm run test:unit        # Vitest frontend unit tests
npm run test:e2e         # Playwright, tests/e2e (needs a running Nextcloud)
npm run test:e2e:install # one-time Chromium download for Playwright
```

Playwright has no default target, on purpose. Set `PLAYWRIGHT_BASE_URL` to the
instance the suite should run against, for example
`PLAYWRIGHT_BASE_URL=http://localhost:8095 npm run test:e2e`; see
`tests/e2e/base-url.ts`.

## Lint and quality

```bash
composer check:strict   # lint, phpcs, phpmd, psalm, phpstan, tests
composer check          # quicker: lint, phpcs, psalm, unit tests
composer cs:fix         # php-cs-fixer
composer phpcs:fix      # phpcbf
npm run lint            # ESLint
npm run stylelint       # Stylelint
npm run format          # Prettier check
npm run test:l10n       # every t()/n() string has a catalogue entry
npm run check:manifest  # validate src/manifest.json
```

`composer check:strict` is the PHP gate CI enforces.

## OpenSpec flow

Work is specified before it is built, with [OpenSpec](openspec/config.yaml):

1. A change starts in `openspec/changes/<change-name>/` with a `proposal.md`,
   `tasks.md`, delta specs under `specs/` and, when it makes a design decision,
   a `design.md`.
2. When it is built, its requirements are merged into `openspec/specs/<capability>/`.
3. The finished change moves to `openspec/changes/archive/`.

## Architecture decisions

`openspec/architecture/` holds this repo's numbered architecture records
(`adr-000` to `adr-003`).

## Conventions

- **Code is generic and named by what it does.** Proposals in this repo build
  features as generic code (for example `ReportMailDelivery`, usable by any export
  job, in `openspec/changes/archive/2026-11-26-reports-compare-periods-and-scheduled-delivery/proposal.md`).
  `.procedure-code-baseline.json` pins the count of procedure-named files; a growing
  count fails the quality gate, so put procedure specifics in configuration.
- **Pages are manifest config.** The app shell renders from `src/manifest.json`. New
  pages and menu entries go in a fragment under `src/manifest.d/`, which `src/main.js`
  merges on top of the base.
- **Data lives in OpenRegister.** The register and its schemas ship as JSON in
  `lib/Settings/pipelinq_register.json`.
- **Every user-visible string is translated** with `t('pipelinq', ...)` and checked by
  `npm run test:l10n`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/): `feat:`, `fix:`,
`docs:`, `refactor:`, `chore:`. Present tense, imperative mood, first line at most 72
characters. Pull requests target `development`.
