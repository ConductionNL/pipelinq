<template>
	<div v-if="totalCount > 0" class="feedback-summary">
		<h4 class="feedback-summary__title">{{ t('pipelinq', 'Feedback') }}</h4>
		<div class="feedback-summary__stats">
			<div class="feedback-summary__stat">
				<span class="feedback-summary__label">{{ t('pipelinq', 'Helpful') }}</span>
				<span class="feedback-summary__value feedback-summary__value--positive">
					{{ positiveCount }}
				</span>
			</div>
			<div class="feedback-summary__stat">
				<span class="feedback-summary__label">{{ t('pipelinq', 'Not helpful') }}</span>
				<span class="feedback-summary__value feedback-summary__value--negative">
					{{ negativeCount }}
				</span>
			</div>
			<div class="feedback-summary__stat">
				<span class="feedback-summary__label">{{ t('pipelinq', 'Satisfaction') }}</span>
				<span
					class="feedback-summary__value"
					:class="satisfactionRate < 70 ? 'feedback-summary__value--warning' : 'feedback-summary__value--positive'">
					{{ satisfactionRate }}%
				</span>
			</div>
		</div>
		<div v-if="satisfactionRate < 70" class="feedback-summary__warning">
			{{ t('pipelinq', 'This article may need review — satisfaction below 70%') }}
		</div>
		<div v-if="recentSuggestions.length > 0" class="feedback-summary__suggestions">
			<h5>{{ t('pipelinq', 'Recent suggestions') }}</h5>
			<div
				v-for="(suggestion, index) in recentSuggestions"
				:key="index"
				class="feedback-summary__suggestion">
				<span class="feedback-summary__suggestion-agent">{{ suggestion.agent }}</span>
				<span class="feedback-summary__suggestion-text">{{ suggestion.comment }}</span>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'FeedbackSummary',
	props: {
		feedback: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		positiveCount() {
			return this.feedback.filter(f => f.rating === 'nuttig').length
		},
		negativeCount() {
			return this.feedback.filter(f => f.rating === 'niet_nuttig').length
		},
		totalCount() {
			return this.feedback.length
		},
		satisfactionRate() {
			if (this.totalCount === 0) {
				return 0
			}
			return Math.round((this.positiveCount / this.totalCount) * 100)
		},
		recentSuggestions() {
			return this.feedback
				.filter(f => f.comment && f.comment.trim())
				.slice(-5)
				.reverse()
		},
	},
}
</script>

<style scoped>
.feedback-summary {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-top: 16px;
}

.feedback-summary__title {
	margin: 0 0 12px;
	font-size: 14px;
	font-weight: 600;
}

.feedback-summary__stats {
	display: flex;
	gap: 24px;
	margin-bottom: 12px;
}

.feedback-summary__stat {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.feedback-summary__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.feedback-summary__value {
	font-size: 18px;
	font-weight: 600;
}

.feedback-summary__value--positive { color: var(--color-success); }
.feedback-summary__value--negative { color: var(--color-error); }
.feedback-summary__value--warning { color: var(--color-warning); }

.feedback-summary__warning {
	background: var(--color-warning);
	color: #000;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	font-size: 13px;
	margin-bottom: 12px;
}

.feedback-summary__suggestions h5 {
	font-size: 13px;
	margin: 0 0 8px;
}

.feedback-summary__suggestion {
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.feedback-summary__suggestion:last-child {
	border-bottom: none;
}

.feedback-summary__suggestion-agent {
	font-weight: 600;
	margin-right: 8px;
}

.feedback-summary__suggestion-text {
	color: var(--color-text-lighter);
}
</style>
