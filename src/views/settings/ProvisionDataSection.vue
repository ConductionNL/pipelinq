<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Provision data, as an admin action rather than a setup wizard step
  - (pipelinq-setup-wizard-review). Runs the same `provision-register` setup
  - action the wizard used to run: it imports the register and schemas and
  - creates the default pipelines and skills. Safe to run more than once.
  -->
<template>
	<CnSettingsSection
		:name="t('pipelinq', 'Provision data')"
		:description="
			t(
				'pipelinq',
				'Create or repair the Pipelinq register, schemas, default pipelines and skills. Run it after you enable OpenRegister, or to repair a partial install. Running it twice creates no duplicates.',
			)
		">
		<NcButton variant="secondary" :disabled="running" @click="provision">
			<template #icon>
				<NcLoadingIcon v-if="running" :size="20" />
				<DatabaseRefreshOutline v-else :size="20" />
			</template>
			{{
				running
					? t('pipelinq', 'Provisioning…')
					: t('pipelinq', 'Provision data')
			}}
		</NcButton>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import DatabaseRefreshOutline from 'vue-material-design-icons/DatabaseRefreshOutline.vue'

export default {
	name: 'ProvisionDataSection',
	components: {
		CnSettingsSection,
		DatabaseRefreshOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			running: false,
			message: '',
			messageType: 'success',
		}
	},

	methods: {
		/**
		 * Run the `provision-register` setup action and show its answer.
		 *
		 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
		 */
		async provision() {
			this.running = true
			this.message = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/pipelinq/api/setup/action/provision-register',
					),
				)
				this.message = data?.message || t('pipelinq', 'Data provisioned.')
				this.messageType = data?.success ? 'success' : 'error'
			} catch (e) {
				this.message =
					e.response?.data?.message
					|| t('pipelinq', 'Provisioning failed.')
				this.messageType = 'error'
			} finally {
				this.running = false
			}
		},
	},
}
</script>
