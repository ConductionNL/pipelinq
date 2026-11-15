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
  - user used to find it. The library page draws that link (`compareUrl`), so
  - it stands as the board draws it in the board look.
  -
  - The manifest `config` keys are declared as props here because
  - CnPageRenderer spreads `pages[].config` onto the dispatched component:
  - anything not declared would fall through onto the root element as a stray
  - HTML attribute instead of reaching the library page.
-->

<template>
	<div class="features-roadmap">
		<CnFeaturesAndRoadmapPage
			:repo="repo"
			:documentationUrl="documentationUrl"
			:openbuiltUrl="openbuiltUrl"
			:llmSkillsUrl="llmSkillsUrl"
			:suggestUrl="suggestUrl"
			:categories="categories"
			:board="board"
			:compareUrl="comparisonUrl"
			:compareLabel="compareLabel" />
	</div>
</template>

<script>
import { CnFeaturesAndRoadmapPage } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
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
		/** Board look options (`subtitle`, ...), forwarded to the library page. */
		board: { type: Object, default: null },
	},

	computed: {
		/**
		 * The curated list's groups, key to caption, in the page's order
		 * (board PqRoadmap), or null to leave the features ungrouped.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
		 */
		categories() {
			return loadState('pipelinq', 'features_roadmap_categories', null)
		},

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
		 * The compare link's text, in the reader's language.
		 *
		 * @return {string}
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-link-to-the-comparison-on-the-public-site
		 */
		compareLabel() {
			return t('pipelinq', 'How pipelinq compares to other help desks')
		},
	},

	methods: {
		t,
	},
}
</script>
