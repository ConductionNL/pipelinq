<template>
	<div class="prospect-card" tabindex="0" @keyup.enter="$emit('create-lead', prospect)">
		<div class="prospect-card__header">
			<span class="prospect-card__name">{{ prospect.tradeName }}</span>
			<span
				class="prospect-card__score"
				:class="scoreClass"
				:title="scoreTooltip">
				{{ prospect.fitScore }}%
			</span>
		</div>

		<div class="prospect-card__details">
			<div v-if="prospect.sbiDescription" class="prospect-card__detail">
				<span class="detail-label">{{ t('pipelinq', 'SBI') }}</span>
				<span>{{ prospect.sbiCode }} — {{ prospect.sbiDescription }}</span>
			</div>
			<div v-if="prospect.employeeCount" class="prospect-card__detail">
				<span class="detail-label">{{ t('pipelinq', 'Employees') }}</span>
				<span>{{ prospect.employeeCount }}</span>
			</div>
			<div v-if="prospect.address && prospect.address.city" class="prospect-card__detail">
				<span class="detail-label">{{ t('pipelinq', 'Location') }}</span>
				<span>{{ prospect.address.city }}{{ prospect.address.province ? ', ' + prospect.address.province : '' }}</span>
			</div>
			<div v-if="prospect.legalForm" class="prospect-card__detail">
				<span class="detail-label">{{ t('pipelinq', 'Legal form') }}</span>
				<span>{{ prospect.legalForm }}</span>
			</div>
			<div class="prospect-card__detail">
				<span class="detail-label">{{ t('pipelinq', 'KVK') }}</span>
				<span>{{ prospect.kvkNumber }}</span>
			</div>
		</div>

		<div class="prospect-card__footer">
			<span class="prospect-card__source">{{ prospect.source }}</span>
			<NcButton type="primary" @click="$emit('create-lead', prospect)">
				{{ t('pipelinq', 'Create Lead') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'ProspectCard',
	components: {
		NcButton,
	},
	props: {
		prospect: {
			type: Object,
			required: true,
		},
	},
	emits: ['create-lead'],
	computed: {
		scoreClass() {
			const score = this.prospect.fitScore || 0
			if (score > 70) return 'score--high'
			if (score >= 40) return 'score--medium'
			return 'score--low'
		},
		scoreTooltip() {
			const b = this.prospect.fitBreakdown
			if (!b) return ''

			const lines = []
			lines.push(t('pipelinq', 'SBI match: {pts}/{max}', { pts: b.sbiMatch || 0, max: 30 }))
			lines.push(t('pipelinq', 'Employees: {pts}/{max}', { pts: b.employeeMatch || 0, max: 25 }))
			lines.push(t('pipelinq', 'Location: {pts}/{max}', { pts: b.locationMatch || 0, max: 20 }))
			lines.push(t('pipelinq', 'Legal form: {pts}/{max}', { pts: b.legalFormMatch || 0, max: 15 }))
			lines.push(t('pipelinq', 'Active: {pts}/{max}', { pts: b.activeMatch || 0, max: 10 }))
			if (b.keywordMatch !== undefined) {
				lines.push(t('pipelinq', 'Keywords: {pts}/{max}', { pts: b.keywordMatch || 0, max: 20 }))
			}
			return lines.join('\n')
		},
	},
}
</script>

<style scoped>
.prospect-card {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: default;
}

.prospect-card:focus {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.prospect-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.prospect-card__name {
	font-weight: 700;
	font-size: 14px;
}

.prospect-card__score {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 700;
	cursor: help;
}

.score--high {
	background: #dcfce7;
	color: #166534;
}

.score--medium {
	background: #fef3c7;
	color: #92400e;
}

.score--low {
	background: #fee2e2;
	color: #991b1b;
}

.prospect-card__details {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
}

.prospect-card__detail {
	display: flex;
	gap: 8px;
	font-size: 13px;
}

.detail-label {
	color: var(--color-text-maxcontrast);
	min-width: 70px;
	flex-shrink: 0;
}

.prospect-card__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.prospect-card__source {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
}
</style>
