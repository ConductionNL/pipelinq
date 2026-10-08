<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Picks a service account: the Nextcloud account a public endpoint (the
  - customer portal, the SMS and WhatsApp webhooks) writes as. It shows the
  - account in use, warns when it is unset, unknown, disabled or outside its
  - group, and lets an admin choose one. The endpoint answers GET and PUT with
  - {userId, usable, reason, group}; both are admin only. The parent passes the
  - translated texts, so each screen keeps its own words.
  -
  - @spec exclude shared admin control for the portal and messaging service accounts;
  -   no requirement owns the acting identity
-->
<template>
	<fieldset class="service-account">
		<legend>{{ legend }}</legend>
		<p class="service-account__help">
			{{ help(account.group) }}
		</p>
		<NcNoteCard v-if="!account.usable" type="warning">
			{{ problem }}
		</NcNoteCard>
		<div class="service-account__row">
			<div class="service-account__field">
				<label :for="inputId">{{
					t('pipelinq', 'Nextcloud user name')
				}}</label>
				<input
					:id="inputId"
					v-model.trim="input"
					type="text"
					autocomplete="off"
					@keyup.enter="save" />
			</div>
			<div class="service-account__field service-account__field--action">
				<NcButton :disabled="saving || !input" @click="save">
					{{ t('pipelinq', 'Use this account') }}
				</NcButton>
			</div>
		</div>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</fieldset>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ServiceAccountPicker',
	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		/** The admin endpoint that answers GET and PUT. */
		url: { type: String, required: true },
		/** The id of the user name input. */
		inputId: { type: String, required: true },
		/** The fieldset legend. */
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

			input: '',
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
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
				this.input = this.account.userId || ''
			} catch {
				this.message = this.loadError
				this.messageType = 'error'
			}
		},

		/**
		 * Use the typed account.
		 *
		 * @spec exclude shared admin control for the portal and messaging service accounts;
		 *   no requirement owns the acting identity
		 */
		async save() {
			this.saving = true
			this.message = ''
			try {
				const response = await axios.put(this.url, { userId: this.input })
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
	gap: 8px;
	margin: 0 0 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.service-account legend {
	padding: 0 4px;
	font-weight: bold;
}

.service-account__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.service-account__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
}

.service-account__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 240px;
}

.service-account__field--action {
	min-width: auto;
}
</style>
