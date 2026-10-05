<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

The Modules page: one card for every page the simple menu leaves out.

The cards, their categories and the lead paragraph are declared in
src/manifest.d/98-modules.json and arrive here as props, the way the page
renderer hands a custom page its config. The card grid is the library's own
(CnReportsPage is a page of cards: a label, a line and a route each), so this
view draws nothing itself.

It is a custom page and not a second `type: "reports"` page on purpose. An app
has one reports page (ADR-112), and these cards are not reports.

A card is a link. The page it opens decides who may see it.

@spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
-->
<template>
	<CnReportsPage
		:title="title"
		:description="description"
		:cards="cards"
		:categories="categories"
		data-testid="modules-page" />
</template>

<script>
import { CnReportsPage } from '@conduction/nextcloud-vue'

/**
 * The Modules page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
 */
export default {
	name: 'ModulesPage',
	components: { CnReportsPage },
	props: {
		/** The page heading, from the manifest page. */
		title: { type: String, default: '' },
		/** The lead paragraph, from the page config. */
		description: { type: String, default: '' },
		/** The cards: `{ id, label, description, icon, category, route }`. */
		cards: { type: Array, default: () => [] },
		/** Category key to label, in the order the filter offers them. */
		categories: { type: Object, default: () => ({}) },
	},
}
</script>
