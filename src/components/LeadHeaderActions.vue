<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The LeadDetail page's `actionsComponent`: "Log a visit", rendered in the
  - page header beside Edit and the Actions menu.
  -
  - @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
  -->
<template>
	<LogVisitAction inHeader :leadId="objectId" :clientId="object?.client || ''" />
</template>

<script>
import LogVisitAction from './LogVisitAction.vue'

export default {
	name: 'LeadHeaderActions',
	components: { LogVisitAction },
	// The slot also hands over schema / store / openEditForm; none of them
	// belong on the root element.
	inheritAttrs: false,

	props: {
		/** The lead, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The lead id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},
}
</script>
