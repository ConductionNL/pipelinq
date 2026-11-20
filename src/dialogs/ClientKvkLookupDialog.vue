<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Look a company up in the Handelsregister from the new request dialog (board
PqNieuwVerzoek, "Opzoeken in de KVK"). The handler types a name or an 8-digit
KVK number; GET /api/kvk/search answers the matching companies, and the one
picked is handed to the form, which selects the client with that KVK number or
creates an organisation client from it.

@spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Look up in the KVK')"
		:subtitle="t('pipelinq', 'Find the company in the trade register')"
		width="form"
		data-testid="client-kvk-lookup-dialog"
		@closing="$emit('close')">
		<form class="client-kvk" @submit.prevent="search">
			<NcTextField
				v-model="query"
				:label="t('pipelinq', 'Company name or KVK number')"
				data-testid="client-kvk-query" />
			<p v-if="error" class="client-kvk__error" role="alert">
				{{ error }}
			</p>
			<p v-else-if="searched && rows.length === 0" class="client-kvk__empty">
				{{ t('pipelinq', 'No company found.') }}
			</p>
			<ul v-if="rows.length > 0" class="client-kvk__results" :aria-label="t('pipelinq', 'Companies found')">
				<li v-for="row in rows" :key="row.id">
					<button
						type="button"
						class="client-kvk__result"
						:data-testid="`client-kvk-result-${row.id}`"
						@click="pick(row.id)">
						<span class="client-kvk__name">{{ row.label }}</span>
						<span class="client-kvk__secondary">{{ row.secondary }}</span>
					</button>
				</li>
			</ul>
		</form>
		<template #actions>
			<NcButton data-testid="client-kvk-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || query.trim() === ''"
				data-testid="client-kvk-search"
				@click="search">
				{{ t('pipelinq', 'Search') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { kvkCompanyRow } from '../services/clientLookups.js'

export default {
	name: 'ClientKvkLookupDialog',
	components: { CnDialog, NcButton, NcTextField },

	props: {
		/** An axios-like client; the specs pass a fake. */
		http: {
			type: [Object, Function],
			default: () => axios,
		},
	},

	emits: ['close', 'picked'],

	data() {
		return {
			query: '',
			busy: false,
			searched: false,
			error: '',
			companies: [],
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string, secondary: string}>} The companies as rows.
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		rows() {
			return this.companies.map((company) => kvkCompanyRow(company)).filter((row) => row.id !== '')
		},
	},

	methods: {
		t,

		/**
		 * Search the Handelsregister by name or KVK number.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		async search() {
			const q = this.query.trim()
			if (this.busy || q === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await this.http.get(generateUrl('/apps/pipelinq/api/kvk/search'), { params: { q } })
				this.companies = Array.isArray(data?.results) ? data.results : []
			} catch (err) {
				this.companies = []
				this.error = err?.response?.data?.error || t('pipelinq', 'The KVK could not be asked. Try again.')
			} finally {
				this.searched = true
				this.busy = false
			}
		},

		/**
		 * Hand the picked company to the form.
		 *
		 * @param {string} kvkNumber The company's KVK number.
		 * @return {void}
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		pick(kvkNumber) {
			const company = this.companies.find((c) => String(c?.kvkNummer || '') === kvkNumber)
			if (company) {
				this.$emit('picked', company)
			}
		},
	},
}
</script>

<style scoped>
.client-kvk {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.client-kvk__results {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.client-kvk__result {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.client-kvk__result:hover,
.client-kvk__result:focus-visible {
	background: var(--color-background-hover);
}

.client-kvk__name {
	font-weight: 600;
}

.client-kvk__secondary,
.client-kvk__empty {
	color: var(--color-text-maxcontrast);
}

.client-kvk__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error-text));
}
</style>
