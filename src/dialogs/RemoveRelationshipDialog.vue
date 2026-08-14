<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - RemoveRelationshipDialog confirms removal of a relationship between two
  - entities; the parent owns the delete of both the relationship and its
  - inverse.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of
  - ContactRelationships.vue.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Remove relationship')" @closing="$emit('close')">
		<p>
			{{
				t('pipelinq', 'Remove the relationship between {from} and {to}?', {
					from: fromName,
					to: toName,
				})
			}}
		</p>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" @click="$emit('confirm')">
				{{ t('pipelinq', 'Remove') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'RemoveRelationshipDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * Display name of the entity the relationship starts from.
		 */
		fromName: {
			type: String,
			default: '',
		},

		/**
		 * Display name of the related entity.
		 */
		toName: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'confirm'],
}
</script>
