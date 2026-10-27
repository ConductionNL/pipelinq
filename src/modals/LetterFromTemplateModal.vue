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
						<NcSelect
							v-model="templateId"
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
					<NcSelect
						v-if="ticketOptions.length > 0"
						v-model="aboutTicketId"
						:options="ticketOptions"
						:inputLabel="t('pipelinq', 'About')"
						labelOutside
						:reduce="(o) => o.value"
						label="label"
						data-testid="letter-about" />
					<NcSelect
						v-if="contactOptions.length > 0"
						v-model="contactId"
						:options="contactOptions"
						:inputLabel="t('pipelinq', 'Contact person (optional)')"
						labelOutside
						:reduce="(o) => o.value"
						label="label"
						data-testid="letter-contact" />
					<p v-if="clientName" class="letter-modal__hint letter-modal__wide">
						{{ t('pipelinq', 'The letter is saved with {client} and appears on their timeline as an outgoing contact moment.', { client: clientName }) }}
					</p>
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
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import {
	downloadLetter,
	fetchLetterTemplates,
	makeLetter,
} from '../services/letters.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LetterFromTemplateModal',
	components: {
		CnDialog,
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
		 *
		 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { clientId, ticketId } = this.routeIds()
				this.resolvedTicketId = ticketId
				this.resolvedClientId = clientId
				const store = useObjectStore()
				if (!clientId && ticketId) {
					const ticket = await store.fetchObject('ticket', ticketId)
					this.resolvedClientId = ticket?.client || ''
				}
				const answer = await fetchLetterTemplates()
				this.available = answer.available
				this.templates = answer.templates
				if (this.templates.length === 1)
					this.templateId = this.templates[0].id
				if (this.resolvedClientId) {
					const client = await store.fetchObject('client', this.resolvedClientId).catch(() => null)
					this.clientName = client?.name || ''
				}
				if (this.available && this.resolvedClientId && !ticketId) {
					this.tickets = (await store.fetchCollection('ticket', {
						client: this.resolvedClientId,
						_limit: 50,
					}).catch(() => [])) || []
					if (this.tickets.length > 0) {
						this.aboutTicketId = this.tickets[0].id
					}
				}
				if (this.available && this.resolvedClientId) {
					this.contacts =
						(await store.fetchCollection('contact', {
							client: this.resolvedClientId,
							_limit: 100,
						})) || []
				}
				if (this.available && !this.resolvedClientId) {
					this.error = t(
						'pipelinq',
						'This ticket has no client, so there is nobody to write to.',
					)
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

.letter-modal__wide {
	grid-column: 1 / -1;
}

.letter-modal__hint {
	margin: 0;
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}
</style>
