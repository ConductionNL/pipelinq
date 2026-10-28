<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Picks a service account: the Nextcloud account a public endpoint (the
  - customer portal, the SMS and WhatsApp webhooks) writes as. It shows the
  - account in use, warns when it is unset, unknown, disabled or outside its
  - group, and lets an admin choose one from the accounts in a select. The endpoint answers GET and PUT with
  - {userId, usable, reason, group}; both are admin only. The parent passes the
  - translated texts, so each screen keeps its own words.
  -
  - @spec exclude shared admin control for the portal and messaging service accounts;
  -   no requirement owns the acting identity
-->
<template>
	<div class="service-account">
		<div class="service-account__field">
			<NcSelect
				:modelValue="selected"
				:inputId="inputId"
				:inputLabel="legend"
				:options="options"
				:clearable="false"
				:disabled="saving"
				@update:modelValue="save" />
			<p class="service-account__help">
				{{ help(account.group) }}
			</p>
		</div>
		<NcNoteCard v-if="status" :type="status.type">
			{{ status.text }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { listAssignableUserIds } from '../../services/userDisplayName.js'

export default {
	name: 'ServiceAccountPicker',
	components: {
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The admin endpoint that answers GET and PUT. */
		url: { type: String, required: true },
		/** The id of the account select's input. */
		inputId: { type: String, required: true },
		/** The select's label. */
		legend: { type: String, required: true },
		/** What the account does, given its group. */
		help: { type: Function, required: true },
		/** What stops working while there is no usable account. */
		consequence: { type: String, required: true },
		/** The confirmation after a save, given the account. */
		savedMessage: { type: Function, required: true },
		/** The error when the account cannot be loaded. */
		loadError: { type: String, required: true },
		/** The error when the account cannot be saved. */
		saveError: { type: String, required: true },
		/** The group to show before the endpoint answered. */
		defaultGroup: { type: String, required: true },
	},

	data() {
		return {
			account: {
				userId: '',
				usable: true,
				reason: null,
				group: this.defaultGroup,
			},

			selected: '',
			userIds: [],
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The accounts to choose from, the one in use first when the list lacks it.
		 *
		 * @return {Array<string>} The user ids.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		options() {
			const current = this.account.userId
			if (current && !this.userIds.includes(current)) {
				return [current, ...this.userIds]
			}
			return this.userIds
		},

		/**
		 * The note under the select: a failure, the problem with the account,
		 * or which account is in use.
		 *
		 * @return {{type: string, text: string}|null} The note, or null.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		status() {
			if (this.message && this.messageType === 'error') {
				return { type: 'error', text: this.message }
			}
			if (!this.account.usable) {
				return { type: 'warning', text: this.problem }
			}
			if (this.account.userId) {
				return { type: 'success', text: this.savedMessage(this.account.userId) }
			}
			return null
		},

		/**
		 * Why the account cannot be used, in words.
		 *
		 * @return {string} The problem.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		problem() {
			const reasons = {
				unknown: t('pipelinq', 'The chosen account does not exist.'),
				disabled: t('pipelinq', 'The chosen account is disabled.'),
				'not-in-group': t(
					'pipelinq',
					'The chosen account is not in the group {group}.',
					{ group: this.account.group },
				),
			}
			const why =
				reasons[this.account.reason]
				|| t('pipelinq', 'No account is chosen.')
			return why + ' ' + this.consequence
		},
	},

	mounted() {
		this.load()
		this.loadUserIds()
	},

	methods: {
		t,

		/**
		 * Load the account in use.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		async load() {
			try {
				const response = await axios.get(this.url)
				this.account = { ...this.account, ...(response.data || {}) }
				this.selected = this.account.userId || ''
			} catch {
				this.message = this.loadError
				this.messageType = 'error'
			}
		},

		/**
		 * Load the accounts an admin can choose from.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		async loadUserIds() {
			this.userIds = await listAssignableUserIds()
		},

		/**
		 * Use the chosen account.
		 *
		 * @param {string} userId The account.
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		async save(userId) {
			if (!userId) {
				return
			}
			this.selected = userId
			this.saving = true
			this.message = ''
			try {
				const response = await axios.put(this.url, { userId })
				this.account = { ...this.account, ...(response.data || {}) }
				this.message = this.savedMessage(this.account.userId)
				this.messageType = 'success'
			} catch (error) {
				this.message = error?.response?.data?.message || this.saveError
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.service-account {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
}

.service-account__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.service-account__help {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
