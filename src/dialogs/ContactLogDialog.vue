<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Log a contact moment on a ticket (board PqTicketContactLoggen): the ticket it
belongs to, the channel, the direction as two choices, when (a date and a
time, filled with now), the duration, the subject, the notes and the
customer. Saves a ticket of type interaction under the ticket the page is on,
for the same customer.

An `open-modal` action carries no object context, so the route names the
ticket. When the dialog closes after a save the page is asked to reload.

@spec openspec/changes/contact-log-dialog/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Log contact')"
		:eyebrow="eyebrow"
		:subtitle="t('pipelinq', 'A contact moment with the customer, saved with this ticket')"
		width="form"
		data-testid="contact-log-dialog"
		@closing="close">
		<form class="contact-log" @submit.prevent="save">
			<p v-if="ticket" class="contact-log__parent contact-log__parent--block" data-testid="contact-log-parent">
				<LinkVariant class="contact-log__parent-icon" :size="16" />
				{{ t('pipelinq', 'Belongs to ticket') }}
				<strong>{{ ticket.reference || '' }}</strong><template v-if="ticket.title">, {{ ticket.title }}</template>
			</p>
			<div class="contact-log__row">
				<label class="contact-log__field">
					<span class="contact-log__label">{{ t('pipelinq', 'Channel') }}</span>
					<select v-model="form.channel" data-testid="contact-log-channel">
						<option v-for="option in channels" :key="option.value" :value="option.value">
							{{ option.label }}
						</option>
					</select>
				</label>
				<fieldset class="contact-log__field">
					<legend class="contact-log__label">
						{{ t('pipelinq', 'Direction') }}
					</legend>
					<label v-for="option in directions" :key="option.value" class="contact-log__radio">
						<input
							v-model="form.direction"
							type="radio"
							name="contact-log-direction"
							:value="option.value"
							:data-testid="`contact-log-direction-${option.value}`">
						{{ option.label }}
					</label>
				</fieldset>
			</div>
			<div class="contact-log__row">
				<div class="contact-log__field">
					<span class="contact-log__label">{{ t('pipelinq', 'When') }}</span>
					<span class="contact-log__when">
						<input v-model="form.date" type="date" :aria-label="t('pipelinq', 'When')" data-testid="contact-log-date">
						<input v-model="form.time" type="time" :aria-label="t('pipelinq', 'Time')" data-testid="contact-log-time">
					</span>
					<span v-if="filledWithNow" class="contact-log__hint">{{ t('pipelinq', 'Filled in with now') }}</span>
				</div>
				<label class="contact-log__field">
					<span class="contact-log__label">{{ t('pipelinq', 'Duration') }}</span>
					<input v-model="form.duration" type="text" :placeholder="t('pipelinq', 'e.g. 4 minutes')" data-testid="contact-log-duration">
				</label>
			</div>
			<label class="contact-log__field">
				<span class="contact-log__label">{{ t('pipelinq', 'Subject') }}</span>
				<input v-model="form.title" type="text" required data-testid="contact-log-subject">
			</label>
			<label class="contact-log__field">
				<span class="contact-log__label">{{ t('pipelinq', 'Notes') }}</span>
				<textarea v-model="form.notes" rows="4" data-testid="contact-log-notes" />
			</label>
			<div v-if="clientName" class="contact-log__field">
				<span class="contact-log__label">{{ t('pipelinq', 'Customer') }}</span>
				<span class="contact-log__client contact-log__control" data-testid="contact-log-client">
					<span class="contact-log__initials" aria-hidden="true">{{ initials }}</span>{{ clientName }}
				</span>
			</div>
			<p v-if="error" class="contact-log__error" role="alert">
				{{ error }}
			</p>
		</form>
		<template #actions>
			<NcButton data-testid="contact-log-cancel" @click="close">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || form.title.trim() === ''"
				data-testid="contact-log-save"
				@click="save">
				<template #icon>
					<ContentSave class="contact-log__save-icon" :size="18" />
				</template>
				{{ t('pipelinq', 'Save contact moment') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { initialsOf } from '../services/userDisplayName.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Two digits.
 *
 * @param {number} n The number.
 * @return {string}
 */
const pad = (n) => String(n).padStart(2, '0')

/**
 * The contact-log dialog on a ticket.
 *
 * @spec openspec/changes/contact-log-dialog/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
 */
export default {
	name: 'ContactLogDialog',
	components: { CnDialog, ContentSave, LinkVariant, NcButton },
	emits: ['close'],

	data() {
		const now = new Date()
		const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
		const time = `${pad(now.getHours())}:${pad(now.getMinutes())}`
		return {
			ticket: null,
			clientName: '',
			busy: false,
			error: '',
			nowDate: date,
			nowTime: time,
			form: { channel: 'telefoon', direction: 'inbound', date, time, duration: '', title: '', notes: '' },
		}
	},

	computed: {
		/**
		 * The ticket the page is on.
		 *
		 * @return {string}
		 */
		ticketId() {
			const id = this.$route?.params?.id
			return typeof id === 'string' ? id : ''
		},

		/**
		 * "Contactmoment · PQ-2026-0417".
		 *
		 * @return {string}
		 */
		eyebrow() {
			const reference = this.ticket?.reference || ''
			return reference ? t('pipelinq', 'Contact moment · {reference}', { reference }) : t('pipelinq', 'Contact moment')
		},

		/**
		 * The channels of a contact moment, as the ticket schema spells them.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		channels() {
			return [
				{ value: 'telefoon', label: t('pipelinq', 'Phone') },
				{ value: 'email', label: t('pipelinq', 'Email') },
				{ value: 'balie', label: t('pipelinq', 'Counter') },
				{ value: 'chat', label: t('pipelinq', 'Chat') },
				{ value: 'social', label: t('pipelinq', 'Social media') },
				{ value: 'brief', label: t('pipelinq', 'Letter') },
				{ value: 'sms', label: t('pipelinq', 'Text message') },
			]
		},

		/**
		 * Inbound or outbound.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		directions() {
			return [
				{ value: 'inbound', label: t('pipelinq', 'Inbound') },
				{ value: 'outbound', label: t('pipelinq', 'Outbound') },
			]
		},

		/**
		 * Whether the date and time still read as now.
		 *
		 * @return {boolean}
		 */
		filledWithNow() {
			return this.form.date === this.nowDate && this.form.time === this.nowTime
		},

		/**
		 * The customer's initials.
		 *
		 * @return {string}
		 */
		initials() {
			return initialsOf(this.clientName)
		},
	},

	async created() {
		await this.loadTicket()
	},

	methods: {
		t,

		/**
		 * Read the ticket and its customer's name.
		 *
		 * @return {Promise<void>}
		 */
		async loadTicket() {
			if (!this.ticketId) {
				return
			}
			const store = useObjectStore()
			try {
				this.ticket = (await store.fetchObject('ticket', this.ticketId)) || null
				const clientId = this.ticket?.client
				if (typeof clientId === 'string' && clientId !== '') {
					const client = await store.fetchObject('client', clientId)
					this.clientName = client?.name || ''
				}
			} catch {
				this.ticket = null
			}
		},

		/**
		 * The contact moment to save: a ticket of type interaction under this
		 * ticket, for its customer.
		 *
		 * @return {object}
		 * @spec openspec/changes/contact-log-dialog/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
		 */
		payload() {
			const when = new Date(`${this.form.date}T${this.form.time || '00:00'}`)
			const object = {
				ticketType: 'interaction',
				parentTicket: this.ticketId,
				channel: this.form.channel,
				direction: this.form.direction,
				occurredAt: Number.isNaN(when.getTime()) ? new Date().toISOString() : when.toISOString(),
				title: this.form.title.trim(),
				notes: this.form.notes.trim(),
			}
			if (this.form.duration.trim() !== '') {
				object.duration = this.form.duration.trim()
			}
			if (this.ticket && typeof this.ticket.client === 'string' && this.ticket.client !== '') {
				object.client = this.ticket.client
			}
			return object
		},

		/**
		 * Save the contact moment and close.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (this.busy || this.form.title.trim() === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await useObjectStore().saveObject('ticket', this.payload())
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch {
				this.error = t('pipelinq', 'The contact moment is not saved. Try again.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close without saving.
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.contact-log {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.contact-log__row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
}

.contact-log__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	margin: 0;
	padding: 0;
	border: 0;
}

.contact-log__label {
	font-size: 14px;
	font-weight: 600;
}

.contact-log__when {
	display: flex;
	gap: 8px;
}

.contact-log__radio {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-inline-end: 16px;
}

.contact-log__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.contact-log__parent {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	font-size: 14px;
}

.contact-log__parent-icon {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.contact-log__field input:not([type="radio"]),
.contact-log__field select,
.contact-log__field textarea,
.contact-log__control {
	box-sizing: border-box;
	width: 100%;
	min-height: 40px;
	margin: 0;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
}

.contact-log__field textarea {
	min-height: 118px;
}

.contact-log__when input[type="time"] {
	width: 84px;
	flex: none;
}

.contact-log__control {
	padding: 0 12px;
}

.contact-log__client {
	display: flex;
	align-items: center;
	gap: 8px;
}

.contact-log__initials {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-background-hover);
	font-size: 12px;
	font-weight: 700;
}

.contact-log__error {
	color: var(--color-text-error, var(--color-error-text));
	margin: 0;
}
</style>
