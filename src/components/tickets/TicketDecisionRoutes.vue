<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The head of the Decisions tab on the ticket page (ticket-decisions-tab,
  - board PqTicketBesluitvorming): what the tab holds, the two routes to a
  - decision, and the existing Convert to case action. The lists themselves
  - are decidiq's `decidesk-decisions` leaf, mounted by the manifest for the
  - ticket and for the case it became. A manifest `markdown` widget would
  - carry the two lines, but it does not translate its text.
  -
  - @spec openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001
  -->
<template>
	<div class="ticket-decision-routes" data-testid="ticket-decision-routes">
		<p class="ticket-decision-routes__lead">
			{{
				t(
					'pipelinq',
					'Formal decisions on this ticket, made and signed in decidiq',
				)
			}}
		</p>
		<p class="ticket-decision-routes__routes">
			{{
				t(
					'pipelinq',
					'A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here.',
				)
			}}
		</p>
		<RequestConversionSection :requestId="ticketId" />
	</div>
</template>

<script>
import RequestConversionSection from '../../views/requests/RequestConversionSection.vue'

export default {
	name: 'TicketDecisionRoutes',

	components: {
		RequestConversionSection,
	},

	props: {
		/** The ticket's id. */
		ticketId: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.ticket-decision-routes__lead {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
}

.ticket-decision-routes__routes {
	margin: 0 0 calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}
</style>
