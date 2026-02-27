// @ts-check

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Pipelinq',
  tagline: 'CRM and pipeline management for Nextcloud',
  url: 'https://pipelinq.app',
  baseUrl: '/',

  // GitHub pages deployment config
  organizationName: 'ConductionNL',
  projectName: 'pipelinq',
  trailingSlash: false,

  onBrokenLinks: 'warn',
  onBrokenMarkdownLinks: 'warn',

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          path: '../docs',
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl:
            'https://github.com/ConductionNL/pipelinq/tree/main/docusaurus/',
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
  markdown: {
    mermaid: true,
  },
  themes: ['@docusaurus/theme-mermaid'],
};

module.exports = config;
