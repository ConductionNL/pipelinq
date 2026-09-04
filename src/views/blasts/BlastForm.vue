<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="blast-form">
		<header class="blast-form__header">
			<h2>{{ t('pipelinq', 'New blast') }}</h2>
			<ol class="blast-form__steps">
				<li
					v-for="(s, idx) in steps"
					:key="s.key"
					:class="{
						'is-current': idx === currentStep,
						'is-done': idx < currentStep,
					}">
					{{ s.label }}
				</li>
			</ol>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-else class="blast-form__step">
			<!-- Step 1: name -->
			<section v-if="step === 'name'" class="blast-form__panel">
				<label class="blast-form__label" for="blast-form-name">
					{{ t('pipelinq', 'Blast name') }} *
				</label>
				<input
					id="blast-form-name"
					v-model="model.name"
					type="text"
					class="blast-form__input"
					:placeholder="t('pipelinq', 'Q4 Gemeente Outreach')" />
			</section>

			<!-- Step 2: audience — a segment the tenant queries, or a list
			     someone joined (marketing-lists-and-double-opt-in). -->
			<section v-if="step === 'segment'" class="blast-form__panel">
				<fieldset class="blast-form__audience-kind">
					<legend>{{ t('pipelinq', 'Send to') }}</legend>
					<NcCheckboxRadioSwitch
						:modelValue="audienceKind"
						value="segment"
						name="blast-audience-kind"
						type="radio"
						data-testid="blast-audience-segment"
						@update:modelValue="audienceKind = $event">
						{{ t('pipelinq', 'A segment') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:modelValue="audienceKind"
						value="list"
						name="blast-audience-kind"
						type="radio"
						data-testid="blast-audience-list"
						@update:modelValue="audienceKind = $event">
						{{ t('pipelinq', 'A mailing list') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<NcSelect
					v-if="audienceKind === 'segment'"
					v-model="selectedSegment"
					:options="segments"
					:inputLabel="t('pipelinq', 'Segment') + ' *'"
					label="name"
					:loading="segmentsLoading" />
				<p
					v-if="audienceKind === 'segment' && selectedSegment"
					class="blast-form__hint">
					{{ t('pipelinq', 'Estimated audience:') }}
					<strong>{{ selectedSegment.estimatedSize ?? '—' }}</strong>
				</p>

				<NcSelect
					v-if="audienceKind === 'list'"
					v-model="selectedList"
					:options="mailingLists"
					:inputLabel="t('pipelinq', 'Mailing list') + ' *'"
					label="name"
					:loading="mailingListsLoading" />
				<p
					v-if="audienceKind === 'list' && selectedList"
					class="blast-form__hint">
					{{
						t(
							'pipelinq',
							'Only confirmed subscribers receive this. Anyone still awaiting confirmation is skipped.',
						)
					}}
				</p>
			</section>

			<!-- Step 3: template -->
			<section v-if="step === 'template'" class="blast-form__panel">
				<NcSelect
					v-model="selectedTemplate"
					:options="filteredTemplates"
					:inputLabel="t('pipelinq', 'Template') + ' *'"
					label="name"
					:loading="templatesLoading" />
				<p
					v-if="templateValidationError"
					class="blast-form__error"
					role="alert">
					{{ templateValidationError }}
				</p>

				<NcLoadingIcon v-if="previewLoading" :size="24" />

				<div v-else-if="preview" class="blast-form__preview">
					<h3>{{ t('pipelinq', 'Preview') }}</h3>
					<p v-if="preview.subject" class="blast-form__preview-subject">
						{{ preview.subject }}
					</p>
					<!-- eslint-disable vue/no-v-html -- preview.bodyHtml is the server's own expandArticlesMarker() output, already sanitised there -->
					<div
						class="blast-form__preview-body"
						v-html="preview.bodyHtml" />
					<!-- eslint-enable vue/no-v-html -->
					<ul
						v-if="preview.articles && preview.articles.length"
						class="blast-form__preview-articles">
						<li
							v-for="(article, index) in preview.articles"
							:key="index">
							<strong>{{ article.title }}</strong>
							<p
								v-if="article.summary"
								class="blast-form__preview-article-summary">
								{{ article.summary }}
							</p>
						</li>
					</ul>
				</div>
			</section>

			<!-- Step 4: channel -->
			<section v-if="step === 'channel'" class="blast-form__panel">
				<!--
					`:reduce` keeps this model a STRING, which is what every
					reader of `selectedChannel` assumes: filteredTemplates()
					compares it to `template.channel`, the preflight guard tests
					it against 'email', and both the create call and the
					preflight query send it as-is. Without it vue-select hands
					back the whole `{ value, label }` option, and all four go
					wrong at once while `canSubmit()`'s `!!` check still passes
					because an object is truthy — so "Create blast" simply stays
					disabled with nothing to show for it.
				-->
				<NcSelect
					v-model="selectedChannel"
					:options="channelOptions"
					:inputLabel="t('pipelinq', 'Channel') + ' *'"
					label="label"
					:reduce="(o) => o.value"
					:clearable="false" />
				<NcSelect
					v-model="selectedConnectorSource"
					:options="connectorSources"
					:inputLabel="t('pipelinq', 'Connector source')"
					label="label"
					:loading="connectorSourcesLoading" />
				<p
					v-if="connectorSourcesError"
					class="blast-form__error"
					role="alert">
					{{ connectorSourcesError }}
				</p>
			</section>

			<!-- Step 5: schedule -->
			<section v-if="step === 'schedule'" class="blast-form__panel">
				<label class="blast-form__label" for="blast-form-scheduled-for">
					{{ t('pipelinq', 'Send at') }}
				</label>
				<input
					id="blast-form-scheduled-for"
					v-model="model.scheduledFor"
					type="datetime-local"
					class="blast-form__input" />
				<p class="blast-form__hint">
					{{ t('pipelinq', 'Leave empty to send immediately on submit.') }}
				</p>
			</section>

			<!-- Step 6: A/B -->
			<section v-if="step === 'ab'" class="blast-form__panel">
				<label class="blast-form__checkbox">
					<input v-model="abEnabled" type="checkbox" />
					{{ t('pipelinq', 'Run an A/B variant test') }}
				</label>
				<div v-if="abEnabled" class="blast-form__ab">
					<label class="blast-form__label" for="blast-form-ab-split">
						{{ t('pipelinq', 'Variant A share (%)') }}:
						{{ model.abSplitPercent }}
					</label>
					<input
						id="blast-form-ab-split"
						v-model.number="model.abSplitPercent"
						type="range"
						min="0"
						max="100"
						step="5"
						class="blast-form__range" />
					<p class="blast-form__hint">
						{{
							t(
								'pipelinq',
								'Variant B will receive the remaining audience share.',
							)
						}}
					</p>
				</div>
			</section>
		</div>

		<p v-if="submitError" class="blast-form__error" role="alert">
			{{ submitError }}
		</p>

		<footer class="blast-form__footer">
			<NcButton variant="tertiary" @click="$router.push({ name: 'Blasts' })">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton v-if="currentStep > 0" variant="secondary" @click="prev">
				{{ t('pipelinq', 'Back') }}
			</NcButton>
			<NcButton
				v-if="!isLastStep"
				variant="primary"
				:disabled="!canAdvance"
				@click="next">
				{{ t('pipelinq', 'Next') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				{{
					submitting
						? t('pipelinq', 'Saving…')
						: t('pipelinq', 'Create blast')
				}}
			</NcButton>
		</footer>

		<MissingConsentModal
			v-if="showConsentModal"
			:contacts="missingConsentContacts"
			:channel="selectedChannel"
			@cancel="onConsentCancel"
			@requestConsent="onConsentRequest"
			@skipAndSend="onConsentSkip" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showInfo } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
} from '@nextcloud/vue'
import MissingConsentModal from '../../modals/MissingConsentModal.vue'
import { previewTemplate } from '../../services/articlesApi.js'

const STEPS = [
	{ key: 'name', labelKey: 'Name' },
	{ key: 'segment', labelKey: 'Segment' },
	{ key: 'template', labelKey: 'Template' },
	{ key: 'channel', labelKey: 'Channel' },
	{ key: 'schedule', labelKey: 'Schedule' },
	{ key: 'ab', labelKey: 'A/B split' },
]

export default {
	name: 'BlastForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		MissingConsentModal,
	},

	data() {
		return {
			loading: false,
			submitting: false,
			submitError: '',
			currentStep: 0,
			model: {
				name: '',
				segmentId: '',
				listId: '',
				templateId: '',
				channel: 'email',
				connectorSourceId: '',
				scheduledFor: '',
				abSplitPercent: 50,
			},

			abEnabled: false,
			audienceKind: 'segment',
			segments: [],
			mailingLists: [],
			templates: [],
			connectorSources: [],
			connectorSourcesError: '',
			selectedSegment: null,
			selectedList: null,
			selectedTemplate: null,
			selectedChannel: 'email',
			selectedConnectorSource: null,
			segmentsLoading: false,
			mailingListsLoading: false,
			templatesLoading: false,
			connectorSourcesLoading: false,
			templateValidationError: '',
			preview: null,
			previewLoading: false,
			showConsentModal: false,
			missingConsentContacts: [],
			consentDecision: null,
		}
	},

	computed: {
		/**
		 * The step descriptors with localised labels.
		 *
		 * @return {Array<{key:string,label:string}>}
		 */
		steps() {
			return STEPS.map((s) => ({
				key: s.key,
				label: this.t('pipelinq', s.labelKey),
			}))
		},

		/**
		 * The current step key.
		 *
		 * @return {string}
		 */
		step() {
			return STEPS[this.currentStep].key
		},

		/**
		 * Whether this is the last step (submit button shown instead of Next).
		 *
		 * @return {boolean}
		 */
		isLastStep() {
			return this.currentStep === STEPS.length - 1
		},

		/**
		 * Channel options shown on the channel step.
		 *
		 * @return {Array<{value:string,label:string}>}
		 */
		channelOptions() {
			return [
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'sms', label: this.t('pipelinq', 'SMS') },
			]
		},

		/**
		 * Templates filtered by the selected channel (email/sms must match).
		 *
		 * @return {Array<object>}
		 */
		filteredTemplates() {
			if (!this.selectedChannel) {
				return this.templates
			}
			return this.templates.filter((t) => t.channel === this.selectedChannel)
		},

		/**
		 * Returns true when the current step's required input is satisfied.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		canAdvance() {
			switch (this.step) {
				case 'name':
					return !!(this.model.name && this.model.name.trim())
				case 'segment':
					return this.hasAudience
				case 'template':
					return !!this.selectedTemplate && !this.templateValidationError
				case 'channel':
					return !!this.selectedChannel
				default:
					return true
			}
		},

		/**
		 * Whether an audience has been chosen. Exactly one is named: the two
		 * pickers are mutually exclusive because a blast that named both would
		 * leave the send path to pick, and whichever it picked would surprise
		 * the marketer who named the other.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		hasAudience() {
			if (this.audienceKind === 'list') {
				return !!this.selectedList
			}
			return !!this.selectedSegment
		},

		/**
		 * Returns true when every required step has been satisfied so the user
		 * can submit. Schedule + A/B are optional but channel/segment/template
		 * are not.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		canSubmit() {
			return (
				!!(this.model.name && this.model.name.trim())
				&& this.hasAudience
				&& !!this.selectedTemplate
				&& !!this.selectedChannel
				&& !this.templateValidationError
			)
		},
	},

	watch: {
		selectedSegment(option) {
			this.model.segmentId = option?.id || ''
		},

		/**
		 * Mirror the picked list onto the payload.
		 *
		 * @param {object|null} option The chosen list, or null.
		 * @return {void}
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		selectedList(option) {
			this.model.listId = option?.id || ''
		},

		/**
		 * Switch the audience picker and clear the side that is now hidden.
		 *
		 * @param {string} kind Either 'segment' or 'list'.
		 * @return {void}
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		audienceKind(kind) {
			// Clear the other side rather than leaving it set but hidden: a
			// stale id would travel in the payload and the server would refuse
			// the blast for naming two audiences, with nothing on screen to
			// explain why.
			if (kind === 'list') {
				this.selectedSegment = null
				this.model.segmentId = ''
				this.loadMailingLists()
				return
			}
			this.selectedList = null
			this.model.listId = ''
		},

		selectedTemplate(option) {
			this.model.templateId = option?.id || ''
			this.validateTemplate()
			this.loadPreview()
		},

		selectedChannel(value) {
			this.model.channel = value
			// Drop the template if it no longer matches the channel.
			if (this.selectedTemplate && this.selectedTemplate.channel !== value) {
				this.selectedTemplate = null
				this.model.templateId = ''
			}
			this.validateTemplate()
			this.loadPreview()
		},

		selectedConnectorSource(option) {
			this.model.connectorSourceId = option?.id || ''
		},

		abEnabled(on) {
			if (!on) {
				this.model.abSplitPercent = 100
			} else if (this.model.abSplitPercent === 100) {
				this.model.abSplitPercent = 50
			}
		},
	},

	mounted() {
		this.loadSegments()
		this.loadTemplates()
		this.loadConnectorSources()
	},

	methods: {
		/**
		 * Load all segments for the segment picker.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async loadSegments() {
			this.segmentsLoading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/segments'),
				)
				this.segments = data?.data || data?.results || data || []
			} catch {
				this.segments = []
			} finally {
				this.segmentsLoading = false
			}
		},

		/**
		 * Load all campaign templates.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async loadTemplates() {
			this.templatesLoading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/templates'),
				)
				this.templates = data?.data || data?.results || data || []
			} catch {
				this.templates = []
			} finally {
				this.templatesLoading = false
			}
		},

		/**
		 * Load OpenConnector sources usable as email/SMS dispatch endpoints.
		 *
		 * OpenConnector's own `/api/sources` CRUD route was removed when its
		 * Source/Mapping/Synchronization/Job entities moved onto OpenRegister's
		 * generic object API — sources now live as OpenRegister objects in the
		 * `openconnector` register, `source` schema. `type` is filtered to
		 * `api` (the generic HTTP dispatch kind `CallService` sends through,
		 * covering every vendor connector — Twilio/MessageBird/CM.com/WhatsApp
		 * included); the Source schema has no distinct `email`/`sms` channel
		 * value, so filtering on one would silently return zero results again.
		 *
		 * @spec exclude bug fix restoring already-intended behaviour after
		 * OpenConnector's API moved — no new requirement introduced
		 */
		async loadConnectorSources() {
			this.connectorSourcesLoading = true
			this.connectorSourcesError = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/source'
							+ '?type=api&isEnabled=true&_limit=200',
					),
				)
				const list = data?.results || data?.data || data || []
				this.connectorSources = list.map((src) => ({
					id: src.id || src.uuid,
					label: src.name || src.title || src.id,
				}))
			} catch {
				this.connectorSources = []
				this.connectorSourcesError = this.t(
					'pipelinq',
					'Could not load connector sources. You can still create the blast and set a source later.',
				)
			} finally {
				this.connectorSourcesLoading = false
			}
		},

		/**
		 * Call the template validation endpoint for email templates; SMS
		 * templates skip the check (per ComplianceService).
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-email-template-validated-before-save
		 */
		async validateTemplate() {
			this.templateValidationError = ''
			if (!this.selectedTemplate || this.selectedChannel !== 'email') {
				return
			}
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/templates/${this.selectedTemplate.id}/validate`,
				)
				const { data } = await axios.post(url, {
					channel: this.selectedChannel,
				})
				if (data?.valid === false) {
					this.templateValidationError =
						data?.error
						|| this.t(
							'pipelinq',
							'Template is missing the unsubscribe token or physical address.',
						)
				}
			} catch (e) {
				const msg = e?.response?.data?.error
				this.templateValidationError =
					msg || this.t('pipelinq', 'Template validation failed.')
			}
		},

		/**
		 * Load the rendered preview for the selected template: its bodies
		 * with the `{{articles}}` marker already expanded, produced by the
		 * same `ArticleService::expandArticlesMarker()` call the send path
		 * runs — so what a marketer reads here is what will send.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 * @return {Promise<void>} Resolves when the preview is in place.
		 */
		async loadPreview() {
			this.preview = null
			if (!this.selectedTemplate) {
				return
			}
			this.previewLoading = true
			try {
				this.preview = await previewTemplate(this.selectedTemplate.id)
			} catch {
				this.preview = null
			} finally {
				this.previewLoading = false
			}
		},

		/**
		 * Advance to the next step (validations gate via canAdvance).
		 */
		next() {
			if (this.currentStep < STEPS.length - 1) {
				this.currentStep += 1
			}
		},

		/**
		 * Step back; clears form-level submit errors.
		 */
		prev() {
			if (this.currentStep > 0) {
				this.currentStep -= 1
			}
			this.submitError = ''
		},

		/**
		 * Run a compliance preflight before the final POST. When contacts are
		 * missing consent the missing-consent modal is shown with skip/request/
		 * cancel actions and submission waits on the user's decision.
		 *
		 * @return {Promise<boolean>} True when the form is allowed to proceed.
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-missing-consent-modal-on-send
		 */
		async preflightCompliance() {
			if (!this.selectedSegment) {
				return true
			}
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/segments/${this.selectedSegment.id}/compliance`,
				)
				const { data } = await axios.get(url, {
					params: { channel: this.selectedChannel },
				})
				const missing = data?.missingConsent || data?.missing || []
				if (missing.length === 0) {
					return true
				}
				this.missingConsentContacts = missing
				this.showConsentModal = true
				return await this.awaitConsentDecision()
			} catch {
				// On preflight failure, surface the error inline and block the send.
				this.submitError = this.t(
					'pipelinq',
					'Could not run pre-send compliance check.',
				)
				return false
			}
		},

		/**
		 * Block until the user resolves the missing-consent modal.
		 *
		 * @return {Promise<boolean>} True if the user chose "Skip and send".
		 */
		awaitConsentDecision() {
			return new Promise((resolve) => {
				const watchHandle = this.$watch('consentDecision', (value) => {
					if (value === null) {
						return
					}
					watchHandle()
					const decision = value
					this.consentDecision = null
					this.showConsentModal = false
					resolve(decision === 'skip')
				})
			})
		},

		/**
		 * Modal handler: user cancelled — abort send.
		 */
		onConsentCancel() {
			this.consentDecision = 'cancel'
		},

		/**
		 * Modal handler: user wants to launch a consent-request flow. We
		 * navigate them to a (forthcoming) consent-request screen but do not
		 * send the blast.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		onConsentRequest() {
			this.consentDecision = 'request'
			// `OC.Notification` does not exist on Nextcloud 34 -- window.OC is
			// present but has no Notification member, so the legacy
			// showTemporary() call threw a TypeError the moment this handler
			// ran and the user saw nothing at all. Every other view in this app
			// already uses @nextcloud/dialogs.
			showInfo(
				this.t(
					'pipelinq',
					'A consent-request flow will be opened for the listed contacts.',
				),
			)
		},

		/**
		 * Modal handler: user accepted skip-and-send.
		 */
		onConsentSkip() {
			this.consentDecision = 'skip'
		},

		/**
		 * Final submit: preflight compliance, POST /api/blasts, redirect to monitor.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async submit() {
			this.submitError = ''
			this.submitting = true
			try {
				const ok = await this.preflightCompliance()
				if (!ok) {
					this.submitting = false
					return
				}
				const payload = {
					name: this.model.name.trim(),
					segmentId: this.model.segmentId,
					listId: this.model.listId,
					templateId: this.model.templateId,
					channel: this.model.channel,
					connectorSourceId: this.model.connectorSourceId,
					scheduledFor: this.model.scheduledFor || null,
					abSplitPercent: this.abEnabled ? this.model.abSplitPercent : 100,
				}
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/blasts'),
					payload,
				)
				const blastId = data?.id || data?.data?.id
				if (blastId) {
					this.$router.push({
						name: 'BlastMonitor',
						params: { id: blastId },
					})
				} else {
					this.$router.push({ name: 'Blasts' })
				}
			} catch (e) {
				const msg = e?.response?.data?.error
				this.submitError =
					msg || this.t('pipelinq', 'Failed to create blast.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.blast-form {
	padding: 20px;
	max-width: 760px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.blast-form__header h2 {
	margin: 0 0 8px;
}

.blast-form__steps {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px 14px;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.blast-form__steps .is-current {
	color: var(--color-primary-element);
	font-weight: 700;
}

.blast-form__steps .is-done {
	color: var(--color-success);
}

.blast-form__panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.blast-form__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.blast-form__input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.blast-form__hint {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.blast-form__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}

.blast-form__preview {
	margin-block-start: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.blast-form__preview h3 {
	margin: 0 0 8px;
}

.blast-form__preview-subject {
	font-weight: 600;
}

.blast-form__preview-body {
	color: var(--color-text-maxcontrast);
}

.blast-form__preview-articles {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.blast-form__preview-article-summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.blast-form__checkbox {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.blast-form__ab {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.blast-form__range {
	width: 100%;
}

.blast-form__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
