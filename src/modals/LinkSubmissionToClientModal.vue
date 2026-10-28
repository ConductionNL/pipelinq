<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Link a ticket to a client and a contact person (PqAanvraagOmzetten). Shows
  - the submission as the visitor sent it, offers a new or an existing client
  - and contact (suggesting the client with the typed organisation name and the
  - contact with the typed e-mail), and can create a lead. The writes run as
  - the signed-in colleague; the plan lives in utils/submissionLink.js.
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Link to a client')"
		size="normal"
		@closing="$emit('close')">
		<div class="link-submission">
			<NcLoadingIcon v-if="loading" />
			<template v-else-if="ticket">
				<section class="link-submission__sent">
					<h3>{{ t('pipelinq', 'The submission, as sent') }}</h3>
					<dl>
						<template v-for="row in sentRows" :key="row.label">
							<dt>{{ row.label }}</dt>
							<dd>{{ row.value }}</dd>
						</template>
					</dl>
				</section>

				<section class="link-submission__choice">
					<h3>{{ t('pipelinq', 'Client') }}</h3>
					<NcCheckboxRadioSwitch
						v-model="clientMode"
						type="radio"
						value="new"
						name="link-client">
						{{ t('pipelinq', 'New client "{name}"', { name: plannedClientName }) }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="clientMode"
						type="radio"
						value="existing"
						name="link-client">
						{{ t('pipelinq', 'Link an existing client') }}
					</NcCheckboxRadioSwitch>
					<p v-if="suggestedClient" class="link-submission__hint">
						{{ t('pipelinq', 'A client with this name already exists: {name}', { name: suggestedClient.name }) }}
					</p>
					<CnPartyPicker
						v-if="clientMode === 'existing'"
						v-model="clientId"
						:inputLabel="t('pipelinq', 'Search an existing client')"
						:selectedLabel="suggestedClient && clientId === suggestedClient.id ? suggestedClient.name : ''"
						:sources="clientSources" />
				</section>

				<section class="link-submission__choice">
					<h3>{{ t('pipelinq', 'Contact person') }}</h3>
					<NcCheckboxRadioSwitch
						v-model="contactMode"
						type="radio"
						value="new"
						name="link-contact">
						{{ t('pipelinq', 'New contact person "{name}"', { name: plannedContactName }) }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="contactMode"
						type="radio"
						value="existing"
						name="link-contact">
						{{ t('pipelinq', 'Link an existing contact person') }}
					</NcCheckboxRadioSwitch>
					<p v-if="suggestedContact" class="link-submission__hint">
						{{ t('pipelinq', 'A contact person with this e-mail address already exists: {name}', { name: suggestedContact.name }) }}
					</p>
					<CnPartyPicker
						v-if="contactMode === 'existing'"
						v-model="contactId"
						:inputLabel="t('pipelinq', 'Search an existing contact person')"
						:selectedLabel="suggestedContact && contactId === suggestedContact.id ? suggestedContact.name : ''"
						:sources="contactSources" />
				</section>

				<section class="link-submission__choice">
					<h3>{{ t('pipelinq', 'Lead') }}</h3>
					<NcCheckboxRadioSwitch v-model="createLead">
						{{ t('pipelinq', 'Create a lead in the pipeline') }}
					</NcCheckboxRadioSwitch>
				</section>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>
			<NcNoteCard v-else type="error">
				{{ t('pipelinq', 'This ticket could not be read.') }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canLink || linking"
				@click="link">
				{{ linking ? t('pipelinq', 'Linking…') : t('pipelinq', 'Link') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnPartyPicker } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { createWithContact } from '../services/contactSyncApi.js'
import { linkPlan, runLinkPlan, submittedParty } from '../utils/submissionLink.js'

/**
 * The OpenRegister object collection URL of a pipelinq schema.
 *
 * @param {string} schema The schema slug.
 * @return {string} The URL.
 */
function collectionUrl(schema) {
	return generateUrl(`/apps/openregister/api/objects/pipelinq/${schema}`)
}

/**
 * The first row of a pipelinq collection that matches a filter, or null.
 *
 * @param {string} schema The schema slug.
 * @param {object} params The filter.
 * @return {Promise<object|null>} The row.
 */
async function firstMatch(schema, params) {
	try {
		const { data } = await axios.get(collectionUrl(schema), { params: { ...params, _limit: 1 } })
		return (data?.results || [])[0] || null
	} catch {
		return null
	}
}

export default {
	name: 'LinkSubmissionToClientModal',

	components: { CnPartyPicker, NcButton, NcCheckboxRadioSwitch, CnDialog, NcLoadingIcon, NcNoteCard },

	props: {
		/** The ticket to link. */
		ticketId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			ticket: null,
			clientMode: 'new',
			clientId: null,
			contactMode: 'new',
			contactId: null,
			createLead: false,
			suggestedClient: null,
			suggestedContact: null,
			linking: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		party() {
			return submittedParty(this.ticket)
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		sentRows() {
			const rows = [
				[t('pipelinq', 'Name'), this.party.name],
				[t('pipelinq', 'Email'), this.party.email],
				[t('pipelinq', 'Phone'), this.party.phone],
				[t('pipelinq', 'Organisation'), this.party.organisation],
				[t('pipelinq', 'Page'), this.party.page],
				[t('pipelinq', 'Message'), this.ticket?.description || ''],
			]
			return rows.filter(([, value]) => value).map(([label, value]) => ({ label, value }))
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		plannedClientName() {
			return this.party.organisation || this.party.name || this.party.email
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		plannedContactName() {
			return this.party.name || this.party.email
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		clientSources() {
			return [{ id: 'client', register: 'pipelinq', schema: 'client', label: t('pipelinq', 'Client'), title: '{name}', secondary: '{email}' }]
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		contactSources() {
			return [{ id: 'contact', register: 'pipelinq', schema: 'contact', label: t('pipelinq', 'Contact person'), title: '{name}', secondary: '{email}' }]
		},

		/** @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step */
		canLink() {
			if (!this.ticket) {
				return false
			}
			if (this.clientMode === 'existing' ? !this.clientId : !this.plannedClientName) {
				return false
			}
			return this.contactMode === 'existing' ? Boolean(this.contactId) : Boolean(this.plannedContactName)
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read the ticket and look for a client and a contact that already match.
		 *
		 * @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
		 */
		async load() {
			try {
				const { data } = await axios.get(`${collectionUrl('ticket')}/${encodeURIComponent(this.ticketId)}`)
				this.ticket = data
			} catch {
				this.ticket = null
			}
			if (this.ticket) {
				const [client, contact] = await Promise.all([
					this.party.organisation ? firstMatch('client', { name: this.party.organisation }) : null,
					this.party.email ? firstMatch('contact', { email: this.party.email }) : null,
				])
				if (client) {
					this.suggestedClient = { id: String(client.id ?? client['@self']?.id), name: client.name }
					this.clientMode = 'existing'
					this.clientId = this.suggestedClient.id
				}
				if (contact) {
					this.suggestedContact = { id: String(contact.id ?? contact['@self']?.id), name: contact.name }
					this.contactMode = 'existing'
					this.contactId = this.suggestedContact.id
				}
			}
			this.loading = false
		},

		/**
		 * Run the plan the choices make, then close.
		 *
		 * @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
		 */
		async link() {
			this.linking = true
			this.error = ''
			try {
				const plan = linkPlan({
					ticket: this.ticket,
					client: { mode: this.clientMode, id: this.clientId },
					contact: { mode: this.contactMode, id: this.contactId },
					lead: this.createLead,
				})
				const result = await runLinkPlan(plan, this.ticketId, {
					createParty: (type, data) => createWithContact(type, data),
					patchTicket: (id, data) => axios.patch(`${collectionUrl('ticket')}/${encodeURIComponent(id)}`, data),
					defaultPipeline: async () => {
						const row = await firstMatch('pipeline', { isDefault: true })
						return row ? String(row.id ?? row['@self']?.id ?? '') : ''
					},
					createLead: async (data) => (await axios.post(collectionUrl('lead'), data)).data,
				})
				if (result.leadSkipped) {
					showWarning(t('pipelinq', 'Linked. No lead was made: there is no default pipeline.'))
				} else {
					showSuccess(t('pipelinq', 'The submission is linked to the client.'))
				}
				this.$emit('close')
				window.location.reload()
			} catch (error) {
				this.error = error?.response?.data?.message || error?.message || t('pipelinq', 'Linking failed; nothing was changed on the ticket.')
			} finally {
				this.linking = false
			}
		},
	},
}
</script>

<style scoped>
.link-submission {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0 8px;
}

.link-submission h3 {
	margin: 0 0 4px;
	font-size: 15px;
	font-weight: 600;
}

.link-submission__sent dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.link-submission__sent dt {
	color: var(--color-text-maxcontrast);
}

.link-submission__sent dd {
	margin: 0;
	white-space: pre-line;
}

.link-submission__hint {
	margin: 4px 0;
	color: var(--color-text-maxcontrast);
}
</style>
