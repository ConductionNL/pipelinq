<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS Customer lookup modal — searches the pipelinq contact register and
  - surfaces decorated rows (name / email / phone + doNotContact privacy
  - badge). Emits @select with the chosen contact UUID, @cancel on close.
  -
  - @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Add customer')"
		:open="true"
		size="normal"
		@closing="onCancel">
		<div class="customer-lookup">
			<NcTextField
				ref="searchInput"
				v-model="query"
				:label="t('pipelinq', 'Search')"
				:placeholder="t('pipelinq', 'Name, e-mail or phone')"
				data-testid="customer-lookup-input"
				@update:model-value="onSearchInput" />

			<div
				v-if="loading"
				class="customer-lookup__state"
				role="status"
				aria-live="polite">
				<NcLoadingIcon :size="20" />
				<span>{{ t('pipelinq', 'Searching…') }}</span>
			</div>

			<p
				v-else-if="error"
				class="customer-lookup__state customer-lookup__error"
				role="alert"
				aria-live="assertive">
				{{ error }}
				<NcButton variant="tertiary" @click="runSearch">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</p>

			<p
				v-else-if="!hasSearched"
				class="customer-lookup__state"
				role="status">
				{{ t('pipelinq', 'Type at least two characters to search.') }}
			</p>

			<p
				v-else-if="results.length === 0"
				class="customer-lookup__state"
				role="status">
				{{ t('pipelinq', 'No results. Try a different search.') }}
			</p>

			<ul
				v-else
				class="customer-lookup__results"
				role="listbox"
				:aria-label="t('pipelinq', 'Customer search results')">
				<li
					v-for="row in results"
					:key="row.id"
					class="customer-lookup__row"
					role="option"
					tabindex="0"
					:aria-selected="false"
					data-testid="customer-lookup-row"
					@click="onSelect(row)"
					@keydown.enter="onSelect(row)">
					<div class="customer-lookup__name">
						<strong>{{ row.name }}</strong>
						<span
							v-if="row.doNotContact"
							class="customer-lookup__badge"
							:title="t('pipelinq', 'This customer does not wish to be contacted.')">
							🔒 {{ row.doNotContactBadge }}
						</span>
					</div>
					<div class="customer-lookup__meta">
						<span v-if="row.email">{{ row.email }}</span>
						<span v-if="row.phone">{{ row.phone }}</span>
					</div>
				</li>
			</ul>
		</div>
		<template #actions>
			<NcButton variant="secondary" @click="onCancel">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { searchCustomers } from '../services/posCustomerApi.js'

const DEBOUNCE_MS = 300
const MIN_QUERY = 2

export default {
	name: 'CustomerLookupModal',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},
	emits: ['select', 'cancel'],
	data() {
		return {
			query: '',
			results: [],
			loading: false,
			error: '',
			hasSearched: false,
			debounceHandle: null,
		}
	},
	mounted() {
		this.$nextTick(() => {
			const input = this.$refs.searchInput
			if (input && typeof input.focus === 'function') {
				input.focus()
			}
		})
	},
	beforeUnmount() {
		if (this.debounceHandle) {
			clearTimeout(this.debounceHandle)
		}
	},
	methods: {
		/**
		 * Debounce the search call to avoid hammering the API while typing.
		 */
		onSearchInput() {
			if (this.debounceHandle) {
				clearTimeout(this.debounceHandle)
			}
			if (!this.query || this.query.trim().length < MIN_QUERY) {
				this.results = []
				this.hasSearched = false
				this.error = ''
				return
			}
			this.debounceHandle = setTimeout(() => this.runSearch(), DEBOUNCE_MS)
		},
		/**
		 * Fire the search request.
		 */
		async runSearch() {
			const needle = (this.query || '').trim()
			if (needle.length < MIN_QUERY) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.results = await searchCustomers(needle, 20)
				this.hasSearched = true
			} catch (e) {
				this.error = t('pipelinq', 'Error searching. Try again later.')
				this.results = []
				this.hasSearched = true
			} finally {
				this.loading = false
			}
		},
		/**
		 * Emit the selected customer.
		 *
		 * @param {object} row The chosen row.
		 */
		onSelect(row) {
			this.$emit('select', row)
		},
		/**
		 * Close the modal without a selection.
		 */
		onCancel() {
			this.$emit('cancel')
		},
	},
}
</script>

<style scoped>
.customer-lookup {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 380px;
}

.customer-lookup__state {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 8px 4px;
}

.customer-lookup__error {
	color: var(--color-error);
}

.customer-lookup__results {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 360px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.customer-lookup__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 10px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}

.customer-lookup__row:last-child {
	border-bottom: none;
}

.customer-lookup__row:hover,
.customer-lookup__row:focus {
	background: var(--color-background-hover);
	outline: none;
}

.customer-lookup__name {
	display: flex;
	align-items: center;
	gap: 8px;
}

.customer-lookup__badge {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 999px;
	background: var(--color-warning, #d97706);
	color: var(--color-primary-text, #fff);
}

.customer-lookup__meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
