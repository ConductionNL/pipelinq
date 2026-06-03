<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Add customer')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="customer-lookup">
			<NcTextField
				ref="search"
				:value.sync="query"
				:label="t('pipelinq', 'Name, email or phone number')"
				:placeholder="t('pipelinq', 'Name, email or phone number')"
				trailing-button-icon="close"
				:show-trailing-button="query !== ''"
				@update:value="onQueryInput"
				@trailing-button-click="clearQuery" />

			<NcLoadingIcon v-if="loading" class="customer-lookup__spinner" :size="32" />

			<NcNoteCard v-else-if="error" type="error">
				{{ t('pipelinq', 'Error searching. Please try again later.') }}
				<NcButton class="customer-lookup__retry" @click="search">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</NcNoteCard>

			<ul v-else-if="results.length"
				class="customer-lookup__results"
				role="listbox"
				:aria-label="t('pipelinq', 'Search results')">
				<li v-for="contact in results"
					:key="contact.id"
					class="customer-lookup__row"
					role="option"
					:aria-selected="false"
					tabindex="0"
					@click="select(contact)"
					@keydown.enter="select(contact)">
					<span class="customer-lookup__name">
						{{ contact.name }}
						<span v-if="contact.doNotContact"
							class="customer-lookup__flag"
							:title="t('pipelinq', 'This customer does not wish to be contacted.')">
							🔒 {{ t('pipelinq', 'Do not contact') }}
						</span>
					</span>
					<span class="customer-lookup__meta">
						<span v-if="contact.email">{{ contact.email }}</span>
						<span v-if="contact.phone">{{ contact.phone }}</span>
						<span v-if="contact.lastPurchaseDate" class="customer-lookup__last">
							{{ t('pipelinq', 'Last purchase: {date}', { date: formatDate(contact.lastPurchaseDate) }) }}
						</span>
					</span>
				</li>
			</ul>

			<NcEmptyContent v-else-if="searched"
				:name="t('pipelinq', 'No results. Try a different search.')" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { searchContacts } from '../services/posCustomer.js'

const DEBOUNCE_MS = 300
const MIN_QUERY = 2

export default {
	name: 'CustomerLookupModal',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcNoteCard,
	},
	emits: ['close', 'select'],
	data() {
		return {
			query: '',
			results: [],
			loading: false,
			error: false,
			searched: false,
			debounceTimer: null,
		}
	},
	mounted() {
		this.$nextTick(() => {
			this.$refs.search?.$el?.querySelector('input')?.focus()
		})
	},
	beforeDestroy() {
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},
	methods: {
		/**
		 * Debounce the search on input change.
		 *
		 * @param {string} value The new query value.
		 */
		onQueryInput(value) {
			this.query = value
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			if (String(value).trim().length < MIN_QUERY) {
				this.results = []
				this.searched = false
				return
			}
			this.debounceTimer = setTimeout(this.search, DEBOUNCE_MS)
		},
		/**
		 * Run the contact search.
		 */
		async search() {
			if (this.query.trim().length < MIN_QUERY) {
				return
			}
			this.loading = true
			this.error = false
			try {
				this.results = await searchContacts(this.query.trim(), 20)
				this.searched = true
			} catch (e) {
				this.error = true
				this.results = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Clear the query and results.
		 */
		clearQuery() {
			this.query = ''
			this.results = []
			this.searched = false
		},
		/**
		 * Emit the selected contact and close.
		 *
		 * @param {object} contact The chosen contact.
		 */
		select(contact) {
			if (contact.doNotContact) {
				showError(t('pipelinq', 'This customer does not wish to be contacted.'))
			}
			this.$emit('select', contact)
			this.$emit('close')
		},
		/**
		 * Format an ISO date as DD-MM-YYYY.
		 *
		 * @param {string} iso The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(iso) {
			const d = new Date(iso)
			if (Number.isNaN(d.getTime())) {
				return iso
			}
			const pad = (n) => String(n).padStart(2, '0')
			return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()}`
		},
	},
}
</script>

<style scoped>
.customer-lookup {
	min-height: 200px;
}

.customer-lookup__spinner {
	margin: 24px auto;
}

.customer-lookup__results {
	margin-top: 12px;
	max-height: 320px;
	overflow-y: auto;
}

.customer-lookup__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.customer-lookup__row:hover,
.customer-lookup__row:focus {
	background-color: var(--color-background-hover);
}

.customer-lookup__name {
	font-weight: bold;
}

.customer-lookup__flag {
	margin-inline-start: 8px;
	font-weight: normal;
	color: var(--color-error);
	font-size: 12px;
}

.customer-lookup__meta {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.customer-lookup__retry {
	margin-top: 8px;
}
</style>
