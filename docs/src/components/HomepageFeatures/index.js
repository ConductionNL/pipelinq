import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Pipeline Management',
    description: (
      <>
        Visual kanban boards for leads and requests. Drag-and-drop stage management with conversion tracking and insights.
      </>
    ),
  },
  {
    title: 'Client & Lead CRM',
    description: (
      <>
        Full client management with contacts, leads, and requests. Track opportunities from first contact to close.
      </>
    ),
  },
  {
    title: 'Built on OpenRegister',
    description: (
      <>
        All data stored as flexible OpenRegister objects. Syncs with Nextcloud Contacts and integrates with your existing workflow.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
