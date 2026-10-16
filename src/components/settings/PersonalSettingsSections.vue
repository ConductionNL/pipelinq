<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The app's own sections of the user settings dialog (board PqPersoonlijk):
  - mail and calendar, the reader's skills and their availability. Each one
  - shows what is set and links to where it is changed; nothing is edited here
  - that has a home elsewhere.
  -->
<template>
	<div class="personal-settings">
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
import { generateUrl } from '@nextcloud/router'
import { NcAppSettingsSection } from '@nextcloud/vue'

export default {
	name: 'PersonalSettingsSections',
	components: {
		CnStatusBadge,
		NcAppSettingsSection,
	},

	data() {
		return {
			isAvailable: false,
			skills: [],
		}
	},

	created() {
		this.load()
	},

	methods: {
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
