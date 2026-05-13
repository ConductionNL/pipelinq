# Pipelinq — Developer Guide

## Branching Strategy

This project follows a strict promotion-based branching model. Code flows upward through environments, and each branch has rules about which source branches it accepts.

```
feature/* → development → beta → main
hotfix/*  → (any branch)
```

### Branch Roles

| Branch | Purpose | Accepts PRs from |
|--------|---------|-----------------|
| `main` | Production-ready releases | `beta`, `hotfix/*` |
| `beta` | Pre-release testing and stabilisation | `development`, `hotfix/*` |
| `development` | Integration of completed features | `feature/*`, `hotfix/*` |
| `feature/*` | Individual feature work | Created from `development` |
| `hotfix/*` | Urgent production fixes | Created from `main`, merged into any branch |

### Flow Diagram

```mermaid
gitGraph
    commit id: "v1.0.0"
    branch beta order: 1
    commit id: "beta-start"
    branch development order: 2
    commit id: "dev-start"
    branch feature/my-feature order: 3
    commit id: "implement"
    commit id: "tests"
    checkout development
    merge feature/my-feature id: "PR: feature → dev"
    branch feature/another order: 4
    commit id: "work"
    checkout development
    merge feature/another id: "PR: feature → dev" tag: "ready"
    checkout beta
    merge development id: "PR: dev → beta"
    checkout main
    merge beta id: "PR: beta → main" tag: "v1.1.0"
    branch hotfix/critical-fix order: 5
    commit id: "fix"
    checkout main
    merge hotfix/critical-fix id: "PR: hotfix → main" tag: "v1.1.1"
    checkout beta
    merge hotfix/critical-fix id: "PR: hotfix → beta"
    checkout development
    merge hotfix/critical-fix id: "PR: hotfix → dev"
```

### Branch Policy Enforcement

A **Branch Policy Check** runs on every PR to `main`, `beta`, and `development`. It automatically blocks PRs from unauthorised source branches:

- PR to `main` from `feature/x` → **blocked**
- PR to `main` from `beta` → **allowed**
- PR to `beta` from `feature/x` → **blocked**
- PR to `beta` from `development` → **allowed**
- PR to `development` from `some-random-branch` → **blocked**
- PR to `development` from `feature/x` → **allowed**
- PR to any branch from `hotfix/x` → **always allowed**

### Working with Hotfixes

Hotfixes bypass the normal promotion flow for urgent production issues:

1. Create `hotfix/description` from `main`
2. Implement and test the fix
3. Open PRs to `main`, `beta`, **and** `development` (to keep all branches in sync)

## Quality Checks

All PRs to `main`, `beta`, and `development` must pass these **blocking** checks before merge:

| Check | Tool | Command |
|-------|------|---------|
| PHP Lint | `php -l` | `composer lint` |
| PHP Coding Standards | PHPCS (Conduction standard) | `composer phpcs` |
| PHP Mess Detection | PHPMD | `composer phpmd` |
| PHP Code Metrics | phpmetrics (informational) | `composer phpmetrics` |
| ESLint + Stylelint | ESLint + Stylelint | `npm run lint && npm run stylelint` |
| Branch Policy | GitHub Actions | Automatic |

### Running Quality Checks Locally

Before pushing, run all checks locally to catch issues early:

```bash
# PHP checks
composer phpcs          # Coding standards (auto-fix: composer cs:fix)
composer phpmd          # Mess detection
composer phpmetrics     # Code metrics report

# Frontend checks
npm run lint && npm run stylelint
```

### Auto-fixing

PHPCS can automatically fix many coding standard violations:

```bash
composer cs:fix         # Auto-fix what PHPCBF can handle (~60% of issues)
```

## Getting Started

### Prerequisites

- PHP 8.1+
- Composer 2.x
- Node.js 20+
- npm

### Setup

```bash
# Install PHP dependencies
composer install

# Install frontend dependencies
npm ci

# Run the app in development mode
npm run dev
```

### Creating a New Feature

```bash
# Start from development
git checkout development
git pull origin development

# Create your feature branch
git checkout -b feature/my-feature

# ... implement, commit, push ...
git push -u origin feature/my-feature

# Open a PR to development
```
