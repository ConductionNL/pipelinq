<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ScreenPopModal renders the chooser the agent sees when a CTI screen-pop
  - returns more than one matching contact / client.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.1
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Incoming call: choose contact')"
		:subtitle="t('pipelinq', 'Multiple contacts match {number}. Select one to open, or create a new contact.', { number: shownNumber })"
		width="form"
		data-testid="screen-pop-dialog"
		@closing="$emit('close')">
		<div class="screen-pop">
			<table class="screen-pop__table" data-testid="screen-pop-matches">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Name') }}</th>
						<th scope="col">{{ t('pipelinq', 'Client') }}</th>
						<th scope="col">{{ t('pipelinq', 'Type') }}</th>
						<th scope="col" class="screen-pop__actions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="match in matches" :key="match.id || match.uuid">
						<td>
							<template v-if="preferredName(match)">
								<strong
									class="screen-pop__preferred"
									data-testid="screen-pop-preferred-name"
									>{{ preferredName(match) }}</strong
								>
								<span
									class="screen-pop__full-name"
									data-testid="screen-pop-full-name"
									>{{ displayName(match) }}</span
								>
							</template>
							<template v-else>
								{{ displayName(match) }}
							</template>
						</td>
						<td class="screen-pop__muted">{{ clientLine(match) }}</td>
						<td>{{ kindOf(match) }}</td>
						<td class="screen-pop__actions">
							<NcButton @click="$emit('select', match)">
								{{ t('pipelinq', 'Select') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" data-testid="screen-pop-new" @click="$emit('intake')">
				<template #icon>
					<AccountPlus :size="20" />
				</template>
				{{ t('pipelinq', 'New contact') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlusOutline.vue'
import { readablePhone } from '../utils/readablePhone.js'

export default {
	name: 'ScreenPopModal',
	components: { AccountPlus, CnDialog, NcButton },
	props: {
		matches: { type: Array, required: true },
		e164: { type: String, default: '' },
		rawNumber: { type: String, default: '' },
	},

	emits: ['close', 'select', 'intake'],
	computed: {
		/** @return {string} The number as people read it. */
		shownNumber() {
			return readablePhone(this.rawNumber || this.e164)
		},
	},

	methods: {
		/**
		 * The client column: the client a contact belongs to, else where a
		 * client lives or what a contact does there.
		 *
		 * @param {object} match A matched contact or client.
		 * @return {string} The line.
		 */
		clientLine(match) {
			return match.clientName || match.organisation || match.role || match.address || '—'
		},

		/**
		 * Person or organisation, as the client record says; a contact person otherwise.
		 *
		 * @param {object} match A matched contact or client.
		 * @return {string} The kind.
		 */
		kindOf(match) {
			const type = String(match.type || '').toLowerCase()
			if (type === 'organization' || type === 'organisation') {
				return t('pipelinq', 'Organisation')
			}
			if (type === 'person') {
				return t('pipelinq', 'Person')
			}
			return match._matchType === 'client' ? t('pipelinq', 'Client') : t('pipelinq', 'Contact person')
		},

		/**
		 * The name the caller asked to be called by, which the agent says
		 * first. Empty when they stated none, or when it equals the full name.
		 *
		 * @param {object} match A matched contact or client.
		 * @return {string} The preferred name, or ''.
		 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
		 */
		preferredName(match) {
			const preferred =
				typeof match.preferredName === 'string'
					? match.preferredName.trim()
					: ''
			return preferred !== '' && preferred !== this.displayName(match)
				? preferred
				: ''
		},

		displayName(match) {
			return (
				match.displayName
				|| match.name
				|| `${match.firstName || ''} ${match.lastName || ''}`.trim()
				|| t('pipelinq', 'Unknown contact')
			)
		},
	},
}
</script>

<style scoped>
.screen-pop__table {
	width: 100%;
	border-collapse: collapse;
}

.screen-pop__table th,
.screen-pop__table td {
	padding: 8px 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.screen-pop__actions {
	text-align: end;
}

.screen-pop__preferred,
.screen-pop__full-name {
	display: block;
}

.screen-pop__full-name,
.screen-pop__muted {
	color: var(--color-text-maxcontrast);
}

.screen-pop__table th {
	font-size: 13px;
	font-weight: 600;
}
</style>
