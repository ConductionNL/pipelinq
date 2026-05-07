/**
 * Pipelinq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/pipelinq.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

/* Pipeline-spike glyph: same SVG as the connext detail page at
   sites/www/src/pages/apps/pipelinq.mdx. Read as a stylised pipeline
   trace running through a sales funnel — the spike is the won deal. */
const PIPELINQ_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M3 12h4l3-9 4 18 3-9h4" />
  </svg>
);

const TAGLINE = (
  <>
    CRM on your <span className="next-blue">Nextcloud</span>. Customers,
    prospects, deals, quotes. Everything as a typed register, no separate
    sales database, no second login. Your sales team works where your
    delivery team already is.
  </>
);

function PipelineValuePanel() {
  const rows = [
    { label: 'lead', val: '40%', tone: 'var(--c-cobalt-300)' },
    { label: 'qualified', val: '60%', tone: 'var(--c-lavender-300)' },
    { label: 'proposal', val: '75%', tone: 'var(--c-mint-500)' },
    { label: 'won', val: '50%', tone: 'var(--c-forest-300)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 6,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 24,
            fontWeight: 700,
            color: 'var(--c-cobalt-700)',
          }}
        >
          € 487k
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 10,
            letterSpacing: '0.05em',
            color: 'var(--c-mint-500)',
          }}
        >
          +12%
        </div>
      </div>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 6 }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
              width: 50,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.val,
                background: row.tone,
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function QuotesPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', stage: 'signed' },
    { tone: 'var(--c-lavender-300)', stage: 'sent' },
    { tone: 'var(--c-mint-500)', stage: 'signed' },
    { tone: 'var(--c-orange-knvb)', stage: 'draft' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 10,
              height: 11,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.stage}
          </div>
        </div>
      ))}
    </div>
  );
}

function HotLeadsPanel() {
  const rows = [
    { val: '€ 84k', tone: 'var(--c-orange-knvb)' },
    { val: '€ 62k', tone: 'var(--c-mint-500)' },
    { val: '€ 48k', tone: 'var(--c-lavender-300)' },
    { val: '€ 31k', tone: 'var(--c-cobalt-300)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '65%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '45%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 10,
              fontWeight: 700,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.val}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Pipeline value',
    desc: 'Total deal value per stage. KPI on top, mini bars per stage. Live, not nightly batch.',
    panel: <PipelineValuePanel />,
  },
  {
    title: "This week's quotes",
    desc: 'Quotes generated, sent, and signed. DocuDesk fills the template, signs with your instance certificate.',
    panel: <QuotesPanel />,
  },
  {
    title: 'Hot leads',
    desc: 'Top deals by value or recent activity. Click through to the customer record.',
    panel: <HotLeadsPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Pipelinq"
      description="CRM on your Nextcloud. Customers, prospects, deals, quotes as typed registers. No separate sales database, no second login."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="pipelinq"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v0.7"
          locales="NL · EN"
          title="Pipelinq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/pipelinq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/pipelinq',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={PIPELINQ_ICON}
          illustration={<AppMock app="pipelinq" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="Sales on the dashboard the team already opens."
          lede="Install Pipelinq and these widgets show up on every sales rep's home screen. Pipeline value first, this week's quotes next, hot leads below."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
