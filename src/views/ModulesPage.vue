<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

The Modules page: one card for every page the simple menu leaves out.

The cards, their categories and the lead paragraph are declared in
src/manifest.d/98-modules.json and arrive here as props, the way the page
renderer hands a custom page its config. Each card is a router link by route
name, so it is a real link: it opens in a new tab on a middle click and has an
address to copy.

It is a custom page and not a second `type: "reports"` page on purpose. An app
has one reports page (ADR-112), and these cards are not reports.

It draws its own cards. The first version handed them to the library's
CnReportsPage, which the library does not export: the import was undefined and
the page rendered empty, with no error. tests/vitest/modulesPage.spec.js mounts
this page and counts the cards, and libraryImports.spec.js fails on any import
the library does not export.

A card is a link. The page it opens decides who may see it.

@spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
-->
<template>
	<div class="modules-page" data-testid="modules-page">
		<header class="modules-page__header">
			<h2 class="modules-page__title">
				{{ translated(title) }}
			</h2>
			<p v-if="description" class="modules-page__description">
				{{ translated(description) }}
			</p>
		</header>
		<section
			v-for="group in groups"
			:key="group.key"
			class="modules-page__group"
			:aria-labelledby="`modules-group-${group.key}`"
			:data-testid="`modules-group-${group.key}`">
			<h3 :id="`modules-group-${group.key}`" class="modules-page__group-title">
				{{ group.label }}
			</h3>
			<ul class="modules-page__grid">
				<li v-for="card in group.cards" :key="card.id">
					<router-link
						class="modules-page__card"
						:to="{ name: card.route }"
						data-testid="modules-card">
						<span class="modules-page__card-title">{{
							card.label
						}}</span>
						<span
							v-if="card.description"
							class="modules-page__card-text">
							{{ card.description }}
						</span>
					</router-link>
				</li>
			</ul>
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The Modules page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
 */
export default {
	name: 'ModulesPage',
	props: {
		/** The page heading, from the manifest page. */
		title: { type: String, default: '' },
		/** The lead paragraph, from the page config. */
		description: { type: String, default: '' },
		/** The cards: `{ id, label, description, icon, category, route }`. */
		cards: { type: Array, default: () => [] },
		/** Category key to label, in the order the groups are drawn. */
		categories: { type: Object, default: () => ({}) },
	},

	computed: {
		/**
		 * The cards per category, in the order the page config declares the
		 * categories. A category without a card is left out, and a card
		 * without a route or a label is not a card.
		 *
		 * @return {Array<{key: string, label: string, cards: Array<object>}>} The groups.
		 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
		 */
		groups() {
			const usable = (Array.isArray(this.cards) ? this.cards : []).filter(
				(card) => card && card.route && card.label,
			)
			return Object.keys(this.categories || {})
				.map((key) => ({
					key,
					label: this.translated(this.categories[key]),
					cards: usable
						.filter((card) => card.category === key)
						.map((card) => ({
							...card,
							label: this.translated(card.label),
							description: this.translated(card.description || ''),
						})),
				}))
				.filter((group) => group.cards.length > 0)
		},
	},

	methods: {
		/**
		 * A manifest string in the reader's language.
		 *
		 * @param {string} text The English source string.
		 * @return {string} The translation, or the text itself.
		 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
		 */
		translated(text) {
			return typeof text === 'string' && text !== '' ? t('pipelinq', text) : ''
		},
	},
}
</script>

<style scoped>
.modules-page {
	padding: calc(var(--default-grid-baseline) * 5);
	max-width: 1200px;
}

.modules-page__title {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
}

.modules-page__description {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 6);
}

.modules-page__group {
	margin-bottom: calc(var(--default-grid-baseline) * 8);
}

.modules-page__group-title {
	margin: 0 0 calc(var(--default-grid-baseline) * 3);
}

.modules-page__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
	list-style: none;
	margin: 0;
	padding: 0;
}

.modules-page__card {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	height: 100%;
	padding: calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-decoration: none;
}

.modules-page__card:hover,
.modules-page__card:focus-visible {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.modules-page__card:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.modules-page__card-title {
	font-weight: bold;
}

.modules-page__card-text {
	color: var(--color-text-maxcontrast);
}
</style>
