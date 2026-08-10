<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - QueueCreateDialog collects the fields for a new queue (title, description,
  - categories, max capacity) and hands them to its parent, which owns the
  - store write.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of QueueList.vue.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Create queue')"
		@closing="$emit('close')">
		<div class="create-form">
			<label for="queue-new-title">{{ t('pipelinq', 'Title') }}</label>
			<input id="queue-new-title"
				v-model="newQueue.title"
				type="text"
				:placeholder="t('pipelinq', 'Queue name...')">

			<label for="queue-new-description">{{ t('pipelinq', 'Description') }}</label>
			<textarea id="queue-new-description" v-model="newQueue.description" :placeholder="t('pipelinq', 'Optional description...')" />

			<label for="queue-new-categories">{{ t('pipelinq', 'Categories (comma-separated)') }}</label>
			<input id="queue-new-categories"
				v-model="newQueue.categoriesInput"
				type="text"
				:placeholder="t('pipelinq', 'e.g. vergunningen, omgevingsrecht')">

			<label for="queue-new-max-capacity">{{ t('pipelinq', 'Max capacity (empty = unlimited)') }}</label>
			<input id="queue-new-max-capacity"
				v-model.number="newQueue.maxCapacity"
				type="number"
				min="1"
				autocomplete="off">
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!newQueue.title" @click="$emit('create', { ...newQueue })">
				{{ t('pipelinq', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'QueueCreateDialog',
	components: {
		NcButton,
		NcDialog,
	},
	emits: ['close', 'create'],
	data() {
		return {
			newQueue: {
				title: '',
				description: '',
				categoriesInput: '',
				maxCapacity: null,
			},
		}
	},
}
</script>

<style scoped>
.create-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.create-form label {
	font-weight: 600;
	font-size: 13px;
	margin-top: 4px;
}

.create-form input,
.create-form textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.create-form textarea {
	min-height: 60px;
	resize: vertical;
}
</style>
