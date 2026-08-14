<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="loyalty-enrollment">
		<h2>{{ t('pipelinq', 'Loyalty enrollment') }}</h2>
		<p>
			{{
				t(
					'pipelinq',
					'Enroll a customer in a loyalty programme. Customer opt-in is mandatory under AVG/GDPR.',
				)
			}}
		</p>

		<form @submit.prevent="enroll">
			<NcTextField
				v-model="klantId"
				:label="t('pipelinq', 'Customer (klantId / contact UID)')"
				required />

			<NcSelect
				v-model="selectedProgramme"
				:input-label="t('pipelinq', 'Programme')"
				:options="programmeOptions"
				label="label"
				:clearable="false" />

			<NcTextField
				v-model="termsVersion"
				:label="t('pipelinq', 'Terms version accepted')" />

			<label class="loyalty-enrollment__opt-in">
				<NcCheckboxRadioSwitch v-model="optInAccepted">
					{{
						t(
							'pipelinq',
							'I agree to store my loyalty data and contact me with offers',
						)
					}}
				</NcCheckboxRadioSwitch>
			</label>

			<p class="loyalty-enrollment__terms">
				<a
					v-if="termsUrl"
					:href="termsUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('pipelinq', 'Read the programme terms') }}
				</a>
			</p>

			<NcButton variant="primary" type="submit" :disabled="!canSubmit">
				{{ t('pipelinq', 'Create loyalty account') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="result" type="success">
			{{
				t('pipelinq', 'Account created: {accountId}', {
					accountId: resultId,
				})
			}}
		</NcNoteCard>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'LoyaltyAccountCreation',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			klantId: '',
			selectedProgramme: null,
			programmes: [],
			optInAccepted: false,
			termsVersion: '1.0',
			result: null,
		}
	},
	computed: {
		programmeOptions() {
			return this.programmes.map((p) => ({
				id: p.id,
				label: p.naam || p.id,
				termsUrl: p.termsUrl,
			}))
		},
		termsUrl() {
			return this.selectedProgramme && this.selectedProgramme.termsUrl
		},
		canSubmit() {
			return this.optInAccepted && this.klantId && this.selectedProgramme
		},
		resultId() {
			if (!this.result) {
				return ''
			}
			return (
				(this.result['@self'] && this.result['@self'].id)
				|| this.result.accountId
				|| ''
			)
		},
	},
	mounted() {
		this.loadProgrammes()
	},
	methods: {
		async loadProgrammes() {
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/pipelinq/loyaltyProgramme?_limit=200',
					),
				)
				const list =
					(response.data && (response.data.results || response.data)) || []
				this.programmes = list.map((p) => ({
					id: p['@self']?.id || p.id || p.programmeId,
					naam: p.naam,
					termsUrl: p.termsUrl,
				}))
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to load programmes'))
			}
		},
		async enroll() {
			if (!this.canSubmit) {
				return
			}

			try {
				// Create the account via OR /objects, with opt-in fields set.
				const payload = {
					klantId: this.klantId,
					programmeId: this.selectedProgramme.id,
					currentBalance: 0,
					lifetimePoints: 0,
					status: 'actief',
					optInAccepted: true,
					optInTimestamp: new Date().toISOString(),
					optInTermsVersion: this.termsVersion,
					aangemaaktOp: new Date().toISOString(),
					lastActivityDate: new Date().toISOString(),
				}
				const response = await axios.post(
					generateUrl(
						'/apps/openregister/api/objects/pipelinq/klantLoyaltyAccount',
					),
					payload,
				)
				this.result = response.data
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to create loyalty account'))
			}
		},
	},
}
</script>

<style scoped>
.loyalty-enrollment {
	padding: 1rem;
	max-width: 640px;
}

.loyalty-enrollment__opt-in {
	display: block;
	margin: 1rem 0;
}

.loyalty-enrollment__terms {
	font-size: 0.85rem;
}
</style>
