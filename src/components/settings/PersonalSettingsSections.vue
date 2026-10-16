<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The app's own sections of the user settings dialog (board PqPersoonlijk):
  - the sync with Contacts (the reader's own address book and write-back
  - choice, kept per user), mail and calendar, the reader's skills and their
  - availability. The last three show what is set and link to where it is
  - changed.
  -->
<template>
	<div class="personal-settings">
		<NcAppSettingsSection
			id="pipelinq-contacts-sync"
			:name="t('pipelinq', 'Sync with Contacts')">
			<p class="personal-settings__lead">
				{{ t('pipelinq', 'Your records in Pipelinq and your Nextcloud address book.') }}
			</p>
			<NcSelect
				v-model="addressBook"
				:inputLabel="t('pipelinq', 'Address book')"
				:options="addressBookOptions"
				:reduce="(option) => option.key"
				label="name"
				:clearable="false"
				data-testid="personal-settings-address-book"
				@update:modelValue="saveContactsSync" />
			<p class="personal-settings__hint">
				{{ t('pipelinq', 'New cards go here. Other address books are only read.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-model="writeBack"
				type="switch"
				data-testid="personal-settings-write-back"
				@update:modelValue="saveContactsSync">
				{{ t('pipelinq', 'Write changes back to Nextcloud Contacts') }}
			</NcCheckboxRadioSwitch>
			<p class="personal-settings__hint">
				{{
					t(
						'pipelinq',
						'Save a resident, company or contact and the card is updated too. The latest change wins.',
					)
				}}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection
			id="pipelinq-mail-calendar"
			:name="t('pipelinq', 'Email and calendar')">
			<p class="personal-settings__lead">
				{{ t('pipelinq', 'Mail and appointments linked to your clients.') }}
			</p>
			<router-link
				class="personal-settings__link"
				:to="{ name: 'SyncSettings' }">
				{{ t('pipelinq', 'Open the sync settings') }}
			</router-link>
		</NcAppSettingsSection>

		<NcAppSettingsSection
			id="pipelinq-skills"
			:name="t('pipelinq', 'My skills')">
			<ul v-if="skills.length > 0" class="personal-settings__skills" data-testid="personal-settings-skills">
				<li v-for="skill in skills" :key="skill">
					<CnStatusBadge :label="skill" variant="info" size="small" />
				</li>
			</ul>
			<p class="personal-settings__lead">
				{{
					t(
						'pipelinq',
						'Skills decide which tickets are suggested to you. An administrator sets them on your agent profile.',
					)
				}}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection
			id="pipelinq-availability"
			:name="t('pipelinq', 'Availability')">
			<p class="personal-settings__lead" data-testid="personal-settings-availability">
				<strong>{{ isAvailable ? t('pipelinq', 'Available.') : t('pipelinq', 'Not available.') }}</strong>
				{{
					t(
						'pipelinq',
						'Set it at the top of the dashboard. When you are not available, calls and the queue pass you by.',
					)
				}}
			</p>
			<router-link
				class="personal-settings__link"
				:to="{ name: 'KccWerkplek' }">
				{{ t('pipelinq', 'Dashboard') }}
			</router-link>
		</NcAppSettingsSection>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcAppSettingsSection, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'

export default {
	name: 'PersonalSettingsSections',
	components: {
		CnStatusBadge,
		NcAppSettingsSection,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	data() {
		return {
			isAvailable: false,
			skills: [],
			addressBook: '',
			addressBooks: [],
			writeBack: true,
		}
	},

	computed: {
		/**
		 * The address books to pick from, the first writable one when none is chosen.
		 *
		 * @return {Array<{key: string, name: string}>}
		 */
		addressBookOptions() {
			return [
				{ key: '', name: this.t('pipelinq', 'The first address book I can write in') },
				...this.addressBooks,
			]
		},
	},

	created() {
		this.load()
		this.loadContactsSync()
	},

	methods: {
		/**
		 * Read the reader's contacts sync choices and the address books on offer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		async loadContactsSync() {
			try {
				const res = await axios.get(generateUrl('/apps/pipelinq/api/contacts-sync/settings'))
				this.applyContactsSync(res.data)
			} catch {
				this.addressBooks = []
			}
		},

		/**
		 * Save the reader's contacts sync choices.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		async saveContactsSync() {
			try {
				const res = await axios.put(generateUrl('/apps/pipelinq/api/contacts-sync/settings'), {
					addressBook: this.addressBook,
					writeBack: this.writeBack,
				})
				this.applyContactsSync(res.data)
			} catch {
				showError(this.t('pipelinq', 'Could not save the sync with Contacts.'))
			}
		},

		/**
		 * Take the server's answer as the shown state.
		 *
		 * @param {object} data The preferences: addressBook, writeBack, addressBooks.
		 * @spec openspec/specs/contacts-sync/spec.md
		 */
		applyContactsSync(data) {
			this.addressBooks = Array.isArray(data?.addressBooks) ? data.addressBooks : []
			this.addressBook = typeof data?.addressBook === 'string' ? data.addressBook : ''
			this.writeBack = data?.writeBack !== false
		},

		/**
		 * Read the reader's agent profile: availability and skills. A failure
		 * leaves the sections with their text and no values.
		 *
		 * @return {Promise<void>}
		 * @spec exclude read-only summary of the agent profile in the user settings dialog
		 */
		async load() {
			try {
				const res = await axios.get(
					generateUrl('/apps/pipelinq/api/kcc-werkplek/state'),
				)
				const profile = (res.data && res.data.agentProfile) || {}
				this.isAvailable = Boolean(profile.isAvailable)
				this.skills = Array.isArray(profile.skills)
					? profile.skills.filter((skill) => typeof skill === 'string' && skill !== '')
					: []
			} catch {
				this.skills = []
			}
		},
	},
}
</script>

<style scoped>
.personal-settings__lead {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.personal-settings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 4px 0 12px;
}

.personal-settings__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.personal-settings__skills {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}
</style>
