<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The "Email and calendar" section of pipelinq's user settings dialog
  - (board PqSynchronisatie): the per-user email matching settings, read and
  - saved through /api/sync/email/*. The account choice lists only the
  - user's own Nextcloud Mail accounts; the server refuses any other.
  -
  - @spec openspec/specs/email-calendar-sync/spec.md
  -->
<template>
	<NcAppSettingsSection
		id="pipelinq-email-calendar"
		:name="t('pipelinq', 'Email and calendar')">
		<p class="email-calendar__description">
			{{ t('pipelinq', 'Mail and appointments linked to your customers. The mail itself stays in Nextcloud Mail.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="email-calendar">
			<NcCheckboxRadioSwitch
				:modelValue="form.enabled"
				type="switch"
				:disabled="saving"
				data-testid="email-sync-enabled"
				@update:modelValue="setEnabled">
				{{ t('pipelinq', 'Turn on email sync') }}
			</NcCheckboxRadioSwitch>
			<p class="email-calendar__hint">
				{{ t('pipelinq', 'Mail from the chosen account goes to the contact with the same email address.') }}
			</p>

			<div v-if="accounts.length" class="email-calendar__accounts" data-testid="email-sync-accounts">
				<NcCheckboxRadioSwitch
					v-for="account in accounts"
					:key="account.id"
					:modelValue="String(form.account)"
					:value="String(account.id)"
					type="radio"
					name="pipelinq-email-sync-account"
					:disabled="saving"
					@update:modelValue="setAccount">
					{{ account.email }}
					<span v-if="account.name" class="email-calendar__hint">{{ account.name }}</span>
				</NcCheckboxRadioSwitch>
			</div>
			<p v-else class="email-calendar__hint" data-testid="email-sync-no-accounts">
				{{ t('pipelinq', 'You have no Nextcloud Mail account yet.') }}
			</p>

			<NcTextField
				:modelValue="form.excludedText"
				:label="t('pipelinq', 'Skip, comma separated')"
				:helperText="t('pipelinq', 'Mail from or to these addresses is not linked.')"
				data-testid="email-sync-excluded"
				@update:modelValue="form.excludedText = $event"
				@blur="save" />

			<dl class="email-calendar__facts" data-testid="email-sync-status">
				<dt>{{ t('pipelinq', 'Last time') }}</dt>
				<dd>{{ lastRunText }}</dd>
				<dt>{{ t('pipelinq', 'Linked') }}</dt>
				<dd>{{ t('pipelinq', '{linked} of {scanned} mails in the last run', { linked: status.linked, scanned: status.scanned }) }}</dd>
				<dt>{{ t('pipelinq', 'Last error') }}</dt>
				<dd>{{ status.error || t('pipelinq', 'none') }}</dd>
			</dl>

			<p class="email-calendar__hint">
				{{ t('pipelinq', 'Pipelinq keeps the subject, sender and date. You read the content in Nextcloud Mail.') }}
			</p>

			<div>
				<NcButton
					:disabled="triggering || !form.enabled || !form.account"
					data-testid="email-sync-trigger"
					@click="trigger">
					{{ t('pipelinq', 'Sync now') }}
				</NcButton>
			</div>
		</div>
	</NcAppSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppSettingsSection,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'

const BASE = '/apps/pipelinq/api/sync/email'

export default {
	name: 'EmailCalendarSettingsSection',
	components: {
		NcAppSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			triggering: false,
			accounts: [],
			form: { account: 0, enabled: false, excludedText: '' },
			status: { lastRunAt: null, linked: 0, scanned: 0, error: null },
		}
	},

	computed: {
		/**
		 * When the last run happened, or Never.
		 *
		 * @return {string}
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		lastRunText() {
			if (!this.status.lastRunAt) {
				return this.t('pipelinq', 'Never')
			}
			const when = new Date(this.status.lastRunAt)
			return Number.isNaN(when.getTime())
				? this.status.lastRunAt
				: when.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the settings, the user's Mail accounts and the last run.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const [settings, accounts, status] = await Promise.all([
					axios.get(generateUrl(`${BASE}/settings`)),
					axios.get(generateUrl(`${BASE}/accounts`)),
					axios.get(generateUrl(`${BASE}/status`)),
				])
				this.applySettings(settings.data)
				this.accounts = Array.isArray(accounts.data?.accounts) ? accounts.data.accounts : []
				this.applyStatus(status.data)
			} catch {
				showError(this.t('pipelinq', 'Could not load the email settings.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} data A settings response.
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		applySettings(data) {
			const settings = data || {}
			this.form.account = Number(settings.account) || 0
			this.form.enabled = settings.enabled === true
			this.form.excludedText = (Array.isArray(settings.excludedAddresses) ? settings.excludedAddresses : []).join(', ')
		},

		/**
		 * @param {object} data A status response.
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		applyStatus(data) {
			const status = data || {}
			this.status = {
				lastRunAt: status.lastRunAt || null,
				linked: Number(status.linked) || 0,
				scanned: Number(status.scanned) || 0,
				error: status.error || null,
			}
		},

		/**
		 * @param {boolean} value Whether email sync is on.
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		setEnabled(value) {
			this.form.enabled = value === true
			if (this.form.enabled && !this.form.account && this.accounts.length === 1) {
				this.form.account = this.accounts[0].id
			}
			this.save()
		},

		/**
		 * @param {string} value The chosen account id.
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		setAccount(value) {
			this.form.account = Number(value) || 0
			this.save()
		},

		/**
		 * Save the settings; the server answers with what it stored.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		async save() {
			this.saving = true
			try {
				const excludedAddresses = (this.form.excludedText || '')
					.split(/[,;\s]+/)
					.map((address) => address.trim())
					.filter((address) => address !== '')
				const response = await axios.post(generateUrl(`${BASE}/settings`), {
					account: Number(this.form.account) || 0,
					enabled: this.form.enabled,
					excludedAddresses,
				})
				this.applySettings(response.data)
			} catch {
				showError(this.t('pipelinq', 'Could not save the email settings.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Run the matching now and show what it linked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/email-calendar-sync/spec.md
		 */
		async trigger() {
			this.triggering = true
			try {
				const response = await axios.post(generateUrl(`${BASE}/trigger`))
				const linked = Number(response.data?.linked) || 0
				const scanned = Number(response.data?.scanned) || 0
				showSuccess(this.t('pipelinq', '{linked} of {scanned} mails linked.', { linked, scanned }))
				const status = await axios.get(generateUrl(`${BASE}/status`))
				this.applyStatus(status.data)
			} catch {
				showError(this.t('pipelinq', 'Could not sync your email.'))
			} finally {
				this.triggering = false
			}
		},
	},
}
</script>

<style scoped>
.email-calendar {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.email-calendar__description,
.email-calendar__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.email-calendar__accounts {
	display: flex;
	flex-direction: column;
}

.email-calendar__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 8px 0 0;
}

.email-calendar__facts dt {
	color: var(--color-text-maxcontrast);
}

.email-calendar__facts dd {
	margin: 0;
}
</style>
