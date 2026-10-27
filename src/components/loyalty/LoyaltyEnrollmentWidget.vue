<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<CnFormWidgetBase
		blockClass="loyalty-enrollment-widget"
		:fields="fields"
		:model="form"
		:canSubmit="canSubmit"
		:submitting="saving"
		:submitLabel="t('pipelinq', 'Create loyalty account')"
		:submittingLabel="t('pipelinq', 'Creating…')"
		:errorMessage="errorMessage"
		@update:field="onFieldUpdate"
		@submit="enroll">
		<template #field-customerId>
			<CnResourceSelect
				register="pipelinq"
				:schema="clientSchema"
				labelField="name"
				:modelValue="form.customerId"
				:inputLabel="t('pipelinq', 'Customer')"
				:preload="true"
				:clearable="false"
				:allowCreate="false"
				@update:modelValue="(id) => onFieldUpdate({ key: 'customerId', value: id || '' })" />
		</template>

		<template #field-optInAccepted>
			<NcCheckboxRadioSwitch
				:modelValue="form.optInAccepted"
				data-testid="loyalty-enrollment-opt-in"
				@update:modelValue="(v) => onFieldUpdate({ key: 'optInAccepted', value: v })">
				{{ t('pipelinq', 'I agree to store my loyalty data and contact me with offers') }}
			</NcCheckboxRadioSwitch>
			<a
				v-if="termsUrl"
				class="loyalty-enrollment-widget__terms"
				:href="termsUrl"
				target="_blank"
				rel="noopener noreferrer"
				data-testid="loyalty-enrollment-terms">
				{{ t('pipelinq', 'Read the programme terms') }}
			</a>
		</template>

		<template #actions>
			<NcButton
				variant="primary"
				wide
				:disabled="saving || !canSubmit"
				data-testid="loyalty-enrollment-submit"
				@click="enroll">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ saving ? t('pipelinq', 'Creating…') : t('pipelinq', 'Create loyalty account') }}
			</NcButton>
		</template>
	</CnFormWidgetBase>
</template>

<script>
import { CnFormWidgetBase, CnResourceSelect } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * LoyaltyEnrollmentWidget — the enrolment card on the loyalty reporting
 * dashboard: a customer, a programme (the page's programme by default), the
 * terms version accepted and the customer's opt-in, which the AVG makes a
 * condition for creating the account.
 *
 * `content.termsVersion` seeds the terms version field and
 * `content.programmeKey` names the page filter the programme follows
 * (default `programme`).
 *
 * @spec exclude This app has no canonical loyalty spec: the loyalty-program
 *  change is archived and no spec inherited it (`ls openspec/specs/ | grep -i loyal`).
 */
export default {
	name: 'LoyaltyEnrollmentWidget',

	components: { CnFormWidgetBase, CnResourceSelect, NcButton, NcCheckboxRadioSwitch, Plus },

	inject: {
		cnWorkspaceContext: { default: null },
	},

	props: {
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			programmes: [],
			form: {
				customerId: '',
				programmeId: '',
				optInTermsVersion: this.content?.termsVersion || '',
				optInAccepted: false,
			},

			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		clientSchema() {
			return this.content?.clientSchema || 'client'
		},

		/** The page's programme, from the workspace context. */
		pageProgramme() {
			const c = this.cnWorkspaceContext
			const bag = c && typeof c === 'object' && 'value' in c ? c.value : c
			const key = this.content?.programmeKey || 'programme'
			return (bag && bag[key]) ? String(bag[key]) : ''
		},

		fields() {
			return [
				{ key: 'customerId', label: t('pipelinq', 'Customer') },
				{
					key: 'programmeId',
					type: 'select',
					label: t('pipelinq', 'Programme'),
					options: this.programmes.map((p) => ({ value: p.id, label: p.name || p.id })),
				},
				{ key: 'optInTermsVersion', label: t('pipelinq', 'Terms version accepted') },
				{ key: 'optInAccepted', label: '' },
			]
		},

		termsUrl() {
			const programme = this.programmes.find((p) => p.id === this.form.programmeId)
			return programme?.termsUrl || ''
		},

		canSubmit() {
			return this.form.optInAccepted === true && this.form.customerId !== '' && this.form.programmeId !== ''
		},
	},

	watch: {
		pageProgramme: {
			immediate: true,
			handler(id) {
				if (id) {
					this.form.programmeId = id
				}
			},
		},
	},

	mounted() {
		this.loadProgrammes()
	},

	methods: {
		t,

		onFieldUpdate({ key, value }) {
			this.form[key] = value
			this.errorMessage = ''
		},

		async loadProgrammes() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/pipelinq/loyaltyProgramme'), { params: { _limit: 200 } })
				const list = response.data?.results || []
				this.programmes = list.map((p) => ({
					id: p['@self']?.id || p.id,
					name: p.name,
					termsUrl: p.termsUrl,
				}))
				if (!this.form.programmeId && this.programmes.length > 0) {
					this.form.programmeId = this.programmes[0].id
				}
			} catch {
				this.errorMessage = t('pipelinq', 'Failed to load programmes')
			}
		},

		async enroll() {
			if (!this.canSubmit || this.saving) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			const now = new Date().toISOString()
			try {
				await axios.post(generateUrl('/apps/openregister/api/objects/pipelinq/customerLoyaltyAccount'), {
					customerId: this.form.customerId,
					programmeId: this.form.programmeId,
					currentBalance: 0,
					lifetimePoints: 0,
					status: 'active',
					optInAccepted: true,
					optInTimestamp: now,
					optInTermsVersion: this.form.optInTermsVersion,
					createdOn: now,
					lastActivityDate: now,
				})
				showSuccess(t('pipelinq', 'Loyalty account created'))
				this.form.customerId = ''
				this.form.optInAccepted = false
			} catch {
				this.errorMessage = t('pipelinq', 'Failed to create loyalty account')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.loyalty-enrollment-widget__terms {
	display: inline-block;
	margin-top: 8px;
	color: var(--color-primary-element);
	font-weight: 600;
	text-decoration: underline;
}
</style>
