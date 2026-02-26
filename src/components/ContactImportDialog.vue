<template>
	<NcDialog
		:name="t('pipelinq', 'Import from Nextcloud Contacts')"
		size="normal"
		@closing="$emit('close')">
		<div class="import-dialog">
			<NcTextField
				:value="query"
				:label="t('pipelinq', 'Search contacts...')"
				:show-trailing-button="query !== ''"
				class="import-dialog__search"
				@update:value="onSearch"
				@trailing-button-click="query = ''; results = []" />

			<NcLoadingIcon v-if="searching" />

			<div v-else-if="results.length > 0" class="import-dialog__results">
				<div
					v-for="contact in results"
					:key="contact.uid"
					class="import-result">
					<div class="import-result__info">
						<span class="import-result__name">{{ contact.name || t('pipelinq', 'Unknown') }}</span>
						<span v-if="contact.email" class="import-result__email">{{ contact.email }}</span>
						<span v-if="contact.org" class="import-result__org">{{ contact.org }}</span>
					</div>
					<div class="import-result__action">
						<span v-if="contact.alreadyLinked" class="import-result__linked">
							{{ t('pipelinq', 'Already linked') }}
						</span>
						<NcButton
							v-else
							:disabled="importing === contact.uid"
							type="primary"
							@click="importContact(contact)">
							{{ importing === contact.uid ? t('pipelinq', 'Importing...') : t('pipelinq', 'Import') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div v-else-if="query.length >= 2 && !searching" class="import-dialog__empty">
				<p>{{ t('pipelinq', 'No contacts found') }}</p>
			</div>

			<div v-else class="import-dialog__hint">
				<p>{{ t('pipelinq', 'Type at least 2 characters to search your Nextcloud contacts') }}</p>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ContactImportDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},
	props: {
		importType: {
			type: String,
			default: 'client',
			validator: v => ['client', 'contact'].includes(v),
		},
		clientId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			query: '',
			results: [],
			searching: false,
			importing: null,
			searchTimeout: null,
		}
	},
	methods: {
		onSearch(value) {
			this.query = value
			clearTimeout(this.searchTimeout)
			if (value.length < 2) {
				this.results = []
				return
			}
			this.searchTimeout = setTimeout(() => {
				this.doSearch()
			}, 300)
		},

		async doSearch() {
			this.searching = true
			try {
				const response = await fetch(
					`/apps/pipelinq/api/contacts-sync/search?q=${encodeURIComponent(this.query)}`,
					{
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.results = data.results || []
				}
			} catch {
				this.results = []
			}
			this.searching = false
		},

		async importContact(contact) {
			this.importing = contact.uid
			try {
				const body = {
					uid: contact.uid,
					addressBookKey: contact.addressBookKey,
					type: this.importType,
				}
				if (this.clientId) {
					body.clientId = this.clientId
				}

				const response = await fetch('/apps/pipelinq/api/contacts-sync/import', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})

				if (response.ok) {
					const data = await response.json()
					contact.alreadyLinked = true
					this.$emit('imported', data.object)
				}
			} catch {
				// Import failed silently
			}
			this.importing = null
		},
	},
}
</script>

<style scoped>
.import-dialog {
	padding: 8px 0;
	min-height: 200px;
}

.import-dialog__search {
	margin-bottom: 16px;
}

.import-dialog__results {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 400px;
	overflow-y: auto;
}

.import-result {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.import-result__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.import-result__name {
	font-weight: 600;
	font-size: 14px;
}

.import-result__email {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.import-result__org {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.import-result__linked {
	font-size: 12px;
	color: var(--color-success);
	font-weight: 600;
}

.import-dialog__empty,
.import-dialog__hint {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
