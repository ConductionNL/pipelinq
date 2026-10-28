<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Verify the identity of the customer in a call against the BRP (board
PqWerkplekKlant, "Wie is het": "Opzoeken in de BRP"). The employee notes what
they checked with the customer, gives the BSN and the purpose binding the
law asks for, and looks the person up through POST /api/brp/lookup with
`verifiedClient`, so the server records on the client when, via what and
what was checked. The BSN is sent once and not kept.

@spec openspec/changes/brp-verifies-the-client/specs/brp-lookup/spec.md#requirement-a-brp-lookup-can-verify-a-clients-identity
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Look up in the BRP')"
		:eyebrow="t('pipelinq', 'Who is it')"
		:subtitle="t('pipelinq', 'Verify the customer\'s identity in the population register')"
		width="form"
		data-testid="brp-client-check-dialog"
		@closing="$emit('close')">
		<form class="brp-check" @submit.prevent="lookUp">
			<label class="brp-check__field">
				<span class="brp-check__label">{{ t('pipelinq', 'Checked with the customer') }}</span>
				<input v-model="identityCheck" type="text" data-testid="brp-check-identity-check">
			</label>
			<label class="brp-check__field">
				<span class="brp-check__label">{{ t('pipelinq', 'BSN') }}</span>
				<input
					v-model="bsn"
					type="text"
					inputmode="numeric"
					maxlength="9"
					autocomplete="off"
					data-testid="brp-check-bsn">
			</label>
			<label class="brp-check__field">
				<span class="brp-check__label">{{ t('pipelinq', 'Reason') }}</span>
				<select v-model="verzoekreden" data-testid="brp-check-reason">
					<option v-for="option in reasons" :key="option.value" :value="option.value">{{ option.label }}</option>
				</select>
			</label>
			<label class="brp-check__field">
				<span class="brp-check__label">{{ t('pipelinq', 'Purpose binding') }}</span>
				<select v-model="purposeBinding" data-testid="brp-check-binding">
					<option v-for="option in bindings" :key="option.value" :value="option.value">{{ option.label }}</option>
				</select>
			</label>
			<p v-if="found" class="brp-check__found" data-testid="brp-check-found">
				{{ found }}
			</p>
			<p v-if="error" class="brp-check__error" role="alert">
				{{ error }}
			</p>
		</form>
		<template #actions>
			<NcButton data-testid="brp-check-cancel" @click="$emit('close')">
				{{ found ? t('pipelinq', 'Close') : t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!found"
				variant="primary"
				:disabled="busy || !/^\d{9}$/.test(bsn)"
				data-testid="brp-check-lookup"
				@click="lookUp">
				{{ t('pipelinq', 'Look up in the BRP') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * The BRP identity check for the customer in a call.
 *
 * @spec openspec/changes/brp-verifies-the-client/specs/brp-lookup/spec.md#requirement-a-brp-lookup-can-verify-a-clients-identity
 */
export default {
	name: 'BrpClientCheckDialog',
	components: { CnDialog, NcButton },

	props: {
		/** The client whose identity is checked. */
		clientId: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			identityCheck: t('pipelinq', 'Date of birth and address asked'),
			bsn: '',
			verzoekreden: 'Reguliere verzoekbehandeling',
			purposeBinding: 'Publieke taak — Wet BRP art. 3.3',
			busy: false,
			error: '',
			found: '',
		}
	},

	computed: {
		/**
		 * The reasons for a lookup, as the BRP panel offers them.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		reasons() {
			return [
				{ value: 'Reguliere verzoekbehandeling', label: t('pipelinq', 'Regular request handling') },
				{ value: 'Behandeling AVG-inzageverzoek art. 15', label: t('pipelinq', 'Behandeling AVG-inzageverzoek art. 15') },
				{ value: 'Overig', label: t('pipelinq', 'Other') },
			]
		},

		/**
		 * The purpose bindings, as the BRP panel offers them.
		 *
		 * @return {Array<{value: string, label: string}>}
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
		 * Look the person up; on a match the server records the verified
		 * identity on the client, and the page reads the client again.
		 *
		 * @return {Promise<void>}
		 */
		async lookUp() {
			if (this.busy || !/^\d{9}$/.test(this.bsn)) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/brp/lookup'), {
					bsn: this.bsn,
					verzoekreden: this.verzoekreden,
					purposeBinding: this.purposeBinding,
					verifiedClient: this.clientId,
					identityCheck: this.identityCheck.trim(),
				})
				const person = (data && data.persoon) || {}
				const name = [person.givenNames, person.namePrefix, person.surname].filter(Boolean).join(' ')
				this.found = t('pipelinq', 'Found in the BRP: {name}. The identity is recorded as verified.', { name: name || t('pipelinq', 'the person') })
				this.bsn = ''
				emit('cn:page:refresh', {})
			} catch (err) {
				const data = err?.response?.data || {}
				this.error = data.errorMessage || t('pipelinq', 'The BRP could not be asked. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.brp-check {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.brp-check__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.brp-check__label {
	font-size: 14px;
	font-weight: 600;
}

.brp-check__found {
	margin: 0;
	color: var(--color-text-success, var(--color-success-text));
}

.brp-check__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error-text));
}
</style>
