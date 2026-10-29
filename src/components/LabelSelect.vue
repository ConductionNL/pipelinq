<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The label picker for the hand-written lead, client and article forms
  - (shared-label-list). It offers the shared label list and refuses a typed
  - word, so the list stays the source. A label the item already carries
  - stays pickable after it left the list. The schema forms get the same
  - picker from the property's x-options-from.
  -->
<template>
	<NcSelect
		v-if="options.length > 0"
		:modelValue="modelValue"
		:options="options"
		:inputLabel="inputLabel || t('pipelinq', 'Labels')"
		:placeholder="t('pipelinq', 'Pick labels')"
		:loading="loading"
		multiple
		:closeOnSelect="false"
		data-testid="label-select"
		@update:modelValue="(value) => $emit('update:modelValue', value || [])" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LabelSelect',
	components: { NcSelect },

	props: {
		/** The picked label names. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The field's label; default "Labels". */
		inputLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			names: [],
			loading: false,
		}
	},

	computed: {
		/**
		 * The list's names and the item's own, in alphabetical order.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-labels-are-one-shared-list-for-articles-leads-and-customers
		 */
		options() {
			const all = new Set([...this.names, ...(this.modelValue || []).map(String)])
			return [...all].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }))
		},
	},

	/**
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-labels-are-one-shared-list-for-articles-leads-and-customers
	 */
	async mounted() {
		this.loading = true
		try {
			const rows = (await useObjectStore().fetchCollection('label', { _limit: 500 })) || []
			this.names = rows.map((row) => String(row?.name ?? '')).filter((name) => name !== '')
		} catch {
			this.names = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
	},
}
</script>
