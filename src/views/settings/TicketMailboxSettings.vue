<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - TicketMailboxSettings lets an admin name the Nextcloud Mail accounts whose
  - inbox opens tickets in the Queue (requests-inbound-to-queue D4). A team
  - mailbox is added as a Mail account first, for example on a functional user,
  - with background sync on; it then shows here to be ticked.
  -
  - @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
  -->
<template>
	<NcSettingsSection
		id="section-ticket-mailboxes"
		:name="t('pipelinq', 'Ticket mailboxes')"
		:description="
			t(
				'pipelinq',
				'New mail in the inbox of a ticket mailbox becomes a ticket in the Queue. Add the team mailbox as a Mail account and switch on background sync for it, then tick it here.',
			)
		">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<p v-if="accounts.length === 0" class="ticket-mailboxes__empty">
				{{ t('pipelinq', 'There are no Mail accounts yet.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-for="account in accounts"
				:key="account.id"
				:modelValue="selected.includes(account.id)"
				@update:modelValue="toggle(account.id, $event)">
				{{ label(account) }}
			</NcCheckboxRadioSwitch>
			<NcButton
				variant="primary"
				:disabled="saving || accounts.length === 0"
				@click="save">
				{{ t('pipelinq', 'Save ticket mailboxes') }}
			</NcButton>
			<NcNoteCard v-if="saved" type="success">
				{{ t('pipelinq', 'The ticket mailboxes are saved.') }}
			</NcNoteCard>
		</template>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
} from '@nextcloud/vue'

export default {
	name: 'TicketMailboxSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		return {
			accounts: [],
			selected: [],
			loading: true,
			saving: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The admin endpoint that lists the Mail accounts and stores the choice.
		 *
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
		 */
		url() {
			return generateUrl('/apps/pipelinq/api/admin/mail-intake/accounts')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the Mail accounts and which are ticket mailboxes.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.url)
				this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
				this.selected = Array.isArray(data?.selected) ? data.selected : []
			} catch {
				this.error = t('pipelinq', 'Could not load the Mail accounts.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Tick or untick one account.
		 *
		 * @param {number} id The Mail account id.
		 * @param {boolean} on Whether it is a ticket mailbox.
		 * @return {void}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
		 */
		toggle(id, on) {
			this.saved = false
			const rest = this.selected.filter((value) => value !== id)
			this.selected = on ? [...rest, id] : rest
		},

		/**
		 * Store the ticked accounts; the server keeps only ids it knows.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(this.url, { accounts: this.selected })
				this.selected = Array.isArray(data?.selected) ? data.selected : []
				this.saved = true
			} catch {
				this.error = t('pipelinq', 'Could not save the ticket mailboxes.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * How one account reads in the list: address, then owner.
		 *
		 * @param {{address: string, owner: string}} account The Mail account.
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
		 */
		label(account) {
			return t('pipelinq', '{address} (account of {owner})', {
				address: account.address,
				owner: account.owner,
			})
		},
	},
}
</script>

<style scoped>
.ticket-mailboxes__empty {
	color: var(--color-text-maxcontrast);
}
</style>
