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
	<NcDialog
		:name="t('pipelinq', 'Incoming call — choose contact')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="screen-pop">
			<p class="screen-pop__intro">
				{{
					t(
						'pipelinq',
						'Multiple contacts match {number}. Select one to open, or create a new contact.',
						{ number: e164 || rawNumber },
					)
				}}
			</p>
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
						<td>{{ displayName(match) }}</td>
						<td>{{ match.clientName || match.organisation || '—' }}</td>
						<td>{{ match._matchType || 'contact' }}</td>
						<td class="screen-pop__actions">
							<NcButton
								variant="primary"
								@click="$emit('select', match)">
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
			<NcButton variant="secondary" @click="$emit('intake')">
				{{ t('pipelinq', 'New contact') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ScreenPopModal',
	components: { NcButton, NcDialog },
	props: {
		matches: { type: Array, required: true },
		e164: { type: String, default: '' },
		rawNumber: { type: String, default: '' },
	},

	emits: ['close', 'select', 'intake'],
	methods: {
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
.screen-pop__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

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
</style>
