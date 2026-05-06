// @ts-check

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Pipelinq',
  tagline: 'CRM and pipeline management for Nextcloud',
  url: 'https://pipelinq.conduction.nl',
  baseUrl: '/',

  // GitHub pages deployment config
  organizationName: 'ConductionNL',
  projectName: 'pipelinq',
  trailingSlash: false,

  onBrokenLinks: 'warn',
  onBrokenMarkdownLinks: 'warn',
  onBrokenAnchors: 'warn',

  i18n: {
    // Dutch locale dropped — `i18n/nl/` carries only stale translation
    // strings (no actual translated markdown), and stale doc IDs trigger
    // SSR `Cannot read properties of undefined (reading 'id')` errors
    // (see ADR-030). Re-add `'nl'` to `locales` once the Dutch
    // translation pass has been completed or the metadata audited.
    defaultLocale: 'en',
    locales: ['en'],
    localeConfigs: {
      en: { label: 'English' },
    },
  },

  markdown: {
    mermaid: true,
    // Tutorial pages reference screenshots populated by
    // `tests/e2e/docs-screenshots.spec.ts`. The Playwright capture run
    // is separate from the docs build, so the build must succeed even
    // when a fresh checkout doesn't have every PNG yet (ADR-030).
    hooks: {
      onBrokenMarkdownImages: 'warn',
    },
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          path: './',
          exclude: ['**/node_modules/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl:
            'https://github.com/ConductionNL/pipelinq/tree/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      navbar: {
        title: 'Pipelinq',
        logo: {
          alt: 'Pipelinq Logo',
          src: 'img/logo.svg',
        },
        items: [
          {
            type: 'docSidebar',
            sidebarId: 'tutorialSidebar',
            position: 'left',
            label: 'Documentation',
          },
          {
            href: 'https://github.com/ConductionNL/pipelinq',
            label: 'GitHub',
            position: 'right',
          },
          {
            type: 'localeDropdown',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Docs',
            items: [
              {
                label: 'Documentation',
                to: '/docs/FEATURES',
              },
            ],
          },
          {
            title: 'Community',
            items: [
              {
                label: 'GitHub',
                href: 'https://github.com/ConductionNL/pipelinq',
              },
            ],
          },
        ],
        copyright: `Copyright © ${new Date().getFullYear()} for <a href="https://openwebconcept.nl">Open Webconcept</a> by <a href="https://conduction.nl">Conduction B.V.</a>`,
      },
      prism: {
        theme: require('prism-react-renderer/themes/github'),
        darkTheme: require('prism-react-renderer/themes/dracula'),
      },
      mermaid: {
        theme: { light: 'default', dark: 'dark' },
      },
    }),
  themes: ['@docusaurus/theme-mermaid'],
};

module.exports = config;
