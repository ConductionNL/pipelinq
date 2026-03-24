<template>
	<div class="article-feedback">
		<h3 class="article-feedback__title">{{ t('pipelinq', 'Was this article helpful?') }}</h3>
		<div class="article-feedback__buttons">
			<NcButton
				:type="submitted === 'nuttig' ? 'primary' : 'secondary'"
				@click="rate('nuttig')">
				{{ t('pipelinq', 'Helpful') }}
			</NcButton>
			<NcButton
				:type="submitted === 'niet_nuttig' ? 'error' : 'secondary'"
				@click="rate('niet_nuttig')">
				{{ t('pipelinq', 'Not helpful') }}
			</NcButton>
		</div>

		<div v-if="submitted" class="article-feedback__thanks">
			{{ t('pipelinq', 'Thank you for your feedback!') }}
		</div>

		<div v-if="showSuggestionForm" class="article-feedback__suggestion">
			<NcTextField
				:value.sync="suggestionText"
				:label="t('pipelinq', 'Suggest an improvement...')"
				:multiline="true" />
			<NcButton
				type="primary"
				:disabled="!suggestionText.trim()"
				@click="submitSuggestion">
				{{ t('pipelinq', 'Submit suggestion') }}
			</NcButton>
		</div>

		<NcButton
			v-if="!showSuggestionForm"
			type="tertiary"
			@click="showSuggestionForm = true">
			{{ t('pipelinq', 'Suggest improvement') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'

export default {
	name: 'ArticleFeedback',
	components: {
		NcButton,
		NcTextField,
	},
	props: {
		articleId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			submitted: null,
			showSuggestionForm: false,
			suggestionText: '',
		}
	},
	computed: {
		store() {
			return useKennisbankStore()
		},
	},
	methods: {
		async rate(rating) {
			await this.store.submitFeedback(this.articleId, rating)
			this.submitted = rating
			this.$emit('feedback-submitted', rating)
		},
		async submitSuggestion() {
			if (!this.suggestionText.trim()) {
				return
			}
			await this.store.submitFeedback(this.articleId, 'niet_nuttig', this.suggestionText)
			this.suggestionText = ''
			this.showSuggestionForm = false
			this.submitted = 'niet_nuttig'
			this.$emit('feedback-submitted', 'niet_nuttig')
		},
	},
}
</script>

<style scoped>
.article-feedback {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	margin-top: 24px;
}

.article-feedback__title {
	margin: 0 0 12px;
	font-size: 15px;
}

.article-feedback__buttons {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.article-feedback__thanks {
	color: var(--color-success);
	font-size: 13px;
	margin-bottom: 8px;
}

.article-feedback__suggestion {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 500px;
	margin-top: 12px;
}
</style>
