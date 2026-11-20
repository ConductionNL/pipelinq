<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Look a caller up in the BRP from the new request dialog (board PqNieuwVerzoek,
"Opzoeken in de BRP"). The handler gives the BSN, the reason and the purpose
binding the law asks for; POST /api/brp/lookup checks the handler's BRP access,
asks the BRP and writes the audit record. The found person is handed to the
form, which selects or creates the client from the name and address. The BSN
is sent once and kept nowhere.

@spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Look up in the BRP')"
		:subtitle="t('pipelinq', 'Find the caller in the population register')"
		width="form"
		data-testid="client-brp-lookup-dialog"
		@closing="$emit('close')">
		<form class="client-brp" @submit.prevent="lookUp">
			<label class="client-brp__field">
				<span class="client-brp__label">{{ t('pipelinq', 'BSN') }}</span>
				<input
					v-model="bsn"
					type="text"
					inputmode="numeric"
					maxlength="9"
					autocomplete="off"
					data-testid="client-brp-bsn">
			</label>
			<label class="client-brp__field">
				<span class="client-brp__label">{{ t('pipelinq', 'Reason') }}</span>
				<select v-model="verzoekreden" data-testid="client-brp-reason">
					<option v-for="option in reasons" :key="option.value" :value="option.value">{{ option.label }}</option>
				</select>
			</label>
			<label class="client-brp__field">
				<span class="client-brp__label">{{ t('pipelinq', 'Purpose binding') }}</span>
				<select v-model="purposeBinding" data-testid="client-brp-binding">
					<option v-for="option in bindings" :key="option.value" :value="option.value">{{ option.label }}</option>
				</select>
			</label>
			<p v-if="error" class="client-brp__error" role="alert">
				{{ error }}
			</p>
		</form>
		<template #actions>
			<NcButton data-testid="client-brp-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !validBsn"
				data-testid="client-brp-lookup"
				@click="lookUp">
				{{ t('pipelinq', 'Look up in the BRP') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'ClientBrpLookupDialog',
	components: { CnDialog, NcButton },

	props: {
		/** An axios-like client; the specs pass a fake. */
		http: {
			type: [Object, Function],
			default: () => axios,
		},
	},

	emits: ['close', 'found'],

	data() {
		return {
			bsn: '',
			verzoekreden: 'Reguliere verzoekbehandeling',
			purposeBinding: 'Publieke taak — Wet BRP art. 3.3',
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the BSN has nine digits.
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		validBsn() {
			return /^\d{9}$/.test(this.bsn)
		},

		/**
		 * @return {Array<{value: string, label: string}>} The reasons.
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		reasons() {
			return [
				{ value: 'Reguliere verzoekbehandeling', label: t('pipelinq', 'Regular request handling') },
				{ value: 'Behandeling AVG-inzageverzoek art. 15', label: t('pipelinq', 'Behandeling AVG-inzageverzoek art. 15') },
				{ value: 'Overig', label: t('pipelinq', 'Other') },
			]
		},

		/**
		 * @return {Array<{value: string, label: string}>} The purpose bindings.
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		bindings() {
			return [
				{ value: 'Publieke taak — Wet BRP art. 3.3', label: t('pipelinq', 'Publieke taak — Wet BRP art. 3.3') },
				{ value: 'AVG art. 6 lid 1 sub e', label: t('pipelinq', 'AVG art. 6 lid 1 sub e (publieke taak)') },
				{ value: 'Overig', label: t('pipelinq', 'Other') },
			]
		},
	},

	methods: {
		t,

		/**
		 * Ask the BRP and hand the found person to the form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		async lookUp() {
			if (this.busy || !this.validBsn) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await this.http.post(generateUrl('/apps/pipelinq/api/brp/lookup'), {
					bsn: this.bsn,
					verzoekreden: this.verzoekreden,
					purposeBinding: this.purposeBinding,
				})
				this.bsn = ''
				const person = data && data.persoon
				if (!person || typeof person !== 'object') {
					this.error = (data && data.errorMessage) || t('pipelinq', 'The BRP did not find this person.')
					return
				}
				this.$emit('found', person)
			} catch (err) {
				const data = err?.response?.data || {}
				this.error = data.errorMessage || data.error || t('pipelinq', 'The BRP could not be asked. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.client-brp {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.client-brp__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.client-brp__label {
	font-size: 14px;
	font-weight: 600;
}

.client-brp__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error-text));
}
</style>
