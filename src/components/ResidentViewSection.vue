<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

"What the resident sees" on a request ticket (portal-resident-view-preview).
It shows the ticket as the resident portal renders it, and, where portaliq is
installed and the ticket belongs to an organisation, what that organisation's
contact reads there. Both panels come from GET /api/tickets/{id}/resident-view,
which builds them with the portal's own code. One line says everything else
stays internal, with the internal field names on demand. Complaint and
interaction tickets get nothing: the portal does not serve them.

@spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
-->
<template>
	<section v-if="isRequest" class="resident-view" data-testid="resident-view">
		<h3 class="resident-view__title">
			{{ t('pipelinq', 'What the resident sees') }}
		</h3>
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="resident-view__error" role="alert">
			{{ error }}
		</p>
		<template v-else-if="view">
			<div class="resident-view__panels">
				<div v-if="view.bespoke" class="resident-view__panel">
					<h4>{{ t('pipelinq', 'In the resident portal') }}</h4>
					<dl>
						<template v-for="row in bespokeRows" :key="row.label">
							<dt>{{ row.label }}</dt>
							<dd>{{ row.value }}</dd>
						</template>
					</dl>
					<h5 v-if="view.bespoke.notes && view.bespoke.notes.length > 0">
						{{ t('pipelinq', 'Messages') }}
					</h5>
					<ul
						v-if="view.bespoke.notes && view.bespoke.notes.length > 0"
						class="resident-view__notes">
						<li v-for="(note, index) in view.bespoke.notes" :key="index">
							<strong
								>{{
									note.author === 'handler'
										? t('pipelinq', 'You')
										: t('pipelinq', 'Resident')
								}}:</strong
							>
							{{ note.message }}
						</li>
					</ul>
				</div>
				<div
					v-if="view.portaliq"
					class="resident-view__panel"
					data-testid="resident-view-portaliq">
					<h4>{{ t('pipelinq', 'In the organisation portal') }}</h4>
					<dl>
						<template
							v-for="(value, field) in view.portaliq.fields"
							:key="field">
							<dt>{{ fieldLabel(field) }}</dt>
							<dd>{{ display(value) }}</dd>
						</template>
					</dl>
				</div>
			</div>
			<p class="resident-view__internal">
				{{ t('pipelinq', 'Everything else on this ticket stays internal.') }}
				<button
					v-if="internalFields.length > 0"
					type="button"
					class="resident-view__toggle"
					:aria-expanded="showInternal ? 'true' : 'false'"
					@click="showInternal = !showInternal">
					{{
						showInternal
							? t('pipelinq', 'Hide internal fields')
							: t('pipelinq', 'Show internal fields')
					}}
				</button>
			</p>
			<ul
				v-if="showInternal"
				class="resident-view__internal-list"
				data-testid="resident-view-internal">
				<li v-for="field in internalFields" :key="field">
					{{ fieldLabel(field) }}
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

/** The event-bus channel the page-level Refresh action broadcasts on. */
const PAGE_REFRESH_CHANNEL = 'cn:page:refresh'

export default {
	name: 'ResidentViewSection',
	components: { NcLoadingIcon },
	props: {
		/** The ticket id (`@objectId`). */
		ticketId: {
			type: String,
			default: '',
		},

		/** The ticket type (`@object.ticketType`): only requests get a preview. */
		ticketType: {
			type: String,
			default: '',
		},

		/** The message to the customer (`@object.customerMessage`): a save reloads the preview. */
		customerMessage: {
			type: String,
			default: '',
		},

		/** The status (`@object.status`): a save reloads the preview. */
		status: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			view: null,
			showInternal: false,
		}
	},

	computed: {
		/**
		 * Whether the ticket is one the resident portal serves.
		 *
		 * @return {boolean} True for a request ticket.
		 */
		isRequest() {
			return this.ticketType === 'request'
		},

		/**
		 * The resident portal's fields, in the order the portal shows them.
		 *
		 * @return {Array<{label: string, value: string}>} The rows.
		 */
		bespokeRows() {
			const b = (this.view && this.view.bespoke) || {}
			const rows = [
				{ label: this.t('pipelinq', 'Number'), value: b.number },
				{ label: this.t('pipelinq', 'Subject'), value: b.subject },
				{ label: this.t('pipelinq', 'Category'), value: b.category },
				{ label: this.t('pipelinq', 'Status'), value: b.status },
				{ label: this.t('pipelinq', 'Date'), value: b.date },
				{ label: this.t('pipelinq', 'Description'), value: b.body },
			]
			if (b.assigneeHidden !== true) {
				rows.push({
					label: this.t('pipelinq', 'Handler'),
					value: b.assignee,
				})
			}
			return rows.map((row) => ({ ...row, value: this.display(row.value) }))
		},

		/**
		 * The ticket fields no portal shows.
		 *
		 * @return {Array<string>} The field names.
		 */
		internalFields() {
			return this.view && Array.isArray(this.view.internalFields)
				? this.view.internalFields
				: []
		},
	},

	watch: {
		ticketId() {
			this.load()
		},

		customerMessage() {
			this.load()
		},

		status() {
			this.load()
		},
	},

	mounted() {
		subscribe(PAGE_REFRESH_CHANNEL, this.load)
		this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH_CHANNEL, this.load)
	},

	methods: {
		/**
		 * Read the resident's view of this ticket.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.isRequest || !this.ticketId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/tickets/{id}/resident-view', {
						id: this.ticketId,
					}),
				)
				this.view = data
			} catch {
				this.error = this.t(
					'pipelinq',
					'What the resident sees could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A value as text, a dash when empty.
		 *
		 * @param {string|number|null|undefined} value The value.
		 * @return {string} The text.
		 * @spec exclude display formatter: an optional value to text
		 */
		display(value) {
			if (value === null || value === undefined || value === '') {
				return '-'
			}
			return String(value)
		},

		/**
		 * A ticket field's name as a person reads it.
		 *
		 * @param {string} field The field.
		 * @return {string} The label.
		 */
		fieldLabel(field) {
			const labels = {
				title: this.t('pipelinq', 'Subject'),
				category: this.t('pipelinq', 'Category'),
				status: this.t('pipelinq', 'Status'),
				description: this.t('pipelinq', 'Description'),
				occurredAt: this.t('pipelinq', 'Date'),
				customerMessage: this.t('pipelinq', 'Message to the customer'),
				notes: this.t('pipelinq', 'Notes'),
				assignee: this.t('pipelinq', 'Handler'),
				priority: this.t('pipelinq', 'Priority'),
			}
			return labels[field] || field
		},
	},
}
</script>

<style scoped>
.resident-view__panels {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
}

.resident-view__panel dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.resident-view__panel dt {
	color: var(--color-text-maxcontrast);
}

.resident-view__panel dd {
	margin: 0;
	white-space: pre-wrap;
}

.resident-view__notes {
	margin: 0;
	padding-inline-start: 20px;
}

.resident-view__internal {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}

.resident-view__toggle {
	margin-inline-start: 8px;
	min-height: 34px;
}

.resident-view__error {
	color: var(--color-error-text);
}
</style>
