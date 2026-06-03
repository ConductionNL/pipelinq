<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Incoming call — choose a contact')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cti-screenpop">
			<p class="cti-screenpop__caller">
				{{ t('pipelinq', 'Caller') }}: <strong>{{ e164Number }}</strong>
			</p>
			<ul class="cti-screenpop__matches">
				<li v-for="match in matches"
					:key="contactId(match)"
					class="cti-screenpop__match">
					<div class="cti-screenpop__match-info">
						<span class="cti-screenpop__name">{{ match.name || t('pipelinq', 'Unnamed contact') }}</span>
						<span class="cti-screenpop__client">{{ match.client || '' }}</span>
					</div>
					<NcButton type="primary" @click="select(match)">
						{{ t('pipelinq', 'Select') }}
					</NcButton>
				</li>
			</ul>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton @click="$emit('new-contact', e164Number)">
				{{ t('pipelinq', 'New contact') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CtiScreenPopModal',
	components: {
		NcDialog,
		NcButton,
	},
	props: {
		/** The normalised caller number. */
		e164Number: {
			type: String,
			default: '',
		},
		/** The candidate contact matches (max 3). */
		matches: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'select', 'new-contact'],
	methods: {
		t,
		contactId(match) {
			return match?.['@self']?.id || match?.id || match?.name
		},
		select(match) {
			this.$emit('select', match)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.cti-screenpop__matches {
	list-style: none;
	margin: 0;
	padding: 0;
}

.cti-screenpop__match {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.cti-screenpop__match-info {
	display: flex;
	flex-direction: column;
}

.cti-screenpop__client {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
