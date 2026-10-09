<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The public satisfaction survey an invitation links to
(customer-satisfaction-closed-loop). It lives in the customer portal as a
public route, so a respondent needs no account: the token in the link is the
authorisation. It asks the survey's questions, offers "Don't send me
satisfaction surveys again", and says plainly when a survey is closed.

@spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
-->
<template>
	<main class="public-survey">
		<p
			v-if="state === 'loading'"
			class="public-survey__state"
			role="status"
			aria-live="polite">
			{{ t('pipelinq', 'Loading…') }}
		</p>

		<section
			v-else-if="state === 'answered'"
			class="public-survey__card"
			role="status"
			aria-live="polite">
			<h1>{{ t('pipelinq', 'Thank you for your answers') }}</h1>
			<p>
				{{
					t(
						'pipelinq',
						'Your answers have been saved. You can close this page.',
					)
				}}
			</p>
			<p v-if="optOut">
				{{
					t('pipelinq', 'We will not send you satisfaction surveys again.')
				}}
			</p>
		</section>

		<section
			v-else-if="state !== 'open'"
			class="public-survey__card"
			role="status">
			<h1>{{ t('pipelinq', 'This survey is closed') }}</h1>
			<p>{{ closedMessage }}</p>
		</section>

		<form v-else class="public-survey__card" novalidate @submit.prevent="submit">
			<h1>{{ survey.title || t('pipelinq', 'How did we do?') }}</h1>
			<p v-if="survey.description">
				{{ survey.description }}
			</p>

			<fieldset
				v-for="question in questions"
				:key="question.key"
				class="public-survey__question">
				<legend>
					{{ question.label || question.key }}
					<span v-if="question.required" class="public-survey__required">
						{{ t('pipelinq', '(required)') }}
					</span>
				</legend>

				<div v-if="scaleOf(question)" class="public-survey__scale">
					<label
						v-for="value in scaleOf(question)"
						:key="value"
						class="public-survey__scale-option">
						<input
							v-model="answers[question.key]"
							type="radio"
							:name="'q-' + question.key"
							:value="value" />
						<span>{{ value }}</span>
					</label>
					<p
						v-if="question.kind === 'nps'"
						class="public-survey__scale-hint">
						{{
							t(
								'pipelinq',
								'0 is not at all likely, 10 is very likely',
							)
						}}
					</p>
				</div>

				<div
					v-else-if="optionsOf(question).length > 0"
					class="public-survey__options">
					<label
						v-for="option in optionsOf(question)"
						:key="option"
						class="public-survey__option">
						<input
							v-model="answers[question.key]"
							type="radio"
							:name="'q-' + question.key"
							:value="option" />
						<span>{{ option }}</span>
					</label>
				</div>

				<textarea
					v-else
					v-model="answers[question.key]"
					class="public-survey__text"
					rows="4"
					:aria-label="question.label || question.key" />
			</fieldset>

			<label class="public-survey__opt-out">
				<input
					v-model="optOut"
					type="checkbox"
					data-testid="survey-opt-out" />
				<span>{{
					t('pipelinq', "Don't send me satisfaction surveys again")
				}}</span>
			</label>

			<p v-if="error" class="public-survey__error" role="alert">
				{{ error }}
			</p>

			<button type="submit" class="public-survey__submit" :disabled="busy">
				{{ t('pipelinq', 'Send my answers') }}
			</button>
		</form>
	</main>
</template>

<script>
import {
	fetchInvitation,
	submitInvitation,
} from '../../services/surveyInvitationApi.js'

/** The answer scale per question kind. */
const SCALES = {
	nps: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
	rating: [1, 2, 3, 4, 5],
}

export default {
	name: 'PublicSurveyForm',
	props: {
		/** The invitation token; the route param when not passed. */
		token: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			state: 'loading',
			survey: {},
			answers: {},
			optOut: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The token from the prop or the route.
		 *
		 * @return {string} The token.
		 */
		resolvedToken() {
			if (this.token) {
				return this.token
			}
			return (
				(this.$route && this.$route.params && this.$route.params.token) || ''
			)
		},

		/**
		 * The survey's questions, in order.
		 *
		 * @return {Array<object>} The questions.
		 */
		questions() {
			return Array.isArray(this.survey.questions) ? this.survey.questions : []
		},

		/**
		 * Why the survey is closed, in words.
		 *
		 * @return {string} The message.
		 */
		closedMessage() {
			if (this.state === 'responded') {
				return this.t(
					'pipelinq',
					'You have already answered this survey. Thank you.',
				)
			}
			if (this.state === 'expired') {
				return this.t(
					'pipelinq',
					'The time to answer this survey has passed.',
				)
			}
			return this.t(
				'pipelinq',
				'This survey link does not work. It may have been mistyped.',
			)
		},
	},

	async mounted() {
		const result = await fetchInvitation(this.resolvedToken).catch(() => ({
			state: 'unknown',
		}))
		this.survey = result.survey || {}
		this.state = result.state
	},

	methods: {
		/**
		 * The fixed scale a question takes, or null.
		 *
		 * @param {object} question The question.
		 * @return {Array<number>|null} The scale.
		 */
		scaleOf(question) {
			return SCALES[question.kind] || null
		},

		/**
		 * The options of a choice question.
		 *
		 * @param {object} question The question.
		 * @return {Array<string>} The options, empty for free text.
		 */
		optionsOf(question) {
			if (question.kind !== 'choice' || !Array.isArray(question.options)) {
				return []
			}
			return question.options.map(String)
		},

		/**
		 * The first required question left unanswered, or null.
		 *
		 * @return {object|null} The question.
		 */
		firstMissing() {
			return (
				this.questions.find((question) => {
					const answer = this.answers[question.key]
					return (
						question.required
						&& (answer === undefined
							|| answer === null
							|| String(answer).trim() === '')
					)
				}) || null
			)
		},

		/**
		 * Send the answers.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			const missing = this.firstMissing()
			if (missing) {
				this.error = this.t('pipelinq', 'Please answer "{question}".', {
					question: missing.label || missing.key,
				})
				return
			}
			this.error = ''
			this.busy = true
			try {
				const result = await submitInvitation(
					this.resolvedToken,
					{ ...this.answers },
					this.optOut,
				)
				this.state = result.state
			} catch {
				this.error = this.t(
					'pipelinq',
					'Your answers could not be sent. Please try again.',
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.public-survey {
	display: flex;
	justify-content: center;
	padding: 24px 16px;
}

.public-survey__card {
	width: 100%;
	max-width: 640px;
	padding: 24px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.public-survey__question {
	margin: 0 0 20px;
	padding: 0;
	border: none;
}

.public-survey__question legend {
	margin-bottom: 8px;
	font-weight: bold;
}

.public-survey__required {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.public-survey__scale,
.public-survey__options {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.public-survey__scale-option,
.public-survey__option,
.public-survey__opt-out {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 44px;
}

.public-survey__scale-hint {
	width: 100%;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.public-survey__text {
	width: 100%;
}

.public-survey__error {
	color: var(--color-error-text);
}

.public-survey__submit {
	min-height: 44px;
	margin-top: 12px;
}
</style>
