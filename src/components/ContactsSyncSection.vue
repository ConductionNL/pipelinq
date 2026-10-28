<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  ContactsSyncSection: the record's card in Nextcloud Contacts. Whether the
  card exists, which address book holds it and when it last changed, with a
  button that writes the record to the card again.

  @spec openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
-->
<template>
	<div class="contacts-sync" data-testid="contacts-sync">
		<NcLoadingIcon v-if="loading" :size="20" />
		<template v-else>
			<CnStatusBadge
				:label="status.linked ? t('pipelinq', 'Synced with Contacts') : t('pipelinq', 'Not in Contacts')"
				:variant="status.linked ? 'success' : 'default'"
				size="small" />
			<p class="contacts-sync__line" data-testid="contacts-sync-line">
				{{ line }}
			</p>
			<NcButton
				variant="secondary"
				:disabled="writing"
				data-testid="contacts-sync-update"
				@click="update">
				<template #icon>
					<Refresh :size="16" />
				</template>
				{{ t('pipelinq', 'Update in Contacts') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { cardStatus, writeBack } from '../services/contactSyncApi.js'

export default {
	name: 'ContactsSyncSection',

	components: { CnStatusBadge, NcButton, NcLoadingIcon, Refresh },

	props: {
		/** The record id. */
		entityId: { type: String, required: true },
		/** `client` or `contact`. */
		entityType: { type: String, default: 'contact' },
		/** The record's address book card UID. */
		contactsUid: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			writing: false,
			status: { linked: false, addressBook: '', updatedAt: null },
		}
	},

	computed: {
		/**
		 * The card in one line: its address book and its last change.
		 *
		 * @return {string} The line.
		 */
		line() {
			if (!this.status.linked) {
				return this.t('pipelinq', 'This record has no card in an address book yet.')
			}
			const when = this.whenText(this.status.updatedAt)
			if (when === '') {
				return this.t('pipelinq', 'Card in the address book {book}.', { book: this.status.addressBook })
			}
			return this.t('pipelinq', 'Card in the address book {book}, updated {when}.', { book: this.status.addressBook, when })
		},
	},

	watch: {
		contactsUid: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the card's status.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				this.status = this.contactsUid ? await cardStatus(this.contactsUid) : { linked: false, addressBook: '', updatedAt: null }
			} catch {
				this.status = { linked: false, addressBook: '', updatedAt: null }
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write the record to its card, then read the status again.
		 *
		 * @return {Promise<void>}
		 */
		async update() {
			this.writing = true
			try {
				await writeBack(this.entityType, this.entityId)
				await this.load()
			} finally {
				this.writing = false
			}
		},

		/**
		 * "today 09:12" for a moment today, else the date.
		 *
		 * @param {string|null} iso The moment.
		 * @return {string} The text, or '' without a moment.
		 */
		whenText(iso) {
			const date = iso ? new Date(iso) : null
			if (!date || Number.isNaN(date.getTime())) {
				return ''
			}
			const language = getLanguage()
			const now = new Date()
			if (date.toDateString() === now.toDateString()) {
				const time = date.toLocaleTimeString(language, { hour: '2-digit', minute: '2-digit' })
				return this.t('pipelinq', 'today {time}', { time })
			}
			return date.toLocaleDateString(language, { day: 'numeric', month: 'short', year: 'numeric' })
		},
	},
}
</script>

<style scoped>
.contacts-sync {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.contacts-sync__line {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}
</style>
