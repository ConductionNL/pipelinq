<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

The client page's contact strip, as boards PqKlant and PqOrganisatie draw it:
one card with the warnings line on top (or "No warnings on this resident."
with Add a warning) and the ways to reach the party under it: call, mail,
directions and log a visit. It composes the warnings panel, the contact links
and the visit action, which stay usable on their own.
-->
<template>
	<section class="party-contact-strip" data-testid="party-contact-strip">
		<PartyIndicatorPanel :partyId="partyId" :partyKind="partyKind" compact />
		<div class="party-contact-strip__actions">
			<ContactLinks
				:phone="phone"
				:email="email"
				:address="address"
				variant="buttons" />
			<LogVisitAction :clientId="partyId" />
		</div>
	</section>
</template>

<script>
import ContactLinks from './ContactLinks.vue'
import LogVisitAction from './LogVisitAction.vue'
import PartyIndicatorPanel from './PartyIndicatorPanel.vue'

/**
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-warnings-and-the-ways-to-reach-the-client-are-one-card
 */
export default {
	name: 'PartyContactStrip',

	components: { ContactLinks, LogVisitAction, PartyIndicatorPanel },

	props: {
		/** The client. */
		partyId: { type: String, required: true },
		/** The client's kind: `person` or `organization`. */
		partyKind: { type: String, default: '' },
		/** Phone number as typed. */
		phone: { type: String, default: '' },
		/** Mail address as typed. */
		email: { type: String, default: '' },
		/** Postal address as typed. */
		address: { type: String, default: '' },
	},
}
</script>

<style scoped>
.party-contact-strip {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0 0 20px;
	padding: 16px 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--cn-board-card-radius, 12px);
	background: var(--color-main-background);
}

.party-contact-strip__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.party-contact-strip__actions :deep(.log-visit-action) {
	margin: 0;
}

.party-contact-strip__actions :deep(.log-visit-action button) {
	min-height: 40px;
	height: 40px;
}
</style>
