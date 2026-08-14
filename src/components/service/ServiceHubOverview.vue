<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2024 Conduction B.V. <info@conduction.nl> -->

<!--
 Service Hub — card-grid landing page (service-group-cards-collapse).

 ADR-044 cards-collapse: the Service top-level navigation group is collapsed
 into a single clickable menu item that links to this page. Each former
 Service child leaf (Requests, Tasks, Contactmomenten, Complaints, Projects,
 MyWork, BookingsGroup, Queues) is rendered as a CnCard. All leaf page routes
 remain registered and reachable; only the navigation nesting changes.

 @spec openspec/changes/service-group-cards-collapse/specs/navigation/spec.md
-->
<template>
	<div class="service-hub" data-testid="service-hub">
		<header class="service-hub__header">
			<h2 class="service-hub__title" data-testid="service-hub-title">
				{{ t('pipelinq', 'Service') }}
			</h2>
			<p class="service-hub__hint" data-testid="service-hub-hint">
				{{
					t(
						'pipelinq',
						'Navigate to any Service area from here. All individual pages remain directly accessible by URL.',
					)
				}}
			</p>
		</header>

		<div class="service-hub__grid" data-testid="service-hub-grid">
			<CnCard
				v-for="card in cards"
				:key="card.id"
				:title="card.label"
				:data-testid="`service-card-${card.id}`"
				class="service-hub__card"
				@click="navigate(card)" />
		</div>
	</div>
</template>

<script>
// SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import { CnCard } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ServiceHubOverview',

	components: {
		CnCard,
	},

	data() {
		return {
			/**
			 * One entry per former Service child leaf.
			 * Labels and route names mirror the manifest entries.
			 * BookingsGroup renders as one card linking to the Bookings
			 * index (its primary route); the sub-group leaf pages
			 * (Services / Resources / Bookings) stay routable via their
			 * own manifest entries.
			 *
			 * The Requests / Contactmomenten / Complaints leaves collapsed into
			 * the unified Tickets index (unify-ticket-supertype). Their cards
			 * keep their own LABEL but deep-link into Tickets with a
			 * `ticketType` query that preselects the matching subtype.
			 */
			cards: [
				{
					id: 'Requests',
					label: t('pipelinq', 'Requests'),
					route: 'Tickets',
					query: { ticketType: 'request' },
				},
				{ id: 'Tasks', label: t('pipelinq', 'Tasks'), route: 'Tasks' },
				{
					id: 'Contactmomenten',
					label: t('pipelinq', 'Contact moments'),
					route: 'Tickets',
					query: { ticketType: 'contactmoment' },
				},
				{
					id: 'Complaints',
					label: t('pipelinq', 'Complaints'),
					route: 'Tickets',
					query: { ticketType: 'complaint' },
				},
				{
					id: 'Projects',
					label: t('pipelinq', 'Projects'),
					route: 'Projects',
				},
				{ id: 'MyWork', label: t('pipelinq', 'My Work'), route: 'MyWork' },
				{
					id: 'BookingsGroup',
					label: t('pipelinq', 'Appointments'),
					route: 'Bookings',
				},
				{ id: 'Queues', label: t('pipelinq', 'Queues'), route: 'Queues' },
			],
		}
	},

	methods: {
		t,

		/**
		 * Navigate to a leaf page by route name, carrying the card's optional
		 * query (used by the ticket-subtype cards to preselect a ticketType on
		 * the unified Tickets index).
		 *
		 * @param {object} card The card whose `route` is a manifest page id.
		 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
		 */
		navigate(card) {
			this.$router.push({ name: card.route, query: card.query || {} })
		},
	},
}
</script>

<style scoped>
.service-hub {
	padding: 1rem;
}

.service-hub__header {
	margin-bottom: 1.5rem;
}

.service-hub__title {
	margin: 0 0 0.25rem 0;
}

.service-hub__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 48rem;
}

.service-hub__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 1rem;
}

.service-hub__card {
	cursor: pointer;
}
</style>
