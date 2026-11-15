/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "How pipelinq compares", served at /compare and /nl/compare.
 *
 * A .js page and not .mdx: docs.path is './', so the docs plugin's MDX loader
 * also matches files under src/pages/ and runs over the pages plugin's output
 * a second time, which fails the build. The prose is short enough to keep in
 * two languages here.
 */

import React from 'react'
import Layout from '@theme/Layout'
import Link from '@docusaurus/Link'
import useDocusaurusContext from '@docusaurus/useDocusaurusContext'
import CapabilityComparison from '@site/src/components/CapabilityComparison'

const PROSE = {
	en: {
		title: 'How pipelinq compares',
		description:
			'We rated pipelinq and two open source help desks on the same list of service desk capabilities. Read the limits of that reading before the scores.',
		intro: [
			'We installed two open source help desks, drove them and rated each one on the same list of service desk capabilities.',
			'The limits of that reading come first, then the totals, then every capability by area.',
		],
		next: 'What to do next',
		nextText:
			'Write down the capabilities your service desk needs, and test every system on that shortlist yourself.',
		tryIt: 'To try pipelinq, follow the',
		guide: 'installation guide',
	},
	nl: {
		title: 'Hoe pipelinq zich verhoudt',
		description:
			'We beoordeelden pipelinq en twee open source helpdesks op dezelfde lijst mogelijkheden van een servicedesk. Lees eerst de grenzen van die beoordeling, dan de scores.',
		intro: [
			'We installeerden twee open source helpdesks, werkten ermee en beoordeelden ze allemaal op dezelfde lijst mogelijkheden van een servicedesk.',
			'Eerst leest u de grenzen van die beoordeling, dan de totalen, dan elke mogelijkheid per gebied.',
		],
		next: 'Wat u nu doet',
		nextText:
			'Schrijf op welke mogelijkheden uw servicedesk nodig heeft, en test elk systeem zelf op die lijst.',
		tryIt: 'Wilt u pipelinq proberen? Volg de',
		guide: 'installatiehandleiding',
	},
}

/**
 * The comparison page.
 *
 * @return {React.ReactElement} The page.
 */
export default function Compare() {
	const { i18n } = useDocusaurusContext()
	const prose = PROSE[i18n.currentLocale] ?? PROSE.en
	return (
		<Layout title={prose.title} description={prose.description}>
			<main className="container margin-vert--lg">
				<h1>{prose.title}</h1>
				{prose.intro.map((line) => (
					<p key={line}>{line}</p>
				))}
				<CapabilityComparison />
				<h2>{prose.next}</h2>
				<p>{prose.nextText}</p>
				<p>
					{prose.tryIt} <Link to="/docs/installation">{prose.guide}</Link>.
				</p>
			</main>
		</Layout>
	)
}
