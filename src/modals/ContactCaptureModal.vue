<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Paste or scan: pasted text (an email signature) or a photo of a business
  - card becomes proposed contact fields. The person checks them, places the
  - lines no rule or model could, and presses Use these fields, which opens the
  - page's own create dialog prefilled. Nothing is saved here (D3).
  -
  - @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
  - @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Paste or scan')"
		size="normal"
		data-testid="contact-capture-modal"
		@closing="$emit('close')">
		<div class="contact-capture">
			<NcTextArea
				v-model="text"
				:label="t('pipelinq', 'Signature or card text')"
				:helperText="t('pipelinq', 'Paste an email signature or the text of a business card.')"
				resize="vertical"
				:rows="6"
				data-testid="contact-capture-text" />
			<div class="contact-capture__row">
				<NcButton
					:disabled="!canRead"
					data-testid="contact-capture-read"
					@click="readText">
					{{ t('pipelinq', 'Read text') }}
				</NcButton>
			</div>

			<div v-if="photoAvailable === true" class="contact-capture__photo">
				<label :for="photoInputId" class="contact-capture__label">
					{{ t('pipelinq', 'Photo of a business card') }}
				</label>
				<input
					:id="photoInputId"
					type="file"
					accept="image/*"
					capture="environment"
					:aria-describedby="photoHintId"
					:disabled="loading"
					data-testid="contact-capture-photo"
					@change="onPhoto">
				<p :id="photoHintId" class="contact-capture__hint">
					{{ t('pipelinq', 'Take a photo with your phone or choose an image. The photo is only used to read the text and is not kept.') }}
				</p>
			</div>
			<NcNoteCard
				v-else-if="photoAvailable === false"
				type="info"
				data-testid="contact-capture-no-photo">
				{{ t('pipelinq', 'Photos cannot be read on this server, because no text recognition is installed. Paste the text instead.') }}
			</NcNoteCard>

			<p
				v-if="loading"
				class="contact-capture__state"
				role="status"
				aria-live="polite">
				<NcLoadingIcon :size="20" />
				<span>{{ t('pipelinq', 'Reading…') }}</span>
			</p>
			<p
				v-else-if="error"
				class="contact-capture__state contact-capture__error"
				role="alert"
				data-testid="contact-capture-error">
				{{ error }}
			</p>

			<template v-if="proposal">
				<h3 class="contact-capture__heading">
					{{ t('pipelinq', 'Proposed fields') }}
				</h3>
				<p class="contact-capture__hint">
					{{ t('pipelinq', 'Check the fields. Nothing is saved until you save the new record.') }}
				</p>
				<div class="contact-capture__fields">
					<NcTextField
						v-for="field in fieldList"
						:key="field.key"
						:modelValue="form[field.key]"
						:label="field.label"
						:data-testid="`contact-capture-field-${field.key}`"
						@update:modelValue="(value) => (form[field.key] = value)" />
				</div>

				<template v-if="unassigned.length > 0">
					<h3 class="contact-capture__heading">
						{{ t('pipelinq', 'Lines without a field') }}
					</h3>
					<p class="contact-capture__hint">
						{{ t('pipelinq', 'Choose a field for each line you want to use.') }}
					</p>
					<ul class="contact-capture__lines">
						<li
							v-for="(line, index) in unassigned"
							:key="`${index}-${line}`"
							class="contact-capture__line"
							data-testid="contact-capture-line">
							<span class="contact-capture__line-text">{{ line }}</span>
							<NcSelect
								:modelValue="assignments[index] || null"
								:options="fieldOptions"
								:inputLabel="t('pipelinq', 'Field for {line}', { line })"
								label="label"
								trackBy="id"
								:reduce="(option) => option.id"
								:clearable="true"
								:placeholder="t('pipelinq', 'Not used')"
								@update:modelValue="(value) => assign(index, value)" />
						</li>
					</ul>
				</template>
			</template>
		</div>

		<template #actions>
			<NcButton data-testid="contact-capture-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!proposal || loading"
				data-testid="contact-capture-use"
				@click="useFields">
				{{ t('pipelinq', 'Use these fields') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { captureStatus, parsePhoto, parseText } from '../services/contactCaptureApi.js'
import { CAPTURE_FIELDS, captureForm, createValues } from '../utils/contactCapture.js'

/** The create dialog each page opens when no `createModal` is given. */
const CREATE_MODALS = {
	client: 'ClientCreateDialog',
	contact: 'ContactCreateDialog',
}

let uid = 0

export default {
	name: 'ContactCaptureModal',

	components: {
		CnDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	inject: {
		cnOpenModal: { default: null },
	},

	// The index page hands every header modal its active filters; they mean nothing here.
	inheritAttrs: false,

	props: {
		/** The page's schema: `client` or `contact`; decides how the fields fill the form. */
		schema: {
			type: String,
			default: 'contact',
			validator: (value) => ['client', 'contact'].includes(value),
		},

		/** The registry key of the create dialog to open; the schema's own by default. */
		createModal: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'useFields'],

	data() {
		uid += 1
		return {
			text: '',
			photoAvailable: null,
			maxPhotoBytes: 0,
			loading: false,
			error: '',
			proposal: null,
			form: captureForm(),
			unassigned: [],
			assignments: {},
			photoInputId: `contact-capture-photo-${uid}`,
			photoHintId: `contact-capture-photo-hint-${uid}`,
		}
	},

	computed: {
		/** @return {boolean} Whether there is text to read and nothing is being read. */
		canRead() {
			return this.text.trim() !== '' && !this.loading
		},

		/** @return {Array<{key: string, label: string}>} The proposed fields, labelled. */
		fieldList() {
			return CAPTURE_FIELDS.map((field) => ({ key: field.key, label: t('pipelinq', field.label) }))
		},

		/** @return {Array<{id: string, label: string}>} The fields a line can be placed in. */
		fieldOptions() {
			return this.fieldList.map((field) => ({ id: field.key, label: field.label }))
		},
	},

	async mounted() {
		const status = await captureStatus()
		this.photoAvailable = status.photo
		this.maxPhotoBytes = status.maxPhotoBytes
	},

	methods: {
		/**
		 * Ask for a proposal for the pasted text.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-pastes-an-email-signature
		 */
		async readText() {
			if (!this.canRead) {
				return
			}
			await this.run(() => parseText(this.text))
		},

		/**
		 * Read the chosen photo; too large a photo is refused before it is sent.
		 *
		 * @param {Event} event The file input's change.
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-scans-a-card-at-a-fair
		 */
		async onPhoto(event) {
			const input = event?.target
			const file = input?.files?.[0]
			if (!file) {
				return
			}
			if (input) {
				input.value = ''
			}
			if (this.maxPhotoBytes > 0 && file.size > this.maxPhotoBytes) {
				this.error = t('pipelinq', 'The photo is too large. Take a smaller photo or paste the text.')
				return
			}
			await this.run(() => parsePhoto(file))
		},

		/**
		 * Run one request and take its proposal, or say why there is none.
		 *
		 * @param {() => Promise<object>} request The request.
		 * @return {Promise<void>}
		 */
		async run(request) {
			this.loading = true
			this.error = ''
			try {
				this.take(await request())
			} catch (error) {
				this.error = this.failure(error?.response?.status)
				if (error?.response?.status === 409) {
					this.photoAvailable = false
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load a proposal into the form and the list of unplaced lines.
		 *
		 * @param {object} proposal The server's proposal.
		 * @return {void}
		 */
		take(proposal) {
			this.proposal = proposal || {}
			if (typeof proposal?.text === 'string' && proposal.text !== '') {
				this.text = proposal.text
			}
			this.form = captureForm(proposal?.fields)
			this.unassigned = Array.isArray(proposal?.unassigned) ? [...proposal.unassigned] : []
			this.assignments = {}
		},

		/**
		 * The sentence for a failed request.
		 *
		 * @param {number|undefined} status The HTTP status.
		 * @return {string} The message.
		 */
		failure(status) {
			switch (status) {
			case 409:
				return t('pipelinq', 'Photos cannot be read on this server. Paste the text instead.')
			case 413:
				return t('pipelinq', 'This is too much to read. Paste only the signature, or take a smaller photo.')
			case 415:
				return t('pipelinq', 'Choose a photo: the file is not an image.')
			case 422:
				return t('pipelinq', 'No text was found on the photo. Try a sharper photo or paste the text.')
			case 502:
				return t('pipelinq', 'The photo could not be read. Try again or paste the text.')
			default:
				return t('pipelinq', 'The text could not be read. Try again.')
			}
		},

		/**
		 * Place an unassigned line in a field, or take it out of one.
		 *
		 * @param {number} index The line's position.
		 * @param {string|null} field The field key, or null for not used.
		 * @return {void}
		 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-without-hermiq-unplaced-lines-are-handed-to-the-user
		 */
		assign(index, field) {
			const line = this.unassigned[index]
			const before = this.assignments[index]
			if (before && this.form[before] === line) {
				this.form[before] = ''
			}
			if (field) {
				this.assignments = { ...this.assignments, [index]: field }
				this.form[field] = line
				return
			}
			const { [index]: _dropped, ...rest } = this.assignments
			this.assignments = rest
		},

		/**
		 * Open the page's create dialog with the fields filled in. Nothing is
		 * saved: the person saves in that dialog. Opening it replaces this
		 * dialog, so no `close` is emitted then (it would close the new one).
		 *
		 * @return {void}
		 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-pastes-an-email-signature
		 */
		useFields() {
			if (!this.proposal) {
				return
			}
			const values = createValues(this.schema, this.form, (text, vars) => t('pipelinq', text, vars))
			const target = this.createModal || CREATE_MODALS[this.schema]
			if (typeof this.cnOpenModal === 'function' && target) {
				this.cnOpenModal(target, { values })
				return
			}
			this.$emit('useFields', values)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.contact-capture {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.contact-capture__row {
	display: flex;
	gap: 8px;
}

.contact-capture__photo {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.contact-capture__label {
	font-weight: bold;
}

.contact-capture__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.contact-capture__state {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
}

.contact-capture__error {
	color: var(--color-error-text);
}

.contact-capture__heading {
	margin: 8px 0 0;
	font-size: var(--default-font-size);
	font-weight: bold;
}

.contact-capture__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 8px 16px;
}

.contact-capture__lines {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.contact-capture__line {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(180px, 1fr);
	align-items: center;
	gap: 8px;
}

.contact-capture__line-text {
	overflow-wrap: anywhere;
}
</style>
