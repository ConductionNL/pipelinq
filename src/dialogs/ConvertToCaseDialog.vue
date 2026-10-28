<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Confirms "Convert to case" on a request ticket (board PqTicketOmzetten):
  - what goes with the case, and the app that takes it over. Emits `confirm`
  - when the agent creates the case and `close` otherwise; the caller posts
  - the conversion.
  -
  - @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Convert to a case')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		width="form"
		data-testid="convert-to-case-dialog"
		@closing="$emit('close')">
		<div class="convert-to-case">
			<CnNoteCard
				type="success"
				:text="t('pipelinq', '{app} is available and can take over this request as a case.', { app: targetApp })" />
			<div v-if="caseTypeSource" class="convert-to-case__field">
				<CnResourceSelect
					:register="caseTypeSource.register"
					:schema="caseTypeSource.schema"
					labelField="title"
					:modelValue="caseType"
					:inputLabel="t('pipelinq', 'Case type')"
					:placeholder="t('pipelinq', 'Choose a case type')"
					:preload="true"
					:clearable="true"
					:allowCreate="false"
					data-testid="convert-to-case-type"
					@update:modelValue="caseType = $event || ''" />
				<p class="convert-to-case__hint">
					{{ t('pipelinq', 'The case types come from {app}.', { app: targetApp }) }}
				</p>
			</div>
			<section class="convert-to-case__carried convert-to-case__carried--block">
				<h3 class="convert-to-case__heading">
					<LockOutline class="convert-to-case__lock" :size="16" />
					{{ t('pipelinq', 'Goes with the case') }}
				</h3>
				<dl class="convert-to-case__facts">
					<div class="convert-to-case__fact">
						<dt>{{ t('pipelinq', 'Title') }}</dt>
						<dd>{{ ticket.title || '' }}</dd>
					</div>
					<div v-if="requesterLine" class="convert-to-case__fact">
						<dt>{{ t('pipelinq', 'Requester') }}</dt>
						<dd>{{ requesterLine }}</dd>
					</div>
					<div v-if="ticket.description" class="convert-to-case__fact">
						<dt>{{ t('pipelinq', 'Description') }}</dt>
						<dd>{{ ticket.description }}</dd>
					</div>
				</dl>
			</section>
			<div class="convert-to-case__option">
				<NcCheckboxRadioSwitch
					:modelValue="keepOpen"
					data-testid="convert-to-case-keep-open"
					@update:modelValue="keepOpen = $event">
					{{ t('pipelinq', 'Keep this ticket open until the case is made') }}
				</NcCheckboxRadioSwitch>
				<p class="convert-to-case__hint">
					{{ t('pipelinq', 'Otherwise it reads Converted to a case, with a link to the case.') }}
				</p>
			</div>
			<div class="convert-to-case__option">
				<NcCheckboxRadioSwitch
					:modelValue="informCustomer"
					data-testid="convert-to-case-inform-customer"
					@update:modelValue="informCustomer = $event">
					{{ informLabel }}
				</NcCheckboxRadioSwitch>
				<p class="convert-to-case__hint">
					{{ informHint }}
				</p>
			</div>
		</div>
		<template #actions>
			<NcButton data-testid="convert-to-case-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="convert-to-case-confirm"
				@click="$emit('confirm', { caseType, keepOpen, informCustomer })">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<FolderPlusOutline v-else :size="20" />
				</template>
				{{ t('pipelinq', 'Create case in {app}', { app: targetApp }) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnNoteCard, CnResourceSelect } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import FolderPlusOutline from 'vue-material-design-icons/FolderPlusOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'

export default {
	name: 'ConvertToCaseDialog',
	components: { CnDialog, CnNoteCard, CnResourceSelect, FolderPlusOutline, LockOutline, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon },

	props: {
		/** The request ticket being converted. */
		ticket: {
			type: Object,
			default: () => ({}),
		},

		/** The customer's name, when the ticket has one. */
		requester: {
			type: String,
			default: '',
		},

		/** The customer's address, written after the name ("Sanne de Vries, Lindelaan 14"). */
		requesterAddress: {
			type: String,
			default: '',
		},

		/** The app that takes the case over. */
		targetApp: {
			type: String,
			default: 'dossiq',
		},

		/** Whether the conversion is being posted. */
		busy: {
			type: Boolean,
			default: false,
		},

		/** The customer portal's name ("Mijn Zuiddrecht"), or '' for a generic name. */
		portalName: {
			type: String,
			default: '',
		},

		/** Where the case types are listed (`{ register, schema }`), or null to ask for none. */
		caseTypeSource: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			caseType: '',
			keepOpen: false,
			informCustomer: false,
		}
	},

	computed: {
		/**
		 * The requester as the case gets it: the name, then the address.
		 *
		 * @return {string}
		 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
		 */
		requesterLine() {
			return [this.requester, this.requesterAddress].filter((part) => part).join(', ')
		},

		/**
		 * "Klant informeren in Mijn Zuiddrecht".
		 *
		 * @return {string}
		 * @spec openspec/changes/convert-informs-the-customer/specs/request-management/spec.md#requirement-converting-can-tell-the-customer-the-case-number
		 */
		informLabel() {
			return this.portalName
				? t('pipelinq', 'Inform the customer in {portal}', { portal: this.portalName })
				: t('pipelinq', 'Inform the customer in the customer portal')
		},

		/**
		 * "Sanne ziet het zaaknummer en wie de zaak behandelt."
		 *
		 * @return {string}
		 */
		informHint() {
			const first = (this.requester || '').trim().split(/\s+/)[0]
			return first
				? t('pipelinq', '{name} sees the case number and who handles the case.', { name: first })
				: t('pipelinq', 'The customer sees the case number and who handles the case.')
		},

		/**
		 * The line above the title: "Convert · PQ-2026-0417".
		 *
		 * @return {string}
		 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
		 */
		eyebrow() {
			const reference = this.ticket.reference || ''
			return reference
				? t('pipelinq', 'Convert · {reference}', { reference })
				: t('pipelinq', 'Convert')
		},

		/**
		 * The line under the title: the reference and the ticket's title.
		 *
		 * @return {string}
		 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
		 */
		subtitle() {
			return [this.ticket.reference, this.ticket.title]
				.filter((part) => typeof part === 'string' && part !== '')
				.join(' · ')
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.convert-to-case {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.convert-to-case__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.convert-to-case__field :deep(.v-select) {
	width: 100%;
}

.convert-to-case__carried--block {
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.convert-to-case__heading {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 10px;
	font-size: 14px;
	font-weight: 600;
}

.convert-to-case__lock {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.convert-to-case__facts {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
}

.convert-to-case__facts dt {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.convert-to-case__option .convert-to-case__hint {
	margin: 0 0 0 36px;
}

.convert-to-case__facts dd {
	margin: 0;
}
</style>
