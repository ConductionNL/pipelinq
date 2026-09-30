<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="werkplek-client-select" data-testid="werkplek-client-select">
		<CnResourceSelect
			register="pipelinq"
			schema="client"
			labelField="name"
			:modelValue="selectedClient"
			:placeholder="t('pipelinq', 'Choose a client')"
			:ariaLabelCombobox="t('pipelinq', 'Client')"
			:preload="true"
			:clearable="false"
			:allowCreate="false"
			@update:modelValue="onSelect" />
	</div>
</template>

<script>
import { CnResourceSelect } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../../store/modules/object.js'

const TYPE_SLUG = 'pipelinq-client'

/**
 * WerkplekClientSelect — the Customer Support page's client in focus, beside
 * the page title (the dashboard's `title-meta` slot).
 *
 * Writes the chosen client to the page workspace context as `selectedClient`,
 * which the client-bound widgets filter on and the Active interaction form
 * pre-fills from. It starts on the first client and cannot be cleared, so the
 * page always has a client in focus.
 */
export default {
	name: 'WerkplekClientSelect',

	components: { CnResourceSelect },

	inject: {
		cnWorkspaceContext: { default: null },
	},

	computed: {
		/**
		 * The page's workspace context; the provided ref may arrive unwrapped or as `{ value }`.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		workspace() {
			const c = this.cnWorkspaceContext
			if (!c || typeof c !== 'object') {
				return null
			}
			return 'value' in c ? c.value : c
		},

		/**
		 * The id of the client in focus, or an empty string.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		selectedClient() {
			const id = this.workspace && this.workspace.selectedClient
			return id ? String(id) : ''
		},
	},

	/**
	 * Preselect a client when the page has none in focus yet.
	 *
	 * @spec openspec/specs/kcc-werkplek/spec.md
	 */
	created() {
		if (!this.selectedClient) {
			this.selectFirstClient()
		}
	},

	methods: {
		/**
		 * Preselect the first client, the same one the picker lists first.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		async selectFirstClient() {
			const store = useObjectStore()
			try {
				store.registerObjectType(TYPE_SLUG, 'client', 'pipelinq')
			} catch {
				// Already registered.
			}
			const items = await store.fetchCollectionForOptions(TYPE_SLUG, {
				_limit: 1,
			})
			const first = Array.isArray(items) ? items[0] : null
			const id = first && (first.id || (first['@self'] && first['@self'].id))
			if (id && !this.selectedClient) {
				this.onSelect(String(id))
			}
		},

		/**
		 * Write the client in focus. An empty value is ignored: the page always
		 * keeps a client.
		 *
		 * @param {string} id The client id.
		 * @return {void}
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		onSelect(id) {
			const holder = this.cnWorkspaceContext
			if (!id || !holder || typeof holder !== 'object') {
				return
			}
			if ('value' in holder) {
				holder.value = { ...(holder.value || {}), selectedClient: id }
				return
			}
			holder.selectedClient = id
		},
	},
}
</script>

<style scoped>
.werkplek-client-select {
	min-width: 260px;
}
</style>
