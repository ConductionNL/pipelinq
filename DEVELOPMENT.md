# Development

## Prerequisites

- Docker & Docker Compose
- Node.js >= 18
- npm
- A running Nextcloud instance with [OpenRegister](https://github.com/ConductionNL/openregister) installed

## Local Development

This app is developed using the [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev) environment. The app is volume-mounted into the Nextcloud container.

```bash
# Start the development environment
docker compose -f openregister/docker-compose.yml up -d

# Build the frontend
cd pipelinq
npm install
npm run dev
```

The app will be available at `http://localhost:8080/apps/pipelinq`.

## Frontend Build

```bash
npm install          # Install dependencies
npm run dev          # Development build (watch mode)
npm run build        # Production build
```

## Code Quality

```bash
composer phpcs       # PHP CodeSniffer — coding standards
composer cs:fix      # Auto-fix coding standard issues
composer phpmd       # PHP Mess Detector — complexity, naming, unused code
composer phpmetrics  # Generate HTML metrics report
```

## Product Page

The product page at [pipelinq.app](https://pipelinq.app) is built with [Docusaurus 3](https://docusaurus.io/) and deployed via GitHub Pages.

### How it works

- The Docusaurus setup lives in the `docusaurus/` folder
- Documentation content comes from the `docs/` folder at the project root — **not** duplicated inside `docusaurus/`
- The Docusaurus config uses `path: '../docs'` to reference the root docs directly
- Pushing to the `development` branch triggers the GitHub Actions workflow (`.github/workflows/documentation.yml`) which builds and deploys to the `gh-pages` branch
- GitHub Pages serves the built site at `pipelinq.app` (configured via `static/CNAME`)

### Local preview

```bash
cd docusaurus
npm install
npm start            # Dev server at http://localhost:3000 with hot reload
```

### Adding documentation

Simply add or edit Markdown files in the `docs/` folder. The sidebar is auto-generated from the folder structure. Changes will appear on the product page after pushing to `development`.
