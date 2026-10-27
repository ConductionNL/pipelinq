<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The "Contacts" section of pipelinq's user settings dialog (board
  - PqPersoonlijk): how many records are linked to Nextcloud Contacts, the
  - addressbook new cards go to, the user's write-back switch, the last full
  - run, and the buttons to run it now or import from Contacts.
  -
  - @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
  -->
<template>
	<NcAppSettingsSection
		id="pipelinq-contacts-sync"
		:name="t('pipelinq', 'Contacts')">
		<p class="contacts-sync__description">
			{{ t('pipelinq', 'Your records in Pipelinq and your Nextcloud addressbook.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="contacts-sync">
			<dl class="contacts-sync__facts" data-testid="contacts-sync-status">
				<dt>{{ t('pipelinq', 'Status') }}</dt>
				<dd>{{ status.lastRun ? t('pipelinq', 'Synced') : t('pipelinq', 'Not synced yet') }}</dd>
				<dt>{{ t('pipelinq', 'Linked') }}</dt>
				<dd>{{ t('pipelinq', '{count} residents, businesses and contacts', { count: status.linked }) }}</dd>
				<dt>{{ t('pipelinq', 'Last time') }}</dt>
				<dd>{{ lastRunText }}</dd>
				<dt>{{ t('pipelinq', 'Addressbook') }}</dt>
				<dd>{{ status.addressBook || t('pipelinq', 'No addressbook to write to') }}</dd>
			</dl>
			<p class="contacts-sync__hint">
				{{ t('pipelinq', 'New cards go here. Other addressbooks are only read.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="status.writeBack"
				type="switch"
				:disabled="saving"
				data-testid="contacts-sync-write-back"
				@update:modelValue="setWriteBack">
				{{ t('pipelinq', 'Write changes back to Nextcloud Contacts') }}
			</NcCheckboxRadioSwitch>
			<p class="contacts-sync__hint">
				{{ t('pipelinq', 'When you save a resident, business or contact, the card gets the change too.') }}
			</p>

			<NcNoteCard
				v-if="failedNames.length"
				type="warning"
				data-testid="contacts-sync-failed">
				{{ t('pipelinq', 'Could not be written: {names}', { names: failedNames.join(', ') }) }}
			</NcNoteCard>

			<div class="contacts-sync__actions">
				<NcButton
					:disabled="running"
					data-testid="contacts-sync-run"
					@click="run">
					{{ t('pipelinq', 'Sync now') }}
				</NcButton>
				<NcButton
					variant="secondary"
					data-testid="contacts-sync-import"
					@click="importing = true">
					{{ t('pipelinq', 'Import from Nextcloud Contacts') }}
				</NcButton>
			</div>
		</div>

		<ContactImportDialog
			v-if="importing"
			importType="contact"
			@imported="load"
			@close="importing = false" />
	</NcAppSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppSettingsSection,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import ContactImportDialog from '../../dialogs/ContactImportDialog.vue'

const BASE = '/apps/pipelinq/api/contacts-sync'

export default {
	name: 'ContactsSyncSettingsSection',
	components: {
		ContactImportDialog,
		NcAppSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			saving: false,
			running: false,
			importing: false,
			status: { writeBack: true, linked: 0, addressBook: '', lastRun: null },
		}
	},

	computed: {
		/**
		 * When the last full run happened and how many cards it wrote, or Never.
		 *
		 * @return {string}
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		lastRunText() {
			const run = this.status.lastRun
			if (!run || !run.at) {
				return this.t('pipelinq', 'Never')
			}
			const when = new Date(run.at)
			const shown = Number.isNaN(when.getTime())
				? run.at
				: when.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
			return this.t('pipelinq', '{when}, {count} cards updated', { when: shown, count: Number(run.updated) || 0 })
		},

		/**
		 * The records whose card the last run could not write.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		failedNames() {
			const failed = this.status.lastRun && Array.isArray(this.status.lastRun.failed) ? this.status.lastRun.failed : []
			return failed.map((entry) => entry && entry.name).filter((name) => name)
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl(`${BASE}/settings`))
				this.applyStatus(response.data)
			} catch {
				showError(this.t('pipelinq', 'Could not load the contacts settings.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} data A status response.
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		applyStatus(data) {
			const status = data || {}
			this.status = {
				writeBack: status.writeBack !== false,
				linked: Number(status.linked) || 0,
				addressBook: status.addressBook || '',
				lastRun: status.lastRun || null,
			}
		},

		/**
		 * Save the write-back switch; the server answers with the status.
		 *
		 * @param {boolean} value Whether saves go to Nextcloud Contacts.
		 * @return {Promise<void>}
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		async setWriteBack(value) {
			this.saving = true
			try {
				const response = await axios.put(generateUrl(`${BASE}/settings`), { writeBack: value === true })
				this.applyStatus(response.data)
			} catch {
				showError(this.t('pipelinq', 'Could not save the contacts settings.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Write every linked record back now, then read the status again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
		 */
		async run() {
			this.running = true
			try {
				await axios.post(generateUrl(`${BASE}/run`))
				const response = await axios.get(generateUrl(`${BASE}/settings`))
				this.applyStatus(response.data)
			} catch {
				showError(this.t('pipelinq', 'Could not sync your contacts.'))
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.contacts-sync {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.contacts-sync__description,
.contacts-sync__hint {
	color: var(--color-text-maxcontrast);
}

.contacts-sync__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.contacts-sync__facts dd {
	margin: 0;
}

.contacts-sync__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
