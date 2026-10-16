<template>
	<CnDialog
		:name="t('pipelinq', 'Import from Nextcloud Contacts')"
		:eyebrow="t('pipelinq', 'Contacts · import')"
		:subtitle="t('pipelinq', 'Choose people from your address books to add as a contact')"
		size="normal"
		width="form"
		:noClose="importing"
		@closing="$emit('close')">
		<div class="import-dialog">
			<NcTextField
				:modelValue="query"
				:label="t('pipelinq', 'Search contacts')"
				:showTrailingButton="query !== ''"
				class="import-dialog__search"
				@update:modelValue="onSearch"
				@trailingButtonClick="onClearSearch">
				<template #icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>

			<NcLoadingIcon v-if="searching" />

			<template v-else-if="results.length > 0">
				<p class="import-dialog__count">
					{{ n('pipelinq', '%n card in your Nextcloud address books matches "{query}"', '%n cards in your Nextcloud address books match "{query}"', results.length, { query }) }}
				</p>
				<ul class="import-dialog__results">
					<li
						v-for="contact in results"
						:key="contact.uid"
						class="import-result">
						<NcAvatar
							:displayName="contact.name || t('pipelinq', 'Unknown')"
							:size="36"
							:disableMenu="true"
							:disableTooltip="true"
							:isNoUser="true" />
						<div class="import-result__info">
							<span class="import-result__name">{{ contact.name || t('pipelinq', 'Unknown') }}</span>
							<span class="import-result__meta">{{ metaLine(contact) }}</span>
						</div>
						<span
							v-if="contact.alreadyLinked"
							class="import-result__linked">
							{{ t('pipelinq', 'Already linked') }}
						</span>
						<NcButton
							v-else
							:pressed="isSelected(contact)"
							:disabled="importing"
							variant="secondary"
							@update:pressed="toggle(contact)">
							<template #icon>
								<AccountPlusOutline :size="20" />
							</template>
							{{ t('pipelinq', 'Import') }}
						</NcButton>
					</li>
				</ul>
			</template>

			<p
				v-else-if="query.length >= 2"
				class="import-dialog__count">
				{{ t('pipelinq', 'No contacts found') }}
			</p>

			<NcNoteCard type="info" class="import-dialog__note">
				{{ t('pipelinq', 'Importing creates a contact in Pipelinq and links it to the card, so later changes go both ways. Type at least 2 characters to search your Nextcloud contacts.') }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="secondary" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="selected.length === 0 || importing"
				@click="importSelected">
				<template #icon>
					<AccountPlusOutline :size="20" />
				</template>
				{{ n('pipelinq', 'Import %n contact', 'Import %n contacts', selected.length) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar, NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'ContactImportDialog',
	components: {
		AccountPlusOutline,
		Magnify,
		NcAvatar,
		CnDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		importType: {
			type: String,
			default: 'client',
			validator: (v) => ['client', 'contact'].includes(v),
		},

		clientId: {
			type: String,
			default: null,
		},
	},

	emits: ['close', 'imported'],

	setup() {
		return { t, n }
	},

	data() {
		return {
			query: '',
			results: [],
			selected: [],
			searching: false,
			importing: false,
			searchTimeout: null,
		}
	},

	methods: {
		/**
		 * @param {string} value The search term typed by the user.
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-3
		 */
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

		/**
		 * Clear the search box and its results.
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		onClearSearch() {
			this.query = ''
			this.results = []
		},

		/**
		 * The line under a card's name: its e-mail and phone, or Unknown when it has neither.
		 *
		 * @param {object} contact An addressbook search result.
		 * @return {string} The meta line.
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		metaLine(contact) {
			const parts = [contact.email, contact.phone].filter(Boolean)
			return parts.length > 0 ? parts.join(' · ') : t('pipelinq', 'Unknown')
		},

		/**
		 * @param {object} contact An addressbook search result.
		 * @return {boolean} Whether the card is marked for import.
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		isSelected(contact) {
			return this.selected.includes(contact.uid)
		},

		/**
		 * Mark or unmark a card for import.
		 *
		 * @param {object} contact An addressbook search result.
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		toggle(contact) {
			this.selected = this.isSelected(contact)
				? this.selected.filter((uid) => uid !== contact.uid)
				: [...this.selected, contact.uid]
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-1
		 */
		async doSearch() {
			this.searching = true
			try {
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/contacts-sync/search?q=${encodeURIComponent(this.query)}`,
					),
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

		/**
		 * Import every marked card, then close.
		 *
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		async importSelected() {
			this.importing = true
			const marked = this.results.filter((contact) => this.isSelected(contact))
			for (const contact of marked) {
				await this.importContact(contact)
			}
			this.importing = false
			this.selected = []
			this.$emit('close')
		},

		/**
		 * @param {object} contact The addressbook contact to import.
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-2
		 */
		async importContact(contact) {
			try {
				const body = {
					uid: contact.uid,
					addressBookKey: contact.addressBookKey,
					type: this.importType,
				}
				if (this.clientId) {
					body.clientId = this.clientId
				}

				const response = await fetch(
					generateUrl('/apps/pipelinq/api/contacts-sync/import'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body),
					},
				)

				if (response.ok) {
					const data = await response.json()
					contact.alreadyLinked = true
					this.$emit('imported', data.object)
				}
			} catch {
				// A failed import leaves the card unlinked; the user can retry.
			}
		},
	},
}
</script>

<style scoped>
.import-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.import-dialog__count {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.import-dialog__results {
	display: flex;
	flex-direction: column;
	max-height: 400px;
	overflow-y: auto;
	border-top: 1px solid var(--color-border);
}

.import-result {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.import-result__info {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.import-result__name {
	font-weight: 600;
}

.import-result__meta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.import-result__linked {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-success-hover, var(--color-background-hover));
	color: var(--color-success-text, var(--color-success));
	font-size: 13px;
	font-weight: 600;
}

.import-dialog__note {
	margin: 0;
}
</style>
