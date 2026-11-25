<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<CnDetailCard
		v-if="rows.length"
		:title="t('pipelinq', 'Other details')"
		data-testid="client-other-details">
		<dl class="client-other-details">
			<div
				v-for="row in rows"
				:key="row.key"
				class="client-other-details__item"
				:data-testid="`client-other-detail-${row.key}`">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.value }}</dd>
			</div>
		</dl>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { translate } from '@nextcloud/l10n'
import { customClientFields, customFieldRows } from '../services/clientCustomFields.js'

/**
 * The values of the fields an administrator added to the client schema, on
 * the client page: label above value, as the Resident and Organisation cards
 * draw theirs. Nothing is drawn while no added field holds a value.
 *
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export default {
	name: 'ClientOtherDetails',
	components: { CnDetailCard },

	props: {
		/** The client as the page holds it. */
		client: {
			type: Object,
			default: null,
		},
	},

	data() {
		return { fields: [] }
	},

	computed: {
		/** @return {Array<{key: string, label: string, value: string}>} The rows to show. */
		rows() {
			return customFieldRows(this.fields, this.client, (text) => translate('pipelinq', text))
		},
	},

	mounted() {
		this.loadFields()
	},

	methods: {
		/**
		 * Read the client schema as OpenRegister serves it now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
		 */
		async loadFields() {
			try {
				const { useObjectStore } = await import('../store/modules/object.js')
				this.fields = customClientFields(await useObjectStore().fetchSchema('client'))
			} catch {
				this.fields = []
			}
		},
	},
}
</script>

<style scoped>
.client-other-details {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px 24px;
	margin: 0;
}

.client-other-details__item {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.client-other-details dt {
	color: var(--color-text-maxcontrast);
}

.client-other-details dd {
	margin: 2px 0 0;
	overflow-wrap: anywhere;
}
</style>
