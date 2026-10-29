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
						class="import-result"
						:class="{ 'import-result--selected': isSelected(contact) }">
						<span class="import-result__initials" aria-hidden="true">{{ initialsOf(contact.name || t('pipelinq', 'Unknown')) }}</span>
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
							:aria-pressed="isSelected(contact) ? 'true' : 'false'"
							:disabled="importing"
							variant="secondary"
							data-testid="import-result-toggle"
							@click="toggle(contact)">
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

			<p class="import-dialog__note" data-testid="import-dialog-note">
				<InformationOutline :size="16" class="import-dialog__note-icon" />
				{{ t('pipelinq', 'Importing creates a contact in Pipelinq and links it to the card, so later changes go both ways. Type at least 2 characters to search your Nextcloud contacts.') }}
			</p>
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
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { initialsOf } from '../services/userDisplayName.js'

export default {
	name: 'ContactImportDialog',
	components: {
		AccountPlusOutline,
		InformationOutline,
		Magnify,
		CnDialog,
		NcButton,
		NcLoadingIcon,
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

	/**
	 * Exposes the translation and initials helpers to the template.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	setup() {
		return { t, n, initialsOf }
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

/* A chosen card keeps its outlined Import button; the row carries the choice. */
.import-result--selected {
	background: var(--color-primary-element-light);
}

.import-result__initials {
	display: inline-flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: var(--color-background-hover);
	color: var(--cn-board-text-soft, #3d4047);
	font-size: 12px;
	font-weight: 700;
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
	background: var(--cn-board-success-tint, #e7f3ea);
	color: var(--cn-board-success-ink, #1e6b2a);
	font-size: 12px;
	font-weight: 600;
}

.import-dialog__note {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin: 0;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	color: var(--cn-board-text-soft, #3d4047);
	font-size: 14px;
}

.import-dialog__note-icon {
	flex: none;
	margin-top: 2px;
}
</style>
