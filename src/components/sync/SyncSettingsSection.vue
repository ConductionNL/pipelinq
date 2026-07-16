<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Pipelinq per-user email-matching settings section (leaf-first
  - email-calendar-sync, ADR-022). Rendered inside NcAppSettingsDialog
  - (NOT a routed page — ADR-004). Pipelinq owns the matching rule;
  - the OpenRegister `email` leaf owns the link record.
  -
  - @spec openspec/specs/email-calendar-sync/spec.md
  - @spec openspec/specs/email-calendar-sync/spec.md
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Email matching')"
		:description="t('pipelinq', 'Periodically link Nextcloud Mail messages to your CRM clients, contacts, leads and requests. The mail itself stays in Nextcloud Mail — Pipelinq only stores the link via the OpenRegister email integration.')">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="sync-settings">
			<div class="sync-settings__field">
				<NcSelect
					v-model="form.account"
					:input-label="t('pipelinq', 'Nextcloud Mail account')"
					:options="accountOptions"
					:reduce="reduceAccount"
					:clearable="true"
					label="label"
					data-testid="sync-email-account" />
				<p class="sync-settings__hint">
					{{ t('pipelinq', 'Choose which Nextcloud Mail account the matcher reads from. Leaving this empty disables matching.') }}
				</p>
			</div>

			<div class="sync-settings__field">
				<NcCheckboxRadioSwitch
					:checked.sync="form.enabled"
					type="switch"
					data-testid="sync-email-enabled">
					{{ t('pipelinq', 'Enable email matching for this account') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="sync-settings__field">
				<label for="sync-email-exclude">{{ t('pipelinq', 'Excluded addresses (comma separated)') }}</label>
				<input
					id="sync-email-exclude"
					v-model="form.excludedText"
					type="text"
					autocomplete="off"
					data-testid="sync-email-exclude"
					:placeholder="t('pipelinq', 'noreply@example.org, mailer@example.org')">
				<p class="sync-settings__hint">
					{{ t('pipelinq', 'Messages with these sender or recipient addresses are skipped by the matcher.') }}
				</p>
			</div>

			<div class="sync-settings__actions">
				<NcButton
					type="primary"
					:disabled="saving"
					data-testid="sync-email-save"
					@click="save">
					{{ t('pipelinq', 'Save settings') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="triggering"
					data-testid="sync-email-trigger"
					@click="trigger">
					{{ t('pipelinq', 'Sync now') }}
				</NcButton>
				<p
					v-if="statusMessage"
					class="sync-settings__status"
					:class="{ 'sync-settings__status--error': statusError }"
					role="status">
					{{ statusMessage }}
				</p>
			</div>

			<div class="sync-settings__status-block">
				<h4>{{ t('pipelinq', 'Last run') }}</h4>
				<dl>
					<dt>{{ t('pipelinq', 'When') }}</dt>
					<dd data-testid="sync-email-status-when">
						{{ status.lastRunAt || t('pipelinq', 'Never') }}
					</dd>
					<dt>{{ t('pipelinq', 'Links created') }}</dt>
					<dd data-testid="sync-email-status-linked">
						{{ status.linked }}
					</dd>
					<dt>{{ t('pipelinq', 'Messages scanned') }}</dt>
					<dd data-testid="sync-email-status-scanned">
						{{ status.scanned }}
					</dd>
					<template v-if="status.error">
						<dt>{{ t('pipelinq', 'Last error') }}</dt>
						<dd
							class="sync-settings__status--error"
							data-testid="sync-email-status-error">
							{{ status.error }}
						</dd>
					</template>
				</dl>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'SyncSettingsSection',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
	},
	data() {
		return {
			loading: false,
			saving: false,
			triggering: false,
			statusMessage: '',
			statusError: false,
			form: {
				account: 0,
				enabled: false,
				excludedText: '',
			},
			status: {
				lastRunAt: null,
				linked: 0,
				scanned: 0,
				error: null,
			},
			accountOptions: [
				{ id: 0, label: this.t('pipelinq', 'None') },
			],
		}
	},
	async created() {
		await this.load()
	},
	methods: {
		/**
		 * Load the persisted matching settings + last-run status.
		 */
		async load() {
			this.loading = true
			try {
				const [settingsResp, statusResp] = await Promise.all([
					axios.get(generateUrl('/apps/pipelinq/api/sync/email/settings')),
					axios.get(generateUrl('/apps/pipelinq/api/sync/email/status')),
				])
				const settings = settingsResp.data || {}
				this.form.account = Number(settings.account) || 0
				this.form.enabled = settings.enabled === true
				const excluded = Array.isArray(settings.excludedAddresses) ? settings.excludedAddresses : []
				this.form.excludedText = excluded.join(', ')

				const statusData = statusResp.data || {}
				this.status = {
					lastRunAt: statusData.lastRunAt || null,
					linked: Number(statusData.linked) || 0,
					scanned: Number(statusData.scanned) || 0,
					error: statusData.error || null,
				}

				// Seed the account selector with the currently configured account
				// so the operator can keep it without listing Mail accounts (the
				// Mail account list lives in NC Mail — see the link in the dialog).
				if (this.form.account > 0) {
					this.accountOptions = [
						{ id: 0, label: this.t('pipelinq', 'None') },
						{ id: this.form.account, label: this.t('pipelinq', 'Account #{id}', { id: this.form.account }) },
					]
				}
			} catch (err) {
				this.statusMessage = this.t('pipelinq', 'Could not load matching settings.')
				this.statusError = true
			} finally {
				this.loading = false
			}
		},
		/**
		 * Persist the form.
		 */
		async save() {
			this.saving = true
			this.statusMessage = ''
			this.statusError = false
			try {
				const excluded = (this.form.excludedText || '')
					.split(/[,;\s]+/)
					.map(s => s.trim())
					.filter(s => s.length > 0)
				const payload = {
					account: Number(this.form.account) || 0,
					enabled: !!this.form.enabled,
					excludedAddresses: excluded,
				}
				const resp = await axios.post(
					generateUrl('/apps/pipelinq/api/sync/email/settings'),
					payload,
				)
				const settings = resp.data || {}
				this.form.account = Number(settings.account) || 0
				this.form.enabled = settings.enabled === true
				const excludedOut = Array.isArray(settings.excludedAddresses) ? settings.excludedAddresses : []
				this.form.excludedText = excludedOut.join(', ')
				this.statusMessage = this.t('pipelinq', 'Matching settings saved.')
			} catch (err) {
				this.statusMessage = this.t('pipelinq', 'Could not save matching settings.')
				this.statusError = true
			} finally {
				this.saving = false
			}
		},
		/**
		 * Run the matcher once for the current user.
		 */
		async trigger() {
			this.triggering = true
			this.statusMessage = ''
			this.statusError = false
			try {
				const resp = await axios.post(generateUrl('/apps/pipelinq/api/sync/email/trigger'))
				const result = resp.data || {}
				const linked = Number(result.linked) || 0
				const scanned = Number(result.scanned) || 0
				this.statusMessage = this.t('pipelinq', '{linked} new links created across {scanned} messages.', { linked, scanned })
				// Reload the status block.
				const statusResp = await axios.get(generateUrl('/apps/pipelinq/api/sync/email/status'))
				const statusData = statusResp.data || {}
				this.status = {
					lastRunAt: statusData.lastRunAt || null,
					linked: Number(statusData.linked) || 0,
					scanned: Number(statusData.scanned) || 0,
					error: statusData.error || null,
				}
			} catch (err) {
				this.statusMessage = this.t('pipelinq', 'Could not run the matching job.')
				this.statusError = true
			} finally {
				this.triggering = false
			}
		},
		/**
		 * Map an NcSelect option to its stored numeric id.
		 *
		 * @param {object} option The selector option object.
		 * @return {number} The id to store in form.account.
		 */
		reduceAccount(option) {
			if (!option) {
				return 0
			}
			return Number(option.id) || 0
		},
	},
}
</script>

<style scoped>
.sync-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 640px;
}

.sync-settings__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sync-settings__field input[type='text'] {
	padding: 6px 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.sync-settings__hint {
	margin: 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.sync-settings__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.sync-settings__status {
	margin: 0;
	color: var(--color-success);
}

.sync-settings__status--error {
	color: var(--color-error);
}

.sync-settings__status-block dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.sync-settings__status-block dt {
	color: var(--color-text-maxcontrast);
}

.sync-settings__status-block dd {
	margin: 0;
}
</style>
