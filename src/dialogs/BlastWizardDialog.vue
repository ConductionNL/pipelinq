<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - The new-blast wizard, mounted into the Blasts index page's `form-dialog`
  - slot: basics, audience, content, delivery, A/B test, then a review of it
  - all before the blast is created.
  -
  - Creating runs the compliance gate first. An email template is validated as
  - soon as it is picked, and a segment's contacts are checked for consent
  - before the POST, with MissingConsentModal deciding a shortfall (skip and
  - send, request consent, or cancel).
  -->
<template>
	<NcDialog
		v-if="show"
		:name="t('pipelinq', 'New blast')"
		:open="true"
		size="large"
		:closeOnClickOutside="false"
		@closing="close()">
		<div class="blast-wizard">
			<ol class="blast-wizard__steps">
				<li
					v-for="(s, idx) in steps"
					:key="s.key"
					class="blast-wizard__step"
					:class="{
						'blast-wizard__step--current': idx === currentStep,
						'blast-wizard__step--done': idx < currentStep,
					}">
					<button
						type="button"
						class="blast-wizard__step-button"
						:disabled="idx > reachableStep"
						:aria-current="idx === currentStep ? 'step' : null"
						@click="goTo(idx)">
						<span class="blast-wizard__step-number" aria-hidden="true">
							<Check v-if="idx < currentStep" :size="16" />
							<template v-else>{{ idx + 1 }}</template>
						</span>
						<span class="blast-wizard__step-label">{{ s.label }}</span>
					</button>
				</li>
			</ol>

			<div class="blast-wizard__body">
				<!-- Basics -->
				<section v-if="step === 'basics'" class="blast-wizard__panel">
					<NcTextField
						id="blast-wizard-name"
						v-model="model.name"
						:label="t('pipelinq', 'Blast name')"
						:placeholder="t('pipelinq', 'Q4 Gemeente Outreach')"
						required />
					<fieldset class="blast-wizard__choice">
						<legend>{{ t('pipelinq', 'Channel') }}</legend>
						<NcCheckboxRadioSwitch
							v-for="option in channelOptions"
							:key="option.value"
							:modelValue="selectedChannel"
							:value="option.value"
							name="blast-wizard-channel"
							type="radio"
							@update:modelValue="selectedChannel = $event">
							{{ option.label }}
						</NcCheckboxRadioSwitch>
					</fieldset>
				</section>

				<!-- Audience: a segment the tenant queries, or a list someone joined. -->
				<section v-if="step === 'audience'" class="blast-wizard__panel">
					<fieldset class="blast-wizard__choice">
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

					<template v-if="audienceKind === 'segment'">
						<NcSelect
							v-model="selectedSegment"
							:options="segments"
							:inputLabel="t('pipelinq', 'Segment')"
							label="name"
							:loading="segmentsLoading"
							class="blast-wizard__select" />
						<p v-if="selectedSegment" class="blast-wizard__hint blast-wizard__audience-hint">
							{{ t('pipelinq', 'Estimated audience:') }}
							<strong>{{ selectedSegment.estimatedSize ?? '—' }}</strong>
						</p>
					</template>
					<template v-else>
						<NcSelect
							v-model="selectedList"
							:options="mailingLists"
							:inputLabel="t('pipelinq', 'Mailing list')"
							label="name"
							:loading="mailingListsLoading"
							class="blast-wizard__select" />
						<p v-if="selectedList" class="blast-wizard__hint blast-wizard__audience-hint">
							{{ t('pipelinq', 'Only confirmed subscribers receive this. Anyone still awaiting confirmation is skipped.') }}
						</p>
					</template>
				</section>

				<!-- Content -->
				<section v-if="step === 'content'" class="blast-wizard__panel">
					<NcSelect
						v-model="selectedTemplate"
						:options="filteredTemplates"
						:inputLabel="t('pipelinq', 'Template')"
						label="name"
						:loading="templatesLoading"
						class="blast-wizard__select" />
					<p v-if="!templatesLoading && filteredTemplates.length === 0" class="blast-wizard__hint">
						{{ t('pipelinq', 'There are no templates for this channel yet.') }}
					</p>
					<NcNoteCard v-if="templateValidationError" type="error" class="blast-wizard__note">
						{{ templateValidationError }}
					</NcNoteCard>

					<NcLoadingIcon v-if="previewLoading" :size="24" />
					<div v-else-if="preview" class="blast-wizard__preview">
						<p v-if="preview.subject" class="blast-wizard__preview-subject">
							<span class="blast-wizard__preview-label">{{ t('pipelinq', 'Subject') }}</span>
							{{ preview.subject }}
						</p>
						<!-- An empty `sandbox` gives the preview an opaque origin and
						     no scripts, forms or popups. -->
						<iframe
							class="blast-wizard__preview-frame"
							sandbox=""
							referrerpolicy="no-referrer"
							:title="t('pipelinq', 'Message preview')"
							:srcdoc="previewDocument" />
						<ul
							v-if="preview.articles && preview.articles.length"
							class="blast-wizard__preview-articles">
							<li v-for="(article, index) in preview.articles" :key="index">
								<strong>{{ article.title }}</strong>
								<p v-if="article.summary">
									{{ article.summary }}
								</p>
							</li>
						</ul>
					</div>
				</section>

				<!-- Delivery -->
				<section v-if="step === 'delivery'" class="blast-wizard__panel">
					<div class="blast-wizard__field">
						<NcSelect
							v-model="selectedTransport"
							:options="transports"
							:inputLabel="t('pipelinq', 'Send through')"
							label="label"
							:loading="transportsLoading"
							class="blast-wizard__select blast-wizard__transport" />
						<p class="blast-wizard__hint">
							{{ t('pipelinq', 'Leave empty to send through the default transport.') }}
						</p>
						<NcNoteCard v-if="transportsError" type="warning" class="blast-wizard__note">
							{{ transportsError }}
						</NcNoteCard>
					</div>

					<div class="blast-wizard__field">
						<NcSelect
							v-model="selectedConnectorSource"
							:options="connectorSources"
							:inputLabel="t('pipelinq', 'Connector source')"
							label="label"
							:loading="connectorSourcesLoading"
							class="blast-wizard__select" />
						<NcNoteCard v-if="connectorSourcesError" type="warning" class="blast-wizard__note">
							{{ connectorSourcesError }}
						</NcNoteCard>
					</div>

					<fieldset class="blast-wizard__choice">
						<legend>{{ t('pipelinq', 'When') }}</legend>
						<NcCheckboxRadioSwitch
							:modelValue="scheduleMode"
							value="now"
							name="blast-wizard-schedule"
							type="radio"
							@update:modelValue="scheduleMode = $event">
							{{ t('pipelinq', 'Send as soon as the blast is created') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:modelValue="scheduleMode"
							value="later"
							name="blast-wizard-schedule"
							type="radio"
							@update:modelValue="scheduleMode = $event">
							{{ t('pipelinq', 'Schedule for later') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<NcDateTimePickerNative
						v-if="scheduleMode === 'later'"
						id="blast-wizard-scheduled-for"
						:modelValue="scheduledForDate"
						type="datetime-local"
						:label="t('pipelinq', 'Send at')"
						class="blast-wizard__schedule"
						@update:modelValue="setScheduledFor" />
				</section>

				<!-- A/B test -->
				<section v-if="step === 'ab'" class="blast-wizard__panel">
					<NcCheckboxRadioSwitch v-model="abEnabled" type="switch">
						{{ t('pipelinq', 'Run an A/B variant test') }}
					</NcCheckboxRadioSwitch>
					<div v-if="abEnabled" class="blast-wizard__ab">
						<label class="blast-wizard__ab-label" for="blast-wizard-ab-split">
							{{ t('pipelinq', 'Variant A share (%)') }}
						</label>
						<input
							id="blast-wizard-ab-split"
							v-model.number="model.abSplitPercent"
							type="range"
							min="0"
							max="100"
							step="5"
							class="blast-wizard__range">
						<div class="blast-wizard__ab-split">
							<span>{{ t('pipelinq', 'Variant A') }} <strong>{{ model.abSplitPercent }}%</strong></span>
							<span>{{ t('pipelinq', 'Variant B') }} <strong>{{ 100 - model.abSplitPercent }}%</strong></span>
						</div>
						<p class="blast-wizard__hint">
							{{ t('pipelinq', 'Variant B will receive the remaining audience share.') }}
						</p>
					</div>
					<p v-else class="blast-wizard__hint">
						{{ t('pipelinq', 'The whole audience receives the same message.') }}
					</p>
				</section>

				<!-- Review -->
				<section v-if="step === 'review'" class="blast-wizard__panel">
					<dl class="blast-wizard__review">
						<template v-for="row in reviewRows" :key="row.key">
							<dt>{{ row.label }}</dt>
							<dd>{{ row.value }}</dd>
							<dd class="blast-wizard__review-change">
								<NcButton variant="tertiary" @click="goTo(stepIndex(row.step))">
									{{ t('pipelinq', 'Edit') }}
								</NcButton>
							</dd>
						</template>
					</dl>
				</section>

				<NcNoteCard v-if="submitError" type="error" class="blast-wizard__note">
					{{ submitError }}
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" class="blast-wizard__cancel" @click="close()">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton v-if="currentStep > 0" variant="secondary" @click="prev">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
				{{ t('pipelinq', 'Back') }}
			</NcButton>
			<NcButton
				v-if="!isLastStep"
				variant="primary"
				:disabled="!canAdvance"
				@click="next">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
				{{ t('pipelinq', 'Next') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="submitting" :size="20" />
					<Send v-else :size="20" />
				</template>
				{{ t('pipelinq', 'Create blast') }}
			</NcButton>
		</template>

		<MissingConsentModal
			v-if="showConsentModal"
			:contacts="missingConsentContacts"
			:channel="selectedChannel"
			@cancel="onConsentCancel"
			@requestConsent="onConsentRequest"
			@skipAndSend="onConsentSkip" />
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showInfo } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDateTimePickerNative,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Send from 'vue-material-design-icons/Send.vue'
import MissingConsentModal from '../modals/MissingConsentModal.vue'
import { previewTemplate } from '../services/articlesApi.js'

const STEPS = [
	{ key: 'basics', label: 'Basics' },
	{ key: 'audience', label: 'Audience' },
	{ key: 'content', label: 'Content' },
	{ key: 'delivery', label: 'Delivery' },
	{ key: 'ab', label: 'A/B test' },
	{ key: 'review', label: 'Review' },
]

/**
 * @return {object} A blank blast.
 */
function blankModel() {
	return {
		name: '',
		segmentId: '',
		listId: '',
		templateId: '',
		channel: 'email',
		connectorSourceId: '',
		transportId: '',
		scheduledFor: '',
		abSplitPercent: 50,
	}
}

/**
 * @param {Date} date A moment.
 * @return {string} It as a `datetime-local` value (`YYYY-MM-DDTHH:mm`, local time).
 */
function toLocalInput(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export default {
	name: 'BlastWizardDialog',
	components: {
		Check,
		ChevronLeft,
		ChevronRight,
		MissingConsentModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Send,
	},

	inheritAttrs: false,

	props: {
		/** Whether the index page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},
	},

	data() {
		return {
			currentStep: 0,
			submitting: false,
			submitError: '',
			model: blankModel(),
			abEnabled: false,
			audienceKind: 'segment',
			scheduleMode: 'now',
			segments: [],
			mailingLists: [],
			templates: [],
			connectorSources: [],
			connectorSourcesError: '',
			transports: [],
			transportsError: '',
			selectedSegment: null,
			selectedList: null,
			selectedTemplate: null,
			selectedChannel: 'email',
			selectedConnectorSource: null,
			selectedTransport: null,
			segmentsLoading: false,
			mailingListsLoading: false,
			templatesLoading: false,
			connectorSourcesLoading: false,
			transportsLoading: false,
			templateValidating: false,
			templateValidationError: '',
			preview: null,
			previewLoading: false,
			showConsentModal: false,
			missingConsentContacts: [],
			consentDecision: null,
		}
	},

	computed: {
		steps() {
			return STEPS.map((s) => ({ key: s.key, label: this.t('pipelinq', s.label) }))
		},

		step() {
			return STEPS[this.currentStep].key
		},

		isLastStep() {
			return this.currentStep === STEPS.length - 1
		},

		channelOptions() {
			return [
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'sms', label: this.t('pipelinq', 'SMS') },
			]
		},

		/** @return {Array<object>} The templates written for the chosen channel. */
		filteredTemplates() {
			return this.templates.filter((template) => template.channel === this.selectedChannel)
		},

		/**
		 * @return {boolean} Exactly one audience is named: a blast naming both
		 *   would leave the send path to pick one.
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		hasAudience() {
			return this.audienceKind === 'list' ? !!this.selectedList : !!this.selectedSegment
		},

		/**
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 * @return {boolean} The current step is filled in well enough to go on.
		 */
		canAdvance() {
			return this.stepValid(this.step)
		},

		/** @return {number} The furthest step the stepper lets you jump to. */
		reachableStep() {
			const firstInvalid = STEPS.findIndex((s) => !this.stepValid(s.key))
			return firstInvalid === -1 ? STEPS.length - 1 : firstInvalid
		},

		/**
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 * @return {boolean} Every required step is satisfied.
		 */
		canSubmit() {
			return STEPS.every((s) => s.key === 'review' || this.stepValid(s.key))
		},

		scheduledForDate() {
			return this.model.scheduledFor ? new Date(this.model.scheduledFor) : null
		},

		/**
		 * The template preview as a standalone document for the sandboxed
		 * frame. Its CSP only allows inline styles and images, on top of the
		 * sandbox.
		 *
		 * @return {string} The preview document.
		 */
		previewDocument() {
			const csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src data: https:"
			return '<!DOCTYPE html><html><head><meta charset="utf-8">'
				+ `<meta http-equiv="Content-Security-Policy" content="${csp}">`
				+ '<style>body{margin:16px;font-family:sans-serif;color:#222;background:#fff}</style>'
				+ `</head><body>${this.preview?.bodyHtml || ''}</body></html>`
		},

		/** @return {Array<object>} What the review step lists, each with the step that sets it. */
		reviewRows() {
			const none = this.t('pipelinq', 'None')
			const audience = this.audienceKind === 'list'
				? this.t('pipelinq', 'Mailing list: {name}', { name: this.selectedList?.name || '' })
				: this.t('pipelinq', 'Segment: {name}', { name: this.selectedSegment?.name || '' })
			const channel = this.channelOptions.find((o) => o.value === this.selectedChannel)?.label || ''
			const when = this.scheduleMode === 'later' && this.scheduledForDate
				? this.scheduledForDate.toLocaleString()
				: this.t('pipelinq', 'As soon as the blast is created')
			const ab = this.abEnabled
				? this.t('pipelinq', 'Variant A {a}%, variant B {b}%', { a: this.model.abSplitPercent, b: 100 - this.model.abSplitPercent })
				: this.t('pipelinq', 'Off')
			return [
				{ key: 'name', step: 'basics', label: this.t('pipelinq', 'Name'), value: this.model.name.trim() },
				{ key: 'channel', step: 'basics', label: this.t('pipelinq', 'Channel'), value: channel },
				{ key: 'audience', step: 'audience', label: this.t('pipelinq', 'Audience'), value: audience },
				{ key: 'template', step: 'content', label: this.t('pipelinq', 'Template'), value: this.selectedTemplate?.name || none },
				{ key: 'transport', step: 'delivery', label: this.t('pipelinq', 'Send through'), value: this.selectedTransport?.label || this.t('pipelinq', 'Default transport') },
				{ key: 'source', step: 'delivery', label: this.t('pipelinq', 'Connector source'), value: this.selectedConnectorSource?.label || none },
				{ key: 'when', step: 'delivery', label: this.t('pipelinq', 'When'), value: when },
				{ key: 'ab', step: 'ab', label: this.t('pipelinq', 'A/B test'), value: ab },
			]
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.reset()
				}
			},
		},

		selectedSegment(option) {
			this.model.segmentId = option?.id || ''
		},

		/**
		 * @param {object|null} option The chosen list, or null.
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		selectedList(option) {
			this.model.listId = option?.id || ''
		},

		/**
		 * Switch the audience picker, clearing the side now hidden: a stale id
		 * would travel in the payload, and the server refuses a blast naming
		 * two audiences.
		 *
		 * @param {string} kind Either 'segment' or 'list'.
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		audienceKind(kind) {
			if (kind === 'list') {
				this.selectedSegment = null
				this.model.segmentId = ''
				this.loadMailingLists()
				return
			}
			this.selectedList = null
			this.model.listId = ''
		},

		/**
		 * @param {object|null} option The template just picked, or null.
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 */
		selectedTemplate(option) {
			this.model.templateId = option?.id || ''
			this.validateTemplate()
			this.loadPreview()
		},

		/**
		 * A template written for the other channel no longer fits.
		 *
		 * @param {string} value The channel just picked.
		 */
		selectedChannel(value) {
			this.model.channel = value
			if (this.selectedTemplate && this.selectedTemplate.channel !== value) {
				this.selectedTemplate = null
			}
		},

		selectedConnectorSource(option) {
			this.model.connectorSourceId = option?.id || ''
		},

		/**
		 * @param {object} option The picked transport, or null.
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-wizard-offers-a-transport-step
		 */
		selectedTransport(option) {
			this.model.transportId = option?.id || ''
		},

		scheduleMode(mode) {
			if (mode === 'now') {
				this.model.scheduledFor = ''
			}
		},

		abEnabled(on) {
			if (on && this.model.abSplitPercent === 100) {
				this.model.abSplitPercent = 50
			}
		},
	},

	methods: {
		/**
		 * @param {string} key A step key.
		 * @return {boolean} Whether that step is filled in well enough.
		 */
		stepValid(key) {
			switch (key) {
				case 'basics':
					return !!this.model.name.trim() && !!this.selectedChannel
				case 'audience':
					return this.hasAudience
				case 'content':
					return !!this.selectedTemplate && !this.templateValidating && !this.templateValidationError
				case 'delivery':
					return this.scheduleMode === 'now' || !!this.model.scheduledFor
				default:
					return true
			}
		},

		stepIndex(key) {
			return STEPS.findIndex((s) => s.key === key)
		},

		goTo(idx) {
			if (idx <= this.reachableStep) {
				this.currentStep = idx
				this.submitError = ''
			}
		},

		next() {
			if (this.canAdvance && !this.isLastStep) {
				this.currentStep += 1
			}
		},

		prev() {
			if (this.currentStep > 0) {
				this.currentStep -= 1
			}
			this.submitError = ''
		},

		setScheduledFor(date) {
			this.model.scheduledFor = date instanceof Date && !isNaN(date.getTime()) ? toLocalInput(date) : ''
		},

		/**
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-wizard-offers-a-transport-step
		 */
		reset() {
			Object.assign(this.$data, this.$options.data.call(this))
			this.loadSegments()
			this.loadTemplates()
			this.loadConnectorSources()
			this.loadTransports()
		},

		/**
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async loadSegments() {
			this.segmentsLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/segments'), { params: { limit: 200 } })
				this.segments = data?.data || data?.results || data || []
			} catch {
				this.segments = []
			} finally {
				this.segmentsLoading = false
			}
		},

		/**
		 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-blast-may-target-a-mailing-list
		 */
		async loadMailingLists() {
			if (this.mailingLists.length || this.mailingListsLoading) {
				return
			}
			this.mailingListsLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mailing-lists'), { params: { limit: 200 } })
				this.mailingLists = data?.data || data?.results || data || []
			} catch {
				this.mailingLists = []
			} finally {
				this.mailingListsLoading = false
			}
		},

		/**
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async loadTemplates() {
			this.templatesLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/templates'), { params: { limit: 200 } })
				this.templates = data?.data || data?.results || data || []
			} catch {
				this.templates = []
			} finally {
				this.templatesLoading = false
			}
		},

		/**
		 * OpenConnector sources usable as dispatch endpoints. They are
		 * OpenRegister objects in the `openconnector` register; `type=api` is
		 * the generic HTTP dispatch kind every vendor connector uses.
		 *
		 * @spec exclude bug fix restoring already-intended behaviour after
		 * OpenConnector's API moved — no new requirement introduced
		 */
		async loadConnectorSources() {
			this.connectorSourcesLoading = true
			this.connectorSourcesError = ''
			try {
				const { data } = await axios.get(generateUrl(
					'/apps/openregister/api/objects/openconnector/source?type=api&isEnabled=true&_limit=200',
				))
				const list = data?.results || data?.data || data || []
				this.connectorSources = list.map((src) => ({
					id: src.id || src.uuid,
					label: src.name || src.title || src.id,
				}))
			} catch {
				this.connectorSources = []
				this.connectorSourcesError = this.t('pipelinq', 'Could not load connector sources. You can still create the blast and set a source later.')
			} finally {
				this.connectorSourcesLoading = false
			}
		},

		/**
		 * The active mail transports, with the default one pre-selected.
		 *
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-wizard-offers-a-transport-step
		 */
		async loadTransports() {
			this.transportsLoading = true
			this.transportsError = ''
			try {
				const { data } = await axios.get(generateUrl(
					'/apps/openregister/api/objects/pipelinq/mailTransport?active=true&_limit=200',
				))
				const list = data?.results || data?.data || data || []
				this.transports = list.map((transport) => ({
					id: transport.id || transport.uuid,
					label: transport.displayName || transport.id,
					default: !!transport.default,
				}))
				const defaultTransport = this.transports.find((transport) => transport.default)
				if (defaultTransport) {
					this.selectedTransport = defaultTransport
				}
			} catch {
				this.transports = []
				this.transportsError = this.t('pipelinq', 'Could not load mail transports. The blast will send through the default transport.')
			} finally {
				this.transportsLoading = false
			}
		},

		/**
		 * Validate an email template (unsubscribe token, physical address);
		 * SMS templates skip the check.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-email-template-validated-before-save
		 */
		async validateTemplate() {
			this.templateValidationError = ''
			if (!this.selectedTemplate || this.selectedChannel !== 'email') {
				return
			}
			const templateId = this.selectedTemplate.id
			this.templateValidating = true
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/pipelinq/api/templates/${templateId}/validate`),
					{ channel: this.selectedChannel },
				)
				if (this.selectedTemplate?.id === templateId && data?.valid === false) {
					this.templateValidationError = data?.error
						|| this.t('pipelinq', 'Template is missing the unsubscribe token or physical address.')
				}
			} catch (e) {
				if (this.selectedTemplate?.id === templateId) {
					this.templateValidationError = e?.response?.data?.error || this.t('pipelinq', 'Template validation failed.')
				}
			} finally {
				this.templateValidating = false
			}
		},

		/**
		 * The template with its `{{articles}}` marker expanded by the same
		 * call the send path makes, so the preview is what will send.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
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
		 * Check a segment's contacts for consent before sending; a shortfall
		 * waits on the marketer's decision in MissingConsentModal.
		 *
		 * @return {Promise<boolean>} Whether the blast may be created.
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-missing-consent-modal-on-send
		 */
		async preflightCompliance() {
			if (!this.selectedSegment) {
				return true
			}
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/pipelinq/api/segments/${this.selectedSegment.id}/compliance`),
					{ params: { channel: this.selectedChannel } },
				)
				const missing = data?.missingConsent || data?.missing || []
				if (missing.length === 0) {
					return true
				}
				this.missingConsentContacts = missing
				this.showConsentModal = true
				return await this.awaitConsentDecision()
			} catch {
				this.submitError = this.t('pipelinq', 'Could not run pre-send compliance check.')
				return false
			}
		},

		/**
		 * @return {Promise<boolean>} Resolves true when "Skip and send" was chosen.
		 */
		awaitConsentDecision() {
			return new Promise((resolve) => {
				const stop = this.$watch('consentDecision', (value) => {
					if (value === null) {
						return
					}
					stop()
					this.consentDecision = null
					this.showConsentModal = false
					resolve(value === 'skip')
				})
			})
		},

		onConsentCancel() {
			this.consentDecision = 'cancel'
		},

		/**
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		onConsentRequest() {
			this.consentDecision = 'request'
			showInfo(this.t('pipelinq', 'A consent-request flow will be opened for the listed contacts.'))
		},

		onConsentSkip() {
			this.consentDecision = 'skip'
		},

		/**
		 * Run the compliance preflight, create the blast, and open its monitor.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
		 */
		async submit() {
			this.submitError = ''
			this.submitting = true
			try {
				if (!await this.preflightCompliance()) {
					return
				}
				const payload = {
					name: this.model.name.trim(),
					segmentId: this.model.segmentId,
					listId: this.model.listId,
					templateId: this.model.templateId,
					channel: this.model.channel,
					connectorSourceId: this.model.connectorSourceId,
					transportId: this.model.transportId,
					scheduledFor: this.model.scheduledFor || null,
					abSplitPercent: this.abEnabled ? this.model.abSplitPercent : 100,
				}
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/blasts'), payload)
				const blastId = data?.id || data?.data?.id
				this.close()
				if (blastId) {
					this.$router.push({ name: 'BlastMonitor', params: { id: blastId } })
				}
			} catch (e) {
				this.submitError = e?.response?.data?.error || this.t('pipelinq', 'Failed to create blast.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.blast-wizard {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 8px;
}

.blast-wizard__steps {
	display: flex;
	margin: 0;
	padding: 0;
	list-style: none;
	counter-reset: none;
}

.blast-wizard__step {
	position: relative;
	flex: 1 1 0;
	min-width: 0;
}

/* The line joining one step to the next. */
.blast-wizard__step:not(:last-child)::after {
	content: '';
	position: absolute;
	top: 14px;
	inset-inline-start: calc(50% + 18px);
	inset-inline-end: calc(-50% + 18px);
	height: 2px;
	background: var(--color-border-dark);
}

.blast-wizard__step--done:not(:last-child)::after {
	background: var(--color-primary-element);
}

.blast-wizard__step-button {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	width: 100%;
	min-height: 0;
	margin: 0;
	padding: 0 4px;
	border: 0;
	background: none;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	cursor: pointer;
}

/* Nextcloud's core button rules give every state a background, border and
   radius, the strongest at (0,4,1); three classes plus the state outrank
   them. The keyboard focus ring is left alone. */
.blast-wizard__steps .blast-wizard__step .blast-wizard__step-button:hover,
.blast-wizard__steps .blast-wizard__step .blast-wizard__step-button:focus,
.blast-wizard__steps .blast-wizard__step .blast-wizard__step-button:active,
.blast-wizard__steps .blast-wizard__step .blast-wizard__step-button:disabled {
	border: 0;
	border-radius: 0;
	background: none;
	color: var(--color-text-maxcontrast);
}

.blast-wizard__steps .blast-wizard__step .blast-wizard__step-button:disabled {
	cursor: default;
	opacity: 0.6;
}

.blast-wizard__step-number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	border: 2px solid var(--color-border-dark);
	border-radius: 50%;
	background: var(--color-main-background);
	font-weight: 700;
}

.blast-wizard__step--current .blast-wizard__step-number {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}

.blast-wizard__step--done .blast-wizard__step-number {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.blast-wizard__step--current .blast-wizard__step-label {
	color: var(--color-main-text);
	font-weight: 700;
}

.blast-wizard__step-label {
	max-width: 100%;
	overflow: hidden;
	font-size: 0.9em;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Tall enough for the biggest step, so the dialog does not jump between them. */
.blast-wizard__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 300px;
}

.blast-wizard__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.blast-wizard__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.blast-wizard__panel .blast-wizard__select {
	width: 100%;
	margin: 0;
}

.blast-wizard__choice {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	border: 0;
}

.blast-wizard__choice legend {
	margin-bottom: 4px;
	font-weight: 700;
}

.blast-wizard__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.blast-wizard__note {
	margin: 0;
}

.blast-wizard__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.blast-wizard__preview-subject {
	margin: 0;
}

.blast-wizard__preview-label {
	margin-inline-end: 6px;
	color: var(--color-text-maxcontrast);
}

/* A message renders on white whatever the theme, so the preview does too. */
.blast-wizard__preview-frame {
	box-sizing: border-box;
	width: 100%;
	height: 260px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: #fff;
}

.blast-wizard__preview-articles {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.blast-wizard__preview-articles p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.blast-wizard__schedule {
	max-width: 320px;
}

.blast-wizard__ab {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.blast-wizard__ab-label {
	font-weight: 700;
}

.blast-wizard__range {
	width: 100%;
	accent-color: var(--color-primary-element);
}

.blast-wizard__ab-split {
	display: flex;
	justify-content: space-between;
}

.blast-wizard__review {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr) auto;
	align-items: center;
	gap: 0 16px;
	margin: 0;
}

/* Nextcloud's core CSS pads every dt and dd by 12px. */
.blast-wizard__review dt,
.blast-wizard__review dd {
	padding: 0;
}

.blast-wizard__review dt {
	color: var(--color-text-maxcontrast);
}

.blast-wizard__review dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.blast-wizard__cancel {
	margin-inline-end: auto;
}

@media (max-width: 720px) {
	.blast-wizard__step-label {
		display: none;
	}

	.blast-wizard__step--current .blast-wizard__step-label {
		display: block;
	}
}
</style>
