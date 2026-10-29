<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - LetterFromTemplateModal makes a printable letter for one client from a
  - filinq template. Opened by the "Make a letter" header action on
  - ClientDetail and TicketDetail; the record comes from the route, because a
  - header action's modal props are not resolved against the page's object.
  - On a ticket page the letter is for the ticket's client and quotes the
  - ticket. filinq renders; pipelinq logs the letter as a contact moment.
  -
  - @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Make a letter from a template')"
		:eyebrow="eyebrow"
		width="wizard"
		data-testid="letter-dialog"
		@closing="$emit('close')">
		<div class="letter-modal" data-testid="letter-modal">
			<p v-if="loading">
				{{ t('pipelinq', 'Loading templates') }}
			</p>
			<p v-else-if="!available" class="letter-modal__error" role="alert">
				{{
					t(
						'pipelinq',
						'Letters are made by filinq, and filinq is not available.',
					)
				}}
			</p>
			<template v-else-if="!result">
				<p v-if="templates.length === 0">
					{{
						t(
							'pipelinq',
							'There are no letter templates yet. An administrator adds them in filinq with the namespace pipelinq.',
						)
					}}
				</p>
				<div v-else class="letter-modal__fields">
					<div class="letter-modal__field">
						<label for="letter-modal-template" class="letter-modal__label">{{ t('pipelinq', 'Template') }}</label>
						<NcSelect
							v-model="templateId"
							inputId="letter-modal-template"
							:options="templateOptions"
							:inputLabel="t('pipelinq', 'Template')"
							labelOutside
							:reduce="(o) => o.value"
							label="label"
							data-testid="letter-template" />
						<p class="letter-modal__hint">
							{{ n('pipelinq', 'Templates come from filinq; %n is shared with pipelinq.', 'Templates come from filinq; %n are shared with pipelinq.', templates.length) }}
						</p>
					</div>
					<div v-if="ticketOptions.length > 0" class="letter-modal__field">
						<label for="letter-modal-about" class="letter-modal__label">{{ t('pipelinq', 'About') }}</label>
						<NcSelect
							v-model="aboutTicketId"
							inputId="letter-modal-about"
							:options="ticketOptions"
							:inputLabel="t('pipelinq', 'About')"
							labelOutside
							:reduce="(o) => o.value"
							label="label"
							data-testid="letter-about" />
					</div>
					<div v-if="contactOptions.length > 0" class="letter-modal__field">
						<label for="letter-modal-contact" class="letter-modal__label">{{ t('pipelinq', 'Contact person') }}<span class="letter-modal__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
						<NcSelect
							v-model="contactId"
							inputId="letter-modal-contact"
							:options="contactOptions"
							:inputLabel="t('pipelinq', 'Contact person (optional)')"
							labelOutside
							:reduce="(o) => o.value"
							label="label"
							data-testid="letter-contact" />
					</div>
				</div>
				<div v-if="templateId" class="letter-modal__review" data-testid="letter-review">
					<div class="letter-modal__preview" data-testid="letter-preview">
						<span class="letter-modal__preview-label">{{ t('pipelinq', 'Preview') }}</span>
						<p v-if="previewing" class="letter-modal__hint">
							{{ t('pipelinq', 'Loading preview') }}
						</p>
						<p v-else-if="previewError" class="letter-modal__hint">
							{{ previewError }}
						</p>
						<!-- eslint-disable-next-line vue/no-v-html -- previewHtml comes from letterPreviewBody(), which sanitises through DOMPurify -->
						<div v-else class="letter-modal__letter" v-html="previewHtml" />
					</div>
					<div class="letter-modal__filled">
						<h3 class="letter-modal__filled-title">
							{{ t('pipelinq', 'Filled in for you') }}
						</h3>
						<dl v-if="fields.length > 0" class="letter-modal__filled-list" data-testid="letter-fields">
							<template v-for="field in fields" :key="field.label">
								<dt>{{ field.label }}</dt>
								<dd :class="{ 'letter-modal__missing': !field.filled }">
									{{ fieldText(field) }}
								</dd>
							</template>
						</dl>
						<div
							v-if="fields.length > 0"
							role="status"
							class="letter-modal__check"
							:class="{ 'letter-modal__check--missing': !complete }"
							data-testid="letter-check">
							<CheckCircleOutline v-if="complete" :size="18" />
							<AlertOutline v-else :size="18" />
							{{ checkLine }}
						</div>
						<div class="letter-modal__field">
							<label for="letter-modal-channel" class="letter-modal__label">{{ t('pipelinq', 'Send as') }}</label>
							<NcSelect
								v-model="channel"
								inputId="letter-modal-channel"
								:options="channelOptions"
								:inputLabel="t('pipelinq', 'Send as')"
								labelOutside
								:clearable="false"
								:reduce="(o) => o.value"
								label="label"
								data-testid="letter-channel" />
							<p v-if="clientName" class="letter-modal__hint">
								{{ t('pipelinq', 'The letter is saved with {client} and appears on their timeline as an outgoing contact moment.', { client: clientName }) }}
							</p>
						</div>
					</div>
				</div>
			</template>
			<div v-else class="letter-modal__result" data-testid="letter-result">
				<p>
					{{
						t(
							'pipelinq',
							'The letter is made and downloaded. A copy is in your Files.',
						)
					}}
				</p>
				<p
					v-if="!result.contactMomentId"
					class="letter-modal__error"
					role="alert">
					{{
						t(
							'pipelinq',
							'The letter is not logged on the client. Log it as a contact moment by hand.',
						)
					}}
				</p>
				<template v-if="result.warnings && result.warnings.length">
					<p>
						{{
							t(
								'pipelinq',
								'filinq reported something to check in the letter:',
							)
						}}
					</p>
					<ul data-testid="letter-warnings">
						<li v-for="(warning, index) in result.warnings" :key="index">
							{{ warning }}
						</li>
					</ul>
				</template>
			</div>
			<p v-if="error" class="letter-modal__error" role="alert">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton
				v-if="!result"
				class="letter-modal__download"
				:disabled="!canMake"
				data-testid="letter-download"
				@click="download">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('pipelinq', 'Download PDF') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ result ? t('pipelinq', 'Close') : t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="!canMake"
				data-testid="letter-make"
				@click="make">
				<template #icon>
					<FileDocumentOutline :size="20" />
				</template>
				{{ t('pipelinq', 'Make letter') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import {
	downloadLetter,
	fetchLetterTemplates,
	letterPreviewBody,
	makeLetter,
	previewLetter,
} from '../services/letters.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LetterFromTemplateModal',
	components: {
		AlertOutline,
		CheckCircleOutline,
		CnDialog,
		Download,
		FileDocumentOutline,
		NcButton,
		NcSelect,
	},

	props: {
		/** The client; read from the route on a client page when not given. */
		clientId: {
			type: String,
			default: '',
		},

		/** The ticket; read from the route on a ticket page when not given. */
		ticketId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			available: false,
			templates: [],
			contacts: [],
			templateId: null,
			contactId: null,
			clientName: '',
			tickets: [],
			aboutTicketId: null,
			resolvedClientId: '',
			resolvedTicketId: '',
			making: false,
			result: null,
			error: '',
			previewing: false,
			previewError: '',
			previewHtml: '',
			fields: [],
			complete: false,
			previewRequest: 0,
			channel: 'pdf',
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/client-letters/spec.md
		 */
		templateOptions() {
			return this.templates.map((tpl) => ({ value: tpl.id, label: tpl.name }))
		},

		/**
		 * @spec openspec/specs/client-letters/spec.md
		 */
		/** @return {string} "Sanne de Vries · letter". */
		eyebrow() {
			return [this.clientName, t('pipelinq', 'letter')].filter(Boolean).join(' · ')
		},

		/** @return {Array<{value: string, label: string}>} The client's tickets the letter can be about. */
		ticketOptions() {
			return this.tickets.map((ticket) => ({
				value: ticket.id,
				label: [ticket.reference, ticket.title].filter(Boolean).join(' '),
			}))
		},

		contactOptions() {
			return this.contacts.map((c) => ({ value: c.id, label: c.name || c.id }))
		},

		/**
		 * @spec openspec/specs/client-letters/spec.md
		 */
		canMake() {
			return Boolean(this.templateId && this.resolvedClientId && !this.making)
		},

		/**
		 * @return {Array<{value: string, label: string}>} How the letter goes out.
		 * @spec openspec/changes/letter-preview-and-fields/specs/client-letters/spec.md
		 */
		channelOptions() {
			return [{ value: 'pdf', label: t('pipelinq', 'PDF to print and post') }]
		},

		/**
		 * @return {string} "All 4 fields filled in, nothing missing", or which are still empty.
		 * @spec openspec/changes/letter-preview-and-fields/specs/client-letters/spec.md
		 */
		checkLine() {
			if (this.complete) {
				return this.n('pipelinq', 'The %n field is filled in, nothing missing', 'All %n fields filled in, nothing missing', this.fields.length)
			}
			const empty = this.fields.filter((field) => !field.filled).map((field) => field.label)
			return t('pipelinq', 'Still empty: {fields}', { fields: empty.join(', ') })
		},

		/** @return {string} What the preview depends on; a change asks for a new one. */
		previewKey() {
			return [this.templateId, this.resolvedClientId, this.contactId, this.resolvedTicketId || this.aboutTicketId].join('|')
		},
	},

	watch: {
		previewKey() {
			this.loadPreview()
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * The record ids: the props, else the route this page is on.
		 *
		 * @return {{clientId: string, ticketId: string}}
		 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
		 */
		routeIds() {
			const path = this.$route?.path || ''
			const id = this.$route?.params?.id || ''
			return {
				clientId: this.clientId || (path.startsWith('/clients/') ? id : ''),
				ticketId: this.ticketId || (path.startsWith('/tickets/') ? id : ''),
			}
		},

		/**
		 * Load the templates, the ticket's client and the client's contacts.
		 * The templates decide what the dialog shows, so `loading` ends when
		 * they answer; the client and its tickets and contacts follow.
		 *
		 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			const { clientId, ticketId } = this.routeIds()
			this.resolvedTicketId = ticketId
			this.resolvedClientId = clientId
			const templatesLoaded = this.loadTemplates()
			try {
				const store = useObjectStore()
				if (!clientId && ticketId) {
					const ticket = await store.fetchObject('ticket', ticketId)
					this.resolvedClientId = ticket?.client || ''
				}
				await templatesLoaded
				if (!this.available) {
					return
				}
				if (!this.resolvedClientId) {
					this.error = t(
						'pipelinq',
						'This ticket has no client, so there is nobody to write to.',
					)
					return
				}
				const client = await store.fetchObject('client', this.resolvedClientId).catch(() => null)
				this.clientName = client?.name || ''
				if (!ticketId) {
					this.tickets = (await store.fetchCollection('ticket', {
						client: this.resolvedClientId,
						_limit: 50,
					}).catch(() => [])) || []
					if (this.tickets.length > 0) {
						this.aboutTicketId = this.tickets[0].id
					}
				}
				this.contacts =
					(await store.fetchCollection('contact', {
						client: this.resolvedClientId,
						_limit: 100,
					})) || []
			} catch {
				this.error = t(
					'pipelinq',
					'The letter templates could not be loaded.',
				)
			}
		},

		/**
		 * Ask filinq, through pipelinq, for the templates; `loading` ends here.
		 *
		 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
		 */
		async loadTemplates() {
			try {
				const answer = await fetchLetterTemplates()
				this.available = answer.available
				this.templates = answer.templates
				if (this.templates.length === 1) {
					this.templateId = this.templates[0].id
				}
			} catch {
				this.error = t(
					'pipelinq',
					'The letter templates could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A filled-in field as the dialog lists it: the value, then where it came from.
		 *
		 * @param {{value: string, source: string, filled: boolean}} field The field.
		 * @return {string} The line.
		 * @spec openspec/changes/letter-preview-and-fields/specs/client-letters/spec.md
		 */
		fieldText(field) {
			if (!field.filled) {
				return [t('pipelinq', 'Not filled in'), field.source].filter(Boolean).join(', ')
			}
			return [field.value, field.source].filter(Boolean).join(', ')
		},

		/**
		 * Ask filinq for the letter as it would be made, and what it filled in.
		 * A newer request wins over one still on its way.
		 *
		 * @spec openspec/changes/letter-preview-and-fields/specs/client-letters/spec.md
		 */
		async loadPreview() {
			if (!this.templateId || !this.resolvedClientId || !this.available) {
				this.previewHtml = ''
				this.fields = []
				return
			}
			const request = ++this.previewRequest
			this.previewing = true
			this.previewError = ''
			try {
				const preview = await previewLetter(this.resolvedClientId, {
					templateId: this.templateId,
					contactId: this.contactId || undefined,
					ticketId: this.resolvedTicketId || this.aboutTicketId || undefined,
				})
				if (request !== this.previewRequest) return
				this.previewHtml = letterPreviewBody(preview.html)
				this.fields = preview.fields
				this.complete = preview.complete
			} catch {
				if (request !== this.previewRequest) return
				this.previewHtml = ''
				this.fields = []
				this.previewError = t('pipelinq', 'The preview could not be made. You can still make the letter.')
			} finally {
				if (request === this.previewRequest) this.previewing = false
			}
		},

		/**
		 * Download the letter as a PDF only: nothing is filed or logged.
		 *
		 * @spec openspec/changes/letter-preview-and-fields/specs/client-letters/spec.md
		 */
		async download() {
			if (!this.canMake) return
			this.making = true
			this.error = ''
			try {
				const letter = await makeLetter(this.resolvedClientId, {
					templateId: this.templateId,
					contactId: this.contactId || undefined,
					ticketId: this.resolvedTicketId || this.aboutTicketId || undefined,
					downloadOnly: true,
				})
				downloadLetter(letter)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('pipelinq', 'The letter could not be made.')
			} finally {
				this.making = false
			}
		},

		/**
		 * Make the letter, download it and show filinq's warnings.
		 *
		 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
		 */
		async make() {
			if (!this.canMake) return
			this.making = true
			this.error = ''
			try {
				const letter = await makeLetter(this.resolvedClientId, {
					templateId: this.templateId,
					contactId: this.contactId || undefined,
					ticketId: this.resolvedTicketId || this.aboutTicketId || undefined,
				})
				downloadLetter(letter)
				this.result = letter
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('pipelinq', 'The letter could not be made.')
			} finally {
				this.making = false
			}
		},
	},
}
</script>

<style scoped>
.letter-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 120px;
}

.letter-modal__error {
	color: var(--color-error-text, var(--color-error));
}

.letter-modal__fields {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.letter-modal__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.letter-modal__label {
	font-size: 14px;
	font-weight: 600;
}

.letter-modal__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.letter-modal__hint {
	margin: 0;
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}

/* The letter beside what was filled in, as PqBriefOpstellen draws it. */
.letter-modal__review {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	align-items: start;
}

.letter-modal__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 18px 22px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	font-size: 14px;
	line-height: 1.55;
}

.letter-modal__preview-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.letter-modal__letter :deep(p) {
	margin: 0 0 8px;
}

.letter-modal__filled {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.letter-modal__filled-title {
	margin: 0;
	font-size: 15px;
	font-weight: 700;
}

.letter-modal__filled-list {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	margin: 0;
	font-size: 14px;
}

.letter-modal__filled-list dt {
	color: var(--color-text-maxcontrast);
}

.letter-modal__filled-list dd {
	margin: 0;
}

.letter-modal__missing {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.letter-modal__check {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-success-hover, var(--color-background-hover));
	color: var(--color-success-text, var(--color-main-text));
	font-size: 14px;
	font-weight: 600;
}

.letter-modal__check--missing {
	background: var(--color-warning-hover, var(--color-background-hover));
	color: var(--color-warning-text, var(--color-main-text));
}

/* "Download PDF" stands at the start of the footer, the other two at the end. */
.letter-modal__download {
	margin-inline-end: auto;
}
</style>
