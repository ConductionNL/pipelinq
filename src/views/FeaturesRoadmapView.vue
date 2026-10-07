<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Features & roadmap page.
  -
  - WHY THIS WRAPPER EXISTS. The page used to be `type: "roadmap"`, which
  - CnPageRenderer dispatches straight to the library's
  - CnFeaturesAndRoadmapPage. That component renders exactly two tabs
  - (features, roadmap) and declares NO slots, so there is no way to add
  - anything to it from the manifest. dossiq hit the same wall first and
  - solved it the same way.
  -
  - So the page is `type: "custom"` and this component owns it. It renders the
  - library page UNCHANGED, which keeps the existing e2e selectors
  - (.cn-features-and-roadmap-view, .cn-features-tab__card, the "Show roadmap"
  - button, the "Suggest feature" link) resolving exactly as before.
  -
  - THE COMPARISON IS NOT HERE ANY MORE. Until 2026-10-07 this page carried a
  - second section, "How pipelinq compares". Ruben ruled that how an app compares
  - belongs on its public site and not in the app, for every app. The section
  - moved to pipelinq.conduction.nl/compare, which renders the same
  - `src/data/capabilityComparison.json` through the same helpers. What stays
  - here is one link to it, so the comparison is still one click from where a
  - user used to find it.
  -
  - The manifest `config` keys are declared as props here because
  - CnPageRenderer spreads `pages[].config` onto the dispatched component:
  - anything not declared would fall through onto the root element as a stray
  - HTML attribute instead of reaching the library page.
-->

<template>
	<div class="features-roadmap">
		<p class="features-roadmap__compare">
			<a
				class="features-roadmap__compare-link"
				:href="comparisonUrl"
				target="_blank"
				rel="noopener noreferrer"
				aria-describedby="features-roadmap-compare-hint">
				{{ t('pipelinq', 'How pipelinq compares to other help desks') }}
			</a>
			<span
				id="features-roadmap-compare-hint"
				class="features-roadmap__compare-hint">
				{{
					t('pipelinq', 'Opens {host} in a new tab.', {
						host: comparisonHost,
					})
				}}
			</span>
		</p>

		<CnFeaturesAndRoadmapPage
			:repo="repo"
			:documentationUrl="documentationUrl"
			:openbuiltUrl="openbuiltUrl"
			:llmSkillsUrl="llmSkillsUrl"
			:suggestUrl="suggestUrl" />
	</div>
</template>

<script>
import { CnFeaturesAndRoadmapPage } from '@conduction/nextcloud-vue'
import { getLanguage, translate as t } from '@nextcloud/l10n'

/**
 * The public site, used when the manifest names no `documentationUrl`.
 *
 * @type {string}
 */
const PUBLIC_SITE = 'https://pipelinq.conduction.nl'

export default {
	name: 'FeaturesRoadmapView',

	components: {
		CnFeaturesAndRoadmapPage,
	},

	props: {
		/** `<owner>/<repo>` on the forge, forwarded to the library page. */
		repo: { type: String, default: '' },
		/** Public documentation site, forwarded to the library page. */
		documentationUrl: { type: String, default: '' },
		/** OpenBuilt CTA override, forwarded to the library page. */
		openbuiltUrl: { type: String, default: '' },
		/** LLM-skills CTA override, forwarded to the library page. */
		llmSkillsUrl: { type: String, default: '' },
		/** Suggest-a-feature CTA override, forwarded to the library page. */
		suggestUrl: { type: String, default: '' },
	},

	computed: {
		/**
		 * The comparison page on the public site, in the reader's language.
		 *
		 * The docs site serves Dutch under `/nl/`, so a Dutch reader lands on
		 * the Dutch page rather than on an English one, one click after a Dutch app.
		 *
		 * @return {string} Absolute URL of the comparison page.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-link-to-the-comparison-on-the-public-site
		 */
		comparisonUrl() {
			const base = (this.documentationUrl || PUBLIC_SITE).replace(/\/+$/, '')
			const dutch = String(getLanguage() || '')
				.toLowerCase()
				.startsWith('nl')
			return `${base}${dutch ? '/nl' : ''}/compare`
		},

		/**
		 * The host the link opens, named in the hint beside it.
		 *
		 * @return {string} Host name, e.g. `pipelinq.conduction.nl`.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-link-to-the-comparison-on-the-public-site
		 */
		comparisonHost() {
			try {
				return new URL(this.comparisonUrl).host
			} catch {
				return this.comparisonUrl
			}
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.features-roadmap__compare {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 12px;
	margin: 0;
	padding: 12px 12px 0;
}

.features-roadmap__compare-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.features-roadmap__compare-hint {
	color: var(--color-text-maxcontrast);
}
</style>
